<?php
/**
 * Compatibility-only codec for Mikhmon's historic config fields.
 *
 * This deliberately matches the legacy encrypt()/decrypt() implementation
 * formerly declared by routeros_api.class.php. It is reversible obfuscation,
 * not cryptographic protection. The RouterOS API class is not required.
 */
if (!function_exists('encrypt')) {
  function encrypt($string, $key = 128) {
    $string = (string)$string;
    $key = (string)$key;
    if ($key === '') return base64_encode($string);
    $result = '';
    for ($i = 0, $k = strlen($string); $i < $k; $i++) {
      $char = substr($string, $i, 1);
      $keychar = substr($key, ($i % strlen($key)) - 1, 1);
      $char = chr((ord($char) + ord($keychar)) & 0xFF);
      $result .= $char;
    }
    return base64_encode($result);
  }
}
if (!function_exists('decrypt')) {
  function decrypt($string, $key = 128) {
    $key = (string)$key;
    $decoded = base64_decode((string)$string);
    if ($decoded === false || $key === '') return '';
    $result = '';
    for ($i = 0, $k = strlen($decoded); $i < $k; $i++) {
      $char = substr($decoded, $i, 1);
      $keychar = substr($key, ($i % strlen($key)) - 1, 1);
      $char = chr((ord($char) - ord($keychar) + 256) & 0xFF);
      $result .= $char;
    }
    return $result;
  }
}
?>