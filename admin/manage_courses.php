<?php
session_start();
include '../config/db.php';

if(!isset($_SESSION['admin_logged_in'])){
    header("Location: login.php");
    exit();
}

/* Fetch all courses */
$courses = mysqli_query($conn, "SELECT * FROM courses ORDER BY course_name ASC");
?>

<!DOCTYPE html>
<html>
<head>
    <title>Manage Courses</title>
    <link rel="stylesheet" href="../assets/css/auth.css">
    <style>
        .auth-card { width:900px; }

        .course-card {
            background: linear-gradient(135deg, var(--skipping-stones), var(--minty-fresh));
            padding: 20px;
            border-radius: 18px;
            margin: 15px;
            text-align: center;
            font-weight: bold;
            font-size: 18px;
            cursor: pointer;
            transition: transform 0.2s;
            text-decoration: none;
            color: var(--art-craft);
            display: inline-block;
            width: 250px;
        }

        .course-card:hover {
            transform: scale(1.05);
        }

        .center {
            text-align: center;
        }

        select, button {
            padding: 8px;
            margin: 5px;
            border-radius: 8px;
        }

        .manage-buttons a {
            display: inline-block;
            margin: 10px;
            padding: 10px 20px;
            border-radius: 10px;
            text-decoration: none;
            font-weight: bold;
            background: linear-gradient(135deg, var(--terra-rosa), var(--honey-glow));
            color: white;
        }
    </style>
</head>
<body>

<div class="auth-card">
    <h2>Manage Courses</h2>

    <!-- STEP 1: Show all courses -->
    <div class="center">
        <?php while($course = mysqli_fetch_assoc($courses)): ?>
            <a class="course-card"
               href="?course_id=<?= $course['course_id'] ?>">
                <?= $course['course_name'] ?>
            </a>
        <?php endwhile; ?>
    </div>

    <?php if(isset($_GET['course_id'])): 
        $course_id = (int)$_GET['course_id'];
    ?>

        <hr>

        <!-- STEP 2: Select Year and Semester -->
        <form method="GET" class="center">
            <input type="hidden" name="course_id" value="<?= $course_id ?>">

            <label>Select Year:</label>
            <select name="year" required>
                <option value="">-- Year --</option>
                <option value="1">First Year</option>
                <option value="2">Second Year</option>
                <option value="3">Third Year</option>
            </select>

            <label>Select Semester:</label>
            <select name="semester" required>
                <option value="">-- Semester --</option>
                <option value="1">Semester 1</option>
                <option value="2">Semester 2</option>
            </select>

            <button type="submit">Continue</button>
        </form>

    <?php endif; ?>

    <?php if(isset($_GET['year']) && isset($_GET['semester'])):
        $year = (int)$_GET['year'];
        $semester = (int)$_GET['semester'];
    ?>

        <hr>

        <!-- STEP 3: Choose What to Manage -->
        <div class="center manage-buttons">
            <h3>Manage:</h3>

            <a href="manage_students.php?course_id=<?= $course_id ?>&year=<?= $year ?>&semester=<?= $semester ?>">
                Students
            </a>

            <a href="manage_modules.php?course_id=<?= $course_id ?>&year=<?= $year ?>&semester=<?= $semester ?>">
                Modules
            </a>

            <a href="manage_teachers.php?course_id=<?= $course_id ?>">
                Teachers
            </a>

            <a href="manage_attendance.php?course_id=<?= $course_id ?>&year=<?= $year ?>&semester=<?= $semester ?>">
                Attendance
            </a>

            <a href="manage_results.php?course_id=<?= $course_id ?>&year=<?= $year ?>&semester=<?= $semester ?>">
                Results
            </a>
        </div>

    <?php endif; ?>

    <br>
    <div class="center">
        <a href="dashboard.php" style="text-decoration:none;font-weight:bold;">
            ← Back to Dashboard
        </a>
    </div>

</div>

</body>
</html>
