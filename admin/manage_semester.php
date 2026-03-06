<?php
/**
 * admin/manage_semester.php - Central Hub for Semester Management
 * Added Attendance card
 */

session_start();
require_once '../config/db.php';
require_once '../config/security_base.php';

// Check admin login
checkAdminSession();

$course_id = isset($_GET['course_id']) ? (int)$_GET['course_id'] : 0;
$year = isset($_GET['year']) ? (int)$_GET['year'] : 0;
$semester = isset($_GET['semester']) ? (int)$_GET['semester'] : 0;

if (!$course_id || !$year || !$semester) {
    header("Location: dashboard.php");
    exit();
}

// Get course details
$course_stmt = $conn->prepare("SELECT course_name FROM courses WHERE course_id = ? AND deleted = 0");
$course_stmt->bind_param("i", $course_id);
$course_stmt->execute();
$course = $course_stmt->get_result()->fetch_assoc();
$course_stmt->close();

if (!$course) {
    header("Location: dashboard.php");
    exit();
}

// Get modules in this semester
$modules_stmt = $conn->prepare("
    SELECT 
        m.*,
        t.fullname as teacher_name,
        COUNT(DISTINCT me.student_id) as enrolled_students
    FROM modules m
    LEFT JOIN teachers t ON m.teacher_id = t.teacher_id
    LEFT JOIN module_enrollments me ON m.module_id = me.module_id
    WHERE m.course_id = ? AND m.year = ? AND m.semester = ? AND m.deleted = 0
    GROUP BY m.module_id
    ORDER BY m.module_code ASC
");
$modules_stmt->bind_param("iii", $course_id, $year, $semester);
$modules_stmt->execute();
$modules = $modules_stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$modules_stmt->close();

// Get student statistics
$students_stmt = $conn->prepare("
    SELECT 
        COUNT(*) as total_students,
        SUM(CASE WHEN status = 'pending' THEN 1 ELSE 0 END) as pending,
        SUM(CASE WHEN status = 'active' THEN 1 ELSE 0 END) as active
    FROM students 
    WHERE course_id = ? AND year = ? AND semester = ? AND deleted = 0
");
$students_stmt->bind_param("iii", $course_id, $year, $semester);
$students_stmt->execute();
$student_stats = $students_stmt->get_result()->fetch_assoc();
$students_stmt->close();

// Get results statistics
$results_stmt = $conn->prepare("
    SELECT 
        COUNT(DISTINCT r.id) as total_results,
        SUM(CASE WHEN r.status = 'published' THEN 1 ELSE 0 END) as published
    FROM modules m
    LEFT JOIN results r ON m.module_id = r.module_id
    WHERE m.course_id = ? AND m.year = ? AND m.semester = ? AND m.deleted = 0
");
$results_stmt->bind_param("iii", $course_id, $year, $semester);
$results_stmt->execute();
$results_stats = $results_stmt->get_result()->fetch_assoc();
$results_stmt->close();

// Get attendance statistics
$attendance_stmt = $conn->prepare("
    SELECT 
        COUNT(DISTINCT a.id) as total_attendance,
        SUM(CASE WHEN a.status = 'published' THEN 1 ELSE 0 END) as published_attendance,
        SUM(a.is_eligible) as eligible_count
    FROM modules m
    LEFT JOIN attendance a ON m.module_id = a.module_id
    WHERE m.course_id = ? AND m.year = ? AND m.semester = ? AND m.deleted = 0
");
$attendance_stmt->bind_param("iii", $course_id, $year, $semester);
$attendance_stmt->execute();
$attendance_stats = $attendance_stmt->get_result()->fetch_assoc();
$attendance_stmt->close();

$csrf_token = generateCSRF();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Manage Semester - <?= htmlspecialchars($course['course_name']) ?></title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body {
            font-family: 'Inter', -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
            background: #f0f2f5;
            color: #1e293b;
        }
        
        .header {
            background: linear-gradient(135deg, #0f172a, #1e293b);
            color: white;
            padding: 1rem 2rem;
            display: flex;
            justify-content: space-between;
            align-items: center;
            width: 100%;
        }
        
        .header h1 {
            font-size: 1.8rem;
            font-weight: 700;
            background: linear-gradient(135deg, #2dd4bf, #14b8a6);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            background-clip: text;
        }
        
        .header a {
            color: white;
            text-decoration: none;
            padding: 0.5rem 1.2rem;
            border-radius: 8px;
            background: rgba(255,255,255,0.1);
            transition: all 0.3s;
        }
        
        .header a:hover {
            background: #2dd4bf;
        }
        
        .container {
            max-width: 1400px;
            margin: 2rem auto;
            padding: 0 2rem;
        }
        
        .breadcrumb {
            background: white;
            padding: 1rem 1.5rem;
            border-radius: 12px;
            margin-bottom: 2rem;
            box-shadow: 0 2px 4px rgba(0,0,0,0.05);
        }
        
        .breadcrumb a {
            color: #2dd4bf;
            text-decoration: none;
            font-weight: 500;
        }
        
        .semester-header {
            background: linear-gradient(135deg, #0f172a, #1e293b);
            color: white;
            padding: 2rem;
            border-radius: 20px;
            margin-bottom: 2rem;
            box-shadow: 0 10px 25px -5px rgba(0,0,0,0.2);
        }
        
        .semester-header h1 {
            font-size: 2rem;
            margin-bottom: 0.5rem;
        }
        
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 1.5rem;
            margin-bottom: 2rem;
        }
        
        .stat-card {
            background: white;
            padding: 1.5rem;
            border-radius: 16px;
            box-shadow: 0 4px 6px -1px rgba(0,0,0,0.1);
            border-left: 4px solid #2dd4bf;
        }
        
        .stat-number {
            font-size: 2rem;
            font-weight: 700;
            color: #0f172a;
        }
        
        .stat-label {
            color: #64748b;
            font-size: 0.9rem;
            margin-top: 0.25rem;
        }
        
        .management-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));
            gap: 1.5rem;
            margin: 2rem 0;
        }
        
        .management-card {
            background: white;
            border-radius: 20px;
            overflow: hidden;
            box-shadow: 0 4px 15px rgba(0,0,0,0.1);
            transition: transform 0.3s;
            cursor: pointer;
            text-decoration: none;
            color: inherit;
            display: block;
        }
        
        .management-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 8px 25px rgba(0,0,0,0.15);
        }
        
        .card-header {
            padding: 1.5rem;
            text-align: center;
            color: white;
        }
        
        .card-header.modules { background: linear-gradient(135deg, #667eea, #764ba2); }
        .card-header.teachers { background: linear-gradient(135deg, #f093fb, #f5576c); }
        .card-header.students { background: linear-gradient(135deg, #4facfe, #00f2fe); }
        .card-header.results { background: linear-gradient(135deg, #10b981, #059669); }
        .card-header.attendance { background: linear-gradient(135deg, #8b5cf6, #7c3aed); }
        
        .card-icon {
            font-size: 2.5rem;
            margin-bottom: 0.5rem;
        }
        
        .card-title {
            font-size: 1.3rem;
            font-weight: 600;
        }
        
        .card-body {
            padding: 1.5rem;
        }
        
        .card-stats {
            display: flex;
            justify-content: space-around;
            margin-bottom: 0.5rem;
        }
        
        .card-stat {
            text-align: center;
        }
        
        .card-stat-value {
            font-size: 1.5rem;
            font-weight: 700;
            color: #0f172a;
        }
        
        .card-stat-label {
            font-size: 0.7rem;
            color: #64748b;
        }
        
        .module-list {
            margin-top: 2rem;
            background: white;
            border-radius: 20px;
            padding: 1.5rem;
            box-shadow: 0 4px 10px rgba(0,0,0,0.1);
        }
        
        .module-list h3 {
            margin-bottom: 1rem;
            color: #0f172a;
        }
        
        .module-item {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 1rem;
            border-bottom: 1px solid #e2e8f0;
        }
        
        .module-item:last-child {
            border-bottom: none;
        }
        
        .module-code {
            font-weight: 600;
            color: #2dd4bf;
        }
        
        .module-info {
            flex: 1;
            margin-left: 1rem;
        }
        
        .module-name {
            font-weight: 500;
        }
        
        .module-teacher {
            font-size: 0.8rem;
            color: #64748b;
        }
        
        .btn-small {
            padding: 0.4rem 0.8rem;
            background: #e2e8f0;
            border-radius: 6px;
            text-decoration: none;
            color: #0f172a;
            font-size: 0.8rem;
            transition: all 0.3s;
        }
        
        .btn-small:hover {
            background: #2dd4bf;
            color: white;
        }
        
        @media (max-width: 768px) {
            .container { padding: 0 1rem; }
            .management-grid { grid-template-columns: 1fr; }
            .module-item { flex-direction: column; align-items: flex-start; gap: 0.5rem; }
            .module-info { margin-left: 0; }
        }
    </style>
</head>
<body>

<div class="header">
    <h1>CSMS Admin</h1>
    <a href="logout.php">🚪 Logout</a>
</div>

<div class="container">
    <!-- Breadcrumb -->
    <div class="breadcrumb">
        <a href="dashboard.php">Dashboard</a> > 
        <a href="manage_course_structure.php?course_id=<?= $course_id ?>"><?= htmlspecialchars($course['course_name']) ?></a> > 
        <strong>Year <?= $year ?> - Semester <?= $semester ?></strong>
    </div>

    <!-- Semester Header -->
    <div class="semester-header">
        <h1><?= htmlspecialchars($course['course_name']) ?> - Year <?= $year ?>, Semester <?= $semester ?></h1>
        <p>Manage all aspects of this semester including attendance tracking</p>
    </div>

    <!-- Quick Stats -->
    <div class="stats-grid">
        <div class="stat-card">
            <div class="stat-number"><?= count($modules) ?></div>
            <div class="stat-label">Total Modules</div>
        </div>
        <div class="stat-card">
            <div class="stat-number"><?= $student_stats['active'] ?? 0 ?></div>
            <div class="stat-label">Active Students</div>
        </div>
        <div class="stat-card">
            <div class="stat-number"><?= $student_stats['pending'] ?? 0 ?></div>
            <div class="stat-label">Pending Approvals</div>
        </div>
        <div class="stat-card">
            <div class="stat-number"><?= $attendance_stats['eligible_count'] ?? 0 ?></div>
            <div class="stat-label">Eligible Students</div>
        </div>
    </div>

    <!-- Management Cards - UPDATED with Attendance -->
    <div class="management-grid">
        <!-- Modules Card -->
        <a href="manage_modules.php?course_id=<?= $course_id ?>&year=<?= $year ?>&semester=<?= $semester ?>" class="management-card">
            <div class="card-header modules">
                <div class="card-icon">📚</div>
                <div class="card-title">Modules</div>
                <div class="card-subtitle">Manage course subjects</div>
            </div>
            <div class="card-body">
                <div class="card-stats">
                    <div class="card-stat">
                        <div class="card-stat-value"><?= count($modules) ?></div>
                        <div class="card-stat-label">Total</div>
                    </div>
                </div>
            </div>
        </a>

        <!-- Teachers Card -->
        <a href="manage_teachers.php?course_id=<?= $course_id ?>" class="management-card">
            <div class="card-header teachers">
                <div class="card-icon">👨‍🏫</div>
                <div class="card-title">Teachers</div>
                <div class="card-subtitle">Assign teachers to modules</div>
            </div>
            <div class="card-body">
                <p style="color: #64748b;">Manage teacher assignments</p>
            </div>
        </a>

        <!-- Students Card -->
        <a href="manage_students.php?course_id=<?= $course_id ?>&year=<?= $year ?>&semester=<?= $semester ?>" class="management-card">
            <div class="card-header students">
                <div class="card-icon">👨‍🎓</div>
                <div class="card-title">Students</div>
                <div class="card-subtitle">Approve and manage enrollments</div>
            </div>
            <div class="card-body">
                <div class="card-stats">
                    <div class="card-stat">
                        <div class="card-stat-value"><?= $student_stats['pending'] ?? 0 ?></div>
                        <div class="card-stat-label">Pending</div>
                    </div>
                    <div class="card-stat">
                        <div class="card-stat-value"><?= $student_stats['active'] ?? 0 ?></div>
                        <div class="card-stat-label">Active</div>
                    </div>
                </div>
            </div>
        </a>

        <!-- Results Card -->
        <a href="manage_results.php?course_id=<?= $course_id ?>&year=<?= $year ?>&semester=<?= $semester ?>" class="management-card">
            <div class="card-header results">
                <div class="card-icon">📊</div>
                <div class="card-title">Results</div>
                <div class="card-subtitle">View and publish grades</div>
            </div>
            <div class="card-body">
                <div class="card-stats">
                    <div class="card-stat">
                        <div class="card-stat-value"><?= $results_stats['total_results'] ?? 0 ?></div>
                        <div class="card-stat-label">Total</div>
                    </div>
                    <div class="card-stat">
                        <div class="card-stat-value"><?= $results_stats['published'] ?? 0 ?></div>
                        <div class="card-stat-label">Published</div>
                    </div>
                </div>
            </div>
        </a>

        <!-- Attendance Card - NEW -->
        <a href="manage_attendance.php?course_id=<?= $course_id ?>&year=<?= $year ?>&semester=<?= $semester ?>" class="management-card">
            <div class="card-header attendance">
                <div class="card-icon">📋</div>
                <div class="card-title">Attendance</div>
                <div class="card-subtitle">Track student attendance & eligibility</div>
            </div>
            <div class="card-body">
                <div class="card-stats">
                    <div class="card-stat">
                        <div class="card-stat-value"><?= $attendance_stats['total_attendance'] ?? 0 ?></div>
                        <div class="card-stat-label">Records</div>
                    </div>
                    <div class="card-stat">
                        <div class="card-stat-value"><?= $attendance_stats['eligible_count'] ?? 0 ?></div>
                        <div class="card-stat-label">Eligible</div>
                    </div>
                </div>
            </div>
        </a>
    </div>

    <!-- Modules in this Semester -->
    <div class="module-list">
        <h3>📚 Modules in This Semester</h3>
        <?php if (count($modules) > 0): ?>
            <?php foreach ($modules as $module): ?>
            <div class="module-item">
                <div>
                    <span class="module-code"><?= htmlspecialchars($module['module_code']) ?></span>
                    <span class="module-name"> - <?= htmlspecialchars($module['module_name']) ?></span>
                    <div class="module-teacher">Teacher: <?= htmlspecialchars($module['teacher_name'] ?? 'Not Assigned') ?></div>
                </div>
                <div>
                    <span style="margin-right: 1rem; color: #64748b;"><?= $module['enrolled_students'] ?> students</span>
                    <a href="edit_module.php?id=<?= $module['module_id'] ?>" class="btn-small">Edit</a>
                </div>
            </div>
            <?php endforeach; ?>
        <?php else: ?>
            <p style="text-align: center; padding: 2rem; color: #64748b;">No modules found for this semester.</p>
        <?php endif; ?>
    </div>
</div>

</body>
</html>