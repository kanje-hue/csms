<?php
session_start();
include '../config/db.php';

if(!isset($_SESSION['student_id'])){
    header("Location: login.php");
    exit();
}

function safe_int($value) {
    return filter_var($value, FILTER_VALIDATE_INT);
}

$student_id = $_SESSION['student_id'];
$student_name = $_SESSION['student_name'] ?? 'Student';
$course_id = $_SESSION['course_id'] ?? null;

// Get student info
$student_stmt = $conn->prepare("SELECT student_id, reg_number, name, course_id, year FROM students WHERE student_id = ? AND deleted = 0");
$student_stmt->bind_param("i", $student_id);
$student_stmt->execute();
$student = $student_stmt->get_result()->fetch_assoc();
$student_stmt->close();

if (!$student) {
    header("Location: login.php");
    exit();
}

$course_id = $student['course_id'];
$current_year = $student['year'];

// Get course name
$course_stmt = $conn->prepare("SELECT course_name FROM courses WHERE course_id = ? AND deleted = 0");
$course_stmt->bind_param("i", $course_id);
$course_stmt->execute();
$course = $course_stmt->get_result()->fetch_assoc();
$course_stmt->close();

$course_name = $course ? $course['course_name'] : 'Unknown Course';

// Fetch modules for ALL years (1, 2, 3) to allow viewing previous semesters
$modules_query = "
    SELECT DISTINCT 
        m.module_id,
        m.module_code,
        m.module_name,
        m.year,
        m.semester,
        t.fullname AS teacher_name,
        COUNT(r.result_id) as has_results,
        MAX(r.published) as results_published
    FROM modules m
    LEFT JOIN teachers t ON m.teacher_id = t.teacher_id
    LEFT JOIN results r ON r.module_id = m.module_id AND r.student_id = ?
    WHERE m.deleted = 0 
    AND m.course_id = ?
    AND m.year <= ?
    GROUP BY m.module_id, m.year, m.semester
    ORDER BY m.year DESC, m.semester DESC, m.module_name ASC
";

$modules_stmt = $conn->prepare($modules_query);
$modules_stmt->bind_param("iii", $student_id, $course_id, $current_year);
$modules_stmt->execute();
$modules_result = $modules_stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$modules_stmt->close();

// Fetch results for all years
$results_query = "
    SELECT 
        r.result_id,
        r.module_id,
        r.ca_marks,
        r.final_marks,
        r.grade,
        r.published,
        m.module_code,
        m.module_name,
        m.year,
        m.semester
    FROM results r
    INNER JOIN modules m ON r.module_id = m.module_id
    WHERE r.student_id = ?
    AND m.course_id = ?
    AND m.deleted = 0
    AND r.published = 1
    ORDER BY m.year DESC, m.semester DESC, m.module_name ASC
";

$results_stmt = $conn->prepare($results_query);
$results_stmt->bind_param("ii", $student_id, $course_id);
$results_stmt->execute();
$results = $results_stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$results_stmt->close();

// Calculate statistics
$total_modules = count($modules_result);
$modules_with_results = 0;
$total_marks = 0;
$results_count = 0;

foreach ($results as $r) {
    $modules_with_results++;
    $total = ($r['ca_marks'] ?? 0) + ($r['final_marks'] ?? 0);
    $total_marks += $total;
    $results_count++;
}

$avg_marks = $results_count > 0 ? round($total_marks / $results_count) : 0;

?>

<!DOCTYPE html>
<html>
<head>
    <title>Student Dashboard</title>
    <link rel="stylesheet" href="../assets/css/auth.css">
    <style>
        .container {
            max-width: 1200px;
            margin: 0 auto;
            padding: 20px;
        }

        .auth-card {
            width: 100%;
            max-width: 1100px;
            padding: 30px;
            border-radius: 18px;
            background: var(--white);
            box-shadow: 0 20px 45px rgba(0,0,0,0.15);
            margin: 30px auto;
        }

        h2 {
            text-align: center;
            margin-bottom: 10px;
            color: var(--midnight-garden);
        }

        .student-info {
            text-align: center;
            color: #666;
            margin-bottom: 20px;
            padding: 15px;
            background: #f9f9f9;
            border-radius: 8px;
        }

        .stats {
            display: grid;
            grid-template-columns: repeat(4, 1fr);
            gap: 15px;
            margin: 20px 0;
        }

        .stat-card {
            background: linear-gradient(135deg, var(--terra-rosa), var(--honey-glow));
            color: white;
            padding: 20px;
            border-radius: 10px;
            text-align: center;
            box-shadow: 0 4px 6px rgba(0,0,0,0.1);
        }

        .stat-number {
            font-size: 28px;
            font-weight: bold;
        }

        .stat-label {
            font-size: 12px;
            margin-top: 5px;
            opacity: 0.9;
        }

        h3 {
            margin-top: 30px;
            margin-bottom: 15px;
            color: var(--midnight-garden);
            border-bottom: 2px solid var(--minty-fresh);
            padding-bottom: 10px;
        }

        table {
            width: 100%;
            border-collapse: collapse;
            margin-bottom: 30px;
            table-layout: fixed;
        }

        th, td {
            padding: 10px;
            border: 1px solid #566947;
            text-align: center;
            word-wrap: break-word;
        }

        th {
            background: var(--minty-fresh);
            color: var(--art-craft);
            font-weight: bold;
        }

        th:first-child, td:first-child {
            text-align: left;
        }

        tr:nth-child(even) {
            background: #f9f9f9;
        }

        tr:hover {
            background: #f0f0f0;
        }

        .badge {
            display: inline-block;
            padding: 4px 8px;
            border-radius: 4px;
            font-size: 12px;
            font-weight: bold;
        }

        .badge-current {
            background: #d4edda;
            color: #155724;
        }

        .badge-previous {
            background: #cce5ff;
            color: #004085;
        }

        .badge-pass {
            background: #d4edda;
            color: #155724;
        }

        .badge-supplementary {
            background: #fff3cd;
            color: #856404;
        }

        .no-data {
            text-align: center;
            padding: 40px;
            color: #666;
            background: #f9f9f9;
            border-radius: 10px;
            margin: 20px 0;
        }

        .auth-links {
            text-align: center;
            margin-top: 30px;
        }

        .auth-links a {
            color: var(--terra-rosa);
            text-decoration: none;
            font-weight: bold;
            margin: 0 15px;
        }

        .auth-links a:hover {
            text-decoration: underline;
        }

        @media (max-width: 768px) {
            .auth-card {
                padding: 15px;
                margin: 15px;
            }

            .stats {
                grid-template-columns: repeat(2, 1fr);
            }

            table {
                font-size: 12px;
            }

            th, td {
                padding: 8px;
            }
        }
    </style>
</head>
<body>

<div class="auth-card">
    <h2>📚 Student Dashboard</h2>
    
    <div class="student-info">
        <strong><?= htmlspecialchars($student['name']) ?></strong> | 
        Reg #: <?= htmlspecialchars($student['reg_number']) ?> | 
        Year: <?= $current_year ?> | 
        Course: <?= htmlspecialchars($course_name) ?>
    </div>

    <!-- Statistics -->
    <div class="stats">
        <div class="stat-card">
            <div class="stat-number"><?= $total_modules ?></div>
            <div class="stat-label">Total Modules</div>
        </div>
        <div class="stat-card">
            <div class="stat-number"><?= $modules_with_results ?></div>
            <div class="stat-label">Results Available</div>
        </div>
        <div class="stat-card">
            <div class="stat-number"><?= $avg_marks ?></div>
            <div class="stat-label">Average Marks</div>
        </div>
        <div class="stat-card">
            <div class="stat-number"><?= $results_count ?></div>
            <div class="stat-label">Published Results</div>
        </div>
    </div>

    <!-- Modules by Year -->
    <h3>📘 Registered Modules (All Years)</h3>
    <?php if (count($modules_result) > 0): ?>
        <table>
            <tr>
                <th>Code</th>
                <th>Module Name</th>
                <th>Year</th>
                <th>Semester</th>
                <th>Teacher</th>
                <th>Status</th>
            </tr>
            <?php 
            $last_year = null;
            foreach ($modules_result as $m): 
                $badge_class = ($m['year'] == $current_year) ? 'badge-current' : 'badge-previous';
                $badge_text = ($m['year'] == $current_year) ? 'Current' : 'Previous';
            ?>
            <tr>
                <td><strong><?= htmlspecialchars($m['module_code']) ?></strong></td>
                <td><?= htmlspecialchars($m['module_name']) ?></td>
                <td><?= $m['year'] ?></td>
                <td><?= $m['semester'] ?></td>
                <td><?= htmlspecialchars($m['teacher_name'] ?? 'Unassigned') ?></td>
                <td><span class="badge <?= $badge_class ?>"><?= $badge_text ?></span></td>
            </tr>
            <?php endforeach; ?>
        </table>
    <?php else: ?>
        <div class="no-data">No modules registered for this course</div>
    <?php endif; ?>

    <!-- Results -->
    <h3>📊 Results (Published Only)</h3>
    <?php if (count($results) > 0): ?>
        <table>
            <tr>
                <th>Code</th>
                <th>Module Name</th>
                <th>Year</th>
                <th>Semester</th>
                <th>CA (0-60)</th>
                <th>Final (0-40)</th>
                <th>Total</th>
                <th>Grade</th>
                <th>Status</th>
            </tr>
            <?php foreach ($results as $r): 
                $total = ($r['ca_marks'] ?? 0) + ($r['final_marks'] ?? 0);
                $status = $total >= 50 ? 'Passed' : 'Supplementary';
                $status_class = $total >= 50 ? 'badge-pass' : 'badge-supplementary';
            ?>
            <tr>
                <td><strong><?= htmlspecialchars($r['module_code']) ?></strong></td>
                <td><?= htmlspecialchars($r['module_name']) ?></td>
                <td><?= $r['year'] ?></td>
                <td><?= $r['semester'] ?></td>
                <td><?= $r['ca_marks'] ?? '-' ?></td>
                <td><?= $r['final_marks'] ?? '-' ?></td>
                <td><strong><?= $total ?></strong></td>
                <td><?= htmlspecialchars($r['grade'] ?? '-') ?></td>
                <td><span class="badge <?= $status_class ?>">
                    <?= $status === 'Passed' ? '✓ Passed' : '⚠️ Supplementary' ?>
                </span></td>
            </tr>
            <?php endforeach; ?>
        </table>
    <?php else: ?>
        <div class="no-data">No published results yet</div>
    <?php endif; ?>

    <div class="auth-links">
        <a href="logout.php">Logout</a>
    </div>
</div>

</body>
</html>