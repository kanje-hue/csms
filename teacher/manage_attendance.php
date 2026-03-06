<?php
/**
 * teacher/manage_attendance.php - Manage Attendance Records
 * Upload CSV, calculate eligibility, and publish attendance
 */

session_start();
require_once '../config/db.php';
require_once '../config/security.php';
require_once '../config/security_base.php';
require_once '../config/email_config.php';

// Check teacher login
if (!isset($_SESSION['teacher_logged_in']) || !isset($_SESSION['teacher_id'])) {
    header("Location: login.php");
    exit();
}

$teacher_id = $_SESSION['teacher_id'];
$teacher_name = $_SESSION['teacher_name'] ?? 'Teacher';
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

$message = "";
$message_type = "";
$csrf_token = generateCSRF();

// Handle CSV Upload
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'upload_csv') {
    validateCSRF($_POST['csrf_token'] ?? '');
    
    if (!isset($_FILES['csv_file']) || $_FILES['csv_file']['error'] !== UPLOAD_ERR_OK) {
        $message = "Error uploading file";
        $message_type = "error";
    } else {
        $file = $_FILES['csv_file'];
        $file_ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
        
        if ($file_ext !== 'csv') {
            $message = "Only CSV files are allowed";
            $message_type = "error";
        } else {
            $handle = fopen($file['tmp_name'], 'r');
            $row_count = 0;
            $success_count = 0;
            $errors = [];
            
            // Get total classes count from CSV header or calculate
            $total_classes = 0;
            
            while (($data = fgetcsv($handle, 1000, ",")) !== FALSE) {
                $row_count++;
                
                // Skip header row
                if ($row_count === 1) {
                    // Check if first row contains headers
                    if (strtolower($data[0]) == 'reg_number' || strtolower($data[0]) == 'reg_no') {
                        continue;
                    }
                    // Try to determine total classes from first data row
                    if (count($data) >= 3 && is_numeric($data[2])) {
                        $total_classes = (int)$data[2];
                    }
                }
                
                if (count($data) < 2) {
                    $errors[] = "Row $row_count: Invalid format (need at least Reg_Number and Attended)";
                    continue;
                }
                
                $reg_number = trim($data[0]);
                $classes_attended = (int)$data[1];
                $classes_total = isset($data[2]) ? (int)$data[2] : $total_classes;
                
                // If total classes not provided, try to get from existing record or use default
                if ($classes_total === 0) {
                    // Check if we already have a record for this student
                    $check_total = $conn->prepare("
                        SELECT total_classes FROM attendance a
                        JOIN students s ON a.student_id = s.student_id
                        WHERE s.reg_number = ? AND a.module_id = ?
                    ");
                    $check_total->bind_param("si", $reg_number, $module_id);
                    $check_total->execute();
                    $existing = $check_total->get_result()->fetch_assoc();
                    $check_total->close();
                    
                    if ($existing && $existing['total_classes'] > 0) {
                        $classes_total = $existing['total_classes'];
                    } else {
                        $classes_total = 30; // Default to 30 classes
                    }
                }
                
                // Validate data
                if ($classes_attended < 0 || $classes_attended > $classes_total) {
                    $errors[] = "Row $row_count: Attended classes must be between 0 and $classes_total";
                    continue;
                }
                
                // Get student ID
                $student_stmt = $conn->prepare("
                    SELECT s.student_id 
                    FROM students s
                    JOIN module_enrollments me ON s.student_id = me.student_id
                    WHERE s.reg_number = ? AND me.module_id = ? AND s.deleted = 0
                ");
                $student_stmt->bind_param("si", $reg_number, $module_id);
                $student_stmt->execute();
                $student_result = $student_stmt->get_result();
                
                if ($student_result->num_rows > 0) {
                    $student = $student_result->fetch_assoc();
                    $student_id = $student['student_id'];
                    
                    // Calculate percentage and eligibility
                    $percentage = ($classes_attended / $classes_total) * 100;
                    $is_eligible = ($percentage >= 60) ? 1 : 0;
                    
                    // Insert or update attendance
                    $insert_stmt = $conn->prepare("
                        INSERT INTO attendance 
                        (student_id, module_id, total_classes, attended_classes, attendance_percentage, is_eligible, status, last_updated)
                        VALUES (?, ?, ?, ?, ?, ?, 'draft', NOW())
                        ON DUPLICATE KEY UPDATE 
                            total_classes = VALUES(total_classes),
                            attended_classes = VALUES(attended_classes),
                            attendance_percentage = VALUES(attendance_percentage),
                            is_eligible = VALUES(is_eligible),
                            last_updated = NOW()
                    ");
                    $insert_stmt->bind_param("iiiidi", 
                        $student_id, $module_id, $classes_total, $classes_attended, 
                        $percentage, $is_eligible
                    );
                    
                    if ($insert_stmt->execute()) {
                        $success_count++;
                    } else {
                        $errors[] = "Row $row_count: Database error";
                    }
                    $insert_stmt->close();
                } else {
                    $errors[] = "Row $row_count: Student $reg_number not found in this module";
                }
                $student_stmt->close();
            }
            
            fclose($handle);
            
            if ($success_count > 0) {
                $message = "✓ Successfully uploaded attendance for $success_count students!";
                if (!empty($errors)) {
                    $message .= " (" . count($errors) . " errors - check logs)";
                }
                $message_type = "success";
                
                // Log the action
                logAdminAction($conn, $teacher_id, 'upload_attendance', 
                    "Uploaded attendance for module {$module['module_code']}, $success_count students");
            } else {
                $message = "No attendance records were uploaded. Please check your CSV format.";
                $message_type = "error";
            }
        }
    }
}

// Handle Publish Attendance
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'publish') {
    validateCSRF($_POST['csrf_token'] ?? '');
    
    // Update all attendance for this module to published
    $stmt = $conn->prepare("UPDATE attendance SET status = 'published' WHERE module_id = ?");
    $stmt->bind_param("i", $module_id);
    
    if ($stmt->execute()) {
        $affected = $stmt->affected_rows;
        
        // Get module details
        $module_info = $conn->prepare("
            SELECT module_code, module_name FROM modules WHERE module_id = ?
        ");
        $module_info->bind_param("i", $module_id);
        $module_info->execute();
        $module_data = $module_info->get_result()->fetch_assoc();
        $module_info->close();
        
        // Get all students in this module
        $students = $conn->prepare("
            SELECT s.student_id, s.name, s.email, a.is_eligible
            FROM students s
            JOIN module_enrollments me ON s.student_id = me.student_id
            LEFT JOIN attendance a ON a.student_id = s.student_id AND a.module_id = ?
            WHERE me.module_id = ? AND s.status = 'active'
        ");
        $students->bind_param("ii", $module_id, $module_id);
        $students->execute();
        $student_list = $students->get_result()->fetch_all(MYSQLI_ASSOC);
        $students->close();
        
        // Create notifications for each student
        foreach ($student_list as $student) {
            $eligible_text = $student['is_eligible'] ? 'eligible' : 'not eligible';
            $notif_msg = "Your attendance report for {$module_data['module_code']} has been published. You are **{$eligible_text}** for the exam.";
            
            $notif = $conn->prepare("
                INSERT INTO notifications (user_type, user_id, module_id, type, message, status, created_at)
                VALUES ('student', ?, ?, 'attendance_published', ?, 'unread', NOW())
            ");
            $notif->bind_param("iis", $student['student_id'], $module_id, $notif_msg);
            $notif->execute();
            $notif->close();
            
            // Send email notification
            if (!empty($student['email'])) {
                $email_subject = "📊 Attendance Report Published - {$module_data['module_code']}";
                $eligibility_class = $student['is_eligible'] ? 'eligible' : 'not-eligible';
                $eligibility_text = $student['is_eligible'] ? '✓ You ARE eligible' : '❌ You are NOT eligible';
                
                $email_body = "
                <html>
                <head>
                    <style>
                        body { font-family: Arial, sans-serif; }
                        .container { max-width: 600px; margin: 0 auto; padding: 20px; }
                        .header { background: #8b5cf6; color: white; padding: 20px; text-align: center; border-radius: 10px 10px 0 0; }
                        .content { background: #f9f9f9; padding: 30px; border-radius: 0 0 10px 10px; }
                        .eligible { background: #d1fae5; color: #065f46; padding: 15px; border-radius: 8px; margin: 20px 0; }
                        .not-eligible { background: #fee2e2; color: #991b1b; padding: 15px; border-radius: 8px; margin: 20px 0; }
                        .btn { background: #8b5cf6; color: white; padding: 10px 20px; text-decoration: none; border-radius: 5px; display: inline-block; }
                    </style>
                </head>
                <body>
                    <div class='container'>
                        <div class='header'>
                            <h1>Attendance Report Published</h1>
                        </div>
                        <div class='content'>
                            <p>Hello <strong>{$student['name']}</strong>,</p>
                            <p>Your attendance report for <strong>{$module_data['module_code']} - {$module_data['module_name']}</strong> has been published.</p>
                            
                            <div class='{$eligibility_class}'>
                                <h3>Exam Eligibility: {$eligibility_text}</h3>
                                <p>Students with ≥60% attendance are eligible to sit for the exam.</p>
                            </div>
                            
                            <p style='text-align: center;'>
                                <a href='http://localhost/csms/student/dashboard.php' class='btn'>View Dashboard</a>
                            </p>
                        </div>
                    </div>
                </body>
                </html>
                ";
                send_email($student['email'], $student['name'], $email_subject, $email_body);
            }
        }
        
        $eligible_count = count(array_filter($student_list, fn($s) => $s['is_eligible']));
        
        $message = "✓ Attendance published for {$module_data['module_code']}. $affected records updated.<br>";
        $message .= "📊 Eligible for exam: $eligible_count students";
        $message_type = "success";
        
        // Log the action
        logAdminAction($conn, $teacher_id, 'publish_attendance', 
            "Published attendance for module {$module['module_code']}, $eligible_count eligible");
    }
    $stmt->close();
}

// Handle Unpublish Attendance
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'unpublish') {
    validateCSRF($_POST['csrf_token'] ?? '');
    
    $stmt = $conn->prepare("UPDATE attendance SET status = 'draft' WHERE module_id = ?");
    $stmt->bind_param("i", $module_id);
    
    if ($stmt->execute()) {
        $message = "✓ Attendance unpublished";
        $message_type = "success";
        
        // Log the action
        logAdminAction($conn, $teacher_id, 'unpublish_attendance', 
            "Unpublished attendance for module {$module['module_code']}");
    }
    $stmt->close();
}

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
$has_attendance = $students_with_attendance > 0;

// Calculate average attendance
$avg_percentage = 0;
if ($students_with_attendance > 0) {
    $total_percentage = array_sum(array_column(array_filter($attendance_records, fn($a) => $a['attendance_percentage'] !== null), 'attendance_percentage'));
    $avg_percentage = round($total_percentage / $students_with_attendance, 1);
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Manage Attendance - <?= htmlspecialchars($module['module_code']) ?></title>
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
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
        }

        .module-details h2 {
            font-size: 1.8rem;
            margin-bottom: 0.5rem;
        }

        .module-details p {
            color: #94a3b8;
        }

        .module-stats {
            text-align: right;
        }

        .alert {
            padding: 1rem;
            border-radius: 8px;
            margin-bottom: 1.5rem;
        }

        .alert.success {
            background: #d1fae5;
            color: #065f46;
            border-left: 4px solid #10b981;
        }

        .alert.error {
            background: #fee2e2;
            color: #991b1b;
            border-left: 4px solid #ef4444;
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
            border-left: 4px solid #8b5cf6;
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

        .tabs {
            display: flex;
            gap: 1rem;
            margin-bottom: 2rem;
            border-bottom: 2px solid #e2e8f0;
        }

        .tab-btn {
            padding: 0.8rem 1.5rem;
            background: none;
            border: none;
            cursor: pointer;
            font-weight: 600;
            color: #64748b;
            border-bottom: 3px solid transparent;
            transition: all 0.3s;
        }

        .tab-btn.active {
            color: #8b5cf6;
            border-bottom-color: #8b5cf6;
        }

        .tab-content {
            display: none;
        }

        .tab-content.active {
            display: block;
        }

        .upload-card {
            background: white;
            border-radius: 20px;
            padding: 2rem;
            box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.1);
        }

        .form-group {
            margin-bottom: 1.5rem;
        }

        .form-group label {
            display: block;
            font-weight: 600;
            margin-bottom: 0.5rem;
            color: #0f172a;
        }

        .form-group input,
        .form-group select {
            width: 100%;
            padding: 0.8rem;
            border: 2px solid #e2e8f0;
            border-radius: 8px;
            font-size: 1rem;
        }

        .btn {
            padding: 0.8rem 1.5rem;
            border: none;
            border-radius: 8px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s;
            text-decoration: none;
            display: inline-block;
        }

        .btn-primary {
            background: #2dd4bf;
            color: white;
        }

        .btn-primary:hover {
            background: #14b8a6;
            transform: translateY(-2px);
        }

        .btn-success {
            background: #10b981;
            color: white;
        }

        .btn-success:hover {
            background: #059669;
        }

        .btn-warning {
            background: #f59e0b;
            color: white;
        }

        .btn-attendance {
            background: #8b5cf6;
            color: white;
        }

        .btn-attendance:hover {
            background: #7c3aed;
        }

        .btn-secondary {
            background: #e2e8f0;
            color: #0f172a;
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

        .action-buttons {
            display: flex;
            gap: 1rem;
            margin: 2rem 0;
            flex-wrap: wrap;
        }

        .template-info {
            background: #f8fafc;
            padding: 1.5rem;
            border-radius: 8px;
            margin: 1.5rem 0;
            font-family: monospace;
        }

        .back-link {
            margin-top: 2rem;
            text-align: center;
        }

        .eligibility-summary {
            background: white;
            border-radius: 16px;
            padding: 1.5rem;
            margin-bottom: 2rem;
            box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.1);
        }

        .eligibility-grid {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 1rem;
            margin-top: 1rem;
        }

        .eligibility-box {
            padding: 1.5rem;
            border-radius: 12px;
            text-align: center;
        }

        .eligibility-box.eligible-box {
            background: #d1fae5;
        }

        .eligibility-box.not-eligible-box {
            background: #fee2e2;
        }

        .eligibility-number {
            font-size: 2.5rem;
            font-weight: 700;
        }

        @media (max-width: 768px) {
            .container {
                padding: 0 1rem;
            }

            .tabs {
                flex-direction: column;
            }

            .action-buttons {
                flex-direction: column;
            }

            table {
                font-size: 0.8rem;
            }

            td, th {
                padding: 0.5rem;
            }

            .eligibility-grid {
                grid-template-columns: 1fr;
            }
        }
    </style>
</head>
<body>

<div class="header">
    <h1>CSMS Teacher</h1>
    <a href="dashboard.php">← Dashboard</a>
    <a href="logout.php">🚪 Logout</a>
</div>

<div class="container">
    <!-- Module Info -->
    <div class="module-info">
        <div class="module-details">
            <h2><?= htmlspecialchars($module['module_code']) ?> - <?= htmlspecialchars($module['module_name']) ?></h2>
            <p><?= htmlspecialchars($module['course_name']) ?> | Year <?= $module['year'] ?> | Semester <?= $module['semester'] ?></p>
        </div>
        <div class="module-stats">
            <div style="font-size: 2rem; font-weight: 700; color: #8b5cf6;"><?= $total_students ?></div>
            <div>Enrolled Students</div>
        </div>
    </div>

    <?php if ($message): ?>
        <div class="alert <?= $message_type ?>"><?= $message ?></div>
    <?php endif; ?>

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
            <div class="stat-number"><?= $eligible_count ?></div>
            <div class="stat-label">Exam Eligible</div>
        </div>
        <div class="stat-card">
            <div class="stat-number"><?= $total_students - $eligible_count ?></div>
            <div class="stat-label">Not Eligible</div>
        </div>
        <div class="stat-card">
            <div class="stat-number"><?= $avg_percentage ?>%</div>
            <div class="stat-label">Avg Attendance</div>
        </div>
    </div>

    <!-- Eligibility Summary -->
    <?php if ($has_attendance): ?>
    <div class="eligibility-summary">
        <h3 style="margin-bottom: 1rem;">📊 Exam Eligibility Summary</h3>
        <div class="eligibility-grid">
            <div class="eligibility-box eligible-box">
                <div class="eligibility-number"><?= $eligible_count ?></div>
                <div>Students Eligible (≥60%)</div>
            </div>
            <div class="eligibility-box not-eligible-box">
                <div class="eligibility-number"><?= $total_students - $eligible_count ?></div>
                <div>Students Not Eligible</div>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <!-- Action Buttons -->
    <div class="action-buttons">
        <?php if ($has_attendance): ?>
            <?php if (!$published): ?>
            <form method="POST" style="display: inline;">
                <input type="hidden" name="csrf_token" value="<?= $csrf_token ?>">
                <input type="hidden" name="action" value="publish">
                <button type="submit" class="btn btn-success" onclick="return confirm('Publish attendance for all students? They will be notified via email.')">
                    📤 Publish Attendance
                </button>
            </form>
            <?php else: ?>
            <form method="POST" style="display: inline;">
                <input type="hidden" name="csrf_token" value="<?= $csrf_token ?>">
                <input type="hidden" name="action" value="unpublish">
                <button type="submit" class="btn btn-warning" onclick="return confirm('Unpublish attendance?')">
                    📥 Unpublish Attendance
                </button>
            </form>
            <?php endif; ?>
        <?php endif; ?>
        <a href="view_attendance.php?module_id=<?= $module_id ?>" class="btn btn-attendance">📊 View Full Report</a>
    </div>

    <!-- Tabs -->
    <div class="tabs">
        <button class="tab-btn active" onclick="switchTab(event, 'upload')">📤 Upload CSV</button>
        <button class="tab-btn" onclick="switchTab(event, 'view')">👁️ View Attendance</button>
    </div>

    <!-- Upload CSV Tab -->
    <div id="upload" class="tab-content active">
        <div class="upload-card">
            <h3 style="margin-bottom: 1.5rem;">📤 Upload Attendance via CSV</h3>

            <div class="template-info">
                <strong>CSV Format:</strong><br>
                Reg_Number,Attended_Classes,Total_Classes<br>
                STU001,25,30<br>
                STU002,18,30<br>
                STU003,28,30<br><br>
                
                <strong>Notes:</strong><br>
                • Total_Classes is optional (if omitted, system uses 30 or previous value)<br>
                • Students with ≥60% attendance are automatically marked eligible<br>
                • You can upload multiple times - latest data will be updated
            </div>

            <form method="POST" enctype="multipart/form-data">
                <input type="hidden" name="csrf_token" value="<?= $csrf_token ?>">
                <input type="hidden" name="action" value="upload_csv">

                <div class="form-group">
                    <label for="csv_file">Choose CSV File</label>
                    <input type="file" id="csv_file" name="csv_file" accept=".csv" required>
                </div>

                <button type="submit" class="btn btn-primary">📤 Upload and Process</button>
                <a href="download_attendance_template.php?module_id=<?= $module_id ?>" class="btn btn-secondary" style="margin-left: 1rem;">📥 Download Template</a>
            </form>
        </div>
    </div>

    <!-- View Attendance Tab -->
    <div id="view" class="tab-content">
        <div class="upload-card">
            <h3 style="margin-bottom: 1.5rem;">👁️ Student Attendance Records</h3>

            <?php if (count($attendance_records) > 0): ?>
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
            <?php else: ?>
                <p style="text-align: center; padding: 2rem; color: #64748b;">No students enrolled in this module</p>
            <?php endif; ?>
        </div>
    </div>

    <div class="back-link">
        <a href="dashboard.php">← Back to Dashboard</a>
    </div>
</div>

<script>
function switchTab(event, tabId) {
    // Hide all tabs
    document.querySelectorAll('.tab-content').forEach(tab => {
        tab.classList.remove('active');
    });
    
    // Remove active from all buttons
    document.querySelectorAll('.tab-btn').forEach(btn => {
        btn.classList.remove('active');
    });
    
    // Show selected tab
    document.getElementById(tabId).classList.add('active');
    event.target.classList.add('active');
}
</script>

</body>
</html>