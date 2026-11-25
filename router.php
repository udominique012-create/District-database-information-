<?php // Gusa ku iterambere: php -S localhost:8000 -t backend/public backend/public/router.php
$p = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
if (is_file(__DIR__ . $p)) return false;
$_GET['route'] = trim($p, '/');
require __DIR__ . '/api.php';
