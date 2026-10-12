<?php
if (!function_exists('decrypt')) {
  function decrypt($str) {
    if (empty($str)) return '';
    $decoded = base64_decode($str, true);
    return ($decoded !== false) ? $decoded : $str;
  }
}
if (!function_exists('encrypt')) {
  function encrypt($str) {
    return base64_encode($str);
  }
}
