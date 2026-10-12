<?php
/**
 * Full RADIUS release: the legacy Mikhmon RouterOS API dashboard is disabled.
 * Keep the historical callback URL working for deployments that still use it;
 * all user-facing management is served by admin.php.
 */
session_start();
error_reporting(0);

if ((string)($_GET['id'] ?? '') === 'tripay-callback') {
    require_once __DIR__ . '/lib/decrypt_helper.php';
    require_once __DIR__ . '/include/config.php';
    require_once __DIR__ . '/settings/tripay-callback.php';
    exit;
}

if (!isset($_SESSION['mikhmon'])) {
    header('Location: ./admin.php?id=login', true, 302);
} else {
    header('Location: ./admin.php?id=' . (isset($_GET['session']) ? 'radius-dashboard' : 'sessions'), true, 302);
}
exit;
?>