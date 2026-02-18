<?php
// Dashboard for managing students

// Function to fetch pending students count
function fetch_pending_students_count() {
    // Database query to get the count of pending students
    return 10; // Example count
}

// Count of pending students
$pending_students_count = fetch_pending_students_count();
?>

<html>
<head>
    <title>Admin Dashboard</title>
</head>
<body>
    <h1>Admin Dashboard</h1>
    <div>
        <span>Pending Students: <span style='font-weight:bold;'><?php echo $pending_students_count; ?></span></span>
        <button onclick='approveStudents()'>Approve Students</button>
    </div>
    <script>
        function approveStudents() {
            // Logic for approving students
            alert('Approve functionality not implemented.');
        }
    </script>
</body>
</html>