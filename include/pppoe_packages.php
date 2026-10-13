<?php
if (!function_exists('pppoePackagePath')) {
  function pppoePackagePath() { return __DIR__ . '/../storage/pppoe_packages.json'; }
  function pppoePackageDefaults() { return array('isolir_profile' => 'ISOLIR', 'packages' => array()); }
  function pppoePackageRead() {
    if (!file_exists(pppoePackagePath())) return pppoePackageDefaults();
    $data = json_decode(file_get_contents(pppoePackagePath()), true);
    if (!is_array($data)) throw new RuntimeException('Mapping paket rusak; pulihkan backup sebelum melanjutkan.');
    return array_merge(pppoePackageDefaults(), $data);
  }
  function pppoePackageWrite($data) {
    $path = pppoePackagePath(); $dir = dirname($path);
    if (!is_dir($dir)) @mkdir($dir, 0750, true);
    $json = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    $tmp = $path . '.tmp.' . getmypid();
    if ($json === false || @file_put_contents($tmp, $json, LOCK_EX) === false) return false;
    @chmod($tmp, 0640);
    if (!@rename($tmp, $path)) { @unlink($tmp); return false; }
    return true;
  }
  function pppoePackageForProfile($profile) {
    $data = pppoePackageRead();
    foreach ((array)($data['packages'] ?? array()) as $package) {
      if ((string)($package['normal_profile'] ?? '') === (string)$profile ||
          ((string)($package['fup_profile'] ?? '') !== '' && (string)$package['fup_profile'] === (string)$profile)) {
        return $package;
      }
    }
    return null;
  }
}
?>
