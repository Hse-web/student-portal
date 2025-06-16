<?php
// File: config/db.php
// ---------------------------
// Simple mysqli connection (exposes $conn)

$host    = 'localhost';
$user    = 'u939508438_artovue';
$pass    = '67Ru@PBk';
$dbName  = 'u939508438_student_portal';
$charset = 'utf8mb4';

$conn = new mysqli($host, $user, $pass, $dbName);
if ($conn->connect_error) {
    die('Database connection error: ' . $conn->connect_error);
}
$conn->set_charset($charset);
