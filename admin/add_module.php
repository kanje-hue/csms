<?php
session_start();
include "../config/db.php";

if (!isset($_SESSION['admin_logged_in'])) {
    header("Location: login.php");
    exit();
}

$message = "";

/* Fetch courses */
$courses = mysqli_query($conn, "SELECT * FROM courses");

/* Fetch teachers */
$teachers = mysqli_query($conn, "SELECT * FROM teachers");

if (isset($_POST['add'])) {

    $code     = mysqli_real_escape_string($conn, $_POST['module_code']);
    $name     = mysqli_real_escape_string($conn, $_POST['module_name']);
    $course   = (int) $_POST['course_id'];
    $semester = (int) $_POST['semester'];
    $teacher  = (int) $_POST['teacher_id'];

    $insert = mysqli_query(
        $conn,
        "INSERT INTO modules 
        (module_code, module_name, course_id, semester, teacher_id, status)
        VALUES
        ('$code', '$name', $course, $semester, $teacher, 'active')"
    );

    if ($insert) {
        $message = "Module added successfully!";
    } else {
        $message = "Error adding module!";
    }
}
?>

<!DOCTYPE html>
<html>
<head>
    <title>Add Module</title>
    <link rel="stylesheet" href="../assets/css/admin-auth.css">
</head>
<body>

<div class="auth-card">
    <h2>Add Module</h2>
    <p>Create modules for a course & semester</p>

    <?php if ($message): ?>
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
                <option value="">-- Select Course --</option>
                <?php while ($c = mysqli_fetch_assoc($courses)): ?>
                    <option value="<?= $c['course_id']; ?>">
                        <?= $c['course_name']; ?>
                    </option>
                <?php endwhile; ?>
            </select>
        </div>

        <div class="form-group">
            <label>Semester</label>
            <select name="semester" required>
                <option value="">-- Select Semester --</option>
                <option value="1">Semester 1</option>
                <option value="2">Semester 2</option>
            </select>
        </div>

        <div class="form-group">
            <label>Teacher</label>
            <select name="teacher_id" required>
                <option value="">-- Assign Teacher --</option>
                <?php while ($t = mysqli_fetch_assoc($teachers)): ?>
                    <option value="<?= $t['teacher_id']; ?>">
                        <?= $t['fullname']; ?>
                    </option>
                <?php endwhile; ?>
            </select>
        </div>

        <button class="btn" name="add">Add Module</button>

    </form>

    <div class="auth-links">
        <a href="dashboard.php">← Back to Dashboard</a>
    </div>
</div>

</body>
</html>
