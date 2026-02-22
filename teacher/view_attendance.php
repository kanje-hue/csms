<?php
session_start();
include '../config/db.php';

if(!isset($_SESSION['teacher_id'])){
    header("Location: login.php");
    exit();
}

$teacher_id = $_SESSION['teacher_id'];
$module_id = isset($_GET['module_id']) ? (int)$_GET['module_id'] : null;

if(!$module_id){
    die("Module not specified");
}

// Verify teacher owns module
$check = $conn->prepare("SELECT m.module_id, m.module_code, m.module_name, c.course_id, c.course_name, m.year, m.semester 
                         FROM modules m
                         LEFT JOIN courses c ON m.course_id = c.course_id
                         WHERE m.module_id = ? AND m.teacher_id = ? AND m.deleted = 0");
$check->bind_param("ii", $module_id, $teacher_id);
$check->execute();
$module_result = $check->get_result();

if($module_result->num_rows == 0){
    die("❌ Unauthorized: This module is not assigned to you");
}

$module = $module_result->fetch_assoc();
$check->close();

// Get enrolled students with attendance data
$students_query = "
    SELECT 
        s.student_id,
        s.reg_number,
        s.name,
        COUNT(DISTINCT ba.attendance_date) as classes_present,
        (SELECT COUNT(DISTINCT attendance_date) FROM biometric_attendance 
         WHERE student_id = s.student_id) as total_attendance_records
    FROM module_enrollments me
    INNER JOIN students s ON me.student_id = s.student_id
    LEFT JOIN biometric_attendance ba ON s.student_id = ba.student_id AND ba.status IN ('present', 'late')
    WHERE me.module_id = ? AND s.deleted = 0 AND s.status = 'active'
    GROUP BY s.student_id, s.reg_number, s.name
    ORDER BY s.name ASC
";

$students_stmt = $conn->prepare($students_query);
$students_stmt->bind_param("i", $module_id);
$students_stmt->execute();
$students = $students_stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$students_stmt->close();

// Calculate total classes held
$classes_query = "SELECT COUNT(DISTINCT attendance_date) as total_classes FROM biometric_attendance";
$classes_stmt = $conn->prepare($classes_query);
$classes_stmt->execute();
$classes_result = $classes_stmt->get_result()->fetch_assoc();
$total_classes = $classes_result['total_classes'] ?? 0;
$classes_stmt->close();

?>

<!DOCTYPE html>
<html>
<head>
    <title>Attendance Report - <?= htmlspecialchars($module['module_code']) ?></title>
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
        }

        .module-info {
            background: linear-gradient(135deg, var(--terra-rosa), var(--honey-glow));
            color: white;
            padding: 20px;
            border-radius: 10px;
            margin-bottom: 20px;
        }

        .module-info strong {
            display: block;
            font-size: 18px;
            margin-bottom: 5px;
        }

        .stats {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 15px;
            margin: 20px 0;
        }

        .stat-card {
            background: linear-gradient(135deg, var(--minty-fresh), #a8d5ba);
            color: var(--art-craft);
            padding: 20px;
            border-radius: 8px;
            text-align: center;
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
            margin-top: 20px;
        }

        th, td {
            padding: 12px;
            border: 1px solid #ddd;
            text-align: left;
        }

        th {
            background: var(--minty-fresh);
            color: var(--art-craft);
            font-weight: bold;
        }

        tr:nth-child(even) {
            background: #f9f9f9;
        }

        tr:hover {
            background: #f0f0f0;
        }

        .percentage {
            font-weight: bold;
            text-align: center;
        }

        .eligible {
            color: #4CAF50;
        }

        .not-eligible {
            color: #f44336;
        }

        .badge {
            display: inline-block;
            padding: 4px 12px;
            border-radius: 12px;
            font-size: 12px;
            font-weight: bold;
            text-align: center;
        }

        .badge-eligible {
            background: #d4edda;
            color: #155724;
        }

        .badge-not-eligible {
            background: #f8d7da;
            color: #721c24;
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
                padding: 8px;
            }
        }
    </style>
</head>
<body>

<div class="auth-card">
    <h2>📊 Attendance & Exam Eligibility Report</h2>

    <div class="module-info">
        <strong><?= htmlspecialchars($module['module_code']) ?> - <?= htmlspecialchars($module['module_name']) ?></strong>
        <small><?= htmlspecialchars($module['course_name']) ?> | Year <?= $module['year'] ?> | Semester <?= $module['semester'] ?></small>
    </div>

    <div class="stats">
        <div class="stat-card">
            <div class="stat-number"><?= count($students) ?></div>
            <div class="stat-label">Total Students Enrolled</div>
        </div>
        <div class="stat-card">
            <div class="stat-number"><?= $total_classes ?></div>
            <div class="stat-label">Total Classes Held</div>
        </div>
        <div class="stat-card">
            <div class="stat-number">
                <?php 
                    $eligible_count = 0;
                    foreach ($students as $s) {
                        $percentage = $total_classes > 0 ? ($s['classes_present'] / $total_classes) * 100 : 0;
                        if ($percentage >= 60) $eligible_count++;
                    }
                    echo $eligible_count;
                ?>
            </div>
            <div class="stat-label">Students Eligible (60%+)</div>
        </div>
    </div>

    <h3>Student Attendance Details</h3>

    <?php if (count($students) > 0): ?>
        <table>
            <thead>
                <tr>
                    <th>Reg Number</th>
                    <th>Student Name</th>
                    <th>Classes Present</th>
                    <th>Total Classes</th>
                    <th>Attendance %</th>
                    <th>Exam Eligible?</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($students as $student): 
                    $present = $student['classes_present'] ?? 0;
                    $percentage = $total_classes > 0 ? round(($present / $total_classes) * 100, 2) : 0;
                    $is_eligible = $percentage >= 60;
                    $badge_class = $is_eligible ? 'badge-eligible' : 'badge-not-eligible';
                    $badge_text = $is_eligible ? '✓ Eligible' : '❌ Not Eligible';
                ?>
                <tr>
                    <td><strong><?= htmlspecialchars($student['reg_number']) ?></strong></td>
                    <td><?= htmlspecialchars($student['name']) ?></td>
                    <td><?= $present ?></td>
                    <td><?= $total_classes ?></td>
                    <td class="percentage <?= $is_eligible ? 'eligible' : 'not-eligible' ?>"><?= $percentage ?>%</td>
                    <td><span class="badge <?= $badge_class ?>"><?= $badge_text ?></span></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    <?php else: ?>
        <div class="no-data">
            <p>❌ No students are enrolled in this module</p>
        </div>
    <?php endif; ?>

    <div class="back-link">
        <a href="dashboard.php">← Back to Dashboard</a>
    </div>
</div>

</body>
</html>