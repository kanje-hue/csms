<?php
// File: admin/dashboard.php
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin Dashboard</title>
</head>
<body>
    <h1>Admin Dashboard</h1>

    <section>
        <h2>Statistics</h2>
        <ul>
            <li>Total Courses: <?php echo $total_courses; ?></li>
            <li>Total Teachers: <?php echo $total_teachers; ?></li>
            <li>Pending Students: <?php echo $total_pending_students; ?></li>
        </ul>
    </section>

    <section>
        <h2>Actions</h2>
        <button onclick="autoActivate()">Auto-Activate Students</button>
    </section>

    <nav>
        <h2>Manage</h2>
        <ul>
            <li><a href="manage_courses.php">Manage Courses</a></li>
            <li><a href="manage_teachers.php">Manage Teachers</a></li>
            <li><a href="pending_students.php">Pending Students</a></li>
            <li><a href="auto_activate_students.php">Auto Activate Students</a></li>
        </ul>
    </nav>

    <script>
        function autoActivate() {
            // Logic to auto-activate students
            alert('Auto-activation process initiated!');
        }
    </script>
</body>
</html>
