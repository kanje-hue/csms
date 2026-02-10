<?php
session_start();
include '../config/db.php';

if(!isset($_SESSION['admin_logged_in'])){
    header("Location: login.php");
    exit();
}

// Activate / deactivate
if(isset($_GET['action'], $_GET['id'])){
    $id = (int)$_GET['id'];

    if($_GET['action'] == 'activate'){
        mysqli_query($conn, "UPDATE teachers SET status='active' WHERE teacher_id=$id");
    } elseif($_GET['action'] == 'deactivate'){
        mysqli_query($conn, "UPDATE teachers SET status='inactive' WHERE teacher_id=$id");
    }
    header("Location: manage_teachers.php");
    exit();
}

$teachers = mysqli_query($conn, "SELECT * FROM teachers ORDER BY teacher_id DESC");
?>

<!DOCTYPE html>
<html>
<head>
    <title>Manage Teachers</title>
    <link rel="stylesheet" href="../assets/css/admin-auth.css">
    <style>
        table { width:100%; border-collapse:collapse; margin-top:20px; }
        th,td { border:1px solid #566947; padding:10px; text-align:center; }
        th { background:var(--minty-fresh); }
    </style>
</head>
<body>

<div class="auth-card" style="width:900px;">
    <h2>Manage Teachers</h2>

    <a href="add_teacher.php" class="btn">+ Add Teacher</a>

    <table>
        <tr>
            <th>ID</th>
            <th>Full Name</th>
            <th>Email</th>
            <th>Status</th>
            <th>Action</th>
        </tr>

        <?php while($t = mysqli_fetch_assoc($teachers)): ?>
        <tr>
            <td><?= $t['teacher_id'] ?></td>
            <td><?= $t['fullname'] ?></td>
            <td><?= $t['email'] ?></td>
            <td><?= $t['status'] ?></td>
            <td>
                <?php if($t['status']=='inactive'): ?>
                    <a href="?action=activate&id=<?= $t['teacher_id'] ?>">Activate</a>
                <?php else: ?>
                    <a href="?action=deactivate&id=<?= $t['teacher_id'] ?>">Deactivate</a>
                <?php endif; ?>
            </td>
        </tr>
        <?php endwhile; ?>
    </table>

    <div class="auth-links">
        <a href="dashboard.php">Back to Dashboard</a>
    </div>
</div>

</body>
</html>
