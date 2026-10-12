<?php
/**
 * Helpers to force an authenticated MikroTik session to reauthenticate after
 * its RADIUS group/profile changes. Uses already-configured Mikhmon router
 * sessions from include/config.php; no credentials are stored in this file.
 */
require_once __DIR__ . '/routeros_api.class.php';

if (!function_exists('mikhmonKickSubscriberOnApi')) {
  function mikhmonKickSubscriberOnApi($api, $username) {
    $username = (string)$username;
    if ($username === '') return array('ppp' => 0, 'hotspot' => 0, 'errors' => 0);
    $counts = array('ppp' => 0, 'hotspot' => 0, 'errors' => 0);

    $pppRows = $api->comm('/ppp/active/print', array('?name' => $username));
    if (is_array($pppRows) && !isset($pppRows['!trap'])) {
      foreach ($pppRows as $row) {
        if (empty($row['.id'])) continue;
        $removed = $api->comm('/ppp/active/remove', array('.id' => $row['.id']));
        if (is_array($removed) && isset($removed['!trap'])) $counts['errors']++;
        else $counts['ppp']++;
      }
    } elseif (is_array($pppRows) && isset($pppRows['!trap'])) $counts['errors']++;

    $hotspotRows = $api->comm('/ip/hotspot/active/print', array('?user' => $username));
    if (is_array($hotspotRows) && !isset($hotspotRows['!trap'])) {
      foreach ($hotspotRows as $row) {
        if (empty($row['.id'])) continue;
        $removed = $api->comm('/ip/hotspot/active/remove', array('.id' => $row['.id']));
        if (is_array($removed) && isset($removed['!trap'])) $counts['errors']++;
        else $counts['hotspot']++;
      }
    } elseif (is_array($hotspotRows) && isset($hotspotRows['!trap'])) $counts['errors']++;
    return $counts;
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

    public function disconnectSubscriber($username) {
      $summary = array('routers_checked' => 0, 'ppp' => 0, 'hotspot' => 0, 'errors' => 0, 'router_errors' => array());
      foreach ($this->routerData as $sessionName => $sessionData) {
        if ($sessionName === 'mikhmon' || !is_array($sessionData)) continue;
        $api = $this->apiFor((string)$sessionName, $sessionData);
        if (!$api) {
          if (isset($this->connectErrors[$sessionName])) $summary['router_errors'][$sessionName] = $this->connectErrors[$sessionName];
          continue;
        }
        $summary['routers_checked']++;
        $result = mikhmonKickSubscriberOnApi($api, $username);
        $summary['ppp'] += $result['ppp'];
        $summary['hotspot'] += $result['hotspot'];
        $summary['errors'] += $result['errors'];
      }
      return $summary;
    }

    public function closeAll() {
      foreach ($this->apis as $api) if (is_object($api)) $api->disconnect();
      $this->apis = array();
    }

    public function __destruct() { $this->closeAll(); }
  }
}
?>
