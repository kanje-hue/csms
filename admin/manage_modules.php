<?php
session_start();
include '../config/db.php';

if(!isset($_SESSION['admin_logged_in'])){
    header("Location: login.php");
    exit();
}

$message = "";

// Handle form submission
if(isset($_POST['add_module'])){
    $module_code = mysqli_real_escape_string($conn, $_POST['module_code']);
    $module_name = mysqli_real_escape_string($conn, $_POST['module_name']);
    $course_id   = (int)$_POST['course_id'];
    $semester    = mysqli_real_escape_string($conn, $_POST['semester']);
    $teacher_id  = (int)$_POST['teacher_id'];

    $sql = "INSERT INTO modules (module_code, module_name, course_id, semester, teacher_id, status)
            VALUES ('$module_code', '$module_name', $course_id, '$semester', $teacher_id, 'active')";

    if(mysqli_query($conn, $sql)){
        $message = "Module added successfully!";
    } else {
        $message = "Error: " . mysqli_error($conn);
    }
}

// Fetch courses and teachers for dropdowns
$courses  = mysqli_query($conn, "SELECT * FROM courses");
$teachers = mysqli_query($conn, "SELECT * FROM teachers");

// Fetch existing modules
$modules = mysqli_query($conn, "SELECT m.*, c.course_name, t.fullname AS teacher_name
                                FROM modules m
                                LEFT JOIN courses c ON m.course_id=c.course_id
                                LEFT JOIN teachers t ON m.teacher_id=t.teacher_id
                                ORDER BY m.module_id DESC");
?>

<!DOCTYPE html>
<html>
<head>
    <title>Manage Modules - Admin</title>
    <link rel="stylesheet" href="../assets/css/auth.css">
    <style>
        table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 20px;
        }
        th, td {
            padding: 10px;
            border: 1px solid #566947;
            text-align: left;
        }
        th {
            background: var(--skipping-stones);
            color: white;
        }
        .form-group select, .form-group input {
            margin-bottom: 10px;
        }
    </style>
</head>
<body>

<div class="auth-card" style="width: 800px;">
    <h2>Manage Modules</h2>

    <?php if($message): ?>
        <div class="message"><?= $message ?></div>
    <?php endif; ?>

    <form method="POST">
        <div class="form-group">
            <label>Module Code</label>
            <input type="text" name="module_code" required>
        </div>

        <div class="form-group">
            <label>Module Name</label>
            <input type="text" name="module_name" required>
        </div>

        <div class="form-group">
            <label>Course</label>
            <select name="course_id" required>
                <option value="">Select Course</option>
                <?php while($c = mysqli_fetch_assoc($courses)): ?>
                    <option value="<?= $c['course_id'] ?>"><?= $c['course_name'] ?></option>
                <?php endwhile; ?>
            </select>
        </div>

        <div class="form-group">
            <label>Semester</label>
            <input type="text" name="semester" placeholder="e.g., Semester 1" required>
        </div>

        <div class="form-group">
            <label>Teacher</label>
            <select name="teacher_id" required>
                <option value="">Select Teacher</option>
                <?php while($t = mysqli_fetch_assoc($teachers)): ?>
                    <option value="<?= $t['teacher_id'] ?>"><?= $t['fullname'] ?></option>
                <?php endwhile; ?>
            </select>
        </div>

        <button class="btn" name="add_module">Add Module</button>
    </form>

    <h3>Existing Modules</h3>
    <table>
        <tr>
            <th>ID</th>
            <th>Code</th>
            <th>Name</th>
            <th>Course</th>
            <th>Semester</th>
            <th>Teacher</th>
            <th>Status</th>
        </tr>
        <?php while($m = mysqli_fetch_assoc($modules)): ?>
            <tr>
                <td><?= $m['module_id'] ?></td>
                <td><?= $m['module_code'] ?></td>
                <td><?= $m['module_name'] ?></td>
                <td><?= $m['course_name'] ?></td>
                <td><?= $m['semester'] ?></td>
                <td><?= $m['teacher_name'] ?></td>
                <td><?= $m['status'] ?></td>
            </tr>
        <?php endwhile; ?>
    </table>

</div>

</body>
</html>
