<?php
/**
 * Scheduled profile automation entry point.
 * Full RADIUS is the only enabled backend: changes are made in radusergroup
 * and active sessions are disconnected with RFC 5176 Disconnect-Message.
 * RouterOS API credentials and connections are never used by this script.
 */
if (PHP_SAPI !== 'cli') { http_response_code(403); exit("CLI only\n"); }
date_default_timezone_set(getenv('TZ') ?: 'Asia/Jakarta');

require __DIR__ . '/radius_profile_automation.php';
