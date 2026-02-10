<?php
session_start();
if(!isset($_SESSION['admin_logged_in'])){
    header("Location: login.php");
    exit();
}
?>

<!DOCTYPE html>
<html>
<head>
    <title>Admin Dashboard - CSMS</title>
    <link rel="stylesheet" href="../assets/css/auth.css">
    <style>
        /* Dashboard cards */
        .dashboard-container {
            display: flex;
            flex-wrap: wrap;
            justify-content: center;
            gap: 20px;
            margin-top: 30px;
        }

        .card {
            width: 200px;
            height: 150px;
            background: linear-gradient(135deg, var(--skipping-stones), var(--minty-fresh));
            border-radius: 18px;
            box-shadow: 0 15px 30px rgba(0,0,0,0.15);
            display: flex;
            flex-direction: column;
            justify-content: center;
            align-items: center;
            color: var(--art-craft);
            text-align: center;
            font-weight: bold;
            font-size: 18px;
            cursor: pointer;
            transition: transform 0.2s;
            text-decoration: none;
        }

        .card:hover {
            transform: scale(1.05);
        }
    </style>
</head>
<body>

<div class="auth-card" style="width: 800px;">
    <h2>Welcome, Admin</h2>

    <div class="dashboard-container">
        <a href="manage_courses.php" class="card">Courses</a>
        <a href="manage_modules.php" class="card">Modules</a>
        <a href="manage_teachers.php" class="card">Teachers</a>
        <a href="manage_students.php" class="card">Students</a>
        <a href="logout.php" class="card" style="background: var(--terra-rosa); color: white;">Logout</a>
    </div>
</div>

</body>
</html>
<ul>
    <li><a href="manage_students.php">Manage Students</a></li>
    <li><a href="manage_teachers.php">Manage Teachers</a></li> <!-- Add this line -->
    <li><a href="#">Manage Courses</a></li>
    <li><a href="#">Manage Attendance</a></li>
    <li><a href="#">Manage Results</a></li>
    <li><a href="logout.php">Logout</a></li>
</ul>
<li><a href="manage_teachers.php">Manage Teachers</a></li>
