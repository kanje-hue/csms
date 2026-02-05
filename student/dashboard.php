<?php
session_start();
error_reporting(E_ALL);
ini_set('display_errors', 1);

// Check if student is logged in
if(!isset($_SESSION['student_id'])){
    header("Location: login.php");
    exit();
}
?>

<!DOCTYPE html>
<html>
<head>
    <title>Student Dashboard - CSMS</title>
</head>
<body>
    <h2>Welcome, <?php echo $_SESSION['student_name']; ?></h2>

    <ul>
        <li><a href="#">Profile</a></li>
        <li><a href="#">Register Courses</a></li>
        <li><a href="#">Attendance</a></li>
        <li><a href="#">Results</a></li>
        <li><a href="logout.php">Logout</a></li>
    </ul>
</body>
</html>
