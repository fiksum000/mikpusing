<?php
/**
 * Disconnect active RouterOS sessions through RADIUS Disconnect-Message (DM).
 * This lets the RADIUS billing backend reauthenticate users without RouterOS API.
 * Requires a configured NAS IP/secret and RouterOS /radius incoming accept=yes.
 */
if (!class_exists('MikhmonRadiusDisconnectClient')) {
  class MikhmonRadiusDisconnectClient {
    private $db;
    private $nasIp;
    private $secret;
    private $port;
    private $radclient;

    public function __construct($radiusClient, array $config) {
      if (!is_object($radiusClient) || !isset($radiusClient->db) || !($radiusClient->db instanceof mysqli)) {
        throw new RuntimeException('Koneksi database RADIUS tidak tersedia untuk Disconnect-Message.');
      }
      $this->db = $radiusClient->db;
      $this->nasIp = trim((string)($config['radius_nas_ip'] ?? ''));
      $this->secret = (string)($config['radius_client_secret'] ?? '');
      $this->port = (int)($config['radius_disconnect_port'] ?? 1700);
      $this->radclient = (string)($config['radclient_path'] ?? '/usr/bin/radclient');

      if (filter_var($this->nasIp, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) === false) {
        throw new RuntimeException('radius_nas_ip belum diatur sebagai IPv4 NAS MikroTik.');
      }
      if ($this->secret === '' || !preg_match('/^[A-Za-z0-9._-]{24,128}$/', $this->secret)) {
        throw new RuntimeException('radius_client_secret tidak tersedia atau formatnya tidak valid.');
      }
      if ($this->port < 1 || $this->port > 65535) {
        throw new RuntimeException('radius_disconnect_port di luar rentang port valid.');
      }
      if (!is_executable($this->radclient)) {
        throw new RuntimeException('radclient tidak ditemukan; pasang paket freeradius-utils.');
      }
      if (!function_exists('proc_open')) {
        throw new RuntimeException('PHP proc_open diperlukan untuk mengirim RADIUS Disconnect-Message.');
      }
    }

    private function quoteAttribute($value) {
      $value = str_replace(array("\\", '"', "\r", "\n"), array("\\\\", '\\"', '', ''), (string)$value);
      return '"' . $value . '"';
    }

    private function sendDisconnect(array $session) {
      $username = trim((string)($session['username'] ?? ''));
      $sessionId = trim((string)($session['acctsessionid'] ?? ''));
      $nas = trim((string)($session['nasipaddress'] ?? ''));
      if ($username === '' || $sessionId === '' || $nas !== $this->nasIp) {
        return array('ok' => false, 'message' => 'Data sesi RADIUS tidak lengkap atau NAS tidak sesuai.');
      }

      $packet = "User-Name = " . $this->quoteAttribute($username) . "\n"
        . "Acct-Session-Id = " . $this->quoteAttribute($sessionId) . "\n"
        . "NAS-IP-Address = " . $nas . "\n";
      $framedIp = trim((string)($session['framedipaddress'] ?? ''));
      if (filter_var($framedIp, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false) {
        $packet .= "Framed-IP-Address = " . $framedIp . "\n";
      }

      $command = array(
        $this->radclient, '-x', '-r', '1', '-t', '3',
        $this->nasIp . ':' . $this->port, 'disconnect', $this->secret
      );
      $pipes = array();
      $proc = @proc_open($command, array(
        0 => array('pipe', 'r'),
        1 => array('pipe', 'w'),
        2 => array('pipe', 'w')
      ), $pipes, null, array('PATH' => '/usr/local/sbin:/usr/local/bin:/usr/sbin:/usr/bin:/sbin:/bin'));
      if (!is_resource($proc)) return array('ok' => false, 'message' => 'Tidak dapat menjalankan radclient.');

      fwrite($pipes[0], $packet);
      fclose($pipes[0]);
      stream_set_timeout($pipes[1], 8);
      stream_set_timeout($pipes[2], 8);
      $stdout = stream_get_contents($pipes[1]);
      $stderr = stream_get_contents($pipes[2]);
      fclose($pipes[1]);
      fclose($pipes[2]);
      $exitCode = proc_close($proc);
      $output = (string)$stdout . "\n" . (string)$stderr;
      $acked = preg_match('/Disconnect-ACK/i', $output) === 1;
      if ($exitCode === 0 && $acked) return array('ok' => true, 'message' => 'Disconnect-ACK received');
      $message = trim(preg_replace('/\s+/', ' ', $output));
      if ($message === '') $message = 'radclient exit code ' . $exitCode;
      return array('ok' => false, 'message' => substr($message, 0, 240));
    }

    /**
     * Return the same summary shape as the API controller to support retries.
     * When no open accounting record exists, the subscriber has no known active
     * session to disconnect; a fresh login will use the newly assigned RADIUS group.
     */
    public function disconnectSubscribers($usernames) {
      $result = array();
      $nasIp = $this->nasIp;
      $stmt = $this->db->prepare(
        "SELECT username, acctsessionid, nasipaddress, framedipaddress, acctuniqueid
         FROM radacct
         WHERE username = ? AND nasipaddress = ? AND acctstoptime IS NULL
           AND acctsessionid <> ''
         ORDER BY radacctid ASC"
      );

      foreach (array_values(array_unique(array_filter(array_map('strval', (array)$usernames), function($v) {
        return trim($v) !== '';
      }))) as $username) {
        $sessions = array();
        $stmt->bind_param('ss', $username, $nasIp);
        $stmt->execute();
        $rs = $stmt->get_result();
        while ($row = $rs->fetch_assoc()) $sessions[] = $row;
        $rs->free();

        $summary = array(
          'routers_checked' => 1, 'ppp' => 0, 'hotspot' => 0,
          'sessions' => 0, 'errors' => 0, 'router_errors' => array(),
          'no_active_sessions' => count($sessions) === 0
        );
        foreach ($sessions as $session) {
          $attempt = $this->sendDisconnect($session);
          if ($attempt['ok']) {
            $summary['sessions']++;
          } else {
            $summary['errors']++;
            $summary['router_errors'][$nasIp] = $attempt['message'];
          }
        }
        $result[$username] = $summary;
      }
      $stmt->close();
      return $result;
    }
  }
}
?>
