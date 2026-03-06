<?php
/**
 * teacher/view_attendance.php - View Attendance Report
 * Shows attendance statistics and exam eligibility
 */

session_start();
require_once '../config/db.php';
require_once '../config/security.php';
require_once '../config/security_base.php';

// Check teacher login
if (!isset($_SESSION['teacher_logged_in']) || !isset($_SESSION['teacher_id'])) {
    header("Location: login.php");
    exit();
}

$teacher_id = $_SESSION['teacher_id'];
$module_id = isset($_GET['module_id']) ? (int)$_GET['module_id'] : 0;

if (!$module_id) {
    header("Location: dashboard.php");
    exit();
}

// Verify teacher owns this module
$check_stmt = $conn->prepare("
    SELECT m.*, c.course_name 
    FROM modules m
    JOIN courses c ON m.course_id = c.course_id
    WHERE m.module_id = ? AND m.teacher_id = ? AND m.deleted = 0
");
$check_stmt->bind_param("ii", $module_id, $teacher_id);
$check_stmt->execute();
$module_result = $check_stmt->get_result();

if ($module_result->num_rows == 0) {
    die("❌ Unauthorized: This module is not assigned to you");
}

$module = $module_result->fetch_assoc();
$check_stmt->close();

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

// Calculate statistics
$total_students = count($attendance_records);
$students_with_attendance = count(array_filter($attendance_records, fn($a) => $a['attended_classes'] !== null));
$eligible_count = count(array_filter($attendance_records, fn($a) => ($a['is_eligible'] ?? 0) == 1));
$published = count(array_filter($attendance_records, fn($a) => ($a['status'] ?? 'draft') === 'published')) > 0;

// Calculate average attendance
$total_percentage = 0;
$count_with_percentage = 0;
foreach ($attendance_records as $record) {
    if ($record['attendance_percentage'] !== null) {
        $total_percentage += $record['attendance_percentage'];
        $count_with_percentage++;
    }
}
$avg_attendance = $count_with_percentage > 0 ? $total_percentage / $count_with_percentage : 0;
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Attendance Report - <?= htmlspecialchars($module['module_code']) ?></title>
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

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
            box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.1);
            position: sticky;
            top: 0;
            z-index: 100;
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
            background: rgba(255, 255, 255, 0.1);
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

        .module-info {
            background: linear-gradient(135deg, #0f172a, #1e293b);
            color: white;
            padding: 2rem;
            border-radius: 20px;
            margin-bottom: 2rem;
        }

        .module-info h2 {
            font-size: 1.8rem;
            margin-bottom: 0.5rem;
        }

        .module-info p {
            color: #94a3b8;
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
            box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.1);
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

        .summary-card {
            background: white;
            border-radius: 16px;
            padding: 2rem;
            margin-bottom: 2rem;
            box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.1);
        }

        .eligibility-summary {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 2rem;
            margin-top: 1rem;
        }

        .eligibility-item {
            text-align: center;
            padding: 1.5rem;
            border-radius: 12px;
        }

        .eligibility-item.eligible {
            background: #d1fae5;
        }

        .eligibility-item.not-eligible {
            background: #fee2e2;
        }

        .eligibility-number {
            font-size: 3rem;
            font-weight: 700;
        }

        .eligibility-label {
            font-size: 1rem;
            color: #475569;
            margin-top: 0.5rem;
        }

        table {
            width: 100%;
            border-collapse: collapse;
            background: white;
            border-radius: 16px;
            overflow: hidden;
            box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.1);
        }

        th {
            background: #f8fafc;
            padding: 1rem;
            text-align: left;
            font-weight: 600;
            color: #0f172a;
            border-bottom: 2px solid #e2e8f0;
        }

        td {
            padding: 1rem;
            border-bottom: 1px solid #e2e8f0;
        }

        tr:hover {
            background: #f8fafc;
        }

        .eligibility-badge {
            display: inline-block;
            padding: 0.25rem 0.75rem;
            border-radius: 20px;
            font-size: 0.8rem;
            font-weight: 600;
        }

        .eligible {
            background: #d1fae5;
            color: #065f46;
        }

        .not-eligible {
            background: #fee2e2;
            color: #991b1b;
        }

        .status-badge {
            display: inline-block;
            padding: 0.25rem 0.75rem;
            border-radius: 20px;
            font-size: 0.8rem;
            font-weight: 600;
        }

        .status-published {
            background: #d1fae5;
            color: #065f46;
        }

        .status-draft {
            background: #fef3c7;
            color: #92400e;
        }

        .back-link {
            margin-top: 2rem;
            text-align: center;
        }

        @media (max-width: 768px) {
            .container {
                padding: 0 1rem;
            }

            .eligibility-summary {
                grid-template-columns: 1fr;
            }

            table {
                font-size: 0.8rem;
            }

            td, th {
                padding: 0.5rem;
            }
        }
    </style>
</head>
<body>

<div class="header">
    <h1>CSMS Teacher</h1>
    <a href="manage_attendance.php?module_id=<?= $module_id ?>">← Manage Attendance</a>
    <a href="logout.php">🚪 Logout</a>
</div>

<div class="container">
    <!-- Module Info -->
    <div class="module-info">
        <h2><?= htmlspecialchars($module['module_code']) ?> - <?= htmlspecialchars($module['module_name']) ?></h2>
        <p><?= htmlspecialchars($module['course_name']) ?> | Year <?= $module['year'] ?> | Semester <?= $module['semester'] ?></p>
    </div>

    <!-- Statistics -->
    <div class="stats-grid">
        <div class="stat-card">
            <div class="stat-number"><?= $total_students ?></div>
            <div class="stat-label">Total Students</div>
        </div>
        <div class="stat-card">
            <div class="stat-number"><?= $students_with_attendance ?></div>
            <div class="stat-label">Attendance Uploaded</div>
        </div>
        <div class="stat-card">
            <div class="stat-number"><?= number_format($avg_attendance, 1) ?>%</div>
            <div class="stat-label">Average Attendance</div>
        </div>
        <div class="stat-card">
            <div class="stat-number"><?= $published ? '✓ Published' : '📝 Draft' ?></div>
            <div class="stat-label">Status</div>
        </div>
    </div>

    <!-- Eligibility Summary -->
    <div class="summary-card">
        <h3 style="margin-bottom: 1rem;">📊 Exam Eligibility Summary</h3>
        <div class="eligibility-summary">
            <div class="eligibility-item eligible">
                <div class="eligibility-number"><?= $eligible_count ?></div>
                <div class="eligibility-label">Students Eligible (≥60%)</div>
            </div>
            <div class="eligibility-item not-eligible">
                <div class="eligibility-number"><?= $total_students - $eligible_count ?></div>
                <div class="eligibility-label">Students Not Eligible</div>
            </div>
        </div>
    </div>

    <!-- Detailed Attendance -->
    <h3 style="margin: 2rem 0 1rem;">📋 Detailed Attendance Records</h3>
    
    <table>
        <thead>
            <tr>
                <th>Reg Number</th>
                <th>Student Name</th>
                <th>Total Classes</th>
                <th>Attended</th>
                <th>Percentage</th>
                <th>Eligibility</th>
                <th>Status</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($attendance_records as $record): 
                $percentage = $record['attendance_percentage'] ?? 0;
                $eligible = ($record['is_eligible'] ?? 0) == 1;
            ?>
            <tr>
                <td><strong><?= htmlspecialchars($record['reg_number']) ?></strong></td>
                <td><?= htmlspecialchars($record['student_name']) ?></td>
                <td><?= $record['total_classes'] ?? '-' ?></td>
                <td><?= $record['attended_classes'] ?? '-' ?></td>
                <td>
                    <?php if ($record['attended_classes'] !== null): ?>
                        <?= number_format($percentage, 1) ?>%
                    <?php else: ?>
                        -
                    <?php endif; ?>
                </td>
                <td>
                    <?php if ($record['attended_classes'] !== null): ?>
                        <span class="eligibility-badge <?= $eligible ? 'eligible' : 'not-eligible' ?>">
                            <?= $eligible ? '✓ Eligible' : '❌ Not Eligible' ?>
                        </span>
                    <?php else: ?>
                        <span class="eligibility-badge not-eligible">Not Uploaded</span>
                    <?php endif; ?>
                </td>
                <td>
                    <?php if ($record['status'] ?? 'draft'): ?>
                        <span class="status-badge status-<?= $record['status'] ?? 'draft' ?>">
                            <?= ucfirst($record['status'] ?? 'draft') ?>
                        </span>
                    <?php endif; ?>
                </td>
            </tr>
            <?php endforeach; ?>
        </tbody>
    </table>

    <div class="back-link">
        <a href="manage_attendance.php?module_id=<?= $module_id ?>">← Back to Attendance Management</a>
    </div>
</div>

</body>
</html>