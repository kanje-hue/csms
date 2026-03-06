<?php
/**
 * admin/manage_results.php - Admin Results Management
 * With working filters and separate view pages
 */

session_start();
require_once '../config/db.php';
require_once '../config/security.php';
require_once '../config/security_base.php';
require_once '../config/email_config.php';

// Check admin login
if (!isset($_SESSION['admin_logged_in']) || !isset($_SESSION['admin_id'])) {
    header("Location: login.php");
    exit();
}

// Session timeout
if (isset($_SESSION['last_activity']) && (time() - $_SESSION['last_activity'] > 3600)) {
    session_destroy();
    header("Location: login.php?timeout=1");
    exit();
}
$_SESSION['last_activity'] = time();

$admin_id = $_SESSION['admin_id'];
$admin_name = $_SESSION['admin_name'] ?? 'Admin';

$message = "";
$message_type = "";
$csrf_token = generateCSRF();

// Handle Publish Results
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'publish') {
    validateCSRF($_POST['csrf_token'] ?? '');
    
    $module_id = (int)($_POST['module_id'] ?? 0);
    
    if ($module_id) {
        $stmt = $conn->prepare("UPDATE results SET status = 'published' WHERE module_id = ?");
        $stmt->bind_param("i", $module_id);
        
        if ($stmt->execute()) {
            $affected = $stmt->affected_rows;
            
            // Get module details
            $module_info = $conn->prepare("
                SELECT m.module_code, m.module_name, c.course_name
                FROM modules m
                JOIN courses c ON m.course_id = c.course_id
                WHERE m.module_id = ?
            ");
            $module_info->bind_param("i", $module_id);
            $module_info->execute();
            $module = $module_info->get_result()->fetch_assoc();
            $module_info->close();
            
            // Get all students in this module
            $students = $conn->prepare("
                SELECT s.student_id, s.name, s.email 
                FROM students s
                JOIN results r ON s.student_id = r.student_id
                WHERE r.module_id = ? AND s.status = 'active'
            ");
            $students->bind_param("i", $module_id);
            $students->execute();
            $student_list = $students->get_result()->fetch_all(MYSQLI_ASSOC);
            $students->close();
            
            // Create notifications
            foreach ($student_list as $student) {
                $notif_msg = "Your results for {$module['module_code']} have been published";
                $notif = $conn->prepare("
                    INSERT INTO notifications (user_type, user_id, module_id, type, message, status, created_at)
                    VALUES ('student', ?, ?, 'result_published', ?, 'unread', NOW())
                ");
                $notif->bind_param("iis", $student['student_id'], $module_id, $notif_msg);
                $notif->execute();
                $notif->close();
            }
            
            logAdminAction($conn, $admin_id, 'publish_results', "Published results for module ID: $module_id");
            $message = "✓ Results published for {$module['module_code']}. $affected results updated.";
            $message_type = "success";
        }
        $stmt->close();
    }
}

// Handle Unpublish Results
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'unpublish') {
    validateCSRF($_POST['csrf_token'] ?? '');
    
    $module_id = (int)($_POST['module_id'] ?? 0);
    
    if ($module_id) {
        $stmt = $conn->prepare("UPDATE results SET status = 'draft' WHERE module_id = ?");
        $stmt->bind_param("i", $module_id);
        
        if ($stmt->execute()) {
            logAdminAction($conn, $admin_id, 'unpublish_results', "Unpublished results for module ID: $module_id");
            $message = "✓ Results unpublished";
            $message_type = "success";
        }
        $stmt->close();
    }
}

// Handle Bulk Publish
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'bulk_publish') {
    validateCSRF($_POST['csrf_token'] ?? '');
    
    $module_ids = $_POST['module_ids'] ?? [];
    $published_count = 0;
    
    if (empty($module_ids)) {
        // Get all modules with draft results
        $draft_modules = $conn->query("
            SELECT DISTINCT module_id FROM results WHERE status = 'draft'
        ")->fetch_all(MYSQLI_ASSOC);
        $module_ids = array_column($draft_modules, 'module_id');
    }
    
    foreach ($module_ids as $module_id) {
        $module_id = (int)$module_id;
        if ($module_id) {
            $publish = $conn->prepare("UPDATE results SET status = 'published' WHERE module_id = ? AND status = 'draft'");
            $publish->bind_param("i", $module_id);
            $publish->execute();
            $published_count += $publish->affected_rows;
            $publish->close();
        }
    }
    
    logAdminAction($conn, $admin_id, 'bulk_publish', "Bulk published $published_count results");
    $message = "✓ Bulk publish complete! $published_count results published.";
    $message_type = "success";
}

// Handle Bulk Unpublish
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'bulk_unpublish') {
    validateCSRF($_POST['csrf_token'] ?? '');
    
    $module_ids = $_POST['module_ids'] ?? [];
    $unpublished_count = 0;
    
    if (empty($module_ids)) {
        // Get all modules with published results
        $published_modules = $conn->query("
            SELECT DISTINCT module_id FROM results WHERE status = 'published'
        ")->fetch_all(MYSQLI_ASSOC);
        $module_ids = array_column($published_modules, 'module_id');
    }
    
    foreach ($module_ids as $module_id) {
        $module_id = (int)$module_id;
        if ($module_id) {
            $unpublish = $conn->prepare("UPDATE results SET status = 'draft' WHERE module_id = ? AND status = 'published'");
            $unpublish->bind_param("i", $module_id);
            $unpublish->execute();
            $unpublished_count += $unpublish->affected_rows;
            $unpublish->close();
        }
    }
    
    logAdminAction($conn, $admin_id, 'bulk_unpublish', "Bulk unpublished $unpublished_count results");
    $message = "✓ Bulk unpublish complete! $unpublished_count results unpublished.";
    $message_type = "success";
}

// Handle Request Results from Teacher
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'request_results') {
    validateCSRF($_POST['csrf_token'] ?? '');
    
    $module_id = (int)($_POST['module_id'] ?? 0);
    $teacher_id = (int)($_POST['teacher_id'] ?? 0);
    $module_code = sanitizeInput($_POST['module_code'] ?? '');
    $module_name = sanitizeInput($_POST['module_name'] ?? '');
    $teacher_email = sanitizeInput($_POST['teacher_email'] ?? '');
    $teacher_name = sanitizeInput($_POST['teacher_name'] ?? 'Teacher');
    
    if ($module_id && $teacher_id && $teacher_email) {
        // Check if recent request already exists
        $check_stmt = $conn->prepare("
            SELECT id FROM notifications 
            WHERE user_type = 'teacher' AND user_id = ? AND module_id = ? AND type = 'result_request'
            AND created_at > DATE_SUB(NOW(), INTERVAL 24 HOUR)
        ");
        $check_stmt->bind_param("ii", $teacher_id, $module_id);
        $check_stmt->execute();
        
        if ($check_stmt->get_result()->num_rows === 0) {
            // Create database notification
            $notification_message = "Admin has requested you to submit results for $module_code - $module_name";
            $notif_stmt = $conn->prepare("
                INSERT INTO notifications (user_type, user_id, module_id, type, message, status, created_at)
                VALUES ('teacher', ?, ?, 'result_request', ?, 'unread', NOW())
            ");
            $notif_stmt->bind_param("iis", $teacher_id, $module_id, $notification_message);
            
            if ($notif_stmt->execute()) {
                // Send email notification
                $email_subject = "📧 Results Request: $module_code";
                $email_body = "
                <html>
                <body style='font-family: Arial, sans-serif;'>
                    <h2 style='color: #0d9488;'>Results Request</h2>
                    <p>Hello <strong>$teacher_name</strong>,</p>
                    <p>Admin has requested you to submit results for <strong>$module_code - $module_name</strong>.</p>
                    <p><a href='http://localhost/csms/teacher/upload_results.php?module_id=$module_id' style='background: #0d9488; color: white; padding: 10px 20px; text-decoration: none; border-radius: 5px;'>Submit Results</a></p>
                </body>
                </html>
                ";
                
                send_email($teacher_email, $teacher_name, $email_subject, $email_body);
                logAdminAction($conn, $admin_id, 'request_results', "Requested results for module ID: $module_id");
                $message = "✓ Request sent to $teacher_name";
                $message_type = "success";
            }
            $notif_stmt->close();
        } else {
            $message = "Request already sent within the last 24 hours";
            $message_type = "warning";
        }
        $check_stmt->close();
    }
}

// Get filter parameters
$course_filter = isset($_GET['course_id']) ? (int)$_GET['course_id'] : 0;
$year_filter = isset($_GET['year']) ? (int)$_GET['year'] : 0;
$semester_filter = isset($_GET['semester']) ? (int)$_GET['semester'] : 0;
$status_filter = isset($_GET['status']) ? $_GET['status'] : 'all';

// Build WHERE clause
$where = ["m.deleted = 0"];
$params = [];
$types = "";

if ($course_filter > 0) {
    $where[] = "m.course_id = ?";
    $params[] = $course_filter;
    $types .= "i";
}
if ($year_filter > 0) {
    $where[] = "m.year = ?";
    $params[] = $year_filter;
    $types .= "i";
}
if ($semester_filter > 0) {
    $where[] = "m.semester = ?";
    $params[] = $semester_filter;
    $types .= "i";
}

$where_clause = "WHERE " . implode(" AND ", $where);

// Get all courses for filter dropdown
$courses = $conn->query("SELECT course_id, course_name FROM courses WHERE deleted = 0 ORDER BY course_name")->fetch_all(MYSQLI_ASSOC);

// Get all modules with their results
$modules_query = "
    SELECT 
        m.module_id,
        m.module_code,
        m.module_name,
        m.year,
        m.semester,
        c.course_id,
        c.course_name,
        t.teacher_id,
        t.fullname as teacher_name,
        t.email as teacher_email,
        COUNT(DISTINCT r.id) as total_results,
        SUM(CASE WHEN r.status = 'published' THEN 1 ELSE 0 END) as published_results,
        SUM(CASE WHEN r.status = 'draft' THEN 1 ELSE 0 END) as draft_results,
        COUNT(DISTINCT r.student_id) as students_with_results
    FROM modules m
    JOIN courses c ON m.course_id = c.course_id
    LEFT JOIN teachers t ON m.teacher_id = t.teacher_id
    LEFT JOIN results r ON m.module_id = r.module_id
    $where_clause
    GROUP BY m.module_id, m.module_code, m.module_name, m.year, m.semester, c.course_id, c.course_name, t.teacher_id, t.fullname, t.email
    ORDER BY c.course_name, m.year, m.semester, m.module_code
";

$stmt = $conn->prepare($modules_query);
if (!empty($params)) {
    $stmt->bind_param($types, ...$params);
}
$stmt->execute();
$modules = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

// Calculate statistics
$total_modules = count($modules);
$modules_with_drafts = count(array_filter($modules, fn($m) => $m['draft_results'] > 0));
$modules_with_results = count(array_filter($modules, fn($m) => $m['total_results'] > 0));
$modules_without_results = count(array_filter($modules, fn($m) => $m['total_results'] == 0));
$total_drafts = array_sum(array_column($modules, 'draft_results'));
$total_published = array_sum(array_column($modules, 'published_results'));

// Get module IDs for bulk actions
$draft_module_ids = array_column(array_filter($modules, fn($m) => $m['draft_results'] > 0), 'module_id');
$published_module_ids = array_column(array_filter($modules, fn($m) => $m['published_results'] > 0), 'module_id');
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Manage Results - CSMS Admin</title>
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
            max-width: 1400px;
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

        .page-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 2rem;
        }

        .page-header h1 {
            font-size: 2rem;
            color: #0f172a;
        }

        .btn {
            padding: 0.8rem 1.5rem;
            border: none;
            border-radius: 10px;
            font-weight: 600;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
            transition: all 0.3s;
            cursor: pointer;
        }

        .btn-primary {
            background: #0d9488;
            color: white;
        }

        .btn-primary:hover {
            background: #115e59;
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

        .btn-secondary {
            background: #e2e8f0;
            color: #0f172a;
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

        .alert.warning {
            background: #fef3c7;
            color: #92400e;
            border-left: 4px solid #f59e0b;
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

        .filter-section {
            background: white;
            border-radius: 16px;
            padding: 1.5rem;
            margin-bottom: 2rem;
            box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.1);
        }

        .filter-form {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 1rem;
            align-items: flex-end;
        }

        .filter-group label {
            display: block;
            font-weight: 600;
            margin-bottom: 0.5rem;
            color: #0f172a;
            font-size: 0.9rem;
        }

        .filter-group select,
        .filter-group input {
            width: 100%;
            padding: 0.8rem;
            border: 2px solid #e2e8f0;
            border-radius: 8px;
            font-size: 0.95rem;
        }

        .filter-actions {
            display: flex;
            gap: 0.5rem;
            align-items: center;
        }

        .bulk-actions {
            background: white;
            border-radius: 16px;
            padding: 1.5rem;
            margin-bottom: 2rem;
            box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.1);
            display: flex;
            gap: 1rem;
            align-items: center;
        }

        .bulk-actions h3 {
            margin-right: auto;
            color: #0f172a;
        }

        .module-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(350px, 1fr));
            gap: 1.5rem;
            margin-top: 1.5rem;
        }

        .module-card {
            background: white;
            border-radius: 16px;
            overflow: hidden;
            box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.1);
            border-left: 4px solid #0d9488;
            transition: transform 0.3s;
        }

        .module-card:hover {
            transform: translateY(-4px);
            box-shadow: 0 12px 20px -8px rgba(0, 0, 0, 0.15);
        }

        .module-card.pending {
            border-left-color: #f59e0b;
        }

        .module-header {
            background: linear-gradient(135deg, #0f172a, #1e293b);
            color: white;
            padding: 1rem;
        }

        .module-code {
            font-size: 1.1rem;
            font-weight: 600;
        }

        .module-course {
            font-size: 0.85rem;
            opacity: 0.8;
            margin-top: 0.25rem;
        }

        .module-body {
            padding: 1rem;
        }

        .stats-row {
            display: flex;
            justify-content: space-between;
            margin-bottom: 0.5rem;
            padding: 0.5rem 0;
            border-bottom: 1px solid #e2e8f0;
        }

        .badge {
            display: inline-block;
            padding: 0.25rem 0.75rem;
            border-radius: 20px;
            font-size: 0.8rem;
            font-weight: 600;
        }

        .badge-published {
            background: #d1fae5;
            color: #065f46;
        }

        .badge-draft {
            background: #fef3c7;
            color: #92400e;
        }

        .badge-none {
            background: #e2e8f0;
            color: #475569;
        }

        .teacher-info {
            font-size: 0.9rem;
            color: #64748b;
            margin: 0.5rem 0;
        }

        .action-buttons {
            display: flex;
            gap: 0.5rem;
            margin-top: 1rem;
            flex-wrap: wrap;
        }

        .action-btn {
            flex: 1;
            min-width: 80px;
            padding: 0.5rem;
            border: none;
            border-radius: 6px;
            font-weight: 500;
            font-size: 0.8rem;
            cursor: pointer;
            text-decoration: none;
            text-align: center;
        }

        .btn-view {
            background: #e2e8f0;
            color: #0f172a;
        }

        .btn-publish {
            background: #10b981;
            color: white;
        }

        .btn-unpublish {
            background: #f59e0b;
            color: white;
        }

        .btn-request {
            background: #fef3c7;
            color: #92400e;
        }

        .back-link {
            margin-top: 2rem;
            text-align: center;
        }

        .no-results {
            text-align: center;
            padding: 3rem;
            background: white;
            border-radius: 16px;
            color: #64748b;
            grid-column: 1 / -1;
        }

        @media (max-width: 768px) {
            .container {
                padding: 0 1rem;
            }

            .filter-form {
                grid-template-columns: 1fr;
            }

            .bulk-actions {
                flex-direction: column;
                align-items: stretch;
            }

            .module-grid {
                grid-template-columns: 1fr;
            }

            .action-buttons {
                flex-direction: column;
            }
        }
    </style>
</head>
<body>

<div class="header">
    <h1>CSMS Admin</h1>
    <div class="admin-info">
        <span>Welcome, <?= htmlspecialchars($admin_name) ?></span>
        <a href="dashboard.php" class="logout-btn">Dashboard</a>
        <a href="logout.php" class="logout-btn">Logout</a>
    </div>
</div>

<div class="container">
    <div class="breadcrumb">
        <a href="dashboard.php">Dashboard</a> > 
        <strong>Results Management</strong>
    </div>

    <div class="page-header">
        <h1>📊 Results Management</h1>
        <a href="dashboard.php" class="btn btn-secondary">← Back to Dashboard</a>
    </div>

    <?php if ($message): ?>
        <div class="alert <?= $message_type ?>"><?= $message ?></div>
    <?php endif; ?>

    <!-- Statistics -->
    <div class="stats-grid">
        <div class="stat-card">
            <div class="stat-number"><?= $total_modules ?></div>
            <div class="stat-label">Total Modules</div>
        </div>
        <div class="stat-card">
            <div class="stat-number"><?= $modules_with_results ?></div>
            <div class="stat-label">Modules with Results</div>
        </div>
        <div class="stat-card">
            <div class="stat-number"><?= $modules_with_drafts ?></div>
            <div class="stat-label">Pending Review</div>
        </div>
        <div class="stat-card">
            <div class="stat-number"><?= $modules_without_results ?></div>
            <div class="stat-label">No Results Yet</div>
        </div>
    </div>

    <!-- Filter Section -->
    <div class="filter-section">
        <h3 style="margin-bottom: 1rem;">🔍 Filter Modules</h3>
        <form method="GET" class="filter-form">
            <div class="filter-group">
                <label>Course</label>
                <select name="course_id">
                    <option value="0">All Courses</option>
                    <?php foreach ($courses as $course): ?>
                    <option value="<?= $course['course_id'] ?>" <?= $course_filter == $course['course_id'] ? 'selected' : '' ?>>
                        <?= htmlspecialchars($course['course_name']) ?>
                    </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="filter-group">
                <label>Year</label>
                <select name="year">
                    <option value="0">All Years</option>
                    <option value="1" <?= $year_filter == 1 ? 'selected' : '' ?>>Year 1</option>
                    <option value="2" <?= $year_filter == 2 ? 'selected' : '' ?>>Year 2</option>
                    <option value="3" <?= $year_filter == 3 ? 'selected' : '' ?>>Year 3</option>
                </select>
            </div>
            <div class="filter-group">
                <label>Semester</label>
                <select name="semester">
                    <option value="0">All Semesters</option>
                    <option value="1" <?= $semester_filter == 1 ? 'selected' : '' ?>>Semester 1</option>
                    <option value="2" <?= $semester_filter == 2 ? 'selected' : '' ?>>Semester 2</option>
                </select>
            </div>
            <div class="filter-group">
                <label>&nbsp;</label>
                <div class="filter-actions">
                    <button type="submit" class="btn btn-primary" style="flex: 1;">Apply Filters</button>
                    <a href="manage_results.php" class="btn btn-secondary">Reset</a>
                </div>
            </div>
        </form>
    </div>

    <!-- Bulk Actions -->
    <?php if (!empty($draft_module_ids) || !empty($published_module_ids)): ?>
    <div class="bulk-actions">
        <h3>⚡ Bulk Actions</h3>
        
        <?php if (!empty($draft_module_ids)): ?>
        <form method="POST" style="display: inline;">
            <input type="hidden" name="csrf_token" value="<?= $csrf_token ?>">
            <input type="hidden" name="action" value="bulk_publish">
            <?php foreach ($draft_module_ids as $id): ?>
            <input type="hidden" name="module_ids[]" value="<?= $id ?>">
            <?php endforeach; ?>
            <button type="submit" class="btn btn-success" onclick="return confirm('Publish all pending results?')">
                📤 Publish All Pending (<?= count($draft_module_ids) ?>)
            </button>
        </form>
        <?php endif; ?>
        
        <?php if (!empty($published_module_ids)): ?>
        <form method="POST" style="display: inline;">
            <input type="hidden" name="csrf_token" value="<?= $csrf_token ?>">
            <input type="hidden" name="action" value="bulk_unpublish">
            <?php foreach ($published_module_ids as $id): ?>
            <input type="hidden" name="module_ids[]" value="<?= $id ?>">
            <?php endforeach; ?>
            <button type="submit" class="btn btn-warning" onclick="return confirm('Unpublish all published results?')">
                📥 Unpublish All Published (<?= count($published_module_ids) ?>)
            </button>
        </form>
        <?php endif; ?>
    </div>
    <?php endif; ?>

    <!-- Modules Grid -->
    <h2 style="margin: 2rem 0 1rem;">📚 Modules Status</h2>
    
    <?php if (count($modules) > 0): ?>
    <div class="module-grid">
        <?php foreach ($modules as $module): 
            $status_class = $module['draft_results'] > 0 ? 'pending' : '';
        ?>
        <div class="module-card <?= $status_class ?>">
            <div class="module-header">
                <div class="module-code"><?= htmlspecialchars($module['module_code']) ?></div>
                <div class="module-course"><?= htmlspecialchars($module['course_name']) ?></div>
            </div>
            <div class="module-body">
                <div class="stats-row">
                    <span>Published:</span>
                    <span class="badge badge-published"><?= $module['published_results'] ?></span>
                </div>
                <div class="stats-row">
                    <span>Draft:</span>
                    <span class="badge <?= $module['draft_results'] > 0 ? 'badge-draft' : 'badge-none' ?>">
                        <?= $module['draft_results'] ?>
                    </span>
                </div>
                <div class="stats-row">
                    <span>Status:</span>
                    <?php if ($module['total_results'] == 0): ?>
                        <span class="badge badge-none">No Results</span>
                    <?php elseif ($module['draft_results'] > 0): ?>
                        <span class="badge badge-draft">Pending Review</span>
                    <?php else: ?>
                        <span class="badge badge-published">Published</span>
                    <?php endif; ?>
                </div>
                
                <div class="teacher-info">
                    👨‍🏫 Teacher: <?= htmlspecialchars($module['teacher_name'] ?? 'Not Assigned') ?>
                </div>

                <div class="action-buttons">
                    <?php if ($module['total_results'] > 0): ?>
                        <a href="view_results.php?module_id=<?= $module['module_id'] ?>" class="action-btn btn-view">👁️ View Results</a>
                        
                        <?php if ($module['draft_results'] > 0): ?>
                        <form method="POST" style="flex: 1;">
                            <input type="hidden" name="csrf_token" value="<?= $csrf_token ?>">
                            <input type="hidden" name="action" value="publish">
                            <input type="hidden" name="module_id" value="<?= $module['module_id'] ?>">
                            <button type="submit" class="action-btn btn-publish">📤 Publish</button>
                        </form>
                        <?php endif; ?>

                        <?php if ($module['published_results'] > 0): ?>
                        <form method="POST" style="flex: 1;">
                            <input type="hidden" name="csrf_token" value="<?= $csrf_token ?>">
                            <input type="hidden" name="action" value="unpublish">
                            <input type="hidden" name="module_id" value="<?= $module['module_id'] ?>">
                            <button type="submit" class="action-btn btn-unpublish">📥 Unpublish</button>
                        </form>
                        <?php endif; ?>
                        
                    <?php else: ?>
                        <?php if ($module['teacher_id']): ?>
                        <form method="POST" style="flex: 1;">
                            <input type="hidden" name="csrf_token" value="<?= $csrf_token ?>">
                            <input type="hidden" name="action" value="request_results">
                            <input type="hidden" name="module_id" value="<?= $module['module_id'] ?>">
                            <input type="hidden" name="teacher_id" value="<?= $module['teacher_id'] ?>">
                            <input type="hidden" name="module_code" value="<?= htmlspecialchars($module['module_code']) ?>">
                            <input type="hidden" name="module_name" value="<?= htmlspecialchars($module['module_name']) ?>">
                            <input type="hidden" name="teacher_email" value="<?= htmlspecialchars($module['teacher_email'] ?? '') ?>">
                            <input type="hidden" name="teacher_name" value="<?= htmlspecialchars($module['teacher_name'] ?? 'Teacher') ?>">
                            <button type="submit" class="action-btn btn-request">📧 Request</button>
                        </form>
                        <?php else: ?>
                        <span style="color: #64748b; text-align: center; padding: 0.5rem;">No teacher assigned</span>
                        <?php endif; ?>
                    <?php endif; ?>
                </div>
            </div>
        </div>
        <?php endforeach; ?>
    </div>
    <?php else: ?>
    <div class="no-results">
        <p>No modules found matching your filters.</p>
    </div>
    <?php endif; ?>

    <div class="back-link">
        <a href="dashboard.php">← Back to Dashboard</a>
    </div>
</div>

</body>
</html>