<?php
session_start();
include '../config/db.php';

if(!isset($_SESSION['admin_logged_in'])){
    header("Location: login.php");
    exit();
}

$admin_id = $_SESSION['admin_id'] ?? 1;
$view = isset($_GET['view']) ? trim($_GET['view']) : 'courses';

?>

<!DOCTYPE html>
<html>
<head>
    <title>
        <?php 
            echo $view === 'courses' ? 'Courses Overview' : 'Results Overview';
        ?> - CSMS
    </title>
    <link rel="stylesheet" href="../assets/css/auth.css">
    <style>
        .auth-card {
            width: 1200px;
            max-width: 100%;
            padding: 30px;
            border-radius: 18px;
            background: var(--white);
            box-shadow: 0 20px 45px rgba(0,0,0,0.15);
            margin: 30px auto;
        }

        h2 {
            text-align: center;
            color: var(--midnight-garden);
            margin-bottom: 30px;
        }

        .tabs {
            display: flex;
            gap: 10px;
            margin-bottom: 30px;
            border-bottom: 2px solid #ddd;
        }

        .tab-btn {
            padding: 12px 20px;
            background: none;
            border: none;
            cursor: pointer;
            font-weight: bold;
            color: #666;
            border-bottom: 3px solid transparent;
            transition: all 0.3s;
        }

        .tab-btn.active {
            color: var(--terra-rosa);
            border-bottom-color: var(--terra-rosa);
        }

        .stats {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 20px;
            margin-bottom: 30px;
        }

        .stat-card {
            background: linear-gradient(135deg, var(--terra-rosa), var(--honey-glow));
            color: white;
            padding: 25px;
            border-radius: 12px;
            text-align: center;
            box-shadow: 0 4px 6px rgba(0,0,0,0.1);
        }

        .stat-number {
            font-size: 36px;
            font-weight: bold;
            margin: 10px 0;
        }

        .stat-label {
            font-size: 14px;
            opacity: 0.9;
        }

        table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 20px;
        }

        th, td {
            padding: 15px;
            border: 1px solid #ddd;
            text-align: left;
        }

        th {
            background: linear-gradient(135deg, var(--terra-rosa), var(--honey-glow));
            color: white;
            font-weight: bold;
        }

        tr:nth-child(even) {
            background: #f9f9f9;
        }

        tr:hover {
            background: #f0f0f0;
        }

        .action-btn {
            padding: 8px 15px;
            background: var(--terra-rosa);
            color: white;
            border: none;
            border-radius: 6px;
            cursor: pointer;
            font-weight: bold;
            font-size: 12px;
            text-decoration: none;
            display: inline-block;
        }

        .action-btn:hover {
            opacity: 0.9;
        }

        .status-badge {
            display: inline-block;
            padding: 4px 12px;
            border-radius: 6px;
            font-size: 12px;
            font-weight: bold;
        }

        .status-draft {
            background: #fff3cd;
            color: #856404;
        }

        .status-published {
            background: #d4edda;
            color: #155724;
        }

        .result-passed {
            color: #4CAF50;
            font-weight: bold;
        }

        .result-supplementary {
            color: #FF9800;
            font-weight: bold;
        }

        .back-link {
            text-align: center;
            margin-top: 30px;
        }

        .back-link a {
            color: var(--terra-rosa);
            text-decoration: none;
            font-weight: bold;
            padding: 10px 20px;
            border: 2px solid var(--terra-rosa);
            border-radius: 8px;
            display: inline-block;
            transition: all 0.3s;
        }

        .back-link a:hover {
            background: var(--terra-rosa);
            color: white;
        }

        .no-data {
            text-align: center;
            padding: 40px;
            color: #666;
        }

        .action-links {
            display: flex;
            gap: 10px;
            flex-wrap: wrap;
        }

        @media (max-width: 768px) {
            .auth-card {
                width: 95%;
                padding: 15px;
            }

            .stats {
                grid-template-columns: 1fr;
            }

            table {
                font-size: 12px;
            }

            th, td {
                padding: 10px;
            }

            .action-links {
                flex-direction: column;
            }

            .action-btn {
                width: 100%;
                box-sizing: border-box;
            }
        }
    </style>
</head>
<body>

<div class="auth-card">
    
    <!-- Tabs -->
    <div class="tabs">
        <button class="tab-btn <?= $view === 'courses' ? 'active' : '' ?>" onclick="window.location.href='admin_overview.php?view=courses'">
            📚 Courses Overview
        </button>
        <button class="tab-btn <?= $view === 'results' ? 'active' : '' ?>" onclick="window.location.href='admin_overview.php?view=results'">
            📊 Results Overview
        </button>
    </div>

    <?php if($view === 'courses'): ?>
        <!-- COURSES VIEW -->
        <h2>📚 Courses Overview</h2>

        <?php
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

            $total_courses = count($courses);
            $total_modules = array_sum(array_column($courses, 'module_count'));
            $total_students = array_sum(array_column($courses, 'student_count'));
        ?>

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
        </div>

        <!-- Courses Table -->
        <h3 style="color: var(--midnight-garden); margin-top: 30px; margin-bottom: 20px;">All Courses</h3>

        <?php if(count($courses) > 0): ?>
            <table>
                <thead>
                    <tr>
                        <th>Course Name</th>
                        <th>Modules</th>
                        <th>Active Students</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach($courses as $course): ?>
                    <tr>
                        <td><strong><?= htmlspecialchars($course['course_name']) ?></strong></td>
                        <td><?= $course['module_count'] ?></td>
                        <td><?= $course['student_count'] ?></td>
                        <td>
                            <div class="action-links">
                                <a href="manage_courses.php?course_id=<?= $course['course_id'] ?>" class="action-btn">
                                    Manage
                                </a>
                                <a href="verify_enrollments.php?course_id=<?= $course['course_id'] ?>&year=1&semester=1" class="action-btn">
                                    Verify
                                </a>
                            </div>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php else: ?>
            <div class="no-data">No courses found</div>
        <?php endif; ?>

    <?php else: ?>
        <!-- RESULTS VIEW -->
        <h2>📊 Results Overview</h2>

        <?php
            // Get results statistics
            $results_stats = $conn->query("SELECT 
                COUNT(*) as total_results,
                SUM(CASE WHEN status = 'published' THEN 1 ELSE 0 END) as published,
                SUM(CASE WHEN status = 'draft' THEN 1 ELSE 0 END) as draft,
                SUM(CASE WHEN ca_marks < 30 OR final_marks < 20 OR total_marks < 50 THEN 1 ELSE 0 END) as supplementary,
                SUM(CASE WHEN ca_marks >= 30 AND final_marks >= 20 AND total_marks >= 50 THEN 1 ELSE 0 END) as passed
            FROM results")->fetch_assoc();
        ?>

        <!-- Statistics -->
        <div class="stats">
            <div class="stat-card">
                <div class="stat-label">Total Results</div>
                <div class="stat-number"><?= $results_stats['total_results'] ?? 0 ?></div>
            </div>
            <div class="stat-card">
                <div class="stat-label">Published</div>
                <div class="stat-number"><?= $results_stats['published'] ?? 0 ?></div>
            </div>
        </div>

        <!-- Results by Module -->
        <h3 style="color: var(--midnight-garden); margin-top: 30px; margin-bottom: 20px;">Results by Module</h3>

        <?php
            $modules_data_query = "
                SELECT 
                    m.module_id,
                    m.module_code,
                    m.module_name,
                    c.course_name,
                    COUNT(r.id) as total_results,
                    SUM(CASE WHEN r.status = 'published' THEN 1 ELSE 0 END) as published,
                    SUM(CASE WHEN r.status = 'draft' THEN 1 ELSE 0 END) as draft
                FROM modules m
                LEFT JOIN courses c ON m.course_id = c.course_id
                LEFT JOIN results r ON m.module_id = r.module_id
                WHERE m.deleted = 0
                GROUP BY m.module_id, m.module_code, m.module_name, c.course_name
                ORDER BY c.course_name ASC, m.module_code ASC
            ";

            $modules_stmt = $conn->prepare($modules_data_query);
            $modules_stmt->execute();
            $modules_data = $modules_stmt->get_result()->fetch_all(MYSQLI_ASSOC);
            $modules_stmt->close();
        ?>

        <?php if(count($modules_data) > 0): ?>
            <table>
                <thead>
                    <tr>
                        <th>Module Code</th>
                        <th>Module Name</th>
                        <th>Course</th>
                        <th>Total Results</th>
                        <th>Published</th>
                        <th>Draft</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach($modules_data as $mod): ?>
                    <tr>
                        <td><strong><?= htmlspecialchars($mod['module_code']) ?></strong></td>
                        <td><?= htmlspecialchars($mod['module_name']) ?></td>
                        <td><?= htmlspecialchars($mod['course_name']) ?></td>
                        <td><?= $mod['total_results'] ?></td>
                        <td>
                            <span class="status-badge status-published">
                                <?= $mod['published'] ?>
                            </span>
                        </td>
                        <td>
                            <span class="status-badge status-draft">
                                <?= $mod['draft'] ?>
                            </span>
                        </td>
                        <td>
                            <a href="manage_results.php?module_id=<?= $mod['module_id'] ?>" class="action-btn">
                                Manage
                            </a>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php else: ?>
            <div class="no-data">No modules found</div>
        <?php endif; ?>

    <?php endif; ?>

    <div class="back-link">
        <a href="dashboard.php">← Back to Dashboard</a>
    </div>
</div>

</body>
</html>