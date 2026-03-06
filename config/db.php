<?php
/**
 * config/db.php - Database Connection Only
 * REMOVED all session settings from this file
 */

$host = 'localhost';
$username = 'root';  // Change this to your DB username
$password = '';      // Change this to your DB password
$database = 'csms';

// Create connection with error reporting
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
$conn = new mysqli($host, $username, $password, $database);

// Set charset to UTF-8
$conn->set_charset('utf8mb4');

// Set timezone
$conn->query("SET time_zone = '+00:00'");

// DO NOT start session here - let the main file handle it
// DO NOT set ini settings here
?>