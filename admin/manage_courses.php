<?php
session_start();
include '../config/db.php';

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

/* ================= ADD COURSE ================= */
if($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'add'){
    
    if (!hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'] ?? '')) {
        $message = "Security token verification failed";
        $message_type = "error";
    } else {
        $course_name = safe_string($_POST['course_name'] ?? '');
        $duration = safe_int($_POST['duration'] ?? 0);

        if(empty($course_name) || !$duration){
            $message = "Please fill all required fields";
            $message_type = "error";
        } else {
            $check = $conn->prepare("SELECT course_id FROM courses WHERE course_name = ? AND deleted = 0");
            $check->bind_param("s", $course_name);
            $check->execute();
            
            if($check->get_result()->num_rows > 0){
                $message = "Course name already exists";
                $message_type = "error";
            } else {
                $stmt = $conn->prepare("INSERT INTO courses (course_name, status, deleted) VALUES (?, 'active', 0)");
                $stmt->bind_param("s", $course_name);
                
                if($stmt->execute()){
                    $message = "Course created successfully";
                    $message_type = "success";
                } else {
                    $message = "Error creating course: " . $stmt->error;
                    $message_type = "error";
                }
                $stmt->close();
            }
            $check->close();
        }
    }
}

/* ================= UPDATE COURSE ================= */
if($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'update'){
    
    if (!hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'] ?? '')) {
        $message = "Security token verification failed";
        $message_type = "error";
    } else {
        $course_id = safe_int($_POST['course_id'] ?? 0);
        $course_name = safe_string($_POST['course_name'] ?? '');
        $status = safe_string($_POST['status'] ?? 'active');

        if(!$course_id || empty($course_name)){
            $message = "Please fill all required fields";
            $message_type = "error";
        } else {
            $stmt = $conn->prepare("
                UPDATE courses 
                SET course_name = ?, status = ?
                WHERE course_id = ? AND deleted = 0
            ");
            $stmt->bind_param("ssi", $course_name, $status, $course_id);
            
            if($stmt->execute()){
                $message = "Course updated successfully";
                $message_type = "success";
            } else {
                $message = "Error updating course: " . $stmt->error;
                $message_type = "error";
            }
            $stmt->close();
        }
    }
}

/* ================= DELETE COURSE ================= */
if($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'delete'){
    
    if (!hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'] ?? '')) {
        $message = "Security token verification failed";
        $message_type = "error";
    } else {
        $course_id = safe_int($_POST['course_id'] ?? 0);
        
        if(!$course_id){
            $message = "Invalid course ID";
            $message_type = "error";
        } else {
            $stmt = $conn->prepare("UPDATE courses SET deleted = 1 WHERE course_id = ?");
            $stmt->bind_param("i", $course_id);
            
            if($stmt->execute()){
                $message = "Course deleted successfully";
                $message_type = "success";
            } else {
                $message = "Error deleting course";
                $message_type = "error";
            }
            $stmt->close();
        }
    }
}

/* ================= PUBLISH/UNPUBLISH RESULTS ================= */
if($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && in_array($_POST['action'], ['publish', 'unpublish'])){
    
    if (!hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'] ?? '')) {
        $message = "Security token verification failed";
        $message_type = "error";
    } else {
        $result_id = safe_int($_POST['result_id'] ?? 0);
        $status = $_POST['action'] === 'publish' ? 'published' : 'draft';
        
        if($result_id){
            $stmt = $conn->prepare("UPDATE results SET status = ? WHERE id = ?");
            $stmt->bind_param("si", $status, $result_id);
            
            if($stmt->execute()){
                $message = "✓ Result " . $_POST['action'] . "ed successfully";
                $message_type = "success";
            } else {
                $message = "Error updating result";
                $message_type = "error";
            }
            $stmt->close();
        }
    }
}

/* ================= BULK PUBLISH/UNPUBLISH ================= */
if($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && in_array($_POST['action'], ['bulk_publish', 'bulk_unpublish'])){
    
    if (!hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'] ?? '')) {
        $message = "Security token verification failed";
        $message_type = "error";
    } else {
        $course_id = safe_int($_POST['course_id'] ?? 0);
        $semester = safe_int($_POST['semester'] ?? 0);
        $status = $_POST['action'] === 'bulk_publish' ? 'published' : 'draft';
        
        if($course_id && $semester){
            // Update all results for modules in this course/semester
            $stmt = $conn->prepare("
                UPDATE results r
                INNER JOIN modules m ON r.module_id = m.module_id
                SET r.status = ?
                WHERE m.course_id = ? AND m.semester = ? AND m.deleted = 0
            ");
            $stmt->bind_param("sii", $status, $course_id, $semester);
            
            if($stmt->execute()){
                $message = "✓ All results have been " . $_POST['action'] . "ed successfully";
                $message_type = "success";
            } else {
                $message = "Error updating results";
                $message_type = "error";
            }
            $stmt->close();
        }
    }
}

/* ================= FETCH COURSES ================= */
$stmt = $conn->prepare("SELECT course_id, course_name, status, deleted FROM courses WHERE deleted = 0 ORDER BY course_name ASC");
$stmt->execute();
$courses = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

$selected_course = null;
$course_id = isset($_GET['course_id']) ? safe_int($_GET['course_id']) : null;
if($course_id){
    $stmt = $conn->prepare("SELECT course_id, course_name FROM courses WHERE course_id = ? AND deleted = 0");
    $stmt->bind_param("i", $course_id);
    $stmt->execute();
    $selected_course = $stmt->get_result()->fetch_assoc();
    $stmt->close();
}

$year = isset($_GET['year']) ? safe_int($_GET['year']) : null;
$semester = isset($_GET['semester']) ? safe_int($_GET['semester']) : null;

?>

<!DOCTYPE html>
<html>
<head>
    <title>Manage Courses - CSMS</title>
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

        .welcome-section {
            background: linear-gradient(135deg, var(--terra-rosa), var(--honey-glow));
            color: white;
            padding: 25px;
            border-radius: 12px;
            margin-bottom: 30px;
            text-align: center;
            box-shadow: 0 4px 6px rgba(0,0,0,0.1);
        }

        .welcome-section h1 {
            margin: 0;
            font-size: 28px;
            margin-bottom: 8px;
        }

        .welcome-section p {
            margin: 0;
            font-size: 16px;
            opacity: 0.95;
        }

        .logout-btn {
            display: inline-block;
            margin-top: 15px;
            padding: 8px 20px;
            background: rgba(255,255,255,0.2);
            color: white;
            border: 2px solid white;
            border-radius: 6px;
            text-decoration: none;
            font-weight: bold;
            cursor: pointer;
            transition: all 0.3s;
        }

        .logout-btn:hover {
            background: white;
            color: var(--terra-rosa);
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

        .center {
            text-align: center;
        }

        h2 {
            text-align: center;
            color: #333;
            margin-bottom: 30px;
            margin-top: 40px;
        }

        h3 {
            text-align: center;
            color: #555;
            margin-top: 20px;
            margin-bottom: 15px;
        }

        /* Course Selection */
        .courses-container {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(250px, 1fr));
            gap: 20px;
            margin: 30px 0;
        }

        .course-card {
            background: linear-gradient(135deg, var(--skipping-stones), var(--minty-fresh));
            padding: 20px;
            border-radius: 18px;
            text-align: center;
            font-weight: bold;
            font-size: 18px;
            cursor: pointer;
            transition: transform 0.2s, box-shadow 0.2s;
            text-decoration: none;
            color: var(--art-craft);
            display: flex;
            flex-direction: column;
            justify-content: center;
            align-items: center;
            min-height: 120px;
            box-shadow: 0 4px 6px rgba(0,0,0,0.1);
        }

        .course-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 8px 12px rgba(0,0,0,0.15);
        }

        .course-card-actions {
            display: flex;
            gap: 5px;
            margin-top: 10px;
            font-size: 12px;
            width: 100%;
        }

        .btn-small {
            flex: 1;
            padding: 4px 8px;
            border: none;
            border-radius: 4px;
            cursor: pointer;
            font-size: 11px;
            font-weight: bold;
            text-decoration: none;
            text-align: center;
            transition: all 0.3s;
        }

        .btn-edit-course {
            background: #4CAF50;
            color: white;
        }

        .btn-edit-course:hover {
            background: #45a049;
        }

        .btn-delete-course {
            background: #f44336;
            color: white;
        }

        .btn-delete-course:hover {
            background: #da190b;
        }

        /* RESULTS SECTION */
        .results-card {
            background: linear-gradient(135deg, #667eea, #764ba2);
            color: white;
            padding: 25px;
            border-radius: 12px;
            cursor: pointer;
            text-align: center;
            font-weight: bold;
            font-size: 20px;
            box-shadow: 0 4px 8px rgba(0,0,0,0.1);
            transition: all 0.3s;
            margin-top: 30px;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 15px;
            min-height: 80px;
        }

        .results-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 8px 12px rgba(0,0,0,0.15);
        }

        .results-card-icon {
            font-size: 40px;
        }

        .results-card-text {
            text-align: left;
        }

        .results-card-title {
            font-size: 22px;
            margin: 0;
        }

        .results-card-subtitle {
            font-size: 13px;
            opacity: 0.9;
            margin: 5px 0 0 0;
        }

        /* Results Content (Hidden by default) */
        .results-content {
            display: none;
            margin-top: 30px;
        }

        .results-content.active {
            display: block;
        }

        .semester-cards {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(400px, 1fr));
            gap: 25px;
            margin-bottom: 30px;
        }

        .semester-card {
            background: white;
            border-radius: 12px;
            box-shadow: 0 4px 8px rgba(0,0,0,0.1);
            overflow: hidden;
            border-top: 4px solid var(--terra-rosa);
            cursor: pointer;
            transition: all 0.3s;
        }

        .semester-card:hover {
            box-shadow: 0 8px 15px rgba(0,0,0,0.15);
            transform: translateY(-3px);
        }

        .semester-card-header {
            background: linear-gradient(135deg, var(--terra-rosa), var(--honey-glow));
            color: white;
            padding: 15px;
            font-weight: bold;
            font-size: 16px;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .semester-card-icon {
            font-size: 20px;
        }

        .semester-card-content {
            display: none;
            padding: 15px;
        }

        .semester-card-content.active {
            display: block;
        }

        .semester-stats {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 8px;
            margin-bottom: 15px;
        }

        .stat-item {
            background: #f5f5f5;
            padding: 10px;
            border-radius: 6px;
            text-align: center;
            border: 1px solid #eee;
        }

        .stat-label {
            font-size: 11px;
            color: #666;
            margin-bottom: 5px;
            font-weight: bold;
        }

        .stat-value {
            font-size: 18px;
            font-weight: bold;
            color: var(--terra-rosa);
        }

        .results-table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 10px;
        }

        .results-table th {
            background: #f5f5f5;
            padding: 8px;
            text-align: left;
            font-weight: bold;
            font-size: 12px;
            border-bottom: 2px solid #ddd;
            color: #333;
        }

        .results-table td {
            padding: 8px;
            border-bottom: 1px solid #eee;
            font-size: 12px;
        }

        .results-table tr:hover {
            background: #f9f9f9;
        }

        .module-code {
            font-weight: bold;
            color: var(--midnight-garden);
        }

        .status-badge {
            display: inline-block;
            padding: 3px 8px;
            border-radius: 10px;
            font-size: 10px;
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

        .result-actions {
            display: flex;
            gap: 5px;
        }

        .result-btn {
            padding: 4px 8px;
            border: none;
            border-radius: 3px;
            cursor: pointer;
            font-size: 10px;
            font-weight: bold;
            text-decoration: none;
            display: inline-block;
            transition: all 0.2s;
        }

        .result-btn-view {
            background: #2196F3;
            color: white;
        }

        .result-btn-view:hover {
            background: #0b7dda;
        }

        .result-btn-publish {
            background: #4CAF50;
            color: white;
        }

        .result-btn-publish:hover {
            background: #45a049;
        }

        .result-btn-unpublish {
            background: #FF9800;
            color: white;
        }

        .result-btn-unpublish:hover {
            background: #E68900;
        }

        .no-results {
            text-align: center;
            padding: 15px;
            color: #999;
            font-size: 12px;
        }

        .bulk-action-btn {
            display: block;
            width: 100%;
            padding: 10px;
            margin-top: 10px;
            border: none;
            border-radius: 6px;
            cursor: pointer;
            font-weight: bold;
            font-size: 12px;
            transition: all 0.3s;
        }

        .bulk-publish-btn {
            background: #4CAF50;
            color: white;
        }

        .bulk-publish-btn:hover {
            background: #45a049;
        }

        .bulk-unpublish-btn {
            background: #FF9800;
            color: white;
        }

        .bulk-unpublish-btn:hover {
            background: #E68900;
        }

        /* Year/Semester Selection */
        .selection-form {
            background: #f9f9f9;
            padding: 20px;
            border-radius: 10px;
            margin: 20px 0;
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 15px;
            align-items: flex-end;
        }

        .form-group {
            display: flex;
            flex-direction: column;
        }

        .form-group label {
            font-weight: bold;
            margin-bottom: 5px;
            color: #333;
        }

        select, button {
            padding: 10px;
            border: 1px solid #ddd;
            border-radius: 5px;
            font-size: 14px;
            background: white;
        }

        select:focus, button:focus {
            outline: none;
            border-color: #4CAF50;
            box-shadow: 0 0 5px rgba(76, 175, 80, 0.3);
        }

        .btn-continue {
            background: #2196F3;
            color: white;
            border: none;
            cursor: pointer;
            font-weight: bold;
            padding: 10px 30px;
        }

        .btn-continue:hover {
            background: #0b7dda;
        }

        /* Management Options */
        .manage-buttons {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(150px, 1fr));
            gap: 15px;
            margin: 30px 0;
        }

        .manage-buttons a {
            display: flex;
            align-items: center;
            justify-content: center;
            min-height: 100px;
            padding: 20px;
            border-radius: 10px;
            text-decoration: none;
            font-weight: bold;
            color: white;
            transition: all 0.3s;
            text-align: center;
            box-shadow: 0 4px 6px rgba(0,0,0,0.1);
        }

        .manage-buttons a:hover {
            transform: translateY(-5px);
            box-shadow: 0 8px 12px rgba(0,0,0,0.15);
        }

        .btn-students {
            background: linear-gradient(135deg, #667eea, #764ba2);
        }

        .btn-modules {
            background: linear-gradient(135deg, #f093fb, #f5576c);
        }

        .btn-teachers {
            background: linear-gradient(135deg, #4facfe, #00f2fe);
        }

        .btn-attendance {
            background: linear-gradient(135deg, #43e97b, #38f9d7);
        }

        .btn-results {
            background: linear-gradient(135deg, #fa709a, #fee140);
            color: #333;
        }

        hr {
            margin: 30px 0;
            border: none;
            border-top: 2px solid #eee;
        }

        .breadcrumb {
            font-size: 14px;
            color: #666;
            margin-bottom: 20px;
            text-align: center;
        }

        .breadcrumb strong {
            color: #333;
        }

        .back-link {
            margin-top: 30px;
            text-align: center;
        }

        .back-link a {
            text-decoration: none;
            color: #2196F3;
            font-weight: bold;
        }

        .back-link a:hover {
            text-decoration: underline;
        }

        /* Modal */
        .modal {
            display: none;
            position: fixed;
            z-index: 1000;
            left: 0;
            top: 0;
            width: 100%;
            height: 100%;
            background-color: rgba(0,0,0,0.5);
        }

        .modal.show {
            display: flex;
            align-items: center;
            justify-content: center;
        }

        .modal-content {
            background-color: #fefefe;
            padding: 30px;
            border-radius: 10px;
            width: 90%;
            max-width: 500px;
            box-shadow: 0 4px 8px rgba(0,0,0,0.2);
        }

        .modal-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 20px;
        }

        .modal-header h2 {
            margin: 0;
            text-align: left;
        }

        .close-btn {
            background: none;
            border: none;
            font-size: 28px;
            cursor: pointer;
            color: #aaa;
        }

        .close-btn:hover {
            color: #000;
        }

        .form-group-modal {
            margin-bottom: 15px;
        }

        .form-group-modal label {
            display: block;
            margin-bottom: 5px;
            font-weight: bold;
        }

        .form-group-modal input,
        .form-group-modal select {
            width: 100%;
            padding: 10px;
            border: 1px solid #ddd;
            border-radius: 5px;
            font-size: 14px;
            box-sizing: border-box;
        }

        .form-actions {
            display: flex;
            gap: 10px;
            margin-top: 20px;
        }

        .form-actions button {
            flex: 1;
            padding: 10px;
            border: none;
            border-radius: 5px;
            cursor: pointer;
            font-weight: bold;
        }

        .btn-submit {
            background: #4CAF50;
            color: white;
        }

        .btn-submit:hover {
            background: #45a049;
        }

        .btn-cancel {
            background: #999;
            color: white;
        }

        .btn-cancel:hover {
            background: #777;
        }

        .add-course-btn {
            display: inline-block;
            background: #4CAF50;
            color: white;
            padding: 10px 20px;
            border-radius: 5px;
            border: none;
            cursor: pointer;
            font-weight: bold;
            margin-bottom: 20px;
        }

        .add-course-btn:hover {
            background: #45a049;
        }

        @media (max-width: 768px) {
            .container {
                padding: 10px;
            }

            .courses-container {
                grid-template-columns: 1fr;
            }

            .manage-buttons {
                grid-template-columns: repeat(2, 1fr);
            }

            .selection-form {
                grid-template-columns: 1fr;
            }

            .modal-content {
                width: 95%;
                padding: 20px;
            }

            .welcome-section h1 {
                font-size: 22px;
            }

            .semester-cards {
                grid-template-columns: 1fr;
            }

            .semester-stats {
                grid-template-columns: repeat(3, 1fr);
            }

            .results-table {
                font-size: 10px;
            }

            .results-table th,
            .results-table td {
                padding: 6px;
            }

            .result-actions {
                flex-direction: column;
            }

            .result-btn {
                width: 100%;
                padding: 6px 4px;
            }

            .results-card {
                flex-direction: column;
                gap: 10px;
            }

            .results-card-icon {
                font-size: 35px;
            }

            .results-card-title {
                font-size: 18px;
            }
        }
    </style>
</head>
<body>

<div class="container">
    <div class="auth-card">
        <!-- WELCOME SECTION -->
        <div class="welcome-section">
            <h1>👋 Welcome, <?= htmlspecialchars($admin_name) ?>!</h1>
            <p>Ready to manage your courses and students</p>
            <a href="logout.php" class="logout-btn">🚪 Logout</a>
        </div>

        <h2>📚 Manage Courses</h2>

        <!-- Alert Messages -->
        <?php if ($message): ?>
            <div class="alert <?= $message_type === 'success' ? 'success' : 'error' ?>">
                <?= htmlspecialchars($message) ?>
            </div>
        <?php endif; ?>

        <!-- Add Course Button -->
        <div class="center">
            <button class="add-course-btn" onclick="openAddCourseModal()">+ Add New Course</button>
        </div>

        <!-- STEP 1: Show all courses -->
        <div class="center">
            <p style="color: #666; margin-bottom: 20px;">Select a course to manage its modules, students, and more</p>
        </div>

        <div class="courses-container">
            <?php foreach($courses as $course): ?>
                <a class="course-card" href="?course_id=<?= $course['course_id'] ?>">
                    <div><?= htmlspecialchars($course['course_name']) ?></div>
                    <span style="font-size: 12px; margin-top: 5px; font-weight: normal;">
                        <?= $course['status'] === 'active' ? '✓ Active' : '⚠️ Inactive' ?>
                    </span>
                    <div class="course-card-actions">
                        <button class="btn-small btn-edit-course" onclick="openEditCourseModal(<?= htmlspecialchars(json_encode($course)) ?>); event.preventDefault();">Edit</button>
                        <form method="POST" style="flex: 1;">
                            <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">
                            <input type="hidden" name="action" value="delete">
                            <input type="hidden" name="course_id" value="<?= $course['course_id'] ?>">
                            <button type="submit" class="btn-small btn-delete-course" onclick="return confirm('Delete this course?')">Delete</button>
                        </form>
                    </div>
                </a>
            <?php endforeach; ?>
        </div>

        <!-- RESULTS CARD (COLLAPSIBLE) -->
        <div class="results-card" onclick="toggleResultsSection()">
            <div class="results-card-icon">📊</div>
            <div class="results-card-text">
                <div class="results-card-title">Results</div>
                <div class="results-card-subtitle">Manage and publish student results</div>
            </div>
        </div>

        <!-- RESULTS CONTENT (HIDDEN BY DEFAULT) -->
        <div class="results-content" id="resultsContent">
            <div class="semester-cards">
                <?php for($sem = 1; $sem <= 2; $sem++): 
                    // Get all modules with results for this semester
                    $modules_results_query = "
                        SELECT 
                            m.module_id,
                            m.module_code,
                            m.module_name,
                            m.year,
                            r.id as result_id,
                            r.status,
                            COUNT(DISTINCT r.student_id) as student_count
                        FROM modules m
                        LEFT JOIN results r ON m.module_id = r.module_id
                        WHERE m.course_id = ? AND m.semester = ? AND m.deleted = 0
                        GROUP BY m.module_id, m.module_code, m.module_name, m.year, r.id, r.status
                        ORDER BY m.year, m.module_code
                    ";
                    
                    $modules_results_stmt = $conn->prepare($modules_results_query);
                    $modules_results_stmt->bind_param("ii", $course_id, $sem);
                    $modules_results_stmt->execute();
                    $modules_with_results = $modules_results_stmt->get_result()->fetch_all(MYSQLI_ASSOC);
                    $modules_results_stmt->close();
                    
                    // Calculate statistics
                    $stats_query = "
                        SELECT 
                            COUNT(DISTINCT m.module_id) as total,
                            COUNT(DISTINCT CASE WHEN r.id IS NOT NULL THEN m.module_id END) as with_results
                        FROM modules m
                        LEFT JOIN results r ON m.module_id = r.module_id
                        WHERE m.course_id = ? AND m.semester = ? AND m.deleted = 0
                    ";
                    
                    $stats_stmt = $conn->prepare($stats_query);
                    $stats_stmt->bind_param("ii", $course_id, $sem);
                    $stats_stmt->execute();
                    $stats = $stats_stmt->get_result()->fetch_assoc();
                    $stats_stmt->close();
                    
                    $total_modules_sem = $stats['total'] ?? 0;
                    $modules_with_submitted_results = $stats['with_results'] ?? 0;
                    $modules_pending = $total_modules_sem - $modules_with_submitted_results;
                ?>
                <div class="semester-card">
                    <div class="semester-card-header" onclick="toggleSemesterContent(event)">
                        <div>
                            <div class="semester-card-icon">📚</div>
                            <span>Semester <?= $sem ?></span>
                        </div>
                        <span style="font-size: 18px;">▼</span>
                    </div>

                    <div class="semester-card-content" id="semester<?= $sem ?>Content">
                        <div class="semester-stats">
                            <div class="stat-item">
                                <div class="stat-label">Total Modules</div>
                                <div class="stat-value"><?= $total_modules_sem ?></div>
                            </div>
                            <div class="stat-item">
                                <div class="stat-label">Results Sent</div>
                                <div class="stat-value" style="color: #4CAF50;"><?= $modules_with_submitted_results ?></div>
                            </div>
                            <div class="stat-item">
                                <div class="stat-label">Pending</div>
                                <div class="stat-value" style="color: #FF9800;"><?= $modules_pending ?></div>
                            </div>
                        </div>

                        <?php if(count($modules_with_results) > 0): ?>
                            <table class="results-table">
                                <thead>
                                    <tr>
                                        <th>Year</th>
                                        <th>Module Code</th>
                                        <th>Module Name</th>
                                        <th>Students</th>
                                        <th>Status</th>
                                        <th>Actions</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach($modules_with_results as $result): ?>
                                        <?php if($result['result_id']): ?>
                                        <tr>
                                            <td><?= $result['year'] ?></td>
                                            <td class="module-code"><?= htmlspecialchars($result['module_code']) ?></td>
                                            <td><?= htmlspecialchars(substr($result['module_name'], 0, 20)) ?></td>
                                            <td><?= $result['student_count'] ?></td>
                                            <td>
                                                <span class="status-badge <?= $result['status'] === 'published' ? 'status-published' : 'status-draft' ?>">
                                                    <?= ucfirst($result['status']) ?>
                                                </span>
                                            </td>
                                            <td>
                                                <div class="result-actions">
                                                    <a href="view_results.php?module_id=<?= $result['module_id'] ?>" class="result-btn result-btn-view" title="View">👁️</a>
                                                    
                                                    <?php if($result['status'] === 'draft'): ?>
                                                    <form method="POST" style="display: inline;">
                                                        <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">
                                                        <input type="hidden" name="action" value="publish">
                                                        <input type="hidden" name="result_id" value="<?= $result['result_id'] ?>">
                                                        <button type="submit" class="result-btn result-btn-publish" title="Publish" onclick="return confirm('Publish?')">📤</button>
                                                    </form>
                                                    <?php else: ?>
                                                    <form method="POST" style="display: inline;">
                                                        <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">
                                                        <input type="hidden" name="action" value="unpublish">
                                                        <input type="hidden" name="result_id" value="<?= $result['result_id'] ?>">
                                                        <button type="submit" class="result-btn result-btn-unpublish" title="Unpublish" onclick="return confirm('Unpublish?')">📥</button>
                                                    </form>
                                                    <?php endif; ?>
                                                </div>
                                            </td>
                                        </tr>
                                        <?php endif; ?>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>

                            <!-- BULK ACTION BUTTONS -->
                            <div style="display: flex; gap: 10px; margin-top: 15px;">
                                <form method="POST" style="flex: 1;">
                                    <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">
                                    <input type="hidden" name="action" value="bulk_publish">
                                    <input type="hidden" name="course_id" value="<?= $course_id ?>">
                                    <input type="hidden" name="semester" value="<?= $sem ?>">
                                    <button type="submit" class="bulk-action-btn bulk-publish-btn" onclick="return confirm('Publish all results for Semester <?= $sem ?>?')">📤 Publish All</button>
                                </form>
                                <form method="POST" style="flex: 1;">
                                    <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">
                                    <input type="hidden" name="action" value="bulk_unpublish">
                                    <input type="hidden" name="course_id" value="<?= $course_id ?>">
                                    <input type="hidden" name="semester" value="<?= $sem ?>">
                                    <button type="submit" class="bulk-action-btn bulk-unpublish-btn" onclick="return confirm('Unpublish all results for Semester <?= $sem ?>?')">📥 Unpublish All</button>
                                </form>
                            </div>
                        <?php else: ?>
                            <div class="no-results">
                                ⏳ No results submitted yet for Semester <?= $sem ?>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
                <?php endfor; ?>
            </div>
        </div>

        <hr>

        <?php if($selected_course): ?>
            <!-- Breadcrumb -->
            <div class="breadcrumb">
                📍 You selected: <strong><?= htmlspecialchars($selected_course['course_name']) ?></strong>
            </div>

            <!-- STEP 2: Select Year and Semester -->
            <form method="GET" class="selection-form">
                <input type="hidden" name="course_id" value="<?= $course_id ?>">

                <div class="form-group">
                    <label for="year">Select Year:</label>
                    <select id="year" name="year" required>
                        <option value="">-- Year --</option>
                        <option value="1" <?= $year === 1 ? 'selected' : '' ?>>First Year</option>
                        <option value="2" <?= $year === 2 ? 'selected' : '' ?>>Second Year</option>
                        <option value="3" <?= $year === 3 ? 'selected' : '' ?>>Third Year</option>
                    </select>
                </div>

                <div class="form-group">
                    <label for="semester">Select Semester:</label>
                    <select id="semester" name="semester" required>
                        <option value="">-- Semester --</option>
                        <option value="1" <?= $semester === 1 ? 'selected' : '' ?>>Semester 1</option>
                        <option value="2" <?= $semester === 2 ? 'selected' : '' ?>>Semester 2</option>
                    </select>
                </div>

                <button type="submit" class="btn-continue">Continue →</button>
            </form>
        <?php endif; ?>

        <?php if($selected_course && $year && $semester): ?>
            <hr>

            <!-- Breadcrumb -->
            <div class="breadcrumb">
                📍 Managing: <strong><?= htmlspecialchars($selected_course['course_name']) ?></strong> | Year <strong><?= $year ?></strong> | Semester <strong><?= $semester ?></strong>
            </div>

            <!-- STEP 3: Choose What to Manage -->
            <h3>What would you like to manage?</h3>
            
            <div class="manage-buttons">
                <a href="manage_students.php?course_id=<?= $course_id ?>&year=<?= $year ?>&semester=<?= $semester ?>" class="btn-students">
                    👥 Students
                </a>

                <a href="manage_modules.php?course_id=<?= $course_id ?>&year=<?= $year ?>&semester=<?= $semester ?>" class="btn-modules">
                    📘 Modules
                </a>

                <a href="manage_teachers.php?course_id=<?= $course_id ?>" class="btn-teachers">
                    👨‍🏫 Teachers
                </a>

                <a href="manage_attendance.php?course_id=<?= $course_id ?>&year=<?= $year ?>&semester=<?= $semester ?>" class="btn-attendance">
                    📋 Attendance
                </a>

                <a href="manage_results.php?course_id=<?= $course_id ?>&year=<?= $year ?>&semester=<?= $semester ?>" class="btn-results">
                    📊 Results
                </a>
            </div>
        <?php endif; ?>

        <!-- Back Link -->
        <div class="back-link">
            <a href="manage_courses.php">← Reset</a>
        </div>
    </div>
</div>

<!-- Add/Edit Course Modal -->
<div id="courseModal" class="modal">
    <div class="modal-content">
        <div class="modal-header">
            <h2 id="modalTitle">Add New Course</h2>
            <button class="close-btn" onclick="closeCourseModal()">×</button>
        </div>

        <form id="courseForm" method="POST">
            <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">
            <input type="hidden" name="action" id="formAction" value="add">
            <input type="hidden" name="course_id" id="courseId" value="">

            <div class="form-group-modal">
                <label for="courseName">Course Name *</label>
                <input type="text" id="courseName" name="course_name" required placeholder="e.g., Bachelor of Science in Computer Science">
            </div>

            <div class="form-group-modal" id="statusGroup" style="display: none;">
                <label for="status">Status</label>
                <select id="status" name="status">
                    <option value="active">Active</option>
                    <option value="inactive">Inactive</option>
                </select>
            </div>

            <div class="form-actions">
                <button type="submit" class="btn-submit">Save Course</button>
                <button type="button" class="btn-cancel" onclick="closeCourseModal()">Cancel</button>
            </div>
        </form>
    </div>
</div>

<script>
function toggleResultsSection() {
    const resultsContent = document.getElementById('resultsContent');
    resultsContent.classList.toggle('active');
}

function toggleSemesterContent(event) {
    event.stopPropagation();
    const header = event.currentTarget;
    const semesterCard = header.closest('.semester-card');
    const content = semesterCard.querySelector('.semester-card-content');
    const icon = header.querySelector('span:last-child');
    
    content.classList.toggle('active');
    icon.style.transform = content.classList.contains('active') ? 'rotate(0deg)' : 'rotate(-180deg)';
}

function openAddCourseModal() {
    document.getElementById('modalTitle').textContent = 'Add New Course';
    document.getElementById('formAction').value = 'add';
    document.getElementById('courseId').value = '';
    document.getElementById('courseName').value = '';
    document.getElementById('statusGroup').style.display = 'none';
    document.getElementById('courseModal').classList.add('show');
}

function openEditCourseModal(course) {
    document.getElementById('modalTitle').textContent = 'Edit Course';
    document.getElementById('formAction').value = 'update';
    document.getElementById('courseId').value = course.course_id;
    document.getElementById('courseName').value = course.course_name;
    document.getElementById('status').value = course.status;
    document.getElementById('statusGroup').style.display = 'block';
    document.getElementById('courseModal').classList.add('show');
}

function closeCourseModal() {
    document.getElementById('courseModal').classList.remove('show');
}

window.onclick = function(event) {
    const modal = document.getElementById('courseModal');
    if (event.target === modal) {
        modal.classList.remove('show');
    }
}
</script>

</body>
</html>