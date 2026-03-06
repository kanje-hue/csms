<?php
/**
 * student/dashboard.php - Student Dashboard
 * Shows results from ALL years and semesters
 */

session_start();
require_once '../config/db.php';
require_once '../config/security_base.php';

if (!isset($_SESSION['student_logged_in']) || !isset($_SESSION['student_id'])) {
    header("Location: login.php");
    exit();
}

// Session timeout (1 hour)
if (isset($_SESSION['last_activity']) && (time() - $_SESSION['last_activity'] > 3600)) {
    session_destroy();
    header("Location: login.php?timeout=1");
    exit();
}
$_SESSION['last_activity'] = time();

$student_id = $_SESSION['student_id'];
$student_name = $_SESSION['student_name'] ?? 'Student';

// Get student info
$student_stmt = $conn->prepare("SELECT student_id, reg_number, name, email, course_id, year, semester, status, photo FROM students WHERE student_id = ? AND deleted = 0");
$student_stmt->bind_param("i", $student_id);
$student_stmt->execute();
$student = $student_stmt->get_result()->fetch_assoc();
$student_stmt->close();

if (!$student || $student['status'] !== 'active') {
    session_destroy();
    header("Location: login.php?inactive=1");
    exit();
}

$course_id = $student['course_id'];
$current_year = $student['year'];
$current_semester = $student['semester'];
$student_photo = $student['photo'] ?? 'default-avatar.png';

// Get course name
$course_stmt = $conn->prepare("SELECT course_name FROM courses WHERE course_id = ? AND deleted = 0");
$course_stmt->bind_param("i", $course_id);
$course_stmt->execute();
$course = $course_stmt->get_result()->fetch_assoc();
$course_stmt->close();
$course_name = $course ? $course['course_name'] : 'Unknown Course';

// ================= MARK NOTIFICATIONS AS READ =================
if (isset($_GET['mark_read'])) {
    $notif_id = (int)$_GET['mark_read'];
    $stmt = $conn->prepare("UPDATE notifications SET status = 'read' WHERE id = ? AND user_id = ? AND user_type = 'student'");
    $stmt->bind_param("ii", $notif_id, $student_id);
    $stmt->execute();
    $stmt->close();
    header("Location: dashboard.php");
    exit();
}

if (isset($_GET['mark_all_read'])) {
    $stmt = $conn->prepare("UPDATE notifications SET status = 'read' WHERE user_id = ? AND user_type = 'student' AND status = 'unread'");
    $stmt->bind_param("i", $student_id);
    $stmt->execute();
    $stmt->close();
    header("Location: dashboard.php");
    exit();
}

// ================= GET NOTIFICATIONS =================
$notif_stmt = $conn->prepare("
    SELECT n.*, m.module_code, m.module_name
    FROM notifications n
    LEFT JOIN modules m ON n.module_id = m.module_id
    WHERE n.user_id = ? AND n.user_type = 'student'
    ORDER BY n.created_at DESC
    LIMIT 20
");
$notif_stmt->bind_param("i", $student_id);
$notif_stmt->execute();
$notifications = $notif_stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$notif_stmt->close();

$unread_count = count(array_filter($notifications, fn($n) => $n['status'] === 'unread'));

// ================= GET MODULES FOR CURRENT YEAR =================
$modules_query = "
    SELECT 
        m.module_id,
        m.module_code,
        m.module_name,
        m.year,
        m.semester,
        t.fullname AS teacher_name
    FROM module_enrollments me
    JOIN modules m ON me.module_id = m.module_id
    LEFT JOIN teachers t ON m.teacher_id = t.teacher_id
    WHERE me.student_id = ? AND m.deleted = 0
    ORDER BY m.year, m.semester, m.module_code
";

$modules_stmt = $conn->prepare($modules_query);
$modules_stmt->bind_param("i", $student_id);
$modules_stmt->execute();
$modules = $modules_stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$modules_stmt->close();

// Group modules by year/semester
$grouped_modules = [];
foreach ($modules as $m) {
    $key = "Year {$m['year']} - Semester {$m['semester']}";
    if (!isset($grouped_modules[$key])) {
        $grouped_modules[$key] = [];
    }
    $grouped_modules[$key][] = $m;
}

// ================= GET ALL RESULTS FROM ALL YEARS =================
$all_results_query = "
    SELECT 
        r.*,
        m.module_code,
        m.module_name,
        m.year,
        m.semester
    FROM results r
    JOIN modules m ON r.module_id = m.module_id
    WHERE r.student_id = ? AND m.deleted = 0
    ORDER BY m.year DESC, m.semester DESC, m.module_code
";

$all_results_stmt = $conn->prepare($all_results_query);
$all_results_stmt->bind_param("i", $student_id);
$all_results_stmt->execute();
$all_results = $all_results_stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$all_results_stmt->close();

// Process results to add supplementary status
foreach ($all_results as &$r) {
    $ca_marks = $r['ca_marks'] ?? 0;
    $final_marks = $r['final_marks'] ?? 0;
    $total = $ca_marks + $final_marks;
    
    // Supplementary conditions:
    $ca_half = 30;
    $final_half = 20;
    $total_half = 50;
    
    $is_supplementary = ($ca_marks < $ca_half) || ($final_marks < $final_half) || ($total < $total_half);
    $r['status_display'] = $is_supplementary ? 'Supplementary' : 'Pass';
    $r['status_class'] = $is_supplementary ? 'badge-supplementary' : 'badge-pass';
    $r['total'] = $total;
}

// Group results by year
$results_by_year = [];
foreach ($all_results as $r) {
    $year = $r['year'];
    if (!isset($results_by_year[$year])) {
        $results_by_year[$year] = [];
    }
    $results_by_year[$year][] = $r;
}

// Separate published and draft
$published_results = array_filter($all_results, fn($r) => $r['status'] === 'published');
$draft_results = array_filter($all_results, fn($r) => $r['status'] === 'draft');

// ================= GET ATTENDANCE FROM ALL YEARS =================
$attendance_query = "
    SELECT 
        a.*,
        m.module_code,
        m.module_name,
        m.year
    FROM attendance a
    JOIN modules m ON a.module_id = m.module_id
    WHERE a.student_id = ? AND a.status = 'published'
    ORDER BY m.year, m.module_code
";

$attendance_stmt = $conn->prepare($attendance_query);
$attendance_stmt->bind_param("i", $student_id);
$attendance_stmt->execute();
$attendance_records = $attendance_stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$attendance_stmt->close();

// Group attendance by year
$attendance_by_year = [];
foreach ($attendance_records as $a) {
    $year = $a['year'];
    if (!isset($attendance_by_year[$year])) {
        $attendance_by_year[$year] = [];
    }
    $attendance_by_year[$year][] = $a;
}

// ================= GET ATTENDANCE NOTIFICATIONS =================
$attendance_notif_query = "
    SELECT n.*, m.module_code, m.module_name
    FROM notifications n
    LEFT JOIN modules m ON n.module_id = m.module_id
    WHERE n.user_id = ? AND n.user_type = 'student' AND n.type = 'attendance_published' AND n.status = 'unread'
    ORDER BY n.created_at DESC
";

$attendance_notif_stmt = $conn->prepare($attendance_notif_query);
$attendance_notif_stmt->bind_param("i", $student_id);
$attendance_notif_stmt->execute();
$attendance_notifications = $attendance_notif_stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$attendance_notif_stmt->close();

// ================= CALCULATE STATISTICS =================
$total_modules_all_years = count($modules);
$total_results_all_years = count($published_results);
$total_attendance_all_years = count($attendance_records);

// GPA Calculation - Across ALL years
$total_points = 0;
$total_credits = 0;
$grade_points = ['A' => 4.0, 'B' => 3.0, 'C' => 2.0, 'D' => 1.0, 'F' => 0.0];

foreach ($published_results as $r) {
    if (isset($grade_points[$r['grade']])) {
        $total_points += $grade_points[$r['grade']];
        $total_credits++;
    }
}
$cumulative_gpa = $total_credits > 0 ? round($total_points / $total_credits, 2) : 0;

// Calculate GPA by year
$gpa_by_year = [];
foreach ($results_by_year as $year => $results) {
    $year_points = 0;
    $year_credits = 0;
    foreach ($results as $r) {
        if ($r['status'] === 'published' && isset($grade_points[$r['grade']])) {
            $year_points += $grade_points[$r['grade']];
            $year_credits++;
        }
    }
    $gpa_by_year[$year] = $year_credits > 0 ? round($year_points / $year_credits, 2) : 0;
}

// Eligibility for exam
$eligible_count = count(array_filter($attendance_records, fn($a) => ($a['is_eligible'] ?? 0) == 1));
$not_eligible_count = count(array_filter($attendance_records, fn($a) => ($a['is_eligible'] ?? 0) == 0));

// Count passes and supplementary
$pass_count = count(array_filter($published_results, fn($r) => $r['status_display'] === 'Pass'));
$supplementary_count = count(array_filter($published_results, fn($r) => $r['status_display'] === 'Supplementary'));

// Handle photo upload
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['photo'])) {
    $target_dir = "../uploads/students/";
    if (!file_exists($target_dir)) {
        mkdir($target_dir, 0777, true);
    }
    
    $file_extension = strtolower(pathinfo($_FILES["photo"]["name"], PATHINFO_EXTENSION));
    $allowed_extensions = ['jpg', 'jpeg', 'png', 'gif'];
    
    if (in_array($file_extension, $allowed_extensions)) {
        $new_filename = "student_" . $student_id . "_" . time() . "." . $file_extension;
        $target_file = $target_dir . $new_filename;
        
        if (move_uploaded_file($_FILES["photo"]["tmp_name"], $target_file)) {
            // Delete old photo if not default
            if ($student['photo'] && $student['photo'] != 'default-avatar.png' && file_exists($target_dir . $student['photo'])) {
                unlink($target_dir . $student['photo']);
            }
            
            $update = $conn->prepare("UPDATE students SET photo = ? WHERE student_id = ?");
            $update->bind_param("si", $new_filename, $student_id);
            $update->execute();
            $update->close();
            
            $student_photo = $new_filename;
            $photo_message = "Photo uploaded successfully!";
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Student Dashboard - CSMS</title>
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
            box-shadow: 0 4px 6px -1px rgba(0,0,0,0.1);
            position: sticky;
            top: 0;
            z-index: 100;
            width: 100%;
        }
        .logo h1 {
            font-size: 1.8rem;
            font-weight: 700;
            background: linear-gradient(135deg, #16a085, #117a65);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            background-clip: text;
        }
        .student-info {
            display: flex;
            align-items: center;
            gap: 2rem;
        }
        .notif-badge {
            background: #16a085;
            color: white;
            padding: 0.2rem 0.6rem;
            border-radius: 20px;
            font-size: 0.8rem;
            font-weight: 600;
            margin-left: 0.5rem;
        }
        .logout-btn {
            background: rgba(255,255,255,0.1);
            color: white;
            padding: 0.5rem 1.2rem;
            border-radius: 8px;
            text-decoration: none;
            transition: all 0.3s;
        }
        .logout-btn:hover { background: #16a085; }
        .container {
            max-width: 1400px;
            margin: 2rem auto;
            padding: 0 2rem;
        }
        .profile-section {
            background: white;
            border-radius: 20px;
            padding: 2rem;
            margin-bottom: 2rem;
            box-shadow: 0 4px 6px -1px rgba(0,0,0,0.1);
            display: flex;
            align-items: center;
            gap: 2rem;
            flex-wrap: wrap;
        }
        .profile-photo {
            width: 120px;
            height: 120px;
            border-radius: 50%;
            object-fit: cover;
            border: 3px solid #16a085;
        }
        .profile-photo-placeholder {
            width: 120px;
            height: 120px;
            border-radius: 50%;
            background: #e2e8f0;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 3rem;
            color: #94a3b8;
            border: 3px solid #16a085;
        }
        .photo-upload {
            flex: 1;
        }
        .photo-upload form {
            display: flex;
            gap: 1rem;
            align-items: center;
            flex-wrap: wrap;
        }
        .btn-upload {
            background: #16a085;
            color: white;
            padding: 0.5rem 1rem;
            border: none;
            border-radius: 8px;
            cursor: pointer;
        }
        .welcome-banner {
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
        .student-details {
            background: rgba(255,255,255,0.1);
            padding: 0.8rem 1.5rem;
            border-radius: 10px;
            font-size: 0.9rem;
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
            border-left: 4px solid #16a085;
        }
        .stat-number { font-size: 2rem; font-weight: 700; color: #0f172a; }
        .stat-label { color: #64748b; font-size: 0.9rem; margin-top: 0.25rem; }
        .section-title {
            font-size: 1.5rem;
            font-weight: 600;
            color: #0f172a;
            margin: 2rem 0 1.5rem;
            padding-bottom: 0.75rem;
            border-bottom: 3px solid #16a085;
            width: 100%;
        }
        .year-title {
            font-size: 1.3rem;
            font-weight: 600;
            color: #0f172a;
            margin: 2rem 0 1rem;
            padding: 0.5rem 1rem;
            background: #e2e8f0;
            border-radius: 8px;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        .gpa-year {
            background: #16a085;
            color: white;
            padding: 0.25rem 1rem;
            border-radius: 20px;
            font-size: 0.9rem;
        }
        .notification-section {
            background: white;
            border-radius: 16px;
            padding: 1.5rem;
            margin-bottom: 2rem;
            box-shadow: 0 4px 6px -1px rgba(0,0,0,0.1);
        }
        .notification-item {
            display: flex;
            align-items: center;
            gap: 1rem;
            padding: 1rem;
            border-bottom: 1px solid #e2e8f0;
        }
        .notification-item.unread {
            background: #f0f9ff;
            border-left: 4px solid #16a085;
        }
        .notification-icon {
            width: 40px; height: 40px;
            background: #e0f2fe;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.2rem;
        }
        .notification-content { flex: 1; }
        .notification-title { font-weight: 600; }
        .notification-time { font-size: 0.8rem; color: #64748b; }
        .btn-mark {
            padding: 0.3rem 0.8rem;
            background: #16a085;
            color: white;
            border: none;
            border-radius: 4px;
            cursor: pointer;
            text-decoration: none;
            font-size: 0.8rem;
        }
        .modules-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(350px, 1fr));
            gap: 1.5rem;
            margin-top: 1.5rem;
        }
        .module-card {
            background: white;
            border-radius: 16px;
            overflow: hidden;
            box-shadow: 0 4px 6px -1px rgba(0,0,0,0.1);
            border-left: 4px solid #16a085;
        }
        .module-header {
            background: linear-gradient(135deg, #0f172a, #1e293b);
            color: white;
            padding: 1rem;
        }
        .module-code { font-size: 1.1rem; font-weight: 600; }
        .module-body { padding: 1rem; }
        .module-teacher {
            color: #64748b;
            font-size: 0.9rem;
            margin: 0.5rem 0;
        }
        table {
            width: 100%;
            border-collapse: collapse;
            background: white;
            border-radius: 16px;
            overflow: hidden;
            box-shadow: 0 4px 6px -1px rgba(0,0,0,0.1);
            margin-bottom: 2rem;
        }
        th {
            background: #f8fafc;
            padding: 1rem;
            text-align: left;
            font-weight: 600;
            border-bottom: 2px solid #e2e8f0;
        }
        td { padding: 1rem; border-bottom: 1px solid #e2e8f0; }
        .badge {
            display: inline-block;
            padding: 0.25rem 0.75rem;
            border-radius: 20px;
            font-size: 0.8rem;
            font-weight: 600;
        }
        .badge-published { background: #d1fae5; color: #065f46; }
        .badge-draft { background: #fef3c7; color: #92400e; }
        .badge-pass { background: #d1fae5; color: #065f46; }
        .badge-supplementary { background: #fef3c7; color: #92400e; }
        .badge-eligible { background: #d1fae5; color: #065f46; }
        .badge-not-eligible { background: #fee2e2; color: #991b1b; }
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
        .transcript-box {
            background: white;
            border-radius: 16px;
            padding: 2rem;
            box-shadow: 0 4px 6px -1px rgba(0,0,0,0.1);
        }
        .letterhead {
            text-align: center;
            margin-bottom: 2rem;
            padding-bottom: 1rem;
            border-bottom: 2px solid #16a085;
        }
        .gpa-display {
            font-size: 3rem;
            font-weight: 700;
            color: #16a085;
        }
        .tabs {
            display: flex;
            gap: 1rem;
            margin-bottom: 2rem;
            border-bottom: 2px solid #e2e8f0;
            flex-wrap: wrap;
        }
        .tab-btn {
            padding: 0.8rem 1.5rem;
            background: none;
            border: none;
            cursor: pointer;
            font-weight: 600;
            color: #64748b;
            border-bottom: 3px solid transparent;
        }
        .tab-btn.active {
            color: #16a085;
            border-bottom-color: #16a085;
        }
        .tab-content { display: none; }
        .tab-content.active { display: block; }
        .action-buttons {
            display: flex;
            gap: 1rem;
            justify-content: center;
            margin-top: 2rem;
        }
        .btn-pdf {
            background: #16a085;
            color: white;
            padding: 0.8rem 2rem;
            border: none;
            border-radius: 8px;
            text-decoration: none;
            font-weight: 600;
            cursor: pointer;
        }
        .btn-print {
            background: #0f172a;
            color: white;
            padding: 0.8rem 2rem;
            border: none;
            border-radius: 8px;
            text-decoration: none;
            font-weight: 600;
            cursor: pointer;
        }
        @media print {
            .header, .tabs, .action-buttons, .btn-print, .btn-pdf, .profile-section, .notification-section {
                display: none;
            }
            .transcript-box {
                box-shadow: none;
                padding: 0;
            }
        }
        @media (max-width: 768px) {
            .container { padding: 0 1rem; }
            .modules-grid { grid-template-columns: 1fr; }
            .profile-section { flex-direction: column; text-align: center; }
        }
    </style>
</head>
<body>
    <div class="header">
        <div class="logo">
            <h1>CSMS Student</h1>
        </div>
        <div class="student-info">
            <span><?= htmlspecialchars($student_name) ?></span>
            <?php if ($unread_count > 0): ?>
                <span class="notif-badge"><?= $unread_count ?> New</span>
            <?php endif; ?>
            <a href="logout.php" class="logout-btn">Logout</a>
        </div>
    </div>

    <div class="container">
        <!-- Profile Section with Photo -->
        <div class="profile-section">
            <?php if ($student_photo && file_exists("../uploads/students/" . $student_photo)): ?>
                <img src="../uploads/students/<?= $student_photo ?>?t=<?= time() ?>" alt="Profile" class="profile-photo">
            <?php else: ?>
                <div class="profile-photo-placeholder">
                    <?= strtoupper(substr($student_name, 0, 1)) ?>
                </div>
            <?php endif; ?>
            
            <div class="photo-upload">
                <h3><?= htmlspecialchars($student_name) ?></h3>
                <p><?= htmlspecialchars($student['reg_number']) ?></p>
                <p><?= htmlspecialchars($course_name) ?> | Current: Year <?= $current_year ?> - Sem <?= $current_semester ?></p>
                <form method="POST" enctype="multipart/form-data">
                    <input type="file" name="photo" accept="image/*" required>
                    <button type="submit" class="btn-upload">Upload Photo</button>
                </form>
                <?php if (isset($photo_message)): ?>
                    <p style="color: #16a085; margin-top: 0.5rem;"><?= $photo_message ?></p>
                <?php endif; ?>
            </div>
        </div>

        <!-- Welcome Banner -->
        <div class="welcome-banner">
            <div>
                <h2>Welcome, <?= htmlspecialchars($student_name) ?>!</h2>
                <p>Your complete academic history across all years</p>
            </div>
            <div class="student-details">
                <strong><?= htmlspecialchars($student['reg_number']) ?></strong> | 
                Cumulative GPA: <strong><?= $cumulative_gpa ?></strong>
            </div>
        </div>

        <!-- Statistics -->
        <div class="stats-grid">
            <div class="stat-card">
                <div class="stat-number"><?= count($all_results) ?></div>
                <div class="stat-label">Total Modules Taken</div>
            </div>
            <div class="stat-card">
                <div class="stat-number"><?= $pass_count ?></div>
                <div class="stat-label">Passed</div>
            </div>
            <div class="stat-card">
                <div class="stat-number"><?= $supplementary_count ?></div>
                <div class="stat-label">Supplementary</div>
            </div>
            <div class="stat-card">
                <div class="stat-number"><?= $cumulative_gpa ?></div>
                <div class="stat-label">Cumulative GPA</div>
            </div>
        </div>

        <!-- Notifications -->
        <?php if ($unread_count > 0): ?>
        <div class="notification-section">
            <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 1rem;">
                <h3 style="color: #0f172a;">🔔 Notifications (<?= $unread_count ?> new)</h3>
                <a href="?mark_all_read=1" class="btn-mark">Mark all as read</a>
            </div>
            <?php foreach (array_filter($notifications, fn($n) => $n['status'] === 'unread') as $notif): ?>
            <div class="notification-item unread">
                <div class="notification-icon">📢</div>
                <div class="notification-content">
                    <div class="notification-title">
                        <?= htmlspecialchars($notif['module_code'] ?? 'System') ?>
                    </div>
                    <div><?= htmlspecialchars($notif['message']) ?></div>
                    <div class="notification-time">
                        <?= date('M d, Y H:i', strtotime($notif['created_at'])) ?>
                    </div>
                </div>
                <a href="?mark_read=<?= $notif['id'] ?>" class="btn-mark">✓ Read</a>
            </div>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>

        <!-- Attendance Notifications -->
        <?php if (count($attendance_notifications) > 0): ?>
        <div class="notification-section" style="border-left: 4px solid #8b5cf6;">
            <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 1rem;">
                <h3 style="color: #0f172a;">📋 New Attendance Reports</h3>
            </div>
            <?php foreach ($attendance_notifications as $notif): ?>
            <div class="notification-item unread">
                <div class="notification-icon">📊</div>
                <div class="notification-content">
                    <div class="notification-title">
                        <?= htmlspecialchars($notif['module_code']) ?> - Attendance Published
                    </div>
                    <div><?= htmlspecialchars($notif['message']) ?></div>
                    <div class="notification-time">
                        <?= date('M d, Y H:i', strtotime($notif['created_at'])) ?>
                    </div>
                </div>
                <a href="?mark_read=<?= $notif['id'] ?>" class="btn-mark">✓ Read</a>
            </div>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>

        <!-- Tabs -->
        <div class="tabs">
            <button class="tab-btn active" onclick="switchTab(event, 'modules')">📚 Current Modules</button>
            <button class="tab-btn" onclick="switchTab(event, 'results')">📊 All Results</button>
            <button class="tab-btn" onclick="switchTab(event, 'attendance')">📋 Attendance History</button>
            <button class="tab-btn" onclick="switchTab(event, 'transcript')">📄 Complete Transcript</button>
        </div>

        <!-- MODULES TAB (Current Year) -->
        <div id="modules" class="tab-content active">
            <h3 class="section-title">Current Year Modules (Year <?= $current_year ?>)</h3>
            <?php if (empty($modules)): ?>
                <p style="text-align: center; padding: 3rem; color: #64748b;">No modules enrolled for current year.</p>
            <?php else: ?>
                <?php foreach ($grouped_modules as $period => $mods): ?>
                <h4 style="margin: 1.5rem 0 0.5rem; color: #0f172a;"><?= $period ?></h4>
                <div class="modules-grid">
                    <?php foreach ($mods as $m): ?>
                    <div class="module-card">
                        <div class="module-header">
                            <div class="module-code"><?= htmlspecialchars($m['module_code']) ?></div>
                        </div>
                        <div class="module-body">
                            <div style="font-weight: 600;"><?= htmlspecialchars($m['module_name']) ?></div>
                            <div class="module-teacher">👨‍🏫 <?= htmlspecialchars($m['teacher_name'] ?? 'Staff') ?></div>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>

        <!-- RESULTS TAB (All Years) -->
        <div id="results" class="tab-content">
            <h3 class="section-title">Complete Academic Results</h3>
            <?php if (empty($all_results)): ?>
                <p style="text-align: center; padding: 3rem; color: #64748b;">No results available yet.</p>
            <?php else: ?>
                <?php foreach ($results_by_year as $year => $year_results): 
                    $year_gpa = $gpa_by_year[$year] ?? 0;
                ?>
                <div class="year-title">
                    <span>📅 Year <?= $year ?></span>
                    <span class="gpa-year">GPA: <?= $year_gpa ?></span>
                </div>
                <table>
                    <thead>
                        <tr>
                            <th>Code</th>
                            <th>Module</th>
                            <th>Semester</th>
                            <th>CA (60)</th>
                            <th>Final (40)</th>
                            <th>Total</th>
                            <th>Grade</th>
                            <th>Status</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($year_results as $r): 
                            $grade_class = 'grade-' . ($r['grade'] ?? 'F');
                        ?>
                        <tr>
                            <td><strong><?= htmlspecialchars($r['module_code']) ?></strong></td>
                            <td><?= htmlspecialchars($r['module_name']) ?></td>
                            <td><?= $r['semester'] ?></td>
                            <td><?= $r['ca_marks'] ?? '-' ?></td>
                            <td><?= $r['final_marks'] ?? '-' ?></td>
                            <td><strong><?= $r['total'] ?></strong></td>
                            <td><span class="grade-badge <?= $grade_class ?>"><?= $r['grade'] ?? '-' ?></span></td>
                            <td><span class="badge <?= $r['status_class'] ?>"><?= $r['status_display'] ?></span></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
                <?php endforeach; ?>

                <?php if (!empty($draft_results)): ?>
                <h4 style="margin: 2rem 0 1rem;">⏳ Pending Results</h4>
                <table>
                    <thead>
                        <tr>
                            <th>Code</th>
                            <th>Module</th>
                            <th>Year</th>
                            <th>Semester</th>
                            <th>Status</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($draft_results as $r): ?>
                        <tr>
                            <td><?= htmlspecialchars($r['module_code']) ?></td>
                            <td><?= htmlspecialchars($r['module_name']) ?></td>
                            <td><?= $r['year'] ?></td>
                            <td><?= $r['semester'] ?></td>
                            <td><span class="badge badge-draft">Awaiting Publication</span></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
                <?php endif; ?>
            <?php endif; ?>
        </div>

        <!-- ATTENDANCE TAB (All Years) -->
        <div id="attendance" class="tab-content">
            <h3 class="section-title">Attendance History & Exam Eligibility</h3>
            
            <?php if (empty($attendance_records)): ?>
                <p style="text-align: center; padding: 3rem; color: #64748b;">No attendance records published yet.</p>
            <?php else: ?>
                <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 1.5rem; margin-bottom: 2rem;">
                    <div class="stat-card" style="border-left-color: #10b981;">
                        <div class="stat-number"><?= $eligible_count ?></div>
                        <div class="stat-label">Modules Eligible (≥60%)</div>
                    </div>
                    <div class="stat-card" style="border-left-color: #ef4444;">
                        <div class="stat-number"><?= $not_eligible_count ?></div>
                        <div class="stat-label">Modules Not Eligible</div>
                    </div>
                </div>

                <?php foreach ($attendance_by_year as $year => $year_attendance): ?>
                <h4 style="margin: 2rem 0 1rem; color: #0f172a;">📅 Year <?= $year ?> Attendance</h4>
                <table>
                    <thead>
                        <tr>
                            <th>Module</th>
                            <th>Total Classes</th>
                            <th>Attended</th>
                            <th>Percentage</th>
                            <th>Eligibility</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($year_attendance as $a): 
                            $pct = $a['attendance_percentage'] ?? 0;
                            $eligible = ($a['is_eligible'] ?? 0) == 1;
                        ?>
                        <tr>
                            <td><strong><?= htmlspecialchars($a['module_code']) ?></strong></td>
                            <td><?= $a['total_classes'] ?? '-' ?></td>
                            <td><?= $a['attended_classes'] ?? '-' ?></td>
                            <td><?= $a['attended_classes'] ? number_format($pct, 1).'%' : '-' ?></td>
                            <td>
                                <span class="badge <?= $eligible ? 'badge-eligible' : 'badge-not-eligible' ?>">
                                    <?= $eligible ? '✓ Eligible' : '❌ Not Eligible' ?>
                                </span>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>

        <!-- TRANSCRIPT TAB (Complete Record) -->
        <div id="transcript" class="tab-content">
            <div class="transcript-box" id="transcript-content">
                <!-- Letterhead -->
                <div class="letterhead">
                    <h2>DON BOSCO KILIMANJARO INTERNATIONAL INSTITUTE</h2>
                    <p>FOR TELECOMMUNICATIONS, ELECTRONICS AND COMPUTERS</p>
                    <p>P.O BOX 3172, ARUSHA, Tel: 0757 845118</p>
                    <p>Email: info@kiitec.ac.tz | Website: www.kiitec.ac.tz</p>
                    <hr style="margin: 1rem 0; border: 1px solid #16a085;">
                </div>

                <div style="text-align: center; margin-bottom: 2rem;">
                    <h3 style="color: #0f172a;">COMPLETE ACADEMIC TRANSCRIPT</h3>
                    <p><strong><?= htmlspecialchars($student_name) ?></strong> | <?= htmlspecialchars($student['reg_number']) ?></p>
                    <p><?= htmlspecialchars($course_name) ?></p>
                    <p>Enrolled: Year <?= $student['year'] ?> - Semester <?= $student['semester'] ?></p>
                </div>

                <div style="display: grid; grid-template-columns: repeat(3, 1fr); gap: 1rem; margin-bottom: 2rem; text-align: center;">
                    <div>
                        <div class="stat-label">Cumulative GPA</div>
                        <div class="gpa-display"><?= $cumulative_gpa ?></div>
                    </div>
                    <div>
                        <div class="stat-label">Total Modules</div>
                        <div class="stat-number" style="font-size: 2rem;"><?= count($all_results) ?></div>
                    </div>
                    <div>
                        <div class="stat-label">Pass Rate</div>
                        <div class="stat-number" style="font-size: 2rem;">
                            <?= count($all_results) > 0 ? round(($pass_count / count($all_results)) * 100) : 0 ?>%
                        </div>
                    </div>
                </div>

                <?php if (!empty($all_results)): ?>
                    <?php foreach ($results_by_year as $year => $year_results): 
                        $year_gpa = $gpa_by_year[$year] ?? 0;
                    ?>
                    <h4 style="margin: 2rem 0 1rem; border-bottom: 1px solid #16a085; padding-bottom: 0.5rem;">
                        Year <?= $year ?> (GPA: <?= $year_gpa ?>)
                    </h4>
                    <table style="margin-bottom: 2rem;">
                        <thead>
                            <tr>
                                <th>Code</th>
                                <th>Module</th>
                                <th>Sem</th>
                                <th>CA</th>
                                <th>Final</th>
                                <th>Total</th>
                                <th>Grade</th>
                                <th>Status</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($year_results as $r): ?>
                            <tr>
                                <td><?= htmlspecialchars($r['module_code']) ?></td>
                                <td><?= htmlspecialchars($r['module_name']) ?></td>
                                <td><?= $r['semester'] ?></td>
                                <td><?= $r['ca_marks'] ?? '-' ?></td>
                                <td><?= $r['final_marks'] ?? '-' ?></td>
                                <td><strong><?= $r['total'] ?></strong></td>
                                <td><span class="grade-badge grade-<?= $r['grade'] ?>"><?= $r['grade'] ?></span></td>
                                <td><span class="badge <?= $r['status_class'] ?>"><?= $r['status_display'] ?></span></td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                    <?php endforeach; ?>
                <?php endif; ?>

                <p style="color: #64748b; font-size: 0.9rem; text-align: right;">Generated on <?= date('F d, Y') ?></p>
                <p style="color: #64748b; font-size: 0.8rem; text-align: center; margin-top: 2rem;">This is an official transcript from CSMS</p>
            </div>

            <div class="action-buttons">
                <button onclick="printTranscript()" class="btn-print">🖨️ Print Transcript</button>
                <button onclick="downloadPDF()" class="btn-pdf">📥 Download PDF</button>
            </div>
        </div>
    </div>

    <script src="https://cdnjs.cloudflare.com/ajax/libs/html2pdf.js/0.10.1/html2pdf.bundle.min.js"></script>
    <script>
        function switchTab(event, tabId) {
            document.querySelectorAll('.tab-content').forEach(t => t.classList.remove('active'));
            document.querySelectorAll('.tab-btn').forEach(b => b.classList.remove('active'));
            document.getElementById(tabId).classList.add('active');
            event.target.classList.add('active');
        }

        function printTranscript() {
            const printContent = document.getElementById('transcript-content').innerHTML;
            const originalContent = document.body.innerHTML;
            
            document.body.innerHTML = printContent;
            window.print();
            document.body.innerHTML = originalContent;
            window.location.reload();
        }

        function downloadPDF() {
            const element = document.getElementById('transcript-content');
            const opt = {
                margin:       1,
                filename:     'transcript_<?= $student['reg_number'] ?>.pdf',
                image:        { type: 'jpeg', quality: 0.98 },
                html2canvas:  { scale: 2 },
                jsPDF:        { unit: 'in', format: 'a4', orientation: 'portrait' }
            };
            html2pdf().set(opt).from(element).save();
        }
    </script>
</body>
</html>