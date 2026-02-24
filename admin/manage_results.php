<?php
session_start();
include '../config/db.php';
include '../config/email_config.php';

if(!isset($_SESSION['admin_logged_in'])){
    header("Location: login.php");
    exit();
}

function safe_int($value) {
    return filter_var($value, FILTER_VALIDATE_INT);
}

function safe_string($value) {
    return htmlspecialchars(trim($value), ENT_QUOTES, 'UTF-8');
}

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

$message = "";
$message_type = "";
$admin_name = $_SESSION['admin_name'] ?? 'Admin';

// Get parameters
$course_id = isset($_GET['course_id']) ? safe_int($_GET['course_id']) : null;
$year = isset($_GET['year']) ? safe_int($_GET['year']) : null;
$semester = isset($_GET['semester']) ? safe_int($_GET['semester']) : null;

if (!$course_id || !$year || !$semester) {
    header("Location: manage_courses.php");
    exit();
}

// Get course name
$course_stmt = $conn->prepare("SELECT course_name FROM courses WHERE course_id = ? AND deleted = 0");
$course_stmt->bind_param("i", $course_id);
$course_stmt->execute();
$course_result = $course_stmt->get_result()->fetch_assoc();
$course_name = $course_result['course_name'] ?? 'Unknown';
$course_stmt->close();

/* ================= REQUEST RESULTS FROM TEACHER ================= */
if($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'request_results'){
    if (!hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'] ?? '')) {
        $message = "Security token verification failed";
        $message_type = "error";
    } else {
        $module_id = safe_int($_POST['module_id'] ?? 0);
        $teacher_id = safe_int($_POST['teacher_id'] ?? 0);
        $module_code = safe_string($_POST['module_code'] ?? '');
        $module_name = safe_string($_POST['module_name'] ?? '');
        
        if($module_id && $teacher_id){
            // Get teacher email and name
            $teacher_stmt = $conn->prepare("SELECT fullname, email FROM teachers WHERE teacher_id = ?");
            $teacher_stmt->bind_param("i", $teacher_id);
            $teacher_stmt->execute();
            $teacher_result = $teacher_stmt->get_result()->fetch_assoc();
            $teacher_email = $teacher_result['email'] ?? '';
            $teacher_fullname = $teacher_result['fullname'] ?? 'Teacher';
            $teacher_stmt->close();
            
            // Check if recent request already exists
            $check_stmt = $conn->prepare("
                SELECT id FROM notifications 
                WHERE teacher_id = ? AND module_id = ? AND type = 'result_request'
                ORDER BY created_at DESC LIMIT 1
            ");
            $check_stmt->bind_param("ii", $teacher_id, $module_id);
            $check_stmt->execute();
            $check_result = $check_stmt->get_result();
            
            if($check_result->num_rows === 0){
                // Create database notification
                $notification_message = "Admin has requested you to submit results for $module_code";
                $notif_stmt = $conn->prepare("
                    INSERT INTO notifications (teacher_id, module_id, type, message, status, created_at)
                    VALUES (?, ?, 'result_request', ?, 'unread', NOW())
                ");
                $notif_stmt->bind_param("iis", $teacher_id, $module_id, $notification_message);
                
                if($notif_stmt->execute()){
                    // Send email notification
                    if($teacher_email){
                        $email_subject = "📧 Results Request - $module_code";
                        
                        $email_body = "
                        <html>
                        <head>
                            <style>
                                body { font-family: Arial, sans-serif; background: #f5f5f5; }
                                .container { max-width: 600px; margin: 20px auto; background: white; padding: 30px; border-radius: 10px; box-shadow: 0 2px 10px rgba(0,0,0,0.1); }
                                .header { background: linear-gradient(135deg, #c46a6a, #f2c66d); color: white; padding: 20px; border-radius: 8px; margin-bottom: 20px; }
                                .header h1 { margin: 0; font-size: 24px; }
                                .content { line-height: 1.6; color: #333; }
                                .module-info { background: #f9f9f9; padding: 15px; border-left: 4px solid #c46a6a; border-radius: 5px; margin: 20px 0; }
                                .module-info p { margin: 5px 0; }
                                .footer { text-align: center; padding: 20px; color: #999; font-size: 12px; border-top: 1px solid #eee; margin-top: 20px; }
                            </style>
                        </head>
                        <body>
                            <div class='container'>
                                <div class='header'>
                                    <h1>📧 Results Request</h1>
                                </div>
                                
                                <div class='content'>
                                    <p>Hello <strong>$teacher_fullname</strong>,</p>
                                    <p>The admin has requested you to submit results for the following module:</p>
                                    
                                    <div class='module-info'>
                                        <p><strong>Course:</strong> $course_name</p>
                                        <p><strong>Module Code:</strong> $module_code</p>
                                        <p><strong>Module Name:</strong> $module_name</p>
                                    </div>
                                    
                                    <p>Please log in to submit the results.</p>
                                    <p>Best regards, <strong>CSMS Administrator</strong></p>
                                </div>
                                
                                <div class='footer'>
                                    <p>Automated message from CSMS. Please do not reply.</p>
                                </div>
                            </div>
                        </body>
                        </html>
                        ";
                        
                        send_email($teacher_email, $teacher_fullname, $email_subject, $email_body);
                    }
                    
                    $message = "✓ Request sent to teacher";
                    $message_type = "success";
                } else {
                    $message = "Error creating notification";
                    $message_type = "error";
                }
                $notif_stmt->close();
            } else {
                $message = "Request already sent";
                $message_type = "info";
            }
            $check_stmt->close();
        }
    }
}

/* ================= PUBLISH RESULTS & NOTIFY STUDENTS ================= */
if($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'publish'){
    if (!hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'] ?? '')) {
        $message = "Security token verification failed";
        $message_type = "error";
    } else {
        $result_id = safe_int($_POST['result_id'] ?? 0);
        $module_id = safe_int($_POST['module_id'] ?? 0);
        
        if($result_id){
            $stmt = $conn->prepare("UPDATE results SET status = 'published' WHERE id = ?");
            $stmt->bind_param("i", $result_id);
            
            if($stmt->execute()){
                // Get module and course info
                $module_info_stmt = $conn->prepare("
                    SELECT m.module_code, m.module_name, c.course_name
                    FROM modules m
                    JOIN courses c ON m.course_id = c.course_id
                    WHERE m.module_id = ?
                ");
                $module_info_stmt->bind_param("i", $module_id);
                $module_info_stmt->execute();
                $module_info = $module_info_stmt->get_result()->fetch_assoc();
                $module_info_stmt->close();
                
                // Get all students in this module and notify them
                $students_stmt = $conn->prepare("
                    SELECT DISTINCT s.student_id, s.name, s.email
                    FROM students s
                    JOIN results r ON s.student_id = r.student_id
                    WHERE r.module_id = ? AND s.status = 'active'
                ");
                $students_stmt->bind_param("i", $module_id);
                $students_stmt->execute();
                $students = $students_stmt->get_result()->fetch_all(MYSQLI_ASSOC);
                $students_stmt->close();
                
                // Send notification to each student
                foreach($students as $student){
                    // Create in-system notification
                    $notif_msg = "Your results for {$module_info['module_code']} have been published";
                    $student_notif_stmt = $conn->prepare("
                        INSERT INTO notifications (student_id, module_id, type, message, status, created_at)
                        VALUES (?, ?, 'result_published', ?, 'unread', NOW())
                    ");
                    $student_notif_stmt->bind_param("iis", $student['student_id'], $module_id, $notif_msg);
                    $student_notif_stmt->execute();
                    $student_notif_stmt->close();
                    
                    // Send email notification
                    if($student['email']){
                        $email_subject = "📊 Your Results Are Published - {$module_info['module_code']}";
                        
                        $email_body = "
                        <html>
                        <head>
                            <style>
                                body { font-family: Arial, sans-serif; background: #f5f5f5; }
                                .container { max-width: 600px; margin: 20px auto; background: white; padding: 30px; border-radius: 10px; box-shadow: 0 2px 10px rgba(0,0,0,0.1); }
                                .header { background: linear-gradient(135deg, #4CAF50, #45a049); color: white; padding: 20px; border-radius: 8px; margin-bottom: 20px; }
                                .header h1 { margin: 0; font-size: 24px; }
                                .content { line-height: 1.6; color: #333; }
                                .module-info { background: #f0f8f0; padding: 15px; border-left: 4px solid #4CAF50; border-radius: 5px; margin: 20px 0; }
                                .module-info p { margin: 5px 0; }
                                .btn { display: inline-block; background: #4CAF50; color: white; padding: 10px 20px; text-decoration: none; border-radius: 5px; margin-top: 15px; }
                                .footer { text-align: center; padding: 20px; color: #999; font-size: 12px; border-top: 1px solid #eee; margin-top: 20px; }
                            </style>
                        </head>
                        <body>
                            <div class='container'>
                                <div class='header'>
                                    <h1>✅ Results Published!</h1>
                                </div>
                                
                                <div class='content'>
                                    <p>Hello <strong>{$student['name']}</strong>,</p>
                                    <p>Great news! Your results have been published for:</p>
                                    
                                    <div class='module-info'>
                                        <p><strong>Course:</strong> {$module_info['course_name']}</p>
                                        <p><strong>Module Code:</strong> {$module_info['module_code']}</p>
                                        <p><strong>Module Name:</strong> {$module_info['module_name']}</p>
                                    </div>
                                    
                                    <p>You can now log in to the CSMS system to view your results.</p>
                                    <a href='#' class='btn'>View Your Results</a>
                                    
                                    <p>Best regards, <strong>CSMS System</strong></p>
                                </div>
                                
                                <div class='footer'>
                                    <p>Automated message from CSMS. Please do not reply.</p>
                                </div>
                            </div>
                        </body>
                        </html>
                        ";
                        
                        send_email($student['email'], $student['name'], $email_subject, $email_body);
                    }
                }
                
                $message = "✓ Result published & " . count($students) . " students notified";
                $message_type = "success";
            } else {
                $message = "Error publishing result";
                $message_type = "error";
            }
            $stmt->close();
        }
    }
}

/* ================= UNPUBLISH RESULTS ================= */
if($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'unpublish'){
    if (!hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'] ?? '')) {
        $message = "Security token verification failed";
        $message_type = "error";
    } else {
        $result_id = safe_int($_POST['result_id'] ?? 0);
        
        if($result_id){
            $stmt = $conn->prepare("UPDATE results SET status = 'draft' WHERE id = ?");
            $stmt->bind_param("i", $result_id);
            
            if($stmt->execute()){
                $message = "✓ Result unpublished successfully";
                $message_type = "success";
            } else {
                $message = "Error unpublishing result";
                $message_type = "error";
            }
            $stmt->close();
        }
    }
}

// Get all modules with results for this course/year/semester
$modules_query = "
    SELECT 
        m.module_id,
        m.module_code,
        m.module_name,
        m.teacher_id,
        COALESCE(t.fullname, t.email, 'Unassigned') as teacher_name,
        COUNT(r.id) as total_results,
        SUM(CASE WHEN r.status = 'published' THEN 1 ELSE 0 END) as published_results,
        SUM(CASE WHEN r.status = 'draft' THEN 1 ELSE 0 END) as draft_results,
        COUNT(DISTINCT r.student_id) as students_with_results,
        MAX(r.id) as last_result_id,
        MAX(r.status) as last_result_status
    FROM modules m
    LEFT JOIN teachers t ON m.teacher_id = t.teacher_id
    LEFT JOIN results r ON m.module_id = r.module_id
    WHERE m.course_id = ? AND m.year = ? AND m.semester = ? AND m.deleted = 0
    GROUP BY m.module_id, m.module_code, m.module_name, m.teacher_id, t.fullname, t.email
    ORDER BY m.module_code ASC
";

$modules_stmt = $conn->prepare($modules_query);
$modules_stmt->bind_param("iii", $course_id, $year, $semester);
$modules_stmt->execute();
$modules = $modules_stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$modules_stmt->close();

// Calculate statistics
$total_modules = count($modules);
$modules_with_results = 0;
$modules_without_results = 0;

foreach($modules as $module) {
    if($module['total_results'] > 0) {
        $modules_with_results++;
    } else {
        $modules_without_results++;
    }
}

?>

<!DOCTYPE html>
<html>
<head>
    <title>Manage Results - CSMS</title>
    <link rel="stylesheet" href="../assets/css/auth.css">
    <style>
        .container {
            max-width: 1200px;
            margin: 0 auto;
            padding: 20px;
        }

        .auth-card {
            width: 100%;
            max-width: 1200px;
            margin: 0 auto;
        }

        .alert {
            padding: 15px;
            margin: 15px 0;
            border-radius: 5px;
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

        .alert.info {
            background: #d1ecf1;
            color: #0c5460;
            display: block;
            border: 1px solid #bee5eb;
        }

        h2 {
            text-align: center;
            color: #333;
            margin-bottom: 10px;
        }

        .breadcrumb {
            text-align: center;
            color: #666;
            margin-bottom: 30px;
            font-size: 14px;
        }

        .breadcrumb strong {
            color: #333;
        }

        /* Statistics Section */
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
            gap: 15px;
            margin: 20px 0;
        }

        .stat-box {
            background: linear-gradient(135deg, var(--terra-rosa), var(--honey-glow));
            color: white;
            padding: 20px;
            border-radius: 10px;
            text-align: center;
            box-shadow: 0 4px 6px rgba(0,0,0,0.1);
        }

        .stat-box h3 {
            margin: 0;
            font-size: 14px;
            opacity: 0.9;
        }

        .stat-number {
            font-size: 32px;
            font-weight: bold;
            margin-top: 10px;
        }

        /* Results Table */
        table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 20px;
            background: white;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
            border-radius: 8px;
            overflow: hidden;
        }

        th {
            background: linear-gradient(135deg, var(--skipping-stones), var(--minty-fresh));
            color: var(--art-craft);
            padding: 15px;
            text-align: left;
            font-weight: bold;
            border-bottom: 2px solid #ddd;
            font-size: 13px;
        }

        td {
            padding: 12px 15px;
            border-bottom: 1px solid #eee;
            font-size: 13px;
        }

        tr:hover {
            background: #f9f9f9;
        }

        tr:last-child td {
            border-bottom: none;
        }

        .module-code {
            font-weight: bold;
            color: var(--midnight-garden);
        }

        .status-badge {
            display: inline-block;
            padding: 5px 12px;
            border-radius: 20px;
            font-size: 11px;
            font-weight: bold;
        }

        .status-published {
            background: #d4edda;
            color: #155724;
        }

        .status-draft {
            background: #fff3cd;
            color: #856404;
        }

        .status-no-results {
            background: #f8d7da;
            color: #721c24;
        }

        .actions {
            display: flex;
            gap: 6px;
            flex-wrap: wrap;
        }

        .btn {
            padding: 6px 10px;
            border: none;
            border-radius: 5px;
            cursor: pointer;
            font-size: 11px;
            font-weight: bold;
            text-decoration: none;
            transition: all 0.3s;
            display: inline-block;
        }

        .btn-view {
            background: #2196F3;
            color: white;
        }

        .btn-view:hover {
            background: #0b7dda;
        }

        .btn-publish {
            background: #4CAF50;
            color: white;
        }

        .btn-publish:hover {
            background: #45a049;
        }

        .btn-unpublish {
            background: #FF9800;
            color: white;
        }

        .btn-unpublish:hover {
            background: #E68900;
        }

        .btn-request {
            background: #9C27B0;
            color: white;
        }

        .btn-request:hover {
            background: #7B1FA2;
        }

        .back-btn {
            display: inline-block;
            padding: 10px 20px;
            background: #2196F3;
            color: white;
            text-decoration: none;
            border-radius: 6px;
            margin-bottom: 20px;
            font-weight: bold;
        }

        .back-btn:hover {
            background: #0b7dda;
        }

        .no-data {
            text-align: center;
            padding: 40px;
            color: #666;
            font-size: 16px;
        }

        @media (max-width: 768px) {
            .container {
                padding: 10px;
            }

            .stats-grid {
                grid-template-columns: 1fr;
            }

            table {
                font-size: 11px;
            }

            th, td {
                padding: 8px;
            }

            .actions {
                flex-direction: column;
            }

            .btn {
                width: 100%;
                text-align: center;
            }
        }
    </style>
</head>
<body>

<div class="container">
    <div class="auth-card">
        <h2>📊 Manage Results</h2>

        <div class="breadcrumb">
            <strong><?= htmlspecialchars($course_name) ?></strong> | Year <strong><?= $year ?></strong> | Semester <strong><?= $semester ?></strong>
        </div>

        <!-- Alert Messages -->
        <?php if ($message): ?>
            <div class="alert <?= $message_type === 'success' ? 'success' : ($message_type === 'info' ? 'info' : 'error') ?>">
                <?= htmlspecialchars($message) ?>
            </div>
        <?php endif; ?>

        <a href="manage_courses.php" class="back-btn">← Back to Manage Courses</a>

        <!-- Statistics Section -->
        <div class="stats-grid">
            <div class="stat-box">
                <h3>📚 Total Modules</h3>
                <div class="stat-number"><?= $total_modules ?></div>
            </div>
            <div class="stat-box">
                <h3>✅ Results Sent</h3>
                <div class="stat-number"><?= $modules_with_results ?></div>
            </div>
            <div class="stat-box">
                <h3>⏳ Pending</h3>
                <div class="stat-number"><?= $modules_without_results ?></div>
            </div>
        </div>

        <!-- Results Table -->
        <?php if ($total_modules > 0): ?>
            <table>
                <thead>
                    <tr>
                        <th>Module Code</th>
                        <th>Module Name</th>
                        <th>Teacher</th>
                        <th>Total Results</th>
                        <th>Status</th>
                        <th>Published</th>
                        <th>Draft</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach($modules as $module): ?>
                    <tr>
                        <td class="module-code"><?= htmlspecialchars($module['module_code']) ?></td>
                        <td><?= htmlspecialchars(substr($module['module_name'], 0, 25)) ?></td>
                        <td><?= htmlspecialchars($module['teacher_name']) ?></td>
                        <td>
                            <?php if($module['total_results'] > 0): ?>
                                <strong><?= $module['total_results'] ?></strong> students
                            <?php else: ?>
                                <span style="color: #999;">No results</span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?php if($module['total_results'] == 0): ?>
                                <span class="status-badge status-no-results">No Results</span>
                            <?php elseif($module['draft_results'] > 0 && $module['published_results'] == 0): ?>
                                <span class="status-badge status-draft">All Draft</span>
                            <?php elseif($module['published_results'] > 0 && $module['draft_results'] == 0): ?>
                                <span class="status-badge status-published">All Published</span>
                            <?php else: ?>
                                <span class="status-badge status-draft">Mixed</span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?php if($module['published_results'] > 0): ?>
                                <span class="status-badge status-published"><?= $module['published_results'] ?></span>
                            <?php else: ?>
                                <span style="color: #999;">0</span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?php if($module['draft_results'] > 0): ?>
                                <span class="status-badge status-draft"><?= $module['draft_results'] ?></span>
                            <?php else: ?>
                                <span style="color: #999;">0</span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <div class="actions">
                                <?php if($module['total_results'] > 0): ?>
                                    <a href="view_results.php?module_id=<?= $module['module_id'] ?>" class="btn btn-view">👁️ View</a>
                                    
                                    <?php if($module['draft_results'] > 0): ?>
                                    <form method="POST" style="display: inline;">
                                        <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">
                                        <input type="hidden" name="action" value="publish">
                                        <input type="hidden" name="result_id" value="<?= $module['last_result_id'] ?>">
                                        <input type="hidden" name="module_id" value="<?= $module['module_id'] ?>">
                                        <button type="submit" class="btn btn-publish" onclick="return confirm('Publish results & notify all students?')">📤 Pub</button>
                                    </form>
                                    <?php endif; ?>

                                    <?php if($module['published_results'] > 0): ?>
                                    <form method="POST" style="display: inline;">
                                        <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">
                                        <input type="hidden" name="action" value="unpublish">
                                        <input type="hidden" name="result_id" value="<?= $module['last_result_id'] ?>">
                                        <button type="submit" class="btn btn-unpublish" onclick="return confirm('Unpublish results?')">📥 Unpub</button>
                                    </form>
                                    <?php endif; ?>
                                <?php else: ?>
                                    <?php if($module['teacher_id']): ?>
                                    <form method="POST" style="display: inline;">
                                        <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">
                                        <input type="hidden" name="action" value="request_results">
                                        <input type="hidden" name="module_id" value="<?= $module['module_id'] ?>">
                                        <input type="hidden" name="teacher_id" value="<?= $module['teacher_id'] ?>">
                                        <input type="hidden" name="module_code" value="<?= htmlspecialchars($module['module_code']) ?>">
                                        <input type="hidden" name="module_name" value="<?= htmlspecialchars($module['module_name']) ?>">
                                        <button type="submit" class="btn btn-request" onclick="return confirm('Request results from teacher?')">📧 Request</button>
                                    </form>
                                    <?php else: ?>
                                    <span style="color: #999; font-size: 11px;">No teacher</span>
                                    <?php endif; ?>
                                <?php endif; ?>
                            </div>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php else: ?>
            <div class="no-data">
                <p>❌ No modules found for this course/year/semester combination</p>
            </div>
        <?php endif; ?>

    </div>
</div>

</body>
</html>