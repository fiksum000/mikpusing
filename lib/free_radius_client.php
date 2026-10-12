<?php
/**
 * RADIUS Client (FreeRADIUS + MySQL) — pengganti RouterOS API
 * Menghubungi DB RADIUS (radcheck, radacct) bukan router langsung
 */
class RadiusClient {
  public $db; public $radiusSecret = '';
  public function __construct($host='localhost', $user='radius', $pass='radius', $db='radius') {
    $this->db = new mysqli($host, $user, $pass, $db);
    if ($this->db->connect_error) throw new Exception('DB RADIUS gagal: '.$this->db->connect_error);
  }
  // Ambil PPPoE secret dari radcheck (username = pppoe login)
  public function getPppSecret($username) {
    $stmt = $this->db->prepare("SELECT username,attribute,value FROM radcheck WHERE username=?");
    $stmt->bind_param("s", $username); $stmt->execute(); $res = $stmt->get_result();
    $row = $res->fetch_assoc(); return $row ? $row : null;
  }
  // Ambil usage dari radacct (periode bulan ini)
  public function getUsage($username, $period='2026-10') {
    $stmt = $this->db->prepare("SELECT SUM(AcctInputOctets)+SUM(AcctOutputOctets) as total FROM radacct WHERE username=? AND DATE_FORMAT(AcctStartTime,'%Y-%m')=?");
    $stmt->bind_param("ss", $username, $period); $stmt->execute(); $res = $stmt->get_result();
    $data = $res->fetch_assoc(); return ($data ? (float)$data['total'] : 0);
  }
  // Update profile (simulasi: update radcheck + log)
  public function setProfile($username, $profile) {
    // Di RADIUS, profile = attribute 'Cleartext-Password' atau group mapping
    // Untuk Mikhmon: simpan di radusergroup atau log
    $stmt = $this->db->prepare("INSERT IGNORE INTO radusergroup (username, groupname, priority) VALUES (?, ?, 1)");
    $stmt->bind_param("ss", $username, $profile); $stmt->execute();
    return true;
  }
  public function disconnect() { $this->db->close(); }
}
