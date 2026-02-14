<?php
session_start();
include '../config/db.php';

if(!isset($_SESSION['admin_logged_in'])){
    header("Location: login.php");
    exit();
}

// Handle restore / permanent delete
if(isset($_GET['action'], $_GET['id'])){
    $teacher_id = (int)$_GET['id'];

    if($_GET['action'] === 'restore'){
        mysqli_query($conn, "UPDATE teachers SET deleted=0 WHERE teacher_id=$teacher_id");
    } elseif($_GET['action'] === 'permanent'){
        mysqli_query($conn, "DELETE FROM teachers WHERE teacher_id=$teacher_id");
    }
    header("Location: recycle_teachers.php");
    exit();
}

// Fetch deleted teachers
$teachers = mysqli_query($conn, "SELECT * FROM teachers WHERE deleted=1 ORDER BY teacher_id DESC");
?>

<!DOCTYPE html>
<html>
<head>
    <title>Recycle Bin - Teachers</title>
    <link rel="stylesheet" href="../assets/css/auth.css">
    <style>
        table { width:100%; border-collapse:collapse; margin-top:20px; }
        th, td { border:1px solid #566947; padding:10px; text-align:center; }
        th { background: var(--wild-blue); }
        a { text-decoration:none; margin:0 5px; }
        .btn-action { padding:5px 10px; border-radius:6px; color:white; }
        .restore { background:#4CAF50; }
        .delete { background:#e74c3c; }
    </style>
</head>
<body>

<div class="auth-card" style="width:900px;">
    <h2>Recycle Bin - Teachers</h2>
    <a href="manage_teachers.php">Back to Manage Teachers</a>

    <table>
        <tr>
            <th>ID</th>
            <th>Full Name</th>
            <th>Email</th>
            <th>Actions</th>
        </tr>

        <?php while($t = mysqli_fetch_assoc($teachers)): ?>
        <tr>
            <td><?= $t['teacher_id'] ?></td>
            <td><?= htmlspecialchars($t['fullname']) ?></td>
            <td><?= htmlspecialchars($t['email']) ?></td>
            <td>
                <a class="btn-action restore" href="?action=restore&id=<?= $t['teacher_id'] ?>">Restore</a>
                <a class="btn-action delete" href="?action=permanent&id=<?= $t['teacher_id'] ?>" onclick="return confirm('Permanently delete?')">Delete</a>
            </td>
        </tr>
        <?php endwhile; ?>
    </table>
</div>

</body>
</html>
