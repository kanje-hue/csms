<?php
session_start();
include '../config/db.php';

if(!isset($_SESSION['student_id'])){
    header("Location: login.php");
    exit();
}

$student_id = $_SESSION['student_id'];
$message = "";

// Fetch student info
$student = mysqli_fetch_assoc(mysqli_query($conn, "SELECT * FROM students WHERE student_id=$student_id"));

// Handle module registration
if(isset($_POST['register_modules'])){
    $selected_modules = $_POST['modules'] ?? [];

    foreach($selected_modules as $module_id){
        // Insert into course_registrations if not already registered
        $check = mysqli_query($conn, "SELECT * FROM course_registrations WHERE student_id=$student_id AND module_id=$module_id");
        if(mysqli_num_rows($check) === 0){
            mysqli_query($conn, "INSERT INTO course_registrations (student_id, module_id) VALUES ($student_id, $module_id)");

            // Automatically create attendance record for the module
            mysqli_query($conn, "INSERT INTO attendance (student_id, module_id, total_classes, attended_classes) 
                                 VALUES ($student_id, $module_id, 0, 0)");
        }
    }
    $message = "Modules registered successfully!";
}

// Fetch available modules for the student based on course and semester
$modules = mysqli_query($conn, "SELECT m.*, t.fullname AS teacher_name, c.course_name
                                FROM modules m
                                JOIN courses c ON m.course_id=c.course_id
                                JOIN teachers t ON m.teacher_id=t.teacher_id
                                WHERE m.course_id=".$student['course_id']." AND m.semester='".$student['semester']."'
                                ORDER BY m.module_name ASC");

// Fetch already registered module IDs
$registered = mysqli_query($conn, "SELECT module_id FROM course_registrations WHERE student_id=$student_id");
$registered_ids = [];
while($r = mysqli_fetch_assoc($registered)){
    $registered_ids[] = $r['module_id'];
}
?>

<!DOCTYPE html>
<html>
<head>
    <title>Register Modules</title>
    <link rel="stylesheet" href="../assets/css/auth.css">
</head>
<body>

<div class="auth-card" style="width: 700px;">
    <h2>Module Registration</h2>
    <p>Hello, <?= $_SESSION['student_name'] ?>! Select your modules for <?= $student['semester'] ?>.</p>

    <?php if($message): ?>
        <div class="message"><?= $message ?></div>
    <?php endif; ?>

    <form method="POST">
        <?php while($m = mysqli_fetch_assoc($modules)): ?>
            <div class="form-group">
                <input type="checkbox" name="modules[]" value="<?= $m['module_id'] ?>" 
                       <?= in_array($m['module_id'], $registered_ids) ? 'checked disabled' : '' ?>>
                <?= $m['module_name'] ?> (<?= $m['course_name'] ?>) - Teacher: <?= $m['teacher_name'] ?>
            </div>
        <?php endwhile; ?>

        <button class="btn" name="register_modules">Register Selected Modules</button>
    </form>

</div>

</body>
</html>
