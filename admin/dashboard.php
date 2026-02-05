<?php
session_start();
error_reporting(E_ALL);
ini_set('display_errors', 1);

if(!isset($_SESSION['admin_logged_in'])){
    header("Location: login.php");
    exit();
}
?>

<!DOCTYPE html>
<html>
<head>
    <title>Admin Dashboard - CSMS</title>
</head>
<body>
    <h2>Welcome, Admin</h2>

    <ul>
        <li><a href="manage_students.php">Manage Students</a></li>
        <li><a href="#">Manage Courses</a></li>
        <li><a href="#">Manage Attendance</a></li>
        <li><a href="#">Manage Results</a></li>
        <li><a href="logout.php">Logout</a></li>
    </ul>
</body>
</html>
