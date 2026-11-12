<?php
$dbHost = getenv('DB_HOST');
$dbName = getenv('DB_NAME');
$dbUser = getenv('DB_USER');
$dbPass = getenv('DB_PASS');

define('DB_HOST', $dbHost === false ? 'localhost' : $dbHost);
define('DB_NAME', $dbName === false ? 'kapsabet_tms' : $dbName);
define('DB_USER', $dbUser === false ? 'root' : $dbUser);
define('DB_PASS', $dbPass === false ? '' : $dbPass); // XAMPP default
require_once __DIR__ . '/includes/lib.php';
