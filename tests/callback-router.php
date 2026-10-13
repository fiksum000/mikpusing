<?php
// Fixture for PHP's development server only; no outbound provider calls.
if (PHP_SAPI !== 'cli-server' || getenv('MIKPUSING_CALLBACK_TEST') !== '1') { http_response_code(403); exit; }
chdir(__DIR__ . '/..');
require_once 'lib/decrypt_helper.php';
$tripayPrivateKey = encrypt('ci-private-key');
function fonnteLogPath() { return ''; } // Prevent production transport declarations in fixture.
function fonnteSendMessage($phone, $message, $meta) {
    if (is_file(getenv('MIKPUSING_CALLBACK_STATE') . '/fail-wa')) return array('status' => false);
    file_put_contents(getenv('MIKPUSING_CALLBACK_STATE') . '/wa-sent', "sent\n", FILE_APPEND);
    return array('status' => true);
}
require 'settings/tripay-callback.php';
