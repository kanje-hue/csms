<?php
<<<<<<< HEAD
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
=======
session_start();
include '../config/db.php';

if(!isset($_SESSION['admin_logged_in'])){
    header("Location: login.php");
    exit();
}

$admin_name = $_SESSION['admin_name'] ?? 'Admin';

// Get course statistics
$courses_query = "SELECT 
    c.course_id,
    c.course_name,
    COUNT(DISTINCT m.module_id) as module_count,
    COUNT(DISTINCT s.student_id) as student_count
FROM courses c
LEFT JOIN modules m ON c.course_id = m.course_id AND m.deleted = 0
LEFT JOIN students s ON c.course_id = s.course_id AND s.deleted = 0 AND s.status = 'active'
WHERE c.deleted = 0
GROUP BY c.course_id, c.course_name
ORDER BY c.course_name ASC";

$courses_stmt = $conn->prepare($courses_query);
$courses_stmt->execute();
$courses = $courses_stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$courses_stmt->close();

// Calculate totals
$total_courses = count($courses);
$total_modules = array_sum(array_column($courses, 'module_count'));
$total_students = array_sum(array_column($courses, 'student_count'));

?>

<!DOCTYPE html>
<html>
<head>
    <title>Admin Dashboard - CSMS</title>
    <link rel="stylesheet" href="../assets/css/auth.css">
    <style>
        .container {
            max-width: 1200px;
            margin: 0 auto;
            padding: 20px;
        }

        .auth-card { 
            width: 100%;
            max-width: 1200px;
            margin: 0 auto;
            background: white;
            padding: 30px;
            border-radius: 18px;
            box-shadow: 0 20px 45px rgba(0,0,0,0.15);
        }

        .header {
            text-align: center;
            margin-bottom: 40px;
        }

        h2 {
            color: var(--midnight-garden);
            margin-bottom: 10px;
            font-size: 28px;
        }

        .welcome {
            color: #666;
            font-size: 14px;
        }

        /* Statistics Cards */
        .stats {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 20px;
            margin: 30px 0;
        }

        .stat-card {
            background: linear-gradient(135deg, #E8956B, #F4C896);
            color: white;
            padding: 30px;
            border-radius: 12px;
            text-align: center;
            box-shadow: 0 4px 6px rgba(0,0,0,0.1);
        }

        .stat-label {
            font-size: 14px;
            opacity: 0.9;
            margin-bottom: 10px;
        }

        .stat-number {
            font-size: 36px;
            font-weight: bold;
        }

        /* Section Title */
        h3 {
            color: var(--midnight-garden);
            margin-top: 40px;
            margin-bottom: 20px;
            font-size: 18px;
        }

        /* Course Cards Grid */
        .courses-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(300px, 1fr));
            gap: 20px;
            margin: 20px 0;
        }

        .course-card {
            background: linear-gradient(135deg, var(--skipping-stones), var(--minty-fresh));
            padding: 25px;
            border-radius: 12px;
            box-shadow: 0 4px 6px rgba(0,0,0,0.1);
            transition: all 0.3s;
        }

        .course-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 8px 12px rgba(0,0,0,0.15);
        }

        .course-title {
            font-size: 18px;
            font-weight: bold;
            color: var(--midnight-garden);
            margin-bottom: 15px;
        }

        .course-stats {
            display: flex;
            justify-content: space-between;
            margin-bottom: 15px;
            font-size: 14px;
            color: #666;
        }

        .course-stats div {
            text-align: center;
        }

        .course-stats-number {
            font-size: 18px;
            font-weight: bold;
            color: var(--midnight-garden);
            display: block;
        }

        .course-stats-label {
            font-size: 12px;
            color: #999;
        }

        .course-actions {
            display: flex;
            flex-direction: column;
            gap: 10px;
            margin-top: 15px;
        }

        .btn {
            padding: 12px 15px;
            border: none;
            border-radius: 8px;
            cursor: pointer;
            font-weight: bold;
            text-decoration: none;
            text-align: center;
            transition: all 0.3s;
            font-size: 13px;
        }

        .btn-manage {
            background: #E8956B;
            color: white;
        }

        .btn-manage:hover {
            opacity: 0.9;
            transform: scale(1.02);
        }

        .btn-verify {
            background: var(--minty-fresh);
            color: var(--art-craft);
        }

        .btn-verify:hover {
            opacity: 0.9;
            transform: scale(1.02);
        }

        .no-courses {
            text-align: center;
            padding: 40px;
            color: #666;
        }

        .logout-link {
            text-align: center;
            margin-top: 40px;
        }

        .logout-link a {
            color: var(--terra-rosa);
            text-decoration: none;
            font-weight: bold;
            padding: 10px 20px;
            border: 2px solid var(--terra-rosa);
            border-radius: 8px;
            display: inline-block;
            transition: all 0.3s;
        }

        .logout-link a:hover {
            background: var(--terra-rosa);
            color: white;
        }

        @media (max-width: 768px) {
            .container {
                padding: 10px;
            }

            .auth-card {
                padding: 15px;
            }

            .stats {
                grid-template-columns: 1fr;
            }

            .courses-grid {
                grid-template-columns: 1fr;
            }

            .course-actions {
                flex-direction: row;
            }

            .btn {
                flex: 1;
            }
        }
    </style>
</head>
<body>

<div class="container">
    <div class="auth-card">
        <!-- Header -->
        <div class="header">
            <h2>📚 Admin Dashboard</h2>
            <p class="welcome">Welcome, <strong><?= htmlspecialchars($admin_name) ?></strong></p>
        </div>

        <!-- Statistics -->
        <div class="stats">
            <div class="stat-card">
                <div class="stat-label">Total Courses</div>
                <div class="stat-number"><?= $total_courses ?></div>
            </div>
            <div class="stat-card">
                <div class="stat-label">Total Modules</div>
                <div class="stat-number"><?= $total_modules ?></div>
            </div>
            <div class="stat-card">
                <div class="stat-label">Active Students</div>
                <div class="stat-number"><?= $total_students ?></div>
            </div>
        </div>

        <!-- Manage Courses -->
        <h3>📚 Manage Courses</h3>

        <?php if (count($courses) > 0): ?>
            <div class="courses-grid">
                <?php foreach($courses as $course): ?>
                    <div class="course-card">
                        <div class="course-title"><?= htmlspecialchars($course['course_name']) ?></div>
                        
                        <div class="course-stats">
                            <div>
                                <span class="course-stats-label">Modules</span>
                                <span class="course-stats-number"><?= $course['module_count'] ?></span>
                            </div>
                            <div>
                                <span class="course-stats-label">Students</span>
                                <span class="course-stats-number"><?= $course['student_count'] ?></span>
                            </div>
                        </div>

                        <div class="course-actions">
                            <a href="manage_students.php?course_id=<?= $course['course_id'] ?>" class="btn btn-manage">
                                📋 Manage
                            </a>
                            <a href="verify_enrollments.php?course_id=<?= $course['course_id'] ?>" class="btn btn-verify">
                                🔍 Verify Enrollments
                            </a>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php else: ?>
            <div class="no-courses">
                <p>❌ No courses found</p>
            </div>
        <?php endif; ?>

        <!-- Logout Link -->
        <div class="logout-link">
            <a href="logout.php">🚪 Logout</a>
        </div>
    </div>
</div>
</body>
</html>
