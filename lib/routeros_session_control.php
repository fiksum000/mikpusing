<?php
/**
 * RouterOS session controller for payment/isolation profile changes.
 * Caches a single API connection per configured router and lists active
 * sessions once per protocol/router, then removes only requested usernames.
 */
require_once __DIR__ . '/routeros_api.class.php';

if (!function_exists('mikhmonKickSubscribersOnApi')) {
  function mikhmonKickSubscribersOnApi($api, $usernames) {
    $targets = array_fill_keys(array_map('strval', (array)$usernames), true);
    $counts = array();
    foreach ($targets as $username => $_) $counts[$username] = array('ppp' => 0, 'hotspot' => 0, 'errors' => 0);
    if (!$targets) return $counts;

    $pppRows = $api->comm('/ppp/active/print');
    if (!is_array($pppRows) || isset($pppRows['!trap'])) {
      foreach ($counts as &$summary) $summary['errors']++;
      unset($summary);
    } else {
      foreach ($pppRows as $row) {
        $username = (string)($row['name'] ?? '');
        if ($username === '' || !isset($targets[$username]) || empty($row['.id'])) continue;
        $removed = $api->comm('/ppp/active/remove', array('.id' => $row['.id']));
        if (is_array($removed) && isset($removed['!trap'])) $counts[$username]['errors']++;
        else $counts[$username]['ppp']++;
      }
    }

    $hotspotRows = $api->comm('/ip/hotspot/active/print');
    if (!is_array($hotspotRows) || isset($hotspotRows['!trap'])) {
      foreach ($counts as &$summary) $summary['errors']++;
      unset($summary);
    } else {
      foreach ($hotspotRows as $row) {
        $username = (string)($row['user'] ?? '');
        if ($username === '' || !isset($targets[$username]) || empty($row['.id'])) continue;
        $removed = $api->comm('/ip/hotspot/active/remove', array('.id' => $row['.id']));
        if (is_array($removed) && isset($removed['!trap'])) $counts[$username]['errors']++;
        else $counts[$username]['hotspot']++;
      }
    }
    return $counts;
  }
}

if (!function_exists('mikhmonKickSubscriberOnApi')) {
  function mikhmonKickSubscriberOnApi($api, $username) {
    $all = mikhmonKickSubscribersOnApi($api, array((string)$username));
    return $all[(string)$username] ?? array('ppp' => 0, 'hotspot' => 0, 'errors' => 0);
  }
}

if (!class_exists('MikhmonRouterSessionController')) {
  class MikhmonRouterSessionController {
    private $routerData;
    private $apis = array();
    private $connectErrors = array();

    public function __construct($routerData) {
      $this->routerData = is_array($routerData) ? $routerData : array();
    }

    private function credentials($sessionData) {
      if (!is_array($sessionData) || count($sessionData) < 4) return null;
      $hostField = explode('!', (string)$sessionData[1], 2);
      $userField = explode('@|@', (string)$sessionData[2], 2);
      $passField = explode('#|#', (string)$sessionData[3], 2);
      if (count($hostField) < 2 || count($userField) < 2 || count($passField) < 2) return null;
      $endpoint = trim($hostField[1]); $routerUser = trim($userField[1]);
      if ($endpoint === '' || $routerUser === '') return null;
      $host = $endpoint; $port = 8728;
      if (preg_match('/^\[(.+)\]:(\d+)$/', $endpoint, $m)) { $host = $m[1]; $port = (int)$m[2]; }
      elseif (preg_match('/^([^:]+):(\d+)$/', $endpoint, $m)) { $host = $m[1]; $port = (int)$m[2]; }
      if ($host === '' || $port < 1 || $port > 65535) return null;
      return array($host, $port, $routerUser, decrypt((string)$passField[1]));
    }

    private function apiFor($sessionName, $sessionData) {
      if (array_key_exists($sessionName, $this->apis)) return $this->apis[$sessionName];
      if (isset($this->connectErrors[$sessionName])) return null;
      $credentials = $this->credentials($sessionData);
      if ($credentials === null) { $this->connectErrors[$sessionName] = 'invalid_credentials_format'; return null; }
      list($host, $port, $user, $password) = $credentials;
      $api = new RouterosAPI();
      $api->debug = false;
      $api->attempts = 1;
      $api->delay = 1;
      $api->timeout = 3;
      $api->port = $port;
      if (!$api->connect($host, $user, $password)) {
        $this->connectErrors[$sessionName] = 'connect_failed';
        return null;
      }
      $this->apis[$sessionName] = $api;
      return $api;
    }

    /**
     * Batch disconnect. Routers are queried once for all active PPP and
     * Hotspot sessions rather than twice per subscriber.
     *
     * Each result tracks whether every configured router was reached. This is
     * intentionally conservative: callers keep retrying if any router is down.
     */
    public function disconnectSubscribers($usernames) {
      $usernames = array_values(array_unique(array_filter(array_map('strval', (array)$usernames), function($u) { return $u !== ''; })));
      $result = array();
      foreach ($usernames as $username) {
        $result[$username] = array('routers_checked' => 0, 'ppp' => 0, 'hotspot' => 0, 'errors' => 0, 'router_errors' => array());
      }
      if (!$usernames) return $result;

      foreach ($this->routerData as $sessionName => $sessionData) {
        if ($sessionName === 'mikhmon' || !is_array($sessionData)) continue;
        $api = $this->apiFor((string)$sessionName, $sessionData);
        if (!$api) {
          $reason = $this->connectErrors[$sessionName] ?? 'connect_failed';
          foreach ($usernames as $username) $result[$username]['router_errors'][(string)$sessionName] = $reason;
          continue;
        }

        foreach ($usernames as $username) $result[$username]['routers_checked']++;
        $routerCounts = mikhmonKickSubscribersOnApi($api, $usernames);
        foreach ($usernames as $username) {
          $counts = $routerCounts[$username] ?? array('ppp' => 0, 'hotspot' => 0, 'errors' => 0);
          $result[$username]['ppp'] += $counts['ppp'];
          $result[$username]['hotspot'] += $counts['hotspot'];
          $result[$username]['errors'] += $counts['errors'];
        }
      }
      return $result;
    }

    public function disconnectSubscriber($username) {
      $all = $this->disconnectSubscribers(array((string)$username));
      return $all[(string)$username] ?? array('routers_checked' => 0, 'ppp' => 0, 'hotspot' => 0, 'errors' => 0, 'router_errors' => array());
    }

    public function closeAll() {
      foreach ($this->apis as $api) if (is_object($api)) $api->disconnect();
      $this->apis = array();
    }

    public function __destruct() { $this->closeAll(); }
  }
}
?>
