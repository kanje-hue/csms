<?php
session_start();
include '../config/db.php';

if(!isset($_SESSION['admin_logged_in'])){
    header("Location: login.php");
    exit();
}

// Handle actions: activate, deactivate, delete
if(isset($_GET['action'], $_GET['id'])){
    $id = (int)$_GET['id'];

    if($_GET['action'] === 'activate'){
        mysqli_query($conn, "UPDATE teachers SET status='active' WHERE teacher_id=$id");
    } elseif($_GET['action'] === 'deactivate'){
        mysqli_query($conn, "UPDATE teachers SET status='inactive' WHERE teacher_id=$id");
    } elseif($_GET['action'] === 'delete'){
        mysqli_query($conn, "UPDATE teachers SET deleted=1 WHERE teacher_id=$id"); // soft delete
    }
    header("Location: manage_teachers.php");
    exit();
}

// Fetch active teachers
$teachers = mysqli_query($conn, "SELECT * FROM teachers WHERE deleted=0 ORDER BY teacher_id DESC");
?>

<!DOCTYPE html>
<html>
<head>
    <title>Manage Teachers</title>
    <link rel="stylesheet" href="../assets/css/auth.css">
    <style>
        .auth-card {
            width: 1000px;
            max-width: 100%;
            padding: 30px;
            border-radius: 18px;
            background: var(--white);
            box-shadow: 0 20px 45px rgba(0,0,0,0.15);
            margin: 30px auto;
        }

        h2 {
            text-align: center;
            margin-bottom: 15px;
            color: var(--midnight-garden);
        }

        table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 20px;
            table-layout: fixed;
        }

        th, td {
            padding: 10px;
            border: 1px solid #566947;
            text-align: center;
            word-wrap: break-word;
        }

        th {
            background: var(--minty-fresh);
            color: var(--art-craft);
        }

        a.action-link {
            color: var(--terra-rosa);
            font-weight: bold;
            text-decoration: none;
            margin: 0 5px;
        }

        a.action-link:hover {
            opacity: 0.8;
        }

        .auth-links {
            text-align: center;
            margin-top: 15px;
        }

        .btn {
            display: inline-block;
            padding: 8px 15px;
            background: linear-gradient(135deg, var(--terra-rosa), var(--honey-glow));
            color: #fff;
            border-radius: 12px;
            text-decoration: none;
            font-weight: bold;
            margin-bottom: 10px;
        }

        .btn:hover {
            opacity: 0.9;
        }

        /* Float Recycle Bin to the right */
        .recycle {
            float: right;
        }
    </style>
</head>
<body>

<div class="auth-card">
    <h2>Manage Teachers</h2>

    <a href="add_teacher.php" class="btn">+ Add Teacher</a>
    <a href="recycle_teachers.php" class="btn recycle">♻ Recycle Bin</a>

    <table>
        <tr>
            <th>ID</th>
            <th>Full Name</th>
            <th>Email</th>
            <th>Status</th>
            <th>Actions</th>
        </tr>

        <?php while($t = mysqli_fetch_assoc($teachers)): ?>
        <tr>
            <td><?= $t['teacher_id'] ?></td>
            <td><?= htmlspecialchars($t['fullname']) ?></td>
            <td><?= htmlspecialchars($t['email']) ?></td>
            <td><?= $t['status'] ?></td>
            <td>
                <?php if($t['status']=='inactive'): ?>
                    <a class="action-link" href="?action=activate&id=<?= $t['teacher_id'] ?>">Activate</a>
                <?php else: ?>
                    <a class="action-link" href="?action=deactivate&id=<?= $t['teacher_id'] ?>">Deactivate</a>
                <?php endif; ?>
                <a class="action-link" href="edit_teacher.php?id=<?= $t['teacher_id'] ?>">Edit</a>
                <a class="action-link" href="?action=delete&id=<?= $t['teacher_id'] ?>" onclick="return confirm('Delete this teacher?')">Delete</a>
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
