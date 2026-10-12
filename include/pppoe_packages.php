<?php
if (!function_exists('pppoePackagePath')) {
  function pppoePackagePath() { return __DIR__ . '/../storage/pppoe_packages.json'; }
  function pppoePackageDefaults() { return array('isolir_profile' => 'ISOLIR', 'packages' => array()); }
  function pppoePackageRead() { if (!file_exists(pppoePackagePath())) return pppoePackageDefaults(); $data = json_decode(@file_get_contents(pppoePackagePath()) ?: '{}', true); return is_array($data) ? array_merge(pppoePackageDefaults(), $data) : pppoePackageDefaults(); }
  function pppoePackageWrite($data) { $dir = dirname(pppoePackagePath()); if (!is_dir($dir)) @mkdir($dir, 0750, true); return @file_put_contents(pppoePackagePath(), json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE), LOCK_EX) !== false; }
  function pppoePackageForProfile($profile) { $data = pppoePackageRead(); foreach ($data['packages'] as $package) if (isset($package['normal_profile']) && $package['normal_profile'] === $profile) return $package; return null; }
}
?>
