<?php
session_start();
error_reporting(E_ALL);
ini_set('display_errors', 1);

if(!isset($_SESSION['admin_logged_in'])){
    header("Location: login.php");
    exit();
}

include '../config/db.php';

// Handle activate/deactivate requests
if(isset($_GET['action']) && isset($_GET['id'])){
    $id = (int)$_GET['id'];
    if($_GET['action'] == 'activate'){
        mysqli_query($conn, "UPDATE students SET status='active' WHERE student_id=$id");
    } elseif($_GET['action'] == 'deactivate'){
        mysqli_query($conn, "UPDATE students SET status='inactive' WHERE student_id=$id");
    }
    header("Location: manage_students.php");
    exit();
}

// Fetch all students
$result = mysqli_query($conn, "SELECT * FROM students ORDER BY student_id DESC");
?>

<!DOCTYPE html>
<html>
<head>
    <title>Manage Students - CSMS</title>
</head>
<body>
    <h2>Manage Students</h2>
    <table border="1" cellpadding="5" cellspacing="0">
        <tr>
            <th>ID</th>
            <th>Reg Number</th>
            <th>Name</th>
            <th>Email</th>
            <th>Semester</th>
            <th>Status</th>
            <th>Action</th>
        </tr>

        <?php while($row = mysqli_fetch_assoc($result)){ ?>
        <tr>
            <td><?php echo $row['student_id']; ?></td>
            <td><?php echo $row['reg_number']; ?></td>
            <td><?php echo $row['name']; ?></td>
            <td><?php echo $row['email']; ?></td>
            <td><?php echo $row['semester']; ?></td>
            <td><?php echo $row['status']; ?></td>
            <td>
                <?php if($row['status'] == 'inactive'){ ?>
                    <a href="?action=activate&id=<?php echo $row['student_id']; ?>">Activate</a>
                <?php } else { ?>
                    <a href="?action=deactivate&id=<?php echo $row['student_id']; ?>">Deactivate</a>
                <?php } ?>
            </td>
        </tr>
        <?php } ?>
    </table>

    <p><a href="dashboard.php">Back to Dashboard</a></p>
</body>
</html>
