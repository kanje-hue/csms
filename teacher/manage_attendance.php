<?php
session_start();
include '../config/db.php';

if(!isset($_SESSION['teacher_id'])){
    header("Location: login.php");
    exit();
}

$teacher_id = $_SESSION['teacher_id'];
$teacher_name = $_SESSION['teacher_name'] ?? 'Teacher';

$module_id = isset($_GET['module_id']) ? (int)$_GET['module_id'] : null;

if(!$module_id){
    die("Module not specified");
}

// Verify teacher owns this module
$check = $conn->prepare("SELECT m.module_id, m.module_code, m.module_name, c.course_name, m.year, m.semester 
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

// Get enrolled students - SIMPLE QUERY
$students_query = "
    SELECT 
        s.student_id,
        s.reg_number,
        s.name,
        s.email,
        me.enrolled_at
    FROM module_enrollments me
    INNER JOIN students s ON me.student_id = s.student_id
    WHERE me.module_id = ? AND s.deleted = 0 AND s.status = 'active'
    ORDER BY s.name ASC
";

$students_stmt = $conn->prepare($students_query);
if(!$students_stmt) {
    die("Query error: " . $conn->error);
}
$students_stmt->bind_param("i", $module_id);
$students_stmt->execute();
$students_result = $students_stmt->get_result();
$students = $students_result->fetch_all(MYSQLI_ASSOC);
$students_stmt->close();

$message = "";
$message_type = "";

?>

<!DOCTYPE html>
<html>
<head>
    <title>Manage Attendance - <?= htmlspecialchars($module['module_code']) ?></title>
    <link rel="stylesheet" href="../assets/css/auth.css">
    <style>
        .auth-card {
            width: 1100px;
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
            margin-bottom: 10px;
        }

        .module-info {
            background: linear-gradient(135deg, var(--terra-rosa), var(--honey-glow));
            color: white;
            padding: 20px;
            border-radius: 10px;
            margin-bottom: 20px;
            text-align: center;
        }

        .module-info strong {
            display: block;
            font-size: 20px;
            margin-bottom: 10px;
        }

        .module-info small {
            display: block;
            opacity: 0.9;
        }

        .alert {
            padding: 12px;
            margin: 15px 0;
            border-radius: 8px;
            display: none;
        }

        .alert.success {
            background: #d4edda;
            color: #155724;
            display: block;
            border: 1px solid #c3e6cb;
        }

        .alert.error {
            background: #f8d7da;
            color: #721c24;
            display: block;
            border: 1px solid #f5c6cb;
        }

        .student-count {
            text-align: center;
            padding: 15px;
            background: #f9f9f9;
            border-radius: 8px;
            margin-bottom: 20px;
            font-size: 16px;
            font-weight: bold;
            color: var(--midnight-garden);
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

        .back-link {
            text-align: center;
            margin-top: 20px;
        }

        .back-link a {
            color: var(--terra-rosa);
            text-decoration: none;
            font-weight: bold;
            font-size: 16px;
        }

        .no-data {
            text-align: center;
            padding: 40px;
            color: #666;
            background: #f9f9f9;
            border-radius: 8px;
            margin: 20px 0;
        }

        .info-box {
            background: #e3f2fd;
            border-left: 4px solid #2196F3;
            padding: 15px;
            border-radius: 4px;
            margin: 20px 0;
            color: #004085;
        }

        @media (max-width: 768px) {
            .auth-card {
                width: 95%;
                padding: 15px;
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
    <h2>📋 Student List</h2>

    <div class="module-info">
        <strong><?= htmlspecialchars($module['module_code']) ?> - <?= htmlspecialchars($module['module_name']) ?></strong>
        <small><?= htmlspecialchars($module['course_name']) ?> | Year <?= $module['year'] ?> | Semester <?= $module['semester'] ?></small>
    </div>

    <?php if ($message): ?>
        <div class="alert <?= $message_type === 'success' ? 'success' : 'error' ?>">
            <?= htmlspecialchars($message) ?>
        </div>
    <?php endif; ?>

    <?php if (count($students) > 0): ?>
        <div class="student-count">
            📊 Total Enrolled Students: <strong><?= count($students) ?></strong>
        </div>

        <div class="info-box">
            ℹ️ These are all the students enrolled in this module for this course/year/semester.
        </div>

        <table>
            <thead>
                <tr>
                    <th>#</th>
                    <th>Reg Number</th>
                    <th>Student Name</th>
                    <th>Email</th>
                    <th>Enrollment Date</th>
                </tr>
            </thead>
            <tbody>
                <?php 
                $count = 1;
                foreach ($students as $student): 
                ?>
                <tr>
                    <td><?= $count++ ?></td>
                    <td><strong><?= htmlspecialchars($student['reg_number']) ?></strong></td>
                    <td><?= htmlspecialchars($student['name']) ?></td>
                    <td><?= htmlspecialchars($student['email']) ?></td>
                    <td><?= date('M d, Y', strtotime($student['enrolled_at'])) ?></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>

    <?php else: ?>
        <div class="no-data">
            <p>❌ No students are enrolled in this module yet.</p>
            <p>Students will appear here once the admin activates them for this course/year/semester combination.</p>
        </div>
    <?php endif; ?>

    <div class="back-link">
        <a href="dashboard.php">← Back to Dashboard</a>
    </div>
</div>

</body>
</html>