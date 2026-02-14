<?php
session_start();
include '../config/db.php';

if(!isset($_SESSION['admin_logged_in'])){
    header("Location: login.php");
    exit();
}

// Restore / permanent delete
if(isset($_GET['action'], $_GET['id'])){
    $module_id = (int)$_GET['id'];

    if($_GET['action'] === 'restore'){
        mysqli_query($conn, "UPDATE modules SET deleted=0 WHERE module_id=$module_id");
    } elseif($_GET['action'] === 'permanent'){
        mysqli_query($conn, "DELETE FROM modules WHERE module_id=$module_id");
    }
    header("Location: recycle_modules.php");
    exit();
}

// Fetch deleted modules
$modules = mysqli_query($conn, "SELECT m.*, c.course_name, t.fullname AS teacher_name
                                FROM modules m
                                LEFT JOIN courses c ON m.course_id=c.course_id
                                LEFT JOIN teachers t ON m.teacher_id=t.teacher_id
                                WHERE m.deleted=1
                                ORDER BY m.module_id DESC");
?>

<!DOCTYPE html>
<html>
<head>
    <title>Recycle Bin - Modules</title>
    <link rel="stylesheet" href="../assets/css/auth.css">
    <style>
        table { width:100%; border-collapse:collapse; margin-top:20px; }
        th, td { border:1px solid #566947; padding:10px; text-align:center; }
        th { background: var(--wild-blue); }
        .btn-action { padding:5px 10px; border-radius:6px; color:white; margin:0 3px; text-decoration:none; }
        .restore { background:#4CAF50; }
        .delete { background:#e74c3c; }
    </style>
</head>
<body>

<div class="auth-card" style="width:1000px;">
    <h2>Recycle Bin - Modules</h2>
    <a href="manage_modules.php">Back to Manage Modules</a>

    <table>
        <tr>
            <th>ID</th>
            <th>Code</th>
            <th>Name</th>
            <th>Course</th>
            <th>Semester</th>
            <th>Teacher</th>
            <th>Actions</th>
        </tr>

        <?php while($m = mysqli_fetch_assoc($modules)): ?>
        <tr>
            <td><?= $m['module_id'] ?></td>
            <td><?= htmlspecialchars($m['module_code']) ?></td>
            <td><?= htmlspecialchars($m['module_name']) ?></td>
            <td><?= htmlspecialchars($m['course_name']) ?></td>
            <td><?= htmlspecialchars($m['semester']) ?></td>
            <td><?= htmlspecialchars($m['teacher_name']) ?></td>
            <td>
                <a class="btn-action restore" href="?action=restore&id=<?= $m['module_id'] ?>">Restore</a>
                <a class="btn-action delete" href="?action=permanent&id=<?= $m['module_id'] ?>" onclick="return confirm('Permanently delete?')">Delete</a>
            </td>
        </tr>
        <?php endwhile; ?>
    </table>
</div>

</body>
</html>
