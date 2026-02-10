<?php
session_start();
include '../config/db.php';

if(!isset($_SESSION['teacher_id'])){
    header("Location: login.php");
    exit();
}

$teacher_id = $_SESSION['teacher_id'];
$message = "";

// Ensure module_id is provided
if(!isset($_GET['module_id'])){
    die("Module ID not specified.");
}
$module_id = (int)$_GET['module_id'];

// Fetch module info and ensure the teacher owns it
$module_res = mysqli_query($conn, "SELECT m.*, c.course_name FROM modules m JOIN courses c ON m.course_id=c.course_id WHERE m.module_id=$module_id AND m.teacher_id=$teacher_id");
if(mysqli_num_rows($module_res) !== 1){
    die("Module not found or access denied.");
}
$module = mysqli_fetch_assoc($module_res);

// Handle attendance updates
if(isset($_POST['update_attendance'])){
    foreach($_POST['attendance'] as $student_id => $data){
        $total = (int)$data['total'];
        $attended = (int)$data['attended'];

        // Ensure record exists
        $check = mysqli_query($conn, "SELECT * FROM attendance WHERE student_id=$student_id AND module_id=$module_id");
        if(mysqli_num_rows($check) > 0){
            mysqli_query($conn, "UPDATE attendance SET total_classes=$total, attended_classes=$attended WHERE student_id=$student_id AND module_id=$module_id");
        } else {
            mysqli_query($conn, "INSERT INTO attendance (student_id,module_id,total_classes,attended_classes) VALUES ($student_id,$module_id,$total,$attended)");
        }
    }
    $message = "Attendance updated successfully!";
}

// Fetch students registered in this module
$students = mysqli_query($conn, "
    SELECT s.student_id, s.name, a.total_classes, a.attended_classes
    FROM module_registrations mr
    JOIN students s ON mr.student_id=s.student_id
    LEFT JOIN attendance a ON a.student_id=s.student_id AND a.module_id=$module_id
    WHERE mr.module_id=$module_id
    ORDER BY s.name ASC
");
?>

<!DOCTYPE html>
<html>
<head>
    <title>Attendance - <?= $module['module_name'] ?></title>
    <link rel="stylesheet" href="../assets/css/auth.css">
    <style>
        table { width: 100%; border-collapse: collapse; margin-top: 15px; }
        th, td { border: 1px solid #566947; padding: 8px; text-align: center; }
        th { background-color: var(--minty-fresh); }
        input { width: 60px; text-align: center; }
        .btn-small { padding: 5px 10px; margin: 2px; font-size: 14px; }
    </style>
</head>
<body>
<div class="auth-card" style="width: 900px;">
    <h2>Attendance for <?= $module['module_name'] ?> (<?= $module['course_name'] ?>)</h2>

    <?php if($message): ?>
        <div class="message"><?= $message ?></div>
    <?php endif; ?>

    <form method="POST">
        <table>
            <tr>
                <th>Student Name</th>
                <th>Total Classes</th>
                <th>Attended Classes</th>
            </tr>
            <?php while($s = mysqli_fetch_assoc($students)): ?>
            <tr>
                <td><?= $s['name'] ?></td>
                <td><input type="number" name="attendance[<?= $s['student_id'] ?>][total]" value="<?= $s['total_classes'] ?? 0 ?>" min="0"></td>
                <td><input type="number" name="attendance[<?= $s['student_id'] ?>][attended]" value="<?= $s['attended_classes'] ?? 0 ?>" min="0"></td>
            </tr>
            <?php endwhile; ?>
        </table>
        <button class="btn" name="update_attendance">Save Attendance</button>
    </form>

    <div class="auth-links">
        <a href="dashboard.php">Back to Dashboard</a> |
        <a href="logout.php">Logout</a>
    </div>
</div>
</body>
</html>
