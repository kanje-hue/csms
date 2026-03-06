<?php
/**
 * admin/view_results.php - View Detailed Results for a Module
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

$admin_name = $_SESSION['admin_name'] ?? 'Admin';
$module_id = isset($_GET['module_id']) ? (int)$_GET['module_id'] : 0;

if (!$module_id) {
    header("Location: manage_results.php");
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

if (!$module) {
    header("Location: manage_results.php");
    exit();
}

// Get results for this module
$results_stmt = $conn->prepare("
    SELECT 
        r.*,
        s.reg_number,
        s.name as student_name,
        s.email
    FROM results r
    JOIN students s ON r.student_id = s.student_id
    WHERE r.module_id = ? AND s.deleted = 0
    ORDER BY s.name ASC
");
$results_stmt->bind_param("i", $module_id);
$results_stmt->execute();
$results = $results_stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$results_stmt->close();

// Calculate statistics
$total_students = count($results);
$avg_ca = $total_students > 0 ? array_sum(array_column($results, 'ca_marks')) / $total_students : 0;
$avg_final = $total_students > 0 ? array_sum(array_column($results, 'final_marks')) / $total_students : 0;
$avg_total = $total_students > 0 ? array_sum(array_column($results, 'total_marks')) / $total_students : 0;

// Grade distribution
$grade_distribution = [
    'A' => 0, 'B' => 0, 'C' => 0, 'D' => 0, 'F' => 0
];
foreach ($results as $result) {
    if (isset($grade_distribution[$result['grade']])) {
        $grade_distribution[$result['grade']]++;
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>View Results - <?= htmlspecialchars($module['module_code']) ?></title>
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

        .header .admin-info {
            display: flex;
            align-items: center;
            gap: 2rem;
        }

        .header .logout-btn {
            color: white;
            text-decoration: none;
            padding: 0.5rem 1.2rem;
            border-radius: 8px;
            background: rgba(255, 255, 255, 0.1);
            transition: all 0.3s;
        }

        .header .logout-btn:hover {
            background: #2dd4bf;
        }

        .container {
            max-width: 1200px;
            margin: 2rem auto;
            padding: 0 2rem;
        }

        .breadcrumb {
            background: white;
            padding: 1rem 1.5rem;
            border-radius: 12px;
            margin-bottom: 2rem;
            box-shadow: 0 2px 4px rgba(0, 0, 0, 0.05);
        }

        .breadcrumb a {
            color: #0d9488;
            text-decoration: none;
            font-weight: 500;
        }

        .module-header {
            background: linear-gradient(135deg, #0f172a, #1e293b);
            color: white;
            padding: 2rem;
            border-radius: 20px;
            margin-bottom: 2rem;
            box-shadow: 0 10px 25px -5px rgba(0, 0, 0, 0.2);
        }

        .module-header h1 {
            font-size: 2rem;
            margin-bottom: 0.5rem;
        }

        .module-header p {
            color: #94a3b8;
            font-size: 1rem;
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
            border-left: 4px solid #0d9488;
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

        .grade-distribution {
            background: white;
            border-radius: 16px;
            padding: 1.5rem;
            margin-bottom: 2rem;
            box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.1);
        }

        .grade-bars {
            display: flex;
            gap: 1rem;
            margin-top: 1rem;
            align-items: flex-end;
        }

        .grade-bar-item {
            flex: 1;
            text-align: center;
        }

        .grade-bar {
            background: #0d9488;
            height: 0;
            border-radius: 8px 8px 0 0;
            transition: height 0.3s;
            min-height: 4px;
        }

        .grade-label {
            margin-top: 0.5rem;
            font-weight: 600;
        }

        .grade-count {
            font-size: 0.8rem;
            color: #64748b;
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

        .grade-badge {
            display: inline-block;
            padding: 0.25rem 0.75rem;
            border-radius: 20px;
            font-weight: 600;
        }

        .grade-A { background: #d1fae5; color: #065f46; }
        .grade-B { background: #dbeafe; color: #1e40af; }
        .grade-C { background: #fef3c7; color: #92400e; }
        .grade-D { background: #fee2e2; color: #991b1b; }
        .grade-F { background: #fee2e2; color: #991b1b; }

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

            .stats-grid {
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
    <h1>CSMS Admin</h1>
    <div class="admin-info">
        <span>Welcome, <?= htmlspecialchars($admin_name) ?></span>
        <a href="manage_results.php" class="logout-btn">← Back to Results</a>
        <a href="logout.php" class="logout-btn">Logout</a>
    </div>
</div>

<div class="container">
    <div class="breadcrumb">
        <a href="dashboard.php">Dashboard</a> > 
        <a href="manage_results.php">Results Management</a> > 
        <strong><?= htmlspecialchars($module['module_code']) ?></strong>
    </div>

    <!-- Module Header -->
    <div class="module-header">
        <h1><?= htmlspecialchars($module['module_code']) ?> - <?= htmlspecialchars($module['module_name']) ?></h1>
        <p><?= htmlspecialchars($module['course_name']) ?> | Year <?= $module['year'] ?> | Semester <?= $module['semester'] ?> | Teacher: <?= htmlspecialchars($module['teacher_name'] ?? 'Not Assigned') ?></p>
    </div>

    <!-- Statistics -->
    <div class="stats-grid">
        <div class="stat-card">
            <div class="stat-number"><?= $total_students ?></div>
            <div class="stat-label">Total Students</div>
        </div>
        <div class="stat-card">
            <div class="stat-number"><?= number_format($avg_ca, 1) ?></div>
            <div class="stat-label">Average CA Marks</div>
        </div>
        <div class="stat-card">
            <div class="stat-number"><?= number_format($avg_final, 1) ?></div>
            <div class="stat-label">Average Final Marks</div>
        </div>
        <div class="stat-card">
            <div class="stat-number"><?= number_format($avg_total, 1) ?></div>
            <div class="stat-label">Average Total</div>
        </div>
    </div>

    <!-- Grade Distribution -->
    <div class="grade-distribution">
        <h3>📊 Grade Distribution</h3>
        <div class="grade-bars">
            <?php 
            $max_count = max($grade_distribution) ?: 1;
            foreach (['A', 'B', 'C', 'D', 'F'] as $grade): 
                $count = $grade_distribution[$grade] ?? 0;
                $height = ($count / $max_count) * 100;
            ?>
            <div class="grade-bar-item">
                <div class="grade-bar" style="height: <?= $height ?>px;"></div>
                <div class="grade-label"><?= $grade ?></div>
                <div class="grade-count"><?= $count ?></div>
            </div>
            <?php endforeach; ?>
        </div>
    </div>

    <!-- Results Table -->
    <h3 style="margin-bottom: 1rem;">📋 Student Results</h3>
    
    <table>
        <thead>
            <tr>
                <th>Reg Number</th>
                <th>Student Name</th>
                <th>CA Marks</th>
                <th>Final Marks</th>
                <th>Total</th>
                <th>Grade</th>
                <th>Status</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($results as $result): 
                $total = ($result['ca_marks'] ?? 0) + ($result['final_marks'] ?? 0);
                $grade_class = 'grade-' . ($result['grade'] ?? 'F');
            ?>
            <tr>
                <td><strong><?= htmlspecialchars($result['reg_number']) ?></strong></td>
                <td><?= htmlspecialchars($result['student_name']) ?></td>
                <td><?= $result['ca_marks'] ?? '-' ?></td>
                <td><?= $result['final_marks'] ?? '-' ?></td>
                <td><strong><?= $total > 0 ? $total : '-' ?></strong></td>
                <td>
                    <?php if ($result['grade']): ?>
                        <span class="grade-badge <?= $grade_class ?>"><?= $result['grade'] ?></span>
                    <?php else: ?>
                        -
                    <?php endif; ?>
                </td>
                <td>
                    <span class="status-badge status-<?= $result['status'] ?? 'draft' ?>">
                        <?= ucfirst($result['status'] ?? 'draft') ?>
                    </span>
                </td>
            </tr>
            <?php endforeach; ?>
        </tbody>
    </table>

    <div class="back-link">
        <a href="manage_results.php">← Back to Results Management</a>
    </div>
</div>

</body>
</html>