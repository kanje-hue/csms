<?php
session_start();
include '../config/db.php';

if(!isset($_SESSION['admin_logged_in'])){
    header("Location: login.php");
    exit();
}

if(!isset($_GET['id'])){
    header("Location: manage_modules.php");
    exit();
}

$module_id = (int)$_GET['id'];
$message = "";

// Fetch module info
$module = mysqli_fetch_assoc(
    mysqli_query($conn, "SELECT * FROM modules WHERE module_id=$module_id")
);

if(!$module){
    header("Location: manage_modules.php");
    exit();
}

// Handle form submission
if(isset($_POST['update_module'])){
    $module_code = mysqli_real_escape_string($conn, $_POST['module_code']);
    $module_name = mysqli_real_escape_string($conn, $_POST['module_name']);
    $course_id   = (int)$_POST['course_id'];
    $semester    = mysqli_real_escape_string($conn, $_POST['semester']);
    $teacher_id  = (int)$_POST['teacher_id'];

    $sql = "UPDATE modules SET 
                module_code='$module_code',
                module_name='$module_name',
                course_id=$course_id,
                semester='$semester',
                teacher_id=$teacher_id
            WHERE module_id=$module_id";

    if(mysqli_query($conn, $sql)){
        $message = "Module updated successfully!";
        $module = mysqli_fetch_assoc(mysqli_query($conn, "SELECT * FROM modules WHERE module_id=$module_id"));
    } else {
        $message = "Error: " . mysqli_error($conn);
    }
}

// Fetch courses & teachers for dropdown
$courses  = mysqli_query($conn, "SELECT * FROM courses");
$teachers = mysqli_query($conn, "SELECT * FROM teachers");
?>

<!DOCTYPE html>
<html>
<head>
    <title>Edit Module</title>
    <link rel="stylesheet" href="../assets/css/auth.css">
</head>
<body>
<div class="auth-card" style="width: 500px;">
    <h2>Edit Module</h2>
    <?php if($message): ?>
        <div class="message"><?= $message ?></div>
    <?php endif; ?>
    <form method="POST">
        <div class="form-group">
            <label>Module Code</label>
            <input type="text" name="module_code" value="<?= $module['module_code'] ?>" required>
        </div>
        <div class="form-group">
            <label>Module Name</label>
            <input type="text" name="module_name" value="<?= $module['module_name'] ?>" required>
        </div>
        <div class="form-group">
            <label>Course</label>
            <select name="course_id" required>
                <?php while($c = mysqli_fetch_assoc($courses)): ?>
                    <option value="<?= $c['course_id'] ?>" <?= $c['course_id']==$module['course_id']?'selected':'' ?>><?= $c['course_name'] ?></option>
                <?php endwhile; ?>
            </select>
        </div>
        <div class="form-group">
            <label>Semester</label>
            <input type="text" name="semester" value="<?= $module['semester'] ?>" required>
        </div>
        <div class="form-group">
            <label>Teacher</label>
            <select name="teacher_id" required>
                <?php while($t = mysqli_fetch_assoc($teachers)): ?>
                    <option value="<?= $t['teacher_id'] ?>" <?= $t['teacher_id']==$module['teacher_id']?'selected':'' ?>><?= $t['fullname'] ?></option>
                <?php endwhile; ?>
            </select>
        </div>
        <button class="btn" name="update_module">Update Module</button>
    </form>
    <div class="auth-links" style="margin-top:15px;">
        <a href="manage_modules.php">Back to Modules</a>
    </div>
</div>
</body>
</html>
