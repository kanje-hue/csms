<?php
session_start();
include '../config/db.php';

if(!isset($_SESSION['student_id'])){
    header("Location: login.php");
    exit();
}

$student_id = $_SESSION['student_id'];
$student_name = $_SESSION['student_name'];

// Fetch registered modules
$modules = mysqli_query($conn, "
    SELECT m.module_code, m.module_name, m.semester, t.fullname AS teacher
    FROM module_registrations mr
    JOIN modules m ON mr.module_id = m.module_id
    JOIN teachers t ON m.teacher_id = t.teacher_id
    WHERE mr.student_id = $student_id
    ORDER BY m.semester, m.module_name
");

// Fetch attendance
$attendance = mysqli_query($conn, "
    SELECT m.module_name, a.total_classes, a.attended_classes
    FROM attendance a
    JOIN modules m ON a.module_id = m.module_id
    WHERE a.student_id = $student_id
    ORDER BY m.semester, m.module_name
");

// Fetch results
$results = mysqli_query($conn, "
    SELECT m.module_name, r.marks, r.grade
    FROM results r
    JOIN modules m ON r.module_id = m.module_id
    WHERE r.student_id = $student_id
    ORDER BY m.semester, m.module_name
");
?>

<!DOCTYPE html>
<html>
<head>
    <title>Student Dashboard</title>
    <link rel="stylesheet" href="../assets/css/auth.css">
    <style>
        h3 { margin-top: 30px; text-align: center; color: var(--midnight-garden); }
        table { width: 100%; border-collapse: collapse; margin-top: 10px; }
        th, td { padding: 10px; border: 1px solid #566947; text-align: center; }
        th { background: var(--minty-fresh); }
        tr:nth-child(even){ background: #f0f0f0; }
    </style>
</head>
<body>

<div class="auth-card" style="width: 900px;">
    <h2>Welcome, <?= htmlspecialchars($student_name) ?></h2>

    <!-- Registered Modules -->
    <h3>Registered Modules</h3>
    <table>
        <tr>
            <th>Code</th>
            <th>Module Name</th>
            <th>Semester</th>
            <th>Teacher</th>
        </tr>
        <?php while($m = mysqli_fetch_assoc($modules)): ?>
        <tr>
            <td><?= $m['module_code'] ?></td>
            <td><?= $m['module_name'] ?></td>
            <td><?= $m['semester'] ?></td>
            <td><?= $m['teacher'] ?></td>
        </tr>
        <?php endwhile; ?>
    </table>

    <!-- Attendance -->
    <h3>Attendance</h3>
    <table>
        <tr>
            <th>Module Name</th>
            <th>Total Classes</th>
            <th>Attended Classes</th>
            <th>Percentage</th>
        </tr>
        <?php while($a = mysqli_fetch_assoc($attendance)): 
            $percent = ($a['total_classes']>0) ? round(($a['attended_classes']/$a['total_classes'])*100,2) : 0;
        ?>
        <tr>
            <td><?= $a['module_name'] ?></td>
            <td><?= $a['total_classes'] ?></td>
            <td><?= $a['attended_classes'] ?></td>
            <td><?= $percent ?>%</td>
        </tr>
        <?php endwhile; ?>
    </table>

    <!-- Results -->
    <h3>Results</h3>
    <table>
        <tr>
            <th>Module Name</th>
            <th>Marks</th>
            <th>Grade</th>
        </tr>
        <?php while($r = mysqli_fetch_assoc($results)): ?>
        <tr>
            <td><?= $r['module_name'] ?></td>
            <td><?= $r['marks'] ?></td>
            <td><?= $r['grade'] ?></td>
        </tr>
        <?php endwhile; ?>
    </table>

    <div class="auth-links" style="margin-top:20px;">
        <a href="logout.php">Logout</a>
    </div>
</div>

</body>
</html>
