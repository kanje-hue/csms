<?php
session_start();
include '../config/db.php';

if (!isset($_SESSION['admin_logged_in'])) {
    header("Location: login.php");
    exit();
}

/* ===============================
   ACTIVATE / DEACTIVATE STUDENT
================================ */
if (isset($_GET['action'], $_GET['id'])) {
    $student_id = (int)$_GET['id'];

    if ($_GET['action'] === 'activate') {

        // 1. Activate student
        mysqli_query(
            $conn,
            "UPDATE students SET status='active' WHERE student_id=$student_id"
        );

        // 2. Get student's course & semester
        $student = mysqli_fetch_assoc(
            mysqli_query(
                $conn,
                "SELECT course_id, semester FROM students WHERE student_id=$student_id"
            )
        );

        $course_id = $student['course_id'];
        $semester  = $student['semester'];

        // 3. Get all modules for that course & semester
        $modules = mysqli_query(
            $conn,
            "SELECT module_id FROM modules 
             WHERE course_id=$course_id 
             AND semester='$semester'"
        );

        // 4. Auto-assign modules + attendance
        while ($m = mysqli_fetch_assoc($modules)) {
            $module_id = $m['module_id'];

            // Module registration
            mysqli_query(
                $conn,
                "INSERT IGNORE INTO module_registrations (student_id, module_id)
                 VALUES ($student_id, $module_id)"
            );

            // Attendance row
            mysqli_query(
                $conn,
                "INSERT IGNORE INTO attendance 
                 (student_id, module_id, total_classes, attended_classes)
                 VALUES ($student_id, $module_id, 0, 0)"
            );
        }

    } elseif ($_GET['action'] === 'deactivate') {

        mysqli_query(
            $conn,
            "UPDATE students SET status='inactive' WHERE student_id=$student_id"
        );
    }

    header("Location: manage_students.php");
    exit();
}

/* ===============================
   FETCH STUDENTS
================================ */
$result = mysqli_query(
    $conn,
    "SELECT s.student_id, s.name, s.email, s.status, c.course_name
     FROM students s
     LEFT JOIN courses c ON s.course_id = c.course_id
     ORDER BY s.student_id DESC"
);
?>

<!DOCTYPE html>
<html>
<head>
    <title>Manage Students</title>
    <link rel="stylesheet" href="../assets/css/admin-auth.css">
    <style>
        table { width: 100%; border-collapse: collapse; margin-top: 20px; }
        th, td { padding: 10px; border: 1px solid #566947; text-align: center; }
        th { background: var(--minty-fresh); }
        a { text-decoration: none; color: var(--terra-rosa); font-weight: bold; }
        a:hover { opacity: 0.8; }
    </style>
</head>
<body>

<div class="auth-card">
    <h2>Manage Students</h2>

    <table>
        <tr>
            <th>ID</th>
            <th>Full Name</th>
            <th>Email</th>
            <th>Course</th>
            <th>Status</th>
            <th>Action</th>
        </tr>

        <?php while ($row = mysqli_fetch_assoc($result)): ?>
        <tr>
            <td><?= $row['student_id'] ?></td>
            <td><?= $row['name'] ?></td>
            <td><?= $row['email'] ?></td>
            <td><?= $row['course_name'] ?></td>
            <td><?= $row['status'] ?></td>
            <td>
                <?php if ($row['status'] === 'inactive'): ?>
                    <a href="?action=activate&id=<?= $row['student_id'] ?>">Activate</a>
                <?php else: ?>
                    <a href="?action=deactivate&id=<?= $row['student_id'] ?>">Deactivate</a>
                <?php endif; ?>
            </td>
        </tr>
        <?php endwhile; ?>
    </table>

    <div class="auth-links">
        <a href="dashboard.php">Back to Dashboard</a> |
        <a href="logout.php">Logout</a>
    </div>
</div>

</body>
</html>
