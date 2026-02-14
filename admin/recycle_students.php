<?php
session_start();
include '../config/db.php';

if(!isset($_SESSION['admin_logged_in'])){
    header("Location: login.php");
    exit();
}

if(!isset($_GET['course_id'], $_GET['year'], $_GET['semester'])){
    die("Invalid Access.");
}

$course_id = (int)$_GET['course_id'];
$year      = (int)$_GET['year'];
$semester  = (int)$_GET['semester'];

if(isset($_GET['action'], $_GET['id'])){
    $student_id = (int)$_GET['id'];

    if($_GET['action'] === 'restore'){
        mysqli_query($conn, "UPDATE students SET deleted=0 WHERE student_id=$student_id");
    }

    if($_GET['action'] === 'permanent'){
        mysqli_query($conn, "DELETE FROM students WHERE student_id=$student_id");
    }

    header("Location: recycle_students.php?course_id=$course_id&year=$year&semester=$semester");
    exit();
}

$students = mysqli_query($conn,"
    SELECT * FROM students
    WHERE deleted=1
    AND course_id=$course_id
    AND year=$year
    AND semester=$semester
");
?>

<!DOCTYPE html>
<html>
<head>
<title>Recycle Bin</title>
<link rel="stylesheet" href="../assets/css/auth.css">
</head>
<body>

<div class="auth-card" style="width:800px;">
<h2>Recycle Bin - Students</h2>

<table style="width:100%; border-collapse:collapse;">
<tr>
<th>ID</th>
<th>Name</th>
<th>Email</th>
<th>Actions</th>
</tr>

<?php while($row=mysqli_fetch_assoc($students)): ?>
<tr>
<td><?= $row['student_id'] ?></td>
<td><?= $row['name'] ?></td>
<td><?= $row['email'] ?></td>
<td>
<a href="?action=restore&id=<?= $row['student_id'] ?>">Restore</a> |
<a href="?action=permanent&id=<?= $row['student_id'] ?>"
onclick="return confirm('Permanent delete?')">Delete Permanently</a>
</td>
</tr>
<?php endwhile; ?>

</table>

<br>
<a href="manage_students.php?course_id=<?= $course_id ?>&year=<?= $year ?>&semester=<?= $semester ?>">
← Back
</a>

</div>

</body>
</html>
