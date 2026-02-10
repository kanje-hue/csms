<?php
session_start();
include '../config/db.php';

if(!isset($_SESSION['admin_logged_in'])){
    header("Location: login.php");
    exit();
}

$message = "";

// Handle form submission
if(isset($_POST['assign'])){
    $module_id = (int)$_POST['module_id'];
    $teacher_id = (int)$_POST['teacher_id'];

    $update = mysqli_query($conn, "UPDATE modules SET teacher_id=$teacher_id WHERE module_id=$module_id");

    if($update){
        $message = "Teacher assigned successfully!";
    } else {
        $message = "Failed to assign teacher: " . mysqli_error($conn);
    }
}

// Fetch all modules
$modules = mysqli_query($conn, "SELECT m.module_id, m.module_code, m.module_name, c.course_name, t.fullname AS teacher_name
                               FROM modules m
                               JOIN courses c ON m.course_id=c.course_id
                               LEFT JOIN teachers t ON m.teacher_id=t.teacher_id
                               ORDER BY c.course_name, m.semester, m.module_name ASC");

// Fetch all teachers
$teachers = mysqli_query($conn, "SELECT teacher_id, fullname FROM teachers WHERE status='active' ORDER BY fullname ASC");
?>

<!DOCTYPE html>
<html>
<head>
    <title>Assign Modules to Teachers</title>
    <link rel="stylesheet" href="../assets/css/admin-auth.css">
    <style>
        table { width: 100%; border-collapse: collapse; margin-top: 20px; }
        th, td { padding: 10px; border: 1px solid #566947; text-align: center; }
        th { background: #bfe3f5; }
        select, button { padding: 5px 10px; border-radius: 5px; }
        button { background: #c46a6a; color: #fff; border: none; cursor: pointer; }
        button:hover { opacity: 0.9; }
    </style>
</head>
<body>

<div class="auth-card">
    <h2>Assign Modules to Teachers</h2>

    <?php if($message): ?>
        <div class="message"><?= $message ?></div>
    <?php endif; ?>

    <table>
        <tr>
            <th>Module Code</th>
            <th>Module Name</th>
            <th>Course</th>
            <th>Current Teacher</th>
            <th>Assign Teacher</th>
        </tr>

        <?php while($m = mysqli_fetch_assoc($modules)): ?>
        <tr>
            <td><?= $m['module_code'] ?></td>
            <td><?= $m['module_name'] ?></td>
            <td><?= $m['course_name'] ?></td>
            <td><?= $m['teacher_name'] ?? 'Not Assigned' ?></td>
            <td>
                <form method="POST" style="display:flex; gap:5px; justify-content:center;">
                    <input type="hidden" name="module_id" value="<?= $m['module_id'] ?>">
                    <select name="teacher_id" required>
                        <option value="">--Select--</option>
                        <?php
                        mysqli_data_seek($teachers, 0); // reset pointer
                        while($t = mysqli_fetch_assoc($teachers)){
                            $selected = ($t['fullname'] == $m['teacher_name']) ? "selected" : "";
                            echo "<option value='{$t['teacher_id']}' $selected>{$t['fullname']}</option>";
                        }
                        ?>
                    </select>
                    <button type="submit" name="assign">Assign</button>
                </form>
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
