<?php
// File: config/db.php
// Legacy mysqli connection; exposes $conn

$host    = 'localhost';
$user    = 'root';
$pass    = '';
$dbName  = 'student_portal';
$charset = 'utf8mb4';

$conn = new mysqli($host, $user, $pass, $dbName);
if ($conn->connect_error) {
    die('DB Connection Error: ' . $conn->connect_error);
}
$conn->set_charset($charset);
