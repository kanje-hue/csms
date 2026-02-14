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

$search = "";
if(isset($_GET['search'])){
    $search = mysqli_real_escape_string($conn,$_GET['search']);
}

/* ================= DELETE ================= */
if(isset($_GET['action'], $_GET['id'])){
    $student_id = (int)$_GET['id'];

    if($_GET['action'] === 'delete'){
        mysqli_query($conn,"UPDATE students SET deleted=1 WHERE student_id=$student_id");
    }

    header("Location: manage_students.php?course_id=$course_id&year=$year&semester=$semester");
    exit();
}

/* ================= UPDATE ================= */
if(isset($_POST['update_student'])){
    $student_id = (int)$_POST['student_id'];
    $name  = mysqli_real_escape_string($conn,$_POST['name']);
    $email = mysqli_real_escape_string($conn,$_POST['email']);

    mysqli_query($conn,"
        UPDATE students 
        SET name='$name', email='$email'
        WHERE student_id=$student_id
    ");

    header("Location: manage_students.php?course_id=$course_id&year=$year&semester=$semester");
    exit();
}

/* ================= PAGINATION ================= */
$limit = 5;
$page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
$start = ($page - 1) * $limit;

$whereSearch = "";
if($search != ""){
    $whereSearch = " AND (name LIKE '%$search%' OR email LIKE '%$search%')";
}

$total_q = mysqli_query($conn,"
    SELECT COUNT(*) as total FROM students
    WHERE deleted=0
    AND course_id=$course_id
    AND year=$year
    AND semester=$semester
    $whereSearch
");
$total = mysqli_fetch_assoc($total_q)['total'];
$pages = ceil($total / $limit);

/* ================= FETCH DATA ================= */
$course_q = mysqli_query($conn,"SELECT course_name FROM courses WHERE course_id=$course_id");
$course = mysqli_fetch_assoc($course_q);

$students = mysqli_query($conn,"
    SELECT * FROM students
    WHERE deleted=0
    AND course_id=$course_id
    AND year=$year
    AND semester=$semester
    $whereSearch
    ORDER BY student_id DESC
    LIMIT $start, $limit
");
?>

<!DOCTYPE html>
<html>
<head>
<title>Manage Students</title>
<link rel="stylesheet" href="../assets/css/auth.css">
<style>
table { width:100%; border-collapse:collapse; margin-top:15px; }
th,td { padding:10px; border:1px solid #566947; text-align:center; }
th { background: var(--minty-fresh); }
.action-btn { font-weight:bold; text-decoration:none; margin:0 5px; }
.delete { color: var(--terra-rosa); }
.edit { color: var(--midnight-garden); }
.recycle { margin-top:10px; display:inline-block; }
.pagination a { margin:5px; text-decoration:none; font-weight:bold; }
</style>
</head>
<body>

<div class="auth-card" style="width:950px;">

<h2>
Manage Students<br>
<?= $course['course_name'] ?> - Year <?= $year ?> - Semester <?= $semester ?>
</h2>

<!-- SEARCH -->
<form method="GET">
<input type="hidden" name="course_id" value="<?= $course_id ?>">
<input type="hidden" name="year" value="<?= $year ?>">
<input type="hidden" name="semester" value="<?= $semester ?>">
<input type="text" name="search" placeholder="Search name or email" value="<?= $search ?>">
<button type="submit">Search</button>
</form>

<a class="recycle" 
href="recycle_students.php?course_id=<?= $course_id ?>&year=<?= $year ?>&semester=<?= $semester ?>">
Recycle Bin
</a>

<?php
/* ================= EDIT FORM ================= */
if(isset($_GET['edit'])){
    $edit_id = (int)$_GET['edit'];
    $edit_q = mysqli_query($conn,"SELECT * FROM students WHERE student_id=$edit_id");
    $edit = mysqli_fetch_assoc($edit_q);
?>
<hr>
<h3>Edit Student</h3>
<form method="POST">
<input type="hidden" name="student_id" value="<?= $edit['student_id'] ?>">
<input type="text" name="name" value="<?= $edit['name'] ?>" required>
<input type="email" name="email" value="<?= $edit['email'] ?>" required>
<button type="submit" name="update_student">Update</button>
</form>
<hr>
<?php } ?>

<table>
<tr>
<th>ID</th>
<th>Name</th>
<th>Email</th>
<th>Actions</th>
</tr>

<?php while($row = mysqli_fetch_assoc($students)): ?>
<tr>
<td><?= $row['student_id'] ?></td>
<td><?= $row['name'] ?></td>
<td><?= $row['email'] ?></td>
<td>
<a class="action-btn edit"
href="?edit=<?= $row['student_id'] ?>&course_id=<?= $course_id ?>&year=<?= $year ?>&semester=<?= $semester ?>">
Edit
</a>

<a class="action-btn delete"
href="?action=delete&id=<?= $row['student_id'] ?>"
onclick="return confirm('Move to recycle bin?')">
Delete
</a>
</td>
</tr>
<?php endwhile; ?>

</table>

<!-- PAGINATION -->
<div class="pagination">
<?php for($i=1;$i<=$pages;$i++): ?>
<a href="?course_id=<?= $course_id ?>&year=<?= $year ?>&semester=<?= $semester ?>&page=<?= $i ?>&search=<?= $search ?>">
<?= $i ?>
</a>
<?php endfor; ?>
</div>

<br>
<a href="manage_courses.php">← Back</a>

</div>

</body>
</html>
