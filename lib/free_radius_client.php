<?php
/**
 * FreeRADIUS SQL client for the optional central-authentication backend.
 * Configure MIKHMON_RADIUS_DB_HOST/USER/PASSWORD/NAME through the service
 * environment or /etc/mikhmon/radius.env; never use a default DB password.
 */
class RadiusClient {
  public $db;
  private $dbName;

  public function __construct($host = null, $user = null, $pass = null, $db = null) {
    $host = $host !== null ? $host : (getenv('MIKHMON_RADIUS_DB_HOST') ?: '127.0.0.1');
    $user = $user !== null ? $user : (getenv('MIKHMON_RADIUS_DB_USER') ?: 'radius');
    $pass = $pass !== null ? $pass : (getenv('MIKHMON_RADIUS_DB_PASSWORD') ?: '');
    $db = $db !== null ? $db : (getenv('MIKHMON_RADIUS_DB_NAME') ?: 'radius');
    if ($pass === '') throw new RuntimeException('MIKHMON_RADIUS_DB_PASSWORD belum diatur.');
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

  /** Sum accounting octets for sessions that started within the selected month. */
  public function getUsageBytes($username, $period = null) {
    $period = $period ?: date('Y-m');
    $start = DateTimeImmutable::createFromFormat('!Y-m-d', $period . '-01');
    if (!$start || $start->format('Y-m') !== $period) throw new InvalidArgumentException('Periode harus YYYY-MM.');
    $end = $start->modify('+1 month');
    $startText = $start->format('Y-m-d H:i:s');
    $endText = $end->format('Y-m-d H:i:s');
    $sql = "SELECT COALESCE(SUM(
              COALESCE(acctinputoctets,0) + COALESCE(acctoutputoctets,0) +
              (COALESCE(acctinputgigawords,0) + COALESCE(acctoutputgigawords,0)) * 4294967296
            ),0) AS total_bytes
            FROM radacct WHERE username = ? AND acctstarttime >= ? AND acctstarttime < ?";
    $stmt = $this->db->prepare($sql);
    $stmt->bind_param('sss', $username, $startText, $endText);
    $stmt->execute();
    $result = $stmt->get_result();
    $row = $result->fetch_assoc();
    $stmt->close();
    return $row ? (float)$row['total_bytes'] : 0.0;
  }

  /** Return usage for a period in GiB. */
  public function getUsage($username, $period = null) {
    return $this->getUsageBytes($username, $period) / 1073741824;
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
