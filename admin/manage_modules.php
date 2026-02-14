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
    $module_id = (int)$_GET['id'];

    if($_GET['action'] === 'delete'){
        mysqli_query($conn,"UPDATE modules SET deleted=1 WHERE module_id=$module_id");
    }

    header("Location: manage_modules.php?course_id=$course_id&year=$year&semester=$semester");
    exit();
}

/* ================= UPDATE ================= */
if(isset($_POST['update_module'])){
    $module_id   = (int)$_POST['module_id'];
    $module_code = mysqli_real_escape_string($conn,$_POST['module_code']);
    $module_name = mysqli_real_escape_string($conn,$_POST['module_name']);
    $teacher_id  = (int)$_POST['teacher_id'];
    $status      = mysqli_real_escape_string($conn,$_POST['status']);

    mysqli_query($conn,"
        UPDATE modules SET
        module_code='$module_code',
        module_name='$module_name',
        teacher_id=$teacher_id,
        status='$status'
        WHERE module_id=$module_id
    ");

    header("Location: manage_modules.php?course_id=$course_id&year=$year&semester=$semester");
    exit();
}

/* ================= ADD ================= */
if(isset($_POST['add_module'])){
    $module_code = mysqli_real_escape_string($conn,$_POST['module_code']);
    $module_name = mysqli_real_escape_string($conn,$_POST['module_name']);
    $teacher_id  = (int)$_POST['teacher_id'];

    mysqli_query($conn,"
        INSERT INTO modules
        (module_code,module_name,course_id,year,semester,teacher_id,status)
        VALUES
        ('$module_code','$module_name',$course_id,$year,$semester,$teacher_id,'active')
    ");

    header("Location: manage_modules.php?course_id=$course_id&year=$year&semester=$semester");
    exit();
}

/* ================= PAGINATION ================= */
$limit = 5;
$page  = isset($_GET['page']) ? (int)$_GET['page'] : 1;
$start = ($page-1)*$limit;

$whereSearch = "";
if($search!=""){
    $whereSearch = " AND (module_code LIKE '%$search%' OR module_name LIKE '%$search%')";
}

$total_q = mysqli_query($conn,"
    SELECT COUNT(*) as total FROM modules
    WHERE deleted=0
    AND course_id=$course_id
    AND year=$year
    AND semester=$semester
    $whereSearch
");
$total = mysqli_fetch_assoc($total_q)['total'];
$pages = ceil($total/$limit);

/* ================= FETCH DATA ================= */
$course_q = mysqli_query($conn,"SELECT course_name FROM courses WHERE course_id=$course_id");
$course = mysqli_fetch_assoc($course_q);

$teachers = mysqli_query($conn,"SELECT * FROM teachers WHERE deleted=0");

$modules = mysqli_query($conn,"
    SELECT m.*, t.fullname AS teacher_name
    FROM modules m
    LEFT JOIN teachers t ON m.teacher_id=t.teacher_id
    WHERE m.deleted=0
    AND m.course_id=$course_id
    AND m.year=$year
    AND m.semester=$semester
    $whereSearch
    ORDER BY m.module_id DESC
    LIMIT $start,$limit
");
?>
<!DOCTYPE html>
<html>
<head>
<title>Manage Modules</title>
<link rel="stylesheet" href="../assets/css/auth.css">
<style>
table { width:100%; border-collapse:collapse; margin-top:15px; }
th,td { padding:10px; border:1px solid var(--midnight-garden); text-align:center; }
th { background: var(--minty-fresh); }
.action-btn { text-decoration:none; font-weight:bold; margin:0 6px; }
.delete { color: var(--terra-rosa); }
.edit { color: var(--midnight-garden); }
.status-active { color: green; font-weight:bold; }
.status-inactive { color: red; font-weight:bold; }
.pagination a { margin:4px; text-decoration:none; color:var(--midnight-garden); font-weight:bold; }
</style>
</head>
<body>

<div class="auth-card" style="width:1000px;">

<h2>
Manage Modules<br>
<?= $course['course_name'] ?> - Year <?= $year ?> - Semester <?= $semester ?>
</h2>

<!-- SEARCH -->
<form method="GET" style="margin-bottom:10px;">
<input type="hidden" name="course_id" value="<?= $course_id ?>">
<input type="hidden" name="year" value="<?= $year ?>">
<input type="hidden" name="semester" value="<?= $semester ?>">
<input type="text" name="search" placeholder="Search module..." value="<?= $search ?>">
<button class="btn" type="submit" style="width:auto;padding:6px 12px;">Search</button>
</form>

<a href="recycle_modules.php?course_id=<?= $course_id ?>&year=<?= $year ?>&semester=<?= $semester ?>">
Recycle Bin
</a>

<hr>

<!-- ADD FORM -->
<form method="POST">
<input type="text" name="module_code" placeholder="Module Code" required>
<input type="text" name="module_name" placeholder="Module Name" required>
<select name="teacher_id" required>
<option value="">Select Teacher</option>
<?php while($t=mysqli_fetch_assoc($teachers)): ?>
<option value="<?= $t['teacher_id'] ?>"><?= $t['fullname'] ?></option>
<?php endwhile; ?>
</select>
<button class="btn" type="submit" name="add_module">Add Module</button>
</form>

<?php
/* EDIT FORM */
if(isset($_GET['edit'])){
    $edit_id=(int)$_GET['edit'];
    $edit_q=mysqli_query($conn,"SELECT * FROM modules WHERE module_id=$edit_id");
    $edit=mysqli_fetch_assoc($edit_q);
?>
<hr>
<h3>Edit Module</h3>
<form method="POST">
<input type="hidden" name="module_id" value="<?= $edit['module_id'] ?>">
<input type="text" name="module_code" value="<?= $edit['module_code'] ?>" required>
<input type="text" name="module_name" value="<?= $edit['module_name'] ?>" required>
<select name="teacher_id" required>
<?php
$teachers2 = mysqli_query($conn,"SELECT * FROM teachers WHERE deleted=0");
while($t=mysqli_fetch_assoc($teachers2)):
?>
<option value="<?= $t['teacher_id'] ?>"
<?= $t['teacher_id']==$edit['teacher_id']?"selected":"" ?>>
<?= $t['fullname'] ?>
</option>
<?php endwhile; ?>
</select>
<select name="status">
<option value="active" <?= $edit['status']=="active"?"selected":"" ?>>Active</option>
<option value="inactive" <?= $edit['status']=="inactive"?"selected":"" ?>>Inactive</option>
</select>
<button class="btn" type="submit" name="update_module">Update Module</button>
</form>
<hr>
<?php } ?>

<table>
<tr>
<th>ID</th>
<th>Code</th>
<th>Name</th>
<th>Teacher</th>
<th>Status</th>
<th>Action</th>
</tr>

<?php while($row=mysqli_fetch_assoc($modules)): ?>
<tr>
<td><?= $row['module_id'] ?></td>
<td><?= $row['module_code'] ?></td>
<td><?= $row['module_name'] ?></td>
<td><?= $row['teacher_name'] ?></td>
<td class="<?= $row['status']=='active'?'status-active':'status-inactive' ?>">
<?= ucfirst($row['status']) ?>
</td>
<td>
<a class="action-btn edit"
href="?edit=<?= $row['module_id'] ?>&course_id=<?= $course_id ?>&year=<?= $year ?>&semester=<?= $semester ?>">
Edit
</a>
<a class="action-btn delete"
href="?action=delete&id=<?= $row['module_id'] ?>"
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
