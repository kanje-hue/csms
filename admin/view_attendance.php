<?php
/**
 * admin/view_attendance.php - View Detailed Attendance for a Module
 */

session_start();
require_once '../config/db.php';
require_once '../config/security.php';
require_once '../config/security_base.php';

if (!isset($_SESSION['admin_logged_in']) || !isset($_SESSION['admin_id'])) {
    header("Location: login.php");
    exit();
}

$admin_name = $_SESSION['admin_name'] ?? 'Admin';
$module_id = isset($_GET['module_id']) ? (int)$_GET['module_id'] : 0;

if (!$module_id) {
    header("Location: manage_attendance.php");
    exit();
}

// Get module details
$module_stmt = $conn->prepare("
    SELECT m.*, c.course_name, t.fullname as teacher_name
    FROM modules m
    JOIN courses c ON m.course_id = c.course_id
    LEFT JOIN teachers t ON m.teacher_id = t.teacher_id
    WHERE m.module_id = ? AND m.deleted = 0
");
$module_stmt->bind_param("i", $module_id);
$module_stmt->execute();
$module = $module_stmt->get_result()->fetch_assoc();
$module_stmt->close();

// Get attendance records
$attendance_query = "
    SELECT 
        a.*,
        s.student_id,
        s.reg_number,
        s.name as student_name,
        s.email
    FROM module_enrollments me
    JOIN students s ON me.student_id = s.student_id
    LEFT JOIN attendance a ON a.student_id = s.student_id AND a.module_id = ?
    WHERE me.module_id = ? AND s.deleted = 0 AND s.status = 'active'
    ORDER BY s.name ASC
";

$attendance_stmt = $conn->prepare($attendance_query);
$attendance_stmt->bind_param("ii", $module_id, $module_id);
$attendance_stmt->execute();
$attendance_records = $attendance_stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$attendance_stmt->close();

$total_students = count($attendance_records);
$eligible_count = count(array_filter($attendance_records, fn($a) => ($a['is_eligible'] ?? 0) == 1));
$published = count(array_filter($attendance_records, fn($a) => ($a['status'] ?? 'draft') === 'published')) > 0;
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Attendance Report - <?= htmlspecialchars($module['module_code']) ?></title>
    <style>
        * { margin:0; padding:0; box-sizing:border-box; }
        body { font-family: 'Inter', sans-serif; background: #f0f2f5; }
        .header {
            background: linear-gradient(135deg, #0f172a, #1e293b);
            color: white; padding: 1rem 2rem;
            display: flex; justify-content: space-between; align-items: center;
        }
        .header h1 { background: linear-gradient(135deg, #8b5cf6, #7c3aed); -webkit-background-clip: text; -webkit-text-fill-color: transparent; }
        .container { max-width: 1200px; margin: 2rem auto; padding: 0 2rem; }
        .module-header {
            background: linear-gradient(135deg, #0f172a, #1e293b);
            color: white; padding: 2rem; border-radius: 20px; margin-bottom: 2rem;
        }
        .stats-grid {
            display: grid; grid-template-columns: repeat(3,1fr); gap: 1.5rem; margin-bottom: 2rem;
        }
        .stat-card {
            background: white; padding: 1.5rem; border-radius: 16px;
            border-left: 4px solid #8b5cf6; box-shadow: 0 4px 6px -1px rgba(0,0,0,0.1);
        }
        .stat-number { font-size: 2rem; font-weight: 700; }
        table {
            width: 100%; background: white; border-radius: 16px; overflow: hidden;
            box-shadow: 0 4px 6px -1px rgba(0,0,0,0.1);
        }
        th { background: #f8fafc; padding: 1rem; text-align: left; }
        td { padding: 1rem; border-bottom: 1px solid #e2e8f0; }
        .eligible { background: #d1fae5; color: #065f46; padding: 0.25rem 0.75rem; border-radius: 20px; }
        .not-eligible { background: #fee2e2; color: #991b1b; padding: 0.25rem 0.75rem; border-radius: 20px; }
        .back-link { margin-top: 2rem; text-align: center; }
    </style>
</head>
<body>
<div class="header">
    <h1>CSMS Admin</h1>
    <a href="manage_attendance.php" style="color:white;">← Back</a>
    <a href="logout.php" style="color:white;">Logout</a>
</div>
<div class="container">
    <div class="module-header">
        <h1><?= htmlspecialchars($module['module_code']) ?> - <?= htmlspecialchars($module['module_name']) ?></h1>
        <p><?= htmlspecialchars($module['course_name']) ?> | Teacher: <?= htmlspecialchars($module['teacher_name'] ?? 'N/A') ?></p>
    </div>
    
    <div class="stats-grid">
        <div class="stat-card"><div class="stat-number"><?= $total_students ?></div><div>Total Students</div></div>
        <div class="stat-card"><div class="stat-number"><?= $eligible_count ?></div><div>Eligible</div></div>
        <div class="stat-card"><div class="stat-number"><?= $published ? '✓ Published' : '📝 Draft' ?></div><div>Status</div></div>
    </div>

    <table>
        <thead><tr><th>Reg</th><th>Student</th><th>Total</th><th>Attended</th><th>%</th><th>Eligible</th></tr></thead>
        <tbody>
            <?php foreach ($attendance_records as $r): 
                $pct = $r['attendance_percentage'] ?? 0;
                $eligible = ($r['is_eligible'] ?? 0) == 1;
            ?>
            <tr>
                <td><strong><?= htmlspecialchars($r['reg_number']) ?></strong></td>
                <td><?= htmlspecialchars($r['student_name']) ?></td>
                <td><?= $r['total_classes'] ?? '-' ?></td>
                <td><?= $r['attended_classes'] ?? '-' ?></td>
                <td><?= $r['attended_classes'] ? number_format($pct,1).'%' : '-' ?></td>
                <td><span class="<?= $eligible ? 'eligible' : 'not-eligible' ?>"><?= $eligible ? '✓ Eligible' : '❌ Not Eligible' ?></span></td>
            </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
</div>
</body>
</html>