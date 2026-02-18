<?php
session_start();
include '../config/db.php';

if(!isset($_SESSION['admin_logged_in'])){
    header("Location: login.php");
    exit();
}

// Inline validation functions
function safe_int($value) {
    return filter_var($value, FILTER_VALIDATE_INT);
}

function safe_string($value) {
    return htmlspecialchars(trim($value), ENT_QUOTES, 'UTF-8');
}

// Generate CSRF token
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

$message = "";
$message_type = "";

/* ================= ADD COURSE ================= */
if($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'add'){
    
    // Verify CSRF
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
            // Check if course already exists
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
    
    // Verify CSRF
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
    
    // Verify CSRF
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

/* ================= RESTORE COURSE ================= */
if($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'restore'){
    
    // Verify CSRF
    if (!hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'] ?? '')) {
        $message = "Security token verification failed";
        $message_type = "error";
    } else {
        $course_id = safe_int($_POST['course_id'] ?? 0);
        
        if(!$course_id){
            $message = "Invalid course ID";
            $message_type = "error";
        } else {
            $stmt = $conn->prepare("UPDATE courses SET deleted = 0 WHERE course_id = ?");
            $stmt->bind_param("i", $course_id);
            
            if($stmt->execute()){
                $message = "Course restored successfully";
                $message_type = "success";
            } else {
                $message = "Error restoring course";
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

// Get selected course if navigating
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
            max-width: 1000px;
            margin: 0 auto;
            padding: 20px;
        }

        .auth-card { 
            width: 100%;
            max-width: 900px;
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

        .center {
            text-align: center;
        }

        h2 {
            text-align: center;
            color: #333;
            margin-bottom: 30px;
        }

        h3 {
            text-align: center;
            color: #555;
            margin-top: 20px;
            margin-bottom: 15px;
        }

        /* STEP 1: Course Selection */
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

        /* STEP 2: Year/Semester Selection */
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

        /* STEP 3: Management Options */
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

        /* Add/Edit Course Modal */
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
        }
    </style>
</head>
<body>

<div class="container">
    <div class="auth-card">
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

        <?php if($selected_course): ?>
            <hr>

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
            <a href="dashboard.php">← Back to Dashboard</a>
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
// Modal functions
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

// Close modal when clicking outside
window.onclick = function(event) {
    const modal = document.getElementById('courseModal');
    if (event.target === modal) {
        modal.classList.remove('show');
    }
}
</script>

</body>
</html>