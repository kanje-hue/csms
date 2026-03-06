<?php
/**
 * teacher/dashboard.php - Teacher Dashboard
 * Fixed: Photo column error
 */

session_start();
require_once '../config/db.php';
require_once '../config/security_base.php';
require_once '../config/security.php';

// Check teacher login
if (!isset($_SESSION['teacher_logged_in']) || !isset($_SESSION['teacher_id'])) {
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

$teacher_id = $_SESSION['teacher_id'];
$teacher_name = $_SESSION['teacher_name'] ?? 'Teacher';

// Get teacher info - FIXED: Check if photo column exists first
$check_column = $conn->query("SHOW COLUMNS FROM teachers LIKE 'photo'");
$has_photo_column = $check_column->num_rows > 0;

if ($has_photo_column) {
    $teacher_stmt = $conn->prepare("SELECT teacher_id, fullname, email, status, photo, force_password_change FROM teachers WHERE teacher_id = ? AND deleted = 0");
} else {
    $teacher_stmt = $conn->prepare("SELECT teacher_id, fullname, email, status, force_password_change FROM teachers WHERE teacher_id = ? AND deleted = 0");
}
$teacher_stmt->bind_param("i", $teacher_id);
$teacher_stmt->execute();
$teacher = $teacher_stmt->get_result()->fetch_assoc();
$teacher_stmt->close();

if (!$teacher) {
    header("Location: login.php");
    exit();
}

$teacher_photo = ($has_photo_column && isset($teacher['photo'])) ? $teacher['photo'] : 'default-avatar.png';

// Handle photo upload - only if column exists
if ($has_photo_column && $_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['photo'])) {
    $target_dir = "../uploads/teachers/";
    if (!file_exists($target_dir)) {
        mkdir($target_dir, 0777, true);
    }
    
    $file_extension = strtolower(pathinfo($_FILES["photo"]["name"], PATHINFO_EXTENSION));
    $allowed_extensions = ['jpg', 'jpeg', 'png', 'gif'];
    
    if (in_array($file_extension, $allowed_extensions)) {
        $new_filename = "teacher_" . $teacher_id . "_" . time() . "." . $file_extension;
        $target_file = $target_dir . $new_filename;
        
        if (move_uploaded_file($_FILES["photo"]["tmp_name"], $target_file)) {
            // Delete old photo if not default
            if ($teacher['photo'] && $teacher['photo'] != 'default-avatar.png' && file_exists($target_dir . $teacher['photo'])) {
                unlink($target_dir . $teacher['photo']);
            }
            
            $update = $conn->prepare("UPDATE teachers SET photo = ? WHERE teacher_id = ?");
            $update->bind_param("si", $new_filename, $teacher_id);
            $update->execute();
            $update->close();
            
            $teacher_photo = $new_filename;
            $photo_message = "Photo uploaded successfully!";
        }
    }
}

// Get teacher's modules with statistics
$modules_query = "
    SELECT 
        m.module_id,
        m.module_code,
        m.module_name,
        m.year,
        m.semester,
        c.course_name,
        c.course_id,
        COUNT(DISTINCT me.student_id) as student_count,
        (SELECT COUNT(*) FROM results r WHERE r.module_id = m.module_id) as results_count,
        (SELECT COUNT(DISTINCT student_id) FROM results WHERE module_id = m.module_id) as students_with_results,
        MAX(CASE WHEN r.status = 'draft' THEN 1 ELSE 0 END) as has_draft,
        MAX(CASE WHEN r.status = 'published' THEN 1 ELSE 0 END) as has_published,
        (SELECT COUNT(*) FROM attendance a WHERE a.module_id = m.module_id) as attendance_count,
        (SELECT MAX(status) FROM attendance WHERE module_id = m.module_id) as attendance_status,
        (SELECT COUNT(*) FROM attendance WHERE module_id = m.module_id AND is_eligible = 1) as eligible_count
    FROM modules m
    JOIN courses c ON m.course_id = c.course_id
    LEFT JOIN module_enrollments me ON m.module_id = me.module_id
    LEFT JOIN results r ON m.module_id = r.module_id
    WHERE m.teacher_id = ? AND m.deleted = 0
    GROUP BY m.module_id, m.module_code, m.module_name, m.year, m.semester, c.course_name, c.course_id
    ORDER BY c.course_name, m.year, m.semester, m.module_code
";

$modules_stmt = $conn->prepare($modules_query);
$modules_stmt->bind_param("i", $teacher_id);
$modules_stmt->execute();
$modules = $modules_stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$modules_stmt->close();

// Get notifications count
$notif_stmt = $conn->prepare("
    SELECT COUNT(*) as count FROM notifications 
    WHERE user_type = 'teacher' AND user_id = ? AND status = 'unread'
");
$notif_stmt->bind_param("i", $teacher_id);
$notif_stmt->execute();
$unread_count = $notif_stmt->get_result()->fetch_assoc()['count'];
$notif_stmt->close();

// Get all notifications
$notif_list_stmt = $conn->prepare("
    SELECT n.*, m.module_code, m.module_name
    FROM notifications n
    LEFT JOIN modules m ON n.module_id = m.module_id
    WHERE n.user_id = ? AND n.user_type = 'teacher'
    ORDER BY n.created_at DESC
    LIMIT 10
");
$notif_list_stmt->bind_param("i", $teacher_id);
$notif_list_stmt->execute();
$recent_notifications = $notif_list_stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$notif_list_stmt->close();

// Calculate statistics
$total_modules = count($modules);
$total_students = array_sum(array_column($modules, 'student_count'));
$modules_with_results = count(array_filter($modules, fn($m) => $m['results_count'] > 0));
$modules_pending = count(array_filter($modules, fn($m) => $m['has_draft'] > 0));
$modules_with_attendance = count(array_filter($modules, fn($m) => $m['attendance_count'] > 0));
$total_eligible = array_sum(array_column($modules, 'eligible_count'));

// Mark notification as read
if (isset($_GET['mark_read'])) {
    $notif_id = (int)$_GET['mark_read'];
    $stmt = $conn->prepare("UPDATE notifications SET status = 'read' WHERE id = ? AND user_id = ? AND user_type = 'teacher'");
    $stmt->bind_param("ii", $notif_id, $teacher_id);
    $stmt->execute();
    $stmt->close();
    header("Location: dashboard.php");
    exit();
}

if (isset($_GET['mark_all_read'])) {
    $stmt = $conn->prepare("UPDATE notifications SET status = 'read' WHERE user_id = ? AND user_type = 'teacher' AND status = 'unread'");
    $stmt->bind_param("i", $teacher_id);
    $stmt->execute();
    $stmt->close();
    header("Location: dashboard.php");
    exit();
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Teacher Dashboard - CSMS</title>
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

        .logo h1 {
            font-size: 1.8rem;
            font-weight: 700;
            background: linear-gradient(135deg, #8b5cf6, #7c3aed);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            background-clip: text;
        }

        .teacher-info {
            display: flex;
            align-items: center;
            gap: 2rem;
        }

        .welcome-text {
            font-size: 0.95rem;
            color: #94a3b8;
        }

        .teacher-name {
            color: #8b5cf6;
            font-weight: 600;
        }

        .notif-badge {
            background: #8b5cf6;
            color: white;
            padding: 0.2rem 0.6rem;
            border-radius: 20px;
            font-size: 0.8rem;
            font-weight: 600;
            margin-left: 0.5rem;
        }

        .logout-btn {
            background: rgba(255, 255, 255, 0.1);
            color: white;
            padding: 0.5rem 1.2rem;
            border-radius: 8px;
            text-decoration: none;
            font-size: 0.9rem;
            font-weight: 500;
            transition: all 0.3s;
            border: 1px solid rgba(255, 255, 255, 0.1);
        }

        .logout-btn:hover {
            background: #8b5cf6;
            border-color: #8b5cf6;
            transform: translateY(-2px);
        }

        .container {
            max-width: 1400px;
            margin: 2rem auto;
            padding: 0 2rem;
        }

        /* Profile Section */
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
            border: 3px solid #8b5cf6;
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
            border: 3px solid #8b5cf6;
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
            background: #8b5cf6;
            color: white;
            padding: 0.5rem 1rem;
            border: none;
            border-radius: 8px;
            cursor: pointer;
            transition: all 0.3s;
        }

        .btn-upload:hover {
            background: #7c3aed;
        }

        /* Welcome Banner */
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

        .welcome-text h2 {
            font-size: 1.8rem;
            margin-bottom: 0.5rem;
        }

        .welcome-text p {
            color: #94a3b8;
        }

        .quick-stats {
            display: flex;
            gap: 2rem;
            flex-wrap: wrap;
        }

        .stat {
            text-align: center;
        }

        .stat-number {
            font-size: 2rem;
            font-weight: 700;
            color: #8b5cf6;
        }

        .stat-label {
            font-size: 0.8rem;
            color: #94a3b8;
        }

        /* Stats Grid */
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
            transition: transform 0.3s;
        }

        .stat-card:hover {
            transform: translateY(-4px);
        }

        .stat-card .number {
            font-size: 2.2rem;
            font-weight: 700;
            color: #0f172a;
        }

        .stat-card .label {
            color: #64748b;
            font-size: 0.9rem;
            margin-top: 0.25rem;
        }

        /* Notifications Section */
        .notification-section {
            background: white;
            border-radius: 16px;
            padding: 1.5rem;
            margin-bottom: 2rem;
            box-shadow: 0 4px 6px -1px rgba(0,0,0,0.1);
        }

        .notification-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 1rem;
        }

        .notification-item {
            display: flex;
            align-items: center;
            gap: 1rem;
            padding: 1rem;
            border-bottom: 1px solid #e2e8f0;
        }

        .notification-item.unread {
            background: #f3e8ff;
            border-left: 4px solid #8b5cf6;
        }

        .notification-icon {
            width: 40px;
            height: 40px;
            background: #ede9fe;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.2rem;
        }

        .notification-content {
            flex: 1;
        }

        .notification-title {
            font-weight: 600;
        }

        .notification-time {
            font-size: 0.8rem;
            color: #64748b;
        }

        .btn-mark {
            padding: 0.3rem 0.8rem;
            background: #8b5cf6;
            color: white;
            border: none;
            border-radius: 4px;
            cursor: pointer;
            text-decoration: none;
            font-size: 0.8rem;
        }

        .btn-mark:hover {
            background: #7c3aed;
        }

        /* Section Title */
        .section-title {
            font-size: 1.5rem;
            font-weight: 600;
            color: #0f172a;
            margin: 2rem 0 1.5rem;
            padding-bottom: 0.75rem;
            border-bottom: 3px solid #8b5cf6;
            display: inline-block;
        }

        /* Module Grid */
        .module-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(380px, 1fr));
            gap: 1.5rem;
            margin-top: 1.5rem;
        }

        .module-card {
            background: white;
            border-radius: 20px;
            overflow: hidden;
            box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.1);
            transition: all 0.3s;
            border: 1px solid #e2e8f0;
        }

        .module-card:hover {
            transform: translateY(-6px);
            box-shadow: 0 20px 25px -5px rgba(0, 0, 0, 0.15);
            border-color: #8b5cf6;
        }

        .module-header {
            background: linear-gradient(135deg, #0f172a, #1e293b);
            color: white;
            padding: 1.5rem;
        }

        .module-code {
            font-size: 1.1rem;
            font-weight: 600;
            margin-bottom: 0.3rem;
        }

        .module-name {
            font-size: 0.95rem;
            opacity: 0.9;
            margin-bottom: 0.5rem;
        }

        .module-course {
            font-size: 0.85rem;
            color: #94a3b8;
        }

        .module-body {
            padding: 1.5rem;
        }

        .module-stats {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 0.5rem;
            margin-bottom: 1rem;
            padding-bottom: 1rem;
            border-bottom: 1px solid #e2e8f0;
        }

        .stat-item {
            text-align: center;
        }

        .stat-value {
            font-size: 1.3rem;
            font-weight: 700;
            color: #0f172a;
        }

        .stat-label {
            font-size: 0.7rem;
            color: #64748b;
        }

        .progress-bar {
            height: 6px;
            background: #e2e8f0;
            border-radius: 3px;
            margin: 1rem 0;
            overflow: hidden;
        }

        .progress-fill {
            height: 100%;
            background: #8b5cf6;
            border-radius: 3px;
        }

        .status-badge {
            display: inline-block;
            padding: 0.25rem 0.75rem;
            border-radius: 20px;
            font-size: 0.75rem;
            font-weight: 600;
        }

        .badge-pending {
            background: #fef3c7;
            color: #92400e;
        }

        .badge-complete {
            background: #d1fae5;
            color: #065f46;
        }

        .badge-not-started {
            background: #e2e8f0;
            color: #475569;
        }

        .badge-attendance {
            background: #dbeafe;
            color: #1e40af;
        }

        .attendance-info {
            display: flex;
            justify-content: space-between;
            align-items: center;
            background: #f8fafc;
            padding: 0.5rem;
            border-radius: 8px;
            margin: 0.5rem 0;
        }

        .eligible-badge {
            background: #d1fae5;
            color: #065f46;
            padding: 0.25rem 0.5rem;
            border-radius: 4px;
            font-size: 0.7rem;
            font-weight: 600;
        }

        .action-buttons {
            display: flex;
            gap: 0.5rem;
            margin-top: 1rem;
            flex-wrap: wrap;
        }

        .btn {
            flex: 1;
            min-width: 100px;
            padding: 0.7rem 0.5rem;
            border: none;
            border-radius: 8px;
            font-weight: 600;
            font-size: 0.8rem;
            cursor: pointer;
            text-decoration: none;
            text-align: center;
            transition: all 0.3s;
        }

        .btn-primary {
            background: #2dd4bf;
            color: white;
        }

        .btn-primary:hover {
            background: #14b8a6;
            transform: translateY(-2px);
        }

        .btn-attendance {
            background: #8b5cf6;
            color: white;
        }

        .btn-attendance:hover {
            background: #7c3aed;
            transform: translateY(-2px);
        }

        .btn-success {
            background: #10b981;
            color: white;
        }

        .btn-success:hover {
            background: #059669;
            transform: translateY(-2px);
        }

        .btn-secondary {
            background: #e2e8f0;
            color: #0f172a;
        }

        .btn-secondary:hover {
            background: #cbd5e1;
        }

        .btn-view {
            background: #94a3b8;
            color: white;
        }

        .btn-view:hover {
            background: #64748b;
        }

        .empty-state {
            text-align: center;
            padding: 4rem;
            background: white;
            border-radius: 20px;
            color: #64748b;
        }

        /* Quick Links */
        .quick-links {
            margin-top: 2rem;
            text-align: center;
            display: flex;
            justify-content: center;
            gap: 2rem;
            flex-wrap: wrap;
        }

        .quick-link {
            color: #8b5cf6;
            text-decoration: none;
            font-weight: 600;
            padding: 0.5rem 1rem;
            border-radius: 8px;
            transition: all 0.3s;
        }

        .quick-link:hover {
            background: #ede9fe;
            color: #7c3aed;
        }

        @media (max-width: 768px) {
            .header {
                flex-direction: column;
                gap: 1rem;
                text-align: center;
                padding: 1rem;
            }

            .teacher-info {
                flex-direction: column;
                gap: 1rem;
            }

            .welcome-banner {
                flex-direction: column;
                text-align: center;
                gap: 1rem;
            }

            .profile-section {
                flex-direction: column;
                text-align: center;
            }

            .container {
                padding: 0 1rem;
            }

            .stats-grid {
                grid-template-columns: 1fr;
            }

            .module-grid {
                grid-template-columns: 1fr;
            }

            .action-buttons {
                flex-direction: column;
            }

            .btn {
                width: 100%;
            }

            .quick-links {
                flex-direction: column;
                gap: 1rem;
            }
        }
    </style>
</head>
<body>

<div class="header">
    <div class="logo">
        <h1>CSMS Teacher</h1>
    </div>
    <div class="teacher-info">
        <span class="welcome-text">Welcome, <span class="teacher-name"><?= htmlspecialchars($teacher['fullname']) ?></span></span>
        <a href="notifications.php" class="logout-btn" style="background: #8b5cf6; color: white;">
            🔔 Notifications
            <?php if ($unread_count > 0): ?>
                <span class="notif-badge"><?= $unread_count ?></span>
            <?php endif; ?>
        </a>
        <a href="logout.php" class="logout-btn">🚪 Logout</a>
    </div>
</div>

<div class="container">
    <!-- Profile Section with Photo - Only show if photo column exists -->
    <?php if ($has_photo_column): ?>
    <div class="profile-section">
        <?php if ($teacher_photo && file_exists("../uploads/teachers/" . $teacher_photo)): ?>
            <img src="../uploads/teachers/<?= $teacher_photo ?>?t=<?= time() ?>" alt="Profile" class="profile-photo">
        <?php else: ?>
            <div class="profile-photo-placeholder">
                <?= strtoupper(substr($teacher['fullname'], 0, 1)) ?>
            </div>
        <?php endif; ?>
        
        <div class="photo-upload">
            <h3><?= htmlspecialchars($teacher['fullname']) ?></h3>
            <p><?= htmlspecialchars($teacher['email']) ?></p>
            <form method="POST" enctype="multipart/form-data">
                <input type="file" name="photo" accept="image/*" required>
                <button type="submit" class="btn-upload">Upload Photo</button>
            </form>
            <?php if (isset($photo_message)): ?>
                <p style="color: #8b5cf6; margin-top: 0.5rem;"><?= $photo_message ?></p>
            <?php endif; ?>
        </div>
    </div>
    <?php endif; ?>

    <!-- Welcome Banner -->
    <div class="welcome-banner">
        <div class="welcome-text">
            <h2>👋 Welcome back, <?= htmlspecialchars($teacher['fullname']) ?>!</h2>
            <p>Manage your modules, attendance tracking, and student results</p>
        </div>
        <div class="quick-stats">
            <div class="stat">
                <div class="stat-number"><?= $total_modules ?></div>
                <div class="stat-label">Modules</div>
            </div>
            <div class="stat">
                <div class="stat-number"><?= $total_students ?></div>
                <div class="stat-label">Students</div>
            </div>
            <div class="stat">
                <div class="stat-number"><?= $modules_pending ?></div>
                <div class="stat-label">Pending</div>
            </div>
            <div class="stat">
                <div class="stat-number"><?= $total_eligible ?></div>
                <div class="stat-label">Eligible</div>
            </div>
        </div>
    </div>

    <!-- Notifications Section -->
    <?php if (!empty($recent_notifications) && $unread_count > 0): ?>
    <div class="notification-section">
        <div class="notification-header">
            <h3 style="color: #0f172a;">🔔 Recent Notifications (<?= $unread_count ?> unread)</h3>
            <a href="?mark_all_read=1" class="btn-mark">Mark all as read</a>
        </div>
        <?php foreach (array_filter($recent_notifications, fn($n) => $n['status'] === 'unread') as $notif): ?>
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

    <!-- Statistics -->
    <div class="stats-grid">
        <div class="stat-card">
            <div class="number"><?= $total_modules ?></div>
            <div class="label">Total Modules Assigned</div>
        </div>
        <div class="stat-card">
            <div class="number"><?= $total_students ?></div>
            <div class="label">Total Students</div>
        </div>
        <div class="stat-card">
            <div class="number"><?= $modules_with_results ?></div>
            <div class="label">Modules with Results</div>
        </div>
        <div class="stat-card">
            <div class="number"><?= $modules_with_attendance ?></div>
            <div class="label">Attendance Uploaded</div>
        </div>
        <div class="stat-card">
            <div class="number"><?= $total_eligible ?></div>
            <div class="label">Exam Eligible</div>
        </div>
    </div>

    <!-- My Modules -->
    <h2 class="section-title">📚 My Modules</h2>

    <?php if (count($modules) > 0): ?>
        <div class="module-grid">
            <?php foreach ($modules as $module): 
                $progress = $module['student_count'] > 0 ? round(($module['students_with_results'] / $module['student_count']) * 100) : 0;
                
                if ($module['has_draft']) {
                    $status_class = 'badge-pending';
                    $status_text = '⏳ Pending Review';
                } elseif ($module['has_published']) {
                    $status_class = 'badge-complete';
                    $status_text = '✓ Published';
                } else {
                    $status_class = 'badge-not-started';
                    $status_text = '📝 Not Started';
                }
                
                $attendance_status = $module['attendance_status'] ?? 'draft';
                $attendance_class = $attendance_status === 'published' ? 'badge-complete' : 'badge-pending';
                $attendance_text = $attendance_status === 'published' ? '✓ Published' : '📝 Draft';
            ?>
            <div class="module-card">
                <div class="module-header">
                    <div class="module-code"><?= htmlspecialchars($module['module_code']) ?></div>
                    <div class="module-name"><?= htmlspecialchars($module['module_name']) ?></div>
                    <div class="module-course"><?= htmlspecialchars($module['course_name']) ?></div>
                </div>
                <div class="module-body">
                    <div class="module-stats">
                        <div class="stat-item">
                            <div class="stat-value"><?= $module['student_count'] ?></div>
                            <div class="stat-label">Students</div>
                        </div>
                        <div class="stat-item">
                            <div class="stat-value"><?= $module['students_with_results'] ?></div>
                            <div class="stat-label">Graded</div>
                        </div>
                        <div class="stat-item">
                            <div class="stat-value"><?= $module['eligible_count'] ?? 0 ?></div>
                            <div class="stat-label">Eligible</div>
                        </div>
                    </div>

                    <!-- Results Progress -->
                    <div style="margin-bottom: 0.5rem;">
                        <div style="display: flex; justify-content: space-between; font-size: 0.75rem; margin-bottom: 0.25rem;">
                            <span>Results Progress</span>
                            <span><?= $progress ?>%</span>
                        </div>
                        <div class="progress-bar">
                            <div class="progress-fill" style="width: <?= $progress ?>%"></div>
                        </div>
                    </div>

                    <!-- Status Badges -->
                    <div style="display: flex; gap: 0.5rem; margin: 0.5rem 0; flex-wrap: wrap;">
                        <span class="status-badge <?= $status_class ?>"><?= $status_text ?></span>
                        <?php if ($module['attendance_count'] > 0): ?>
                        <span class="status-badge <?= $attendance_class ?>">📊 <?= $attendance_text ?></span>
                        <?php endif; ?>
                    </div>

                    <!-- Attendance Quick Info -->
                    <?php if ($module['eligible_count'] > 0): ?>
                    <div class="attendance-info">
                        <span>🎓 Exam Eligibility</span>
                        <span class="eligible-badge"><?= $module['eligible_count'] ?> Eligible</span>
                    </div>
                    <?php endif; ?>

                    <!-- Action Buttons -->
                    <div class="action-buttons">
                        <a href="manage_attendance.php?module_id=<?= $module['module_id'] ?>" class="btn btn-attendance">📊 Attendance</a>
                        <a href="view_attendance.php?module_id=<?= $module['module_id'] ?>" class="btn btn-view">👁️ Report</a>
                        <a href="upload_results.php?module_id=<?= $module['module_id'] ?>" class="btn btn-success">📝 Results</a>
                    </div>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
    <?php else: ?>
        <div class="empty-state">
            <p style="font-size: 1.2rem; margin-bottom: 1rem;">📚 No modules assigned yet</p>
            <p style="color: #64748b;">Please contact the administrator to assign modules to your account.</p>
        </div>
    <?php endif; ?>

    <!-- Quick Links -->
    <div class="quick-links">
        <a href="change_password.php" class="quick-link">🔑 Change Password</a>
        <a href="notifications.php" class="quick-link">🔔 All Notifications</a>
        <a href="profile.php" class="quick-link">👤 My Profile</a>
    </div>
</div>

</body>
</html>