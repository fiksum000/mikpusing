<?php
if (!function_exists('mikhmonRadiusBaseConfig')) {
  function mikhmonRadiusBaseConfig() {
    static $base = null;
    if ($base !== null) return $base;
    $path = getenv('MIKHMON_RADIUS_CONFIG') ?: '/etc/mikhmon/radius.php';
    $value = (is_file($path) && is_readable($path)) ? require $path : array();
    $base = is_array($value) ? $value : array();
    return $base;
  }
}
if (!function_exists('mikhmonIntegrationSettings')) {
  function mikhmonIntegrationSettings() {
    static $settings = null;
    if ($settings !== null) return $settings;
    $path = __DIR__ . '/../storage/integration_settings.json';
    $value = is_file($path) ? json_decode(@file_get_contents($path) ?: '{}', true) : array();
    $settings = is_array($value) ? $value : array();
    return $settings;
  }
}
if (!function_exists('mikhmonRadiusConfig')) {
  function mikhmonRadiusConfig() {
    static $loaded = null;
    if ($loaded !== null) return $loaded;
    $base = mikhmonRadiusBaseConfig();
    $settings = mikhmonIntegrationSettings();
    $backend = strtolower((string)($settings['ppp_auth_backend'] ?? ($base['ppp_auth_backend'] ?? 'radius')));
    $base['ppp_auth_backend'] = in_array($backend, array('routeros', 'radius'), true) ? $backend : 'radius';
    $loaded = $base;
    return $loaded;
  }
}

/**
 * FreeRADIUS SQL client for the optional central-authentication backend.
 * Configure MIKHMON_RADIUS_DB_HOST/USER/PASSWORD/NAME through the service
 * environment or /etc/mikhmon/radius.env; never use a default DB password.
 */
class RadiusClient {
  public $db;
  private $dbName;

  public function __construct($host = null, $user = null, $pass = null, $db = null) {
    $fileConfig = mikhmonRadiusConfig();
    $host = $host !== null ? $host : (getenv('MIKHMON_RADIUS_DB_HOST') ?: ($fileConfig['db_host'] ?? '127.0.0.1'));
    $user = $user !== null ? $user : (getenv('MIKHMON_RADIUS_DB_USER') ?: ($fileConfig['db_user'] ?? 'radius'));
    $pass = $pass !== null ? $pass : (getenv('MIKHMON_RADIUS_DB_PASSWORD') ?: ($fileConfig['db_password'] ?? ''));
    $db = $db !== null ? $db : (getenv('MIKHMON_RADIUS_DB_NAME') ?: ($fileConfig['db_name'] ?? 'radius'));
    if ($pass === '') throw new RuntimeException('Password database RADIUS belum diatur di /etc/mikhmon/radius.php atau environment.');
    if (!class_exists('mysqli')) throw new RuntimeException('Ekstensi PHP mysqli belum aktif.');
    mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
    $this->db = new mysqli($host, $user, $pass, $db);
    $this->db->set_charset('utf8mb4');
    $this->dbName = $db;
  }

  /** Return all RADIUS users and their highest-priority group. */
  public function listUsers() {
    $sql = "SELECT u.username,
              COALESCE((SELECT g.groupname FROM radusergroup g
                        WHERE g.username = u.username
                        ORDER BY g.priority ASC, g.id ASC LIMIT 1), '') AS profile
            FROM (SELECT DISTINCT username FROM radcheck WHERE username <> '') u
            ORDER BY u.username";
    $result = $this->db->query($sql);
    $rows = array();
    while ($row = $result->fetch_assoc()) $rows[] = $row;
    $result->free();
    return $rows;
  }

  /** Return group names which have RADIUS reply attributes configured. */
  public function listGroups() {
    $result = $this->db->query("SELECT DISTINCT groupname FROM radgroupreply WHERE groupname <> '' ORDER BY groupname");
    $rows = array();
    while ($row = $result->fetch_assoc()) $rows[] = (string)$row['groupname'];
    $result->free();
    return $rows;
  }

  public function getPppSecret($username) {
    $stmt = $this->db->prepare("SELECT username, attribute, op, value FROM radcheck WHERE username = ? ORDER BY id ASC");
    $stmt->bind_param('s', $username);
    $stmt->execute();
    $result = $stmt->get_result();
    $rows = array();
    while ($row = $result->fetch_assoc()) $rows[] = $row;
    $stmt->close();
    return $rows;
  }

  private function accountingStatePath() { return __DIR__ . '/../storage/radius_accounting.json'; }

  /** Return the sampled monthly usage map: username => bytes. */
  public function getMonthlyUsageMap($period = null) {
    $period = $period ?: date('Y-m');
    $path = $this->accountingStatePath();
    if (!is_file($path)) return array();
    $state = json_decode(@file_get_contents($path) ?: '{}', true);
    if (!is_array($state) || !isset($state['usage'][$period]) || !is_array($state['usage'][$period])) return array();
    return $state['usage'][$period];
  }

  /** Read sampled byte counter for one username, or null until sampler runs. */
  public function getUsageBytes($username, $period = null) {
    $map = $this->getMonthlyUsageMap($period);
    return array_key_exists($username, $map) ? (float)$map[$username] : null;
  }

  /** Return sampled usage for a period in GiB, or null before first sample. */
  public function getUsage($username, $period = null) {
    $bytes = $this->getUsageBytes($username, $period);
    return $bytes === null ? null : ($bytes / 1073741824);
  }

  /**
   * Sample FreeRADIUS accounting counters. Schedule every 5 minutes.
   * Sampling deltas preserves monthly usage for sessions that cross month
   * boundaries; accuracy depends on interim updates and this sampler running.
   */
  public function sampleAccountingCounters($period = null) {
    $period = $period ?: date('Y-m');
    $month = DateTimeImmutable::createFromFormat('!Y-m-d', $period . '-01');
    if (!$month || $month->format('Y-m') !== $period) throw new InvalidArgumentException('Periode harus YYYY-MM.');
    $monthStart = $month->format('Y-m-d H:i:s');
    $monthEnd = $month->modify('+1 month')->format('Y-m-d H:i:s');
    $sql = "SELECT radacctid, acctuniqueid, username, acctstarttime, acctstoptime,
                   acctinputoctets, acctoutputoctets, acctinputgigawords, acctoutputgigawords
            FROM radacct
            WHERE acctstarttime < ? AND (acctstoptime IS NULL OR acctstoptime >= ?)";
    $stmt = $this->db->prepare($sql);
    $stmt->bind_param('ss', $monthEnd, $monthStart);
    $stmt->execute();
    $result = $stmt->get_result();
    $rows = array();
    while ($row = $result->fetch_assoc()) $rows[] = $row;
    $stmt->close();

    $path = $this->accountingStatePath();
    $dir = dirname($path);
    if (!is_dir($dir) && !@mkdir($dir, 0750, true) && !is_dir($dir)) throw new RuntimeException('Direktori storage tidak bisa dibuat.');
    $lock = @fopen($path . '.lock', 'c');
    if (!$lock || !flock($lock, LOCK_EX)) throw new RuntimeException('Tidak bisa mengunci file accounting state.');
    try {
      $state = is_file($path) ? json_decode(@file_get_contents($path) ?: '{}', true) : array();
      if (!is_array($state)) $state = array();
      if (!isset($state['sessions']) || !is_array($state['sessions'])) $state['sessions'] = array();
      if (!isset($state['usage']) || !is_array($state['usage'])) $state['usage'] = array();
      if (!isset($state['usage'][$period]) || !is_array($state['usage'][$period])) $state['usage'][$period] = array();
      $now = time();
      $sampled = 0;
      foreach ($rows as $row) {
        $username = trim((string)($row['username'] ?? ''));
        if ($username === '') continue;
        $unique = trim((string)($row['acctuniqueid'] ?? ''));
        $sessionKey = $unique !== '' ? $unique : ('radacctid:' . (string)$row['radacctid']);
        $current = max(0.0, (float)($row['acctinputoctets'] ?? 0) + (float)($row['acctoutputoctets'] ?? 0)
          + (((float)($row['acctinputgigawords'] ?? 0) + (float)($row['acctoutputgigawords'] ?? 0)) * 4294967296));
        $old = $state['sessions'][$sessionKey] ?? null;
        if (is_array($old)) {
          $last = max(0.0, (float)($old['last_counter_bytes'] ?? $current));
          $delta = $current >= $last ? $current - $last : $current;
        } else {
          $started = !empty($row['acctstarttime']) ? strtotime($row['acctstarttime']) : false;
          $delta = ($started !== false && $started >= strtotime($monthStart) && $started < strtotime($monthEnd)) ? $current : 0.0;
        }
        if ($delta > 0) {
          $state['usage'][$period][$username] = (float)($state['usage'][$period][$username] ?? 0) + $delta;
        }
        $state['sessions'][$sessionKey] = array(
          'username' => $username, 'last_counter_bytes' => $current,
          'last_seen' => $now, 'acctstarttime' => (string)($row['acctstarttime'] ?? ''),
          'acctstoptime' => (string)($row['acctstoptime'] ?? '')
        );
        $sampled++;
      }
      // Keep current and recent history only.
      foreach ($state['sessions'] as $key => $session) {
        $stop = !empty($session['acctstoptime']) ? strtotime($session['acctstoptime']) : false;
        if ($stop !== false && $stop < ($now - 14 * 86400)) unset($state['sessions'][$key]);
      }
      ksort($state['usage']);
      if (count($state['usage']) > 18) $state['usage'] = array_slice($state['usage'], -18, null, true);
      $state['updated_at'] = date('c');
      $json = json_encode($state, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
      if ($json === false) throw new RuntimeException('Gagal encode accounting state.');
      $tmp = $path . '.tmp';
      if (@file_put_contents($tmp, $json, LOCK_EX) === false || !@rename($tmp, $path)) throw new RuntimeException('Gagal menulis accounting state.');
      return array('period' => $period, 'rows_sampled' => $sampled, 'users' => count($state['usage'][$period]), 'updated_at' => $state['updated_at']);
    } finally {
      flock($lock, LOCK_UN);
      fclose($lock);
    }
  }

  /** Set a user's FreeRADIUS group. Group should already exist in radgroupreply. */
  public function setProfile($username, $profile) {
    $username = trim((string)$username);
    $profile = trim((string)$profile);
    if ($username === '' || $profile === '') throw new InvalidArgumentException('Username dan group RADIUS wajib diisi.');

    $stmt = $this->db->prepare('SELECT 1 FROM radgroupreply WHERE groupname = ? LIMIT 1');
    $stmt->bind_param('s', $profile);
    $stmt->execute();
    $exists = $stmt->get_result()->fetch_row();
    $stmt->close();
    if (!$exists) throw new RuntimeException("Group RADIUS tidak ditemukan di radgroupreply: " . $profile);

    $this->db->begin_transaction();
    try {
      $stmt = $this->db->prepare('DELETE FROM radusergroup WHERE username = ?');
      $stmt->bind_param('s', $username); $stmt->execute(); $stmt->close();
      $priority = 1;
      $stmt = $this->db->prepare('INSERT INTO radusergroup (username, groupname, priority) VALUES (?, ?, ?)');
      $stmt->bind_param('ssi', $username, $profile, $priority); $stmt->execute(); $stmt->close();
      $this->db->commit();
    } catch (Throwable $e) {
      $this->db->rollback();
      throw $e;
    }
    return true;
  }

  /** Add/update a RADIUS user's cleartext password and group. */
  public function upsertUser($username, $password, $profile) {
    $username = trim((string)$username);
    if ($username === '' || $password === '') throw new InvalidArgumentException('Username/password wajib diisi.');
    // FreeRADIUS radcheck has no portable unique key across installations.
    $this->db->begin_transaction();
    try {
      $stmt = $this->db->prepare("DELETE FROM radcheck WHERE username = ? AND attribute = 'Cleartext-Password'");
      $stmt->bind_param('s', $username); $stmt->execute(); $stmt->close();
      $attribute = 'Cleartext-Password'; $op = ':=';
      $stmt = $this->db->prepare('INSERT INTO radcheck (username, attribute, op, value) VALUES (?, ?, ?, ?)');
      $stmt->bind_param('ssss', $username, $attribute, $op, $password); $stmt->execute(); $stmt->close();
      $this->db->commit();
      if ($profile !== '') $this->setProfile($username, $profile);
    } catch (Throwable $e) {
      $this->db->rollback();
      throw $e;
    }
    return true;
  }

  public function deleteUser($username) {
    $username = trim((string)$username);
    if ($username === '') throw new InvalidArgumentException('Username wajib diisi.');
    $this->db->begin_transaction();
    try {
      foreach (array('radcheck','radreply','radusergroup') as $table) {
        $stmt = $this->db->prepare('DELETE FROM ' . $table . ' WHERE username = ?');
        $stmt->bind_param('s', $username); $stmt->execute(); $stmt->close();
      }
      $this->db->commit();
    } catch (Throwable $e) {
      $this->db->rollback();
      throw $e;
    }
    return true;
  }

  public function disconnect() {
    if ($this->db instanceof mysqli) $this->db->close();
  }
}
?>
