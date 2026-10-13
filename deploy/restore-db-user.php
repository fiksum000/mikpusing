<?php
if (PHP_SAPI !== 'cli') { http_response_code(403); exit; }
$config = require '/etc/mikhmon/radius.php';
$user = $config['db_user']; $db = $config['db_name']; $password = $config['db_password'];
if (!preg_match('/^[A-Za-z][A-Za-z0-9_]{0,30}$/', $user) || !preg_match('/^[A-Za-z][A-Za-z0-9_]{0,30}$/', $db) || !preg_match('/^[A-Za-z0-9._-]{24,128}$/', $password)) throw new RuntimeException('Invalid database credentials format');
$sql = new mysqli('localhost', 'root', '');
$sql->query("CREATE USER IF NOT EXISTS '$user'@'localhost' IDENTIFIED BY '$password'");
$sql->query("ALTER USER '$user'@'localhost' IDENTIFIED BY '$password'");
$sql->query("GRANT SELECT, INSERT, UPDATE, DELETE, CREATE, ALTER, INDEX, DROP ON `$db`.* TO '$user'@'localhost'");
$sql->close();
