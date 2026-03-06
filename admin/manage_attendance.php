<?php
/**
 * admin/manage_attendance.php - Admin Attendance Management
 * View attendance for a specific course/year/semester
 */

session_start();
require_once '../config/db.php';
require_once '../config/security.php';
require_once '../config/security_base.php';

// Check admin login
if (!isset($_SESSION['admin_logged_in']) || !isset($_SESSION['admin_id'])) {
    header("Location: login.php");
    exit();
}

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

$message = "";
$message_type = "";
$csrf_token = generateCSRF();

// Get all modules with attendance for this semester
$modules_query = "
    SELECT 
        m.module_id,
        m.module_code,
        m.module_name,
        t.fullname as teacher_name,
        COUNT(DISTINCT a.id) as attendance_count,
        SUM(CASE WHEN a.status = 'published' THEN 1 ELSE 0 END) as published_count,
        SUM(CASE WHEN a.status = 'draft' THEN 1 ELSE 0 END) as draft_count,
        SUM(a.is_eligible) as eligible_count,
        COUNT(DISTINCT me.student_id) as student_count
    FROM modules m
    LEFT JOIN teachers t ON m.teacher_id = t.teacher_id
    LEFT JOIN attendance a ON m.module_id = a.module_id
    LEFT JOIN module_enrollments me ON m.module_id = me.module_id
    WHERE m.course_id = ? AND m.year = ? AND m.semester = ? AND m.deleted = 0
    GROUP BY m.module_id, m.module_code, m.module_name, t.fullname
    ORDER BY m.module_code ASC
";

$modules_stmt = $conn->prepare($modules_query);
$modules_stmt->bind_param("iii", $course_id, $year, $semester);
$modules_stmt->execute();
$modules = $modules_stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$modules_stmt->close();

// Calculate totals
$total_modules = count($modules);
$total_attendance = array_sum(array_column($modules, 'attendance_count'));
$total_eligible = array_sum(array_column($modules, 'eligible_count'));
$total_students = array_sum(array_column($modules, 'student_count'));
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Manage Attendance - <?= htmlspecialchars($course['course_name']) ?></title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body {
            font-family: 'Inter', sans-serif;
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
        }
        .breadcrumb a {
            color: #2dd4bf;
            text-decoration: none;
        }
        .page-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 2rem;
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
            border-left: 4px solid #8b5cf6;
        }
        .stat-number {
            font-size: 2rem;
            font-weight: 700;
        }
        table {
            width: 100%;
            border-collapse: collapse;
            background: white;
            border-radius: 16px;
            overflow: hidden;
            box-shadow: 0 4px 6px -1px rgba(0,0,0,0.1);
        }
        th {
            background: #f8fafc;
            padding: 1rem;
            text-align: left;
            font-weight: 600;
            border-bottom: 2px solid #e2e8f0;
        }
        td {
            padding: 1rem;
            border-bottom: 1px solid #e2e8f0;
        }
        .badge {
            display: inline-block;
            padding: 0.25rem 0.75rem;
            border-radius: 20px;
            font-size: 0.8rem;
            font-weight: 600;
        }
        .badge-published { background: #d1fae5; color: #065f46; }
        .badge-draft { background: #fef3c7; color: #92400e; }
        .badge-eligible { background: #d1fae5; color: #065f46; }
        .btn {
            padding: 0.5rem 1rem;
            background: #8b5cf6;
            color: white;
            border: none;
            border-radius: 6px;
            text-decoration: none;
            cursor: pointer;
        }
        .btn-small {
            padding: 0.3rem 0.6rem;
            background: #e2e8f0;
            border-radius: 4px;
            text-decoration: none;
            color: #0f172a;
            font-size: 0.8rem;
        }
        .back-link {
            margin-top: 2rem;
            text-align: center;
        }
    </style>
</head>
<body>
    <div class="header">
        <h1>CSMS Admin - Attendance</h1>
        <a href="logout.php" style="color:white;">Logout</a>
    </div>
    
    <div class="container">
        <div class="breadcrumb">
            <a href="dashboard.php">Dashboard</a> > 
            <a href="manage_course_structure.php?course_id=<?= $course_id ?>"><?= htmlspecialchars($course['course_name']) ?></a> > 
            <a href="manage_semester.php?course_id=<?= $course_id ?>&year=<?= $year ?>&semester=<?= $semester ?>">Year <?= $year ?> - Semester <?= $semester ?></a> > 
            <strong>Attendance</strong>
        </div>
        
        <div class="page-header">
            <h1>📋 Attendance Management</h1>
            <a href="manage_semester.php?course_id=<?= $course_id ?>&year=<?= $year ?>&semester=<?= $semester ?>" class="btn">← Back</a>
        </div>
        
        <div class="stats-grid">
            <div class="stat-card">
                <div class="stat-number"><?= $total_modules ?></div>
                <div>Total Modules</div>
            </div>
            <div class="stat-card">
                <div class="stat-number"><?= $total_attendance ?></div>
                <div>Attendance Records</div>
            </div>
            <div class="stat-card">
                <div class="stat-number"><?= $total_eligible ?></div>
                <div>Eligible Students</div>
            </div>
        </div>
        
        <table>
            <thead>
                <tr>
                    <th>Module Code</th>
                    <th>Module Name</th>
                    <th>Teacher</th>
                    <th>Students</th>
                    <th>Records</th>
                    <th>Eligible</th>
                    <th>Status</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($modules as $module): ?>
                <tr>
                    <td><strong><?= htmlspecialchars($module['module_code']) ?></strong></td>
                    <td><?= htmlspecialchars($module['module_name']) ?></td>
                    <td><?= htmlspecialchars($module['teacher_name'] ?? 'N/A') ?></td>
                    <td><?= $module['student_count'] ?></td>
                    <td><?= $module['attendance_count'] ?></td>
                    <td><span class="badge badge-eligible"><?= $module['eligible_count'] ?></span></td>
                    <td>
                        <?php if ($module['published_count'] > 0): ?>
                            <span class="badge badge-published">Published</span>
                        <?php elseif ($module['draft_count'] > 0): ?>
                            <span class="badge badge-draft">Draft</span>
                        <?php else: ?>
                            <span class="badge">No Data</span>
                        <?php endif; ?>
                    </td>
                    <td>
                        <a href="../teacher/view_attendance.php?module_id=<?= $module['module_id'] ?>" class="btn-small">View</a>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        
        <div class="back-link">
            <a href="manage_semester.php?course_id=<?= $course_id ?>&year=<?= $year ?>&semester=<?= $semester ?>">← Back to Semester</a>
        </div>
    </div>
</body>
</html>