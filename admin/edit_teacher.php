<?php
session_start();
include '../config/db.php';

if(!isset($_SESSION['admin_logged_in'])){
    header("Location: login.php");
    exit();
}

$message = "";

// Get teacher ID
if(!isset($_GET['id'])){
    header("Location: manage_teachers.php");
    exit();
}

$teacher_id = (int)$_GET['id'];

// Fetch teacher info
$teacher_query = mysqli_query($conn, "SELECT * FROM teachers WHERE teacher_id=$teacher_id AND deleted=0");
if(mysqli_num_rows($teacher_query) === 0){
    header("Location: manage_teachers.php");
    exit();
}

$teacher = mysqli_fetch_assoc($teacher_query);

// Handle form submission
if(isset($_POST['update_teacher'])){
    $fullname = mysqli_real_escape_string($conn, $_POST['fullname']);
    $email    = mysqli_real_escape_string($conn, $_POST['email']);
    $status   = mysqli_real_escape_string($conn, $_POST['status']);

    // Update teacher info
    $update = mysqli_query($conn, "
        UPDATE teachers 
        SET fullname='$fullname', email='$email', status='$status'
        WHERE teacher_id=$teacher_id
    ");

    if($update){
        $message = "Teacher updated successfully!";
        // Refresh teacher data
        $teacher = mysqli_fetch_assoc(mysqli_query($conn, "SELECT * FROM teachers WHERE teacher_id=$teacher_id"));
    } else {
        $message = "Error: " . mysqli_error($conn);
    }
}
?>

<!DOCTYPE html>
<html>
<head>
    <title>Edit Teacher</title>
    <link rel="stylesheet" href="../assets/css/auth.css">
</head>
<body>

<div class="auth-card" style="width: 450px;">
    <h2>Edit Teacher</h2>

    <?php if($message): ?>
        <div class="message"><?= $message ?></div>
    <?php endif; ?>

    <form method="POST">
        <div class="form-group">
            <label>Full Name</label>
            <input type="text" name="fullname" value="<?= htmlspecialchars($teacher['fullname']) ?>" required>
        </div>

        <div class="form-group">
            <label>Email</label>
            <input type="email" name="email" value="<?= htmlspecialchars($teacher['email']) ?>" required>
        </div>

        <div class="form-group">
            <label>Status</label>
            <select name="status" required>
                <option value="active" <?= $teacher['status']=='active'?'selected':'' ?>>Active</option>
                <option value="inactive" <?= $teacher['status']=='inactive'?'selected':'' ?>>Inactive</option>
            </select>
        </div>

        <button class="btn" name="update_teacher">Update Teacher</button>
    </form>

    <div class="auth-links" style="margin-top:15px;">
        <a href="manage_teachers.php">Back to Manage Teachers</a>
    </div>
</div>

</body>
</html>
