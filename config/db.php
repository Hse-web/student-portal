<?php
// File: config/db.php
// -----------------------------
// Legacy mysqli connection (restores $conn for all pages)

// Database credentials (adjust if needed)
$host    = 'localhost';
$user    = 'root';
$pass    = '';
$dbName  = 'student_portal';
$charset = 'utf8mb4';

// Create mysqli connection
$conn = new mysqli($host, $user, $pass, $dbName);
if ($conn->connect_error) {
    die('Database connection error: ' . $conn->connect_error);
}
$conn->set_charset($charset);

// If you want PDO alongside mysqli, you can initialize it here as $pdo:
// $dsn = "mysql:host={$host};dbname={$dbName};charset={$charset}";
// $pdo = new PDO($dsn, $user, $pass, [
//     PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
//     PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
//     PDO::ATTR_EMULATE_PREPARES => false,
// ]);
?>
