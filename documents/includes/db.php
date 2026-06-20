<?php
declare(strict_types=1);

date_default_timezone_set('Asia/Colombo');

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

$DB_HOST = '127.0.0.1';
$DB_USER = 'root';
$DB_PASS = '';
$DB_NAME = 'stadium_booking';

$mysqli = new mysqli($DB_HOST, $DB_USER, $DB_PASS, $DB_NAME);

if ($mysqli->connect_errno) {
    http_response_code(500);
    exit('Database connection failed: ' . htmlspecialchars($mysqli->connect_error));
}

$mysqli->set_charset('utf8mb4');
$mysqli->query("SET time_zone = '+05:30'");

function h(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
}
