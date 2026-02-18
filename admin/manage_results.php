<?php
session_start();
include '../config/db.php';

if(!isset($_SESSION['admin_logged_in'])){
    header("Location: login.php");
    exit();
}

$course_id = isset($_GET['course_id']) ? (int)$_GET['course_id'] : null;
$year = isset($_GET['year']) ? (int)$_GET['year'] : null;
$semester = isset($_GET['semester']) ? (int)$_GET['semester'] : null;

if(!$course_id || !$year || !$semester){
    die("Invalid access.");
}

function safe_int($value) {
    return filter_var($value, FILTER_VALIDATE_INT);
}

function safe_string($value) {
    return htmlspecialchars(trim($value), ENT_QUOTES, 'UTF-8');
}

function safe_email($value) {
    return filter_var(trim($value), FILTER_VALIDATE_EMAIL);
}

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

$message = "";
$message_type = "";
$search = "";

/* ================= ACTIVATE STUDENT ================= */
if($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'activate'){
    if (!hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'] ?? '')) {
        $message = "Security token verification failed";
        $message_type = "error";
    } else {
        $student_id = safe_int($_POST['student_id'] ?? 0);
        
        if(!$student_id){
            $message = "Invalid student ID";
            $message_type = "error";
        } else {
            $stmt = $conn->prepare("UPDATE students SET status = 'active' WHERE student_id = ? AND deleted = 0");
            $stmt->bind_param("i", $student_id);
            
            if($stmt->execute()){
                $message = "✓ Student activated successfully! They can now login.";
                $message_type = "success";
            } else {
                $message = "Error activating student";
                $message_type = "error";
            }
            $stmt->close();
        }
    }
}

/* ================= DEACTIVATE STUDENT ================= */
if($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'deactivate'){
    if (!hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'] ?? '')) {
        $message = "Security token verification failed";
        $message_type = "error";
    } else {
        $student_id = safe_int($_POST['student_id'] ?? 0);
        
        if(!$student_id){
            $message = "Invalid student ID";
            $message_type = "error";
        } else {
            $stmt = $conn->prepare("UPDATE students SET status = 'inactive' WHERE student_id = ? AND deleted = 0");
            $stmt->bind_param("i", $student_id);
            
            if($stmt->execute()){
                $message = "⚠️ Student deactivated. They cannot login anymore.";
                $message_type = "success";
            } else {
                $message = "Error deactivating student";
                $message_type = "error";
            }
            $stmt->close();
        }
    }
}

/* ================= ADD STUDENT ================= */
if($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'add'){
    if (!hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'] ?? '')) {
        $message = "Security token verification failed";
        $message_type = "error";
    } else {
        $reg_number = safe_string($_POST['reg_number'] ?? '');
        $name = safe_string($_POST['name'] ?? '');
        $email = safe_email($_POST['email'] ?? '');
        $password = $_POST['password'] ?? '';
        $confirm_password = $_POST['confirm_password'] ?? '';

        if(empty($reg_number) || empty($name) || !$email){
            $message = "Please fill all required fields";
            $message_type = "error";
        } elseif($password !== $confirm_password){
            $message = "Passwords do not match";
            $message_type = "error";
        } elseif(strlen($password) < 6){
            $message = "Password must be at least 6 characters";
            $message_type = "error";
        } else {
            $check = $conn->prepare("SELECT student_id FROM students WHERE reg_number = ? AND deleted = 0");
            $check->bind_param("s", $reg_number);
            $check->execute();
            
            if($check->get_result()->num_rows > 0){
                $message = "Registration number already exists";
                $message_type = "error";
            } else {
                $check_email = $conn->prepare("SELECT student_id FROM students WHERE email = ? AND deleted = 0");
                $check_email->bind_param("s", $email);
                $check_email->execute();
                
                if($check_email->get_result()->num_rows > 0){
                    $message = "Email already registered";
                    $message_type = "error";
                } else {
                    $password_hash = password_hash($password, PASSWORD_DEFAULT);
                    $stmt = $conn->prepare("INSERT INTO students (reg_number, name, email, password, course_id, year, semester, status, deleted, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, 'active', 0, NOW())");
                    $stmt->bind_param("ssssiii", $reg_number, $name, $email, $password_hash, $course_id, $year, $semester);
                    
                    if($stmt->execute()){
                        $message = "Student created successfully";
                        $message_type = "success";
                    } else {
                        $message = "Error creating student";
                        $message_type = "error";
                    }
                    $stmt->close();
                }
                $check_email->close();
            }
            $check->close();
        }
    }
}

/* ================= UPDATE STUDENT ================= */
if($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'update'){
    if (!hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'] ?? '')) {
        $message = "Security token verification failed";
        $message_type = "error";
    } else {
        $student_id = safe_int($_POST['student_id'] ?? 0);
        $name = safe_string($_POST['name'] ?? '');
        $email = safe_email($_POST['email'] ?? '');

        if(!$student_id || empty($name) || !$email){
            $message = "Please fill all required fields";
            $message_type = "error";
        } else {
            $check = $conn->prepare("SELECT student_id FROM students WHERE email = ? AND student_id != ? AND deleted = 0");
            $check->bind_param("si", $email, $student_id);
            $check->execute();
            
            if($check->get_result()->num_rows > 0){
                $message = "Email is already used";
                $message_type = "error";
            } else {
                $stmt = $conn->prepare("UPDATE students SET name = ?, email = ? WHERE student_id = ? AND deleted = 0");
                $stmt->bind_param("ssi", $name, $email, $student_id);
                
                if($stmt->execute()){
                    $message = "Student updated successfully";
                    $message_type = "success";
                } else {
                    $message = "Error updating student";
                    $message_type = "error";
                }
                $stmt->close();
            }
            $check->close();
        }
    }
}

/* ================= DELETE STUDENT ================= */
if($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'delete'){
    if (!hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'] ?? '')) {
        $message = "Security token verification failed";
        $message_type = "error";
    } else {
        $student_id = safe_int($_POST['student_id'] ?? 0);
        
        if(!$student_id){
            $message = "Invalid student ID";
            $message_type = "error";
        } else {
            $stmt = $conn->prepare("UPDATE students SET deleted = 1 WHERE student_id = ?");
            $stmt->bind_param("i", $student_id);
            
            if($stmt->execute()){
                $message = "Student deleted successfully";
                $message_type = "success";
            } else {
                $message = "Error deleting student";
                $message_type = "error";
            }
            $stmt->close();
        }
    }
}

/* ================= RESTORE STUDENT ================= */
if($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'restore'){
    if (!hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'] ?? '')) {
        $message = "Security token verification failed";
        $message_type = "error";
    } else {
        $student_id = safe_int($_POST['student_id'] ?? 0);
        
        if(!$student_id){
            $message = "Invalid student ID";
            $message_type = "error";
        } else {
            $stmt = $conn->prepare("UPDATE students SET deleted = 0 WHERE student_id = ?");
            $stmt->bind_param("i", $student_id);
            
            if($stmt->execute()){
                $message = "Student restored successfully";
                $message_type = "success";
            } else {
                $message = "Error restoring student";
                $message_type = "error";
            }
            $stmt->close();
        }
    }
}

/* ================= PAGINATION ================= */
$page = isset($_GET['page']) ? safe_int($_GET['page']) : 1;
if (!$page) $page = 1;

$limit = 15;
$offset = ($page - 1) * $limit;

$search_term = null;
$where = "WHERE deleted = 0 AND course_id = ? AND year = ? AND semester = ?";

if (isset($_GET['search']) && !empty($_GET['search'])) {
    $search = safe_string($_GET['search']);
    $search_term = "%" . $search . "%";
    $where .= " AND (name LIKE ? OR email LIKE ? OR reg_number LIKE ?)";
}

$count_query = "SELECT COUNT(*) as total FROM students $where";
$count_stmt = $conn->prepare($count_query);

if ($search_term) {
    $count_stmt->bind_param("iiisss", $course_id, $year, $semester, $search_term, $search_term, $search_term);
} else {
    $count_stmt->bind_param("iii", $course_id, $year, $semester);
}
$count_stmt->execute();
$total = $count_stmt->get_result()->fetch_assoc()['total'];
$pages = ceil($total / $limit);
$count_stmt->close();

$query = "SELECT student_id, reg_number, name, email, status, deleted FROM students $where ORDER BY name ASC LIMIT ?, ?";

$stmt = $conn->prepare($query);

if ($search_term) {
    $stmt->bind_param("iiissssii", $course_id, $year, $semester, $search_term, $search_term, $search_term, $limit, $offset);
} else {
    $stmt->bind_param("iiiii", $course_id, $year, $semester, $limit, $offset);
}

$stmt->execute();
$students = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

$course_stmt = $conn->prepare("SELECT course_name FROM courses WHERE course_id = ?");
$course_stmt->bind_param("i", $course_id);
$course_stmt->execute();
$course_result = $course_stmt->get_result()->fetch_assoc();
$course_name = $course_result ? $course_result['course_name'] : 'Unknown';
$course_stmt->close();

?>

<!DOCTYPE html>
<html>
<head>
    <title>Manage Students</title>
    <link rel="stylesheet" href="../assets/css/auth.css">
    <style>
        .auth-card {
            width: 1000px;
            max-width: 100%;
            padding: 30px;
            border-radius: 18px;
            background: var(--white);
            box-shadow: 0 20px 45px rgba(0,0,0,0.15);
            margin: 30px auto;
        }

        h2 {
            text-align: center;
            margin-bottom: 15px;
            color: var(--midnight-garden);
        }

        .breadcrumb {
            text-align: center;
            font-size: 13px;
            color: #666;
            margin-bottom: 20px;
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

        .search-box {
            display: flex;
            gap: 10px;
            margin-bottom: 20px;
            justify-content: center;
            flex-wrap: wrap;
        }

        .search-box input {
            padding: 8px 12px;
            border: 1px solid #ddd;
            border-radius: 8px;
            font-size: 14px;
            min-width: 300px;
        }

        .search-box button {
            padding: 8px 15px;
            background: var(--terra-rosa);
            color: white;
            border: none;
            border-radius: 8px;
            cursor: pointer;
            font-weight: bold;
        }

        .search-box button:hover {
            opacity: 0.9;
        }

        table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 20px;
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
        }

        td:first-child, th:first-child {
            text-align: left;
        }

        a.action-link {
            color: var(--terra-rosa);
            font-weight: bold;
            text-decoration: none;
            margin: 0 5px;
            cursor: pointer;
        }

        a.action-link:hover {
            opacity: 0.8;
        }

        .badge {
            display: inline-block;
            padding: 4px 8px;
            border-radius: 4px;
            font-size: 12px;
            font-weight: bold;
        }

        .badge-active {
            background: #d4edda;
            color: #155724;
        }

        .badge-pending {
            background: #cce5ff;
            color: #004085;
        }

        .badge-inactive {
            background: #fff3cd;
            color: #856404;
        }

        .badge-deleted {
            background: #f8d7da;
            color: #721c24;
        }

        .auth-links {
            text-align: center;
            margin-top: 15px;
        }

        .btn {
            display: inline-block;
            padding: 8px 15px;
            background: linear-gradient(135deg, var(--terra-rosa), var(--honey-glow));
            color: #fff;
            border-radius: 12px;
            text-decoration: none;
            font-weight: bold;
            margin-bottom: 10px;
            border: none;
            cursor: pointer;
        }

        .btn:hover {
            opacity: 0.9;
        }

        .add-btn {
            float: left;
        }

        .back-btn {
            float: right;
        }

        .no-data {
            text-align: center;
            padding: 30px;
            color: #666;
        }

        .pagination {
            text-align: center;
            margin-top: 20px;
            padding: 10px;
        }

        .pagination a, .pagination span {
            padding: 5px 10px;
            margin: 0 3px;
            border: 1px solid #ddd;
            border-radius: 5px;
            text-decoration: none;
            color: #333;
        }

        .pagination a:hover {
            background: #f0f0f0;
        }

        .pagination span.active {
            background: var(--terra-rosa);
            color: white;
            border-color: var(--terra-rosa);
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
            background-color: rgba(0,0,0,0.4);
        }

        .modal.show {
            display: flex;
            align-items: center;
            justify-content: center;
        }

        .modal-content {
            background-color: var(--white);
            padding: 25px;
            border-radius: 12px;
            width: 90%;
            max-width: 500px;
            box-shadow: 0 4px 6px rgba(0,0,0,0.1);
            max-height: 90vh;
            overflow-y: auto;
        }

        .modal-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 20px;
        }

        .modal-header h3 {
            margin: 0;
            color: var(--midnight-garden);
        }

        .close-btn {
            background: none;
            border: none;
            font-size: 24px;
            cursor: pointer;
            color: #999;
        }

        .close-btn:hover {
            color: #000;
        }

        .form-group {
            margin-bottom: 15px;
        }

        .form-group label {
            display: block;
            margin-bottom: 5px;
            font-weight: bold;
            color: var(--midnight-garden);
        }

        .form-group input,
        .form-group select {
            width: 100%;
            padding: 8px;
            border: 1px solid #ddd;
            border-radius: 8px;
            font-size: 14px;
            box-sizing: border-box;
        }

        .form-group input:focus,
        .form-group select:focus {
            outline: none;
            border-color: var(--terra-rosa);
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
            border-radius: 8px;
            cursor: pointer;
            font-weight: bold;
        }

        .btn-submit {
            background: linear-gradient(135deg, var(--terra-rosa), var(--honey-glow));
            color: white;
        }

        .btn-submit:hover {
            opacity: 0.9;
        }

        .btn-cancel {
            background: #999;
            color: white;
        }

        .btn-cancel:hover {
            opacity: 0.9;
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

            .search-box {
                flex-direction: column;
            }

            .search-box input {
                min-width: 100%;
            }

            .add-btn, .back-btn {
                float: none;
                display: block;
                width: 100%;
                text-align: center;
            }

            .modal-content {
                width: 95%;
            }
        }
    </style>
</head>
<body>

<div class="auth-card">
    <h2>Manage Students</h2>

    <div class="breadcrumb">
        <?= htmlspecialchars($course_name) ?> | Year <?= $year ?> | Semester <?= $semester ?>
    </div>

    <?php if ($message): ?>
        <div class="alert <?= $message_type === 'success' ? 'success' : 'error' ?>">
            <?= htmlspecialchars($message) ?>
        </div>
    <?php endif; ?>

    <button onclick="openAddModal()" class="btn add-btn">+ Add Student</button>
    <a href="manage_courses.php" class="btn back-btn">← Back</a>
    <div style="clear: both;"></div>

    <div class="search-box">
        <form method="GET" style="display: flex; gap: 10px; width: 100%;">
            <input type="hidden" name="course_id" value="<?= $course_id ?>">
            <input type="hidden" name="year" value="<?= $year ?>">
            <input type="hidden" name="semester" value="<?= $semester ?>">
            <input type="text" name="search" placeholder="Search by name, email, or reg number..." value="<?= htmlspecialchars($search) ?>">
            <button type="submit">🔍 Search</button>
            <?php if(!empty($search)): ?>
                <a href="?course_id=<?= $course_id ?>&year=<?= $year ?>&semester=<?= $semester ?>" style="padding: 8px 15px; background: #999; color: white; text-decoration: none; border-radius: 8px;">Clear</a>
            <?php endif; ?>
        </form>
    </div>

    <?php if (count($students) > 0): ?>
        <table>
            <tr>
                <th>Reg Number</th>
                <th>Name</th>
                <th>Email</th>
                <th>Status</th>
                <th>Actions</th>
            </tr>

            <?php foreach ($students as $student): ?>
            <tr>
                <td><?= htmlspecialchars($student['reg_number']) ?></td>
                <td><?= htmlspecialchars($student['name']) ?></td>
                <td><?= htmlspecialchars($student['email']) ?></td>
                <td>
                    <span class="badge badge-<?= $student['deleted'] ? 'deleted' : $student['status'] ?>">
                        <?php 
                            if($student['deleted']) {
                                echo '🗑️ Deleted';
                            } elseif($student['status'] === 'active') {
                                echo '✓ Active';
                            } elseif($student['status'] === 'pending') {
                                echo '⏳ Pending';
                            } else {
                                echo '⚠️ Inactive';
                            }
                        ?>
                    </span>
                </td>
                <td>
                    <?php if (!$student['deleted']): ?>
                        <?php if ($student['status'] !== 'active'): ?>
                            <form method="POST" style="display: inline;">
                                <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">
                                <input type="hidden" name="action" value="activate">
                                <input type="hidden" name="student_id" value="<?= $student['student_id'] ?>">
                                <a class="action-link" onclick="this.parentForm.submit();">Activate</a>
                            </form>
                        <?php else: ?>
                            <form method="POST" style="display: inline;">
                                <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">
                                <input type="hidden" name="action" value="deactivate">
                                <input type="hidden" name="student_id" value="<?= $student['student_id'] ?>">
                                <a class="action-link" onclick="this.parentForm.submit();">Deactivate</a>
                            </form>
                        <?php endif; ?>
                        
                        <a class="action-link" onclick="openEditModal(<?= htmlspecialchars(json_encode($student)) ?>)">Edit</a>
                        
                        <form method="POST" style="display: inline;">
                            <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">
                            <input type="hidden" name="action" value="delete">
                            <input type="hidden" name="student_id" value="<?= $student['student_id'] ?>">
                            <a class="action-link" onclick="if(confirm('Delete this student?')) this.parentForm.submit(); return false;">Delete</a>
                        </form>
                    <?php else: ?>
                        <form method="POST" style="display: inline;">
                            <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">
                            <input type="hidden" name="action" value="restore">
                            <input type="hidden" name="student_id" value="<?= $student['student_id'] ?>">
                            <a class="action-link" onclick="this.parentForm.submit();">Restore</a>
                        </form>
                    <?php endif; ?>
                </td>
            </tr>
            <?php endforeach; ?>
        </table>

        <?php if ($pages > 1): ?>
            <div class="pagination">
                <?php if ($page > 1): ?>
                    <a href="?course_id=<?= $course_id ?>&year=<?= $year ?>&semester=<?= $semester ?>&page=1<?= !empty($search) ? '&search=' . urlencode($search) : '' ?>">« First</a>
                    <a href="?course_id=<?= $course_id ?>&year=<?= $year ?>&semester=<?= $semester ?>&page=<?= $page - 1 ?><?= !empty($search) ? '&search=' . urlencode($search) : '' ?>">‹ Previous</a>
                <?php endif; ?>

                <?php for ($i = max(1, $page - 2); $i <= min($pages, $page + 2); $i++): ?>
                    <?php if ($i === $page): ?>
                        <span class="active"><?= $i ?></span>
                    <?php else: ?>
                        <a href="?course_id=<?= $course_id ?>&year=<?= $year ?>&semester=<?= $semester ?>&page=<?= $i ?><?= !empty($search) ? '&search=' . urlencode($search) : '' ?>"><?= $i ?></a>
                    <?php endif; ?>
                <?php endfor; ?>

                <?php if ($page < $pages): ?>
                    <a href="?course_id=<?= $course_id ?>&year=<?= $year ?>&semester=<?= $semester ?>&page=<?= $page + 1 ?><?= !empty($search) ? '&search=' . urlencode($search) : '' ?>">Next ›</a>
                    <a href="?course_id=<?= $course_id ?>&year=<?= $year ?>&semester=<?= $semester ?>&page=<?= $pages ?><?= !empty($search) ? '&search=' . urlencode($search) : '' ?>">Last »</a>
                <?php endif; ?>
            </div>
        <?php endif; ?>
    <?php else: ?>
        <div class="no-data">
            <p>No students found for this course/year/semester combination.</p>
        </div>
    <?php endif; ?>

    <div class="auth-links">
        <a href="manage_courses.php">Back to Dashboard</a>
    </div>
</div>

<!-- Modal -->
<div id="studentModal" class="modal">
    <div class="modal-content">
        <div class="modal-header">
            <h3 id="modalTitle">Add Student</h3>
            <button class="close-btn" onclick="closeModal()">×</button>
        </div>

        <form id="studentForm" method="POST">
            <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">
            <input type="hidden" name="action" id="formAction" value="add">
            <input type="hidden" name="student_id" id="studentId" value="">

            <div class="form-group">
                <label for="regNumber">Registration Number *</label>
                <input type="text" id="regNumber" name="reg_number" required>
            </div>

            <div class="form-group">
                <label for="name">Full Name *</label>
                <input type="text" id="name" name="name" required>
            </div>

            <div class="form-group">
                <label for="email">Email *</label>
                <input type="email" id="email" name="email" required>
            </div>

            <div id="passwordRow">
                <div class="form-group">
                    <label for="password">Password *</label>
                    <input type="password" id="password" name="password" required>
                </div>

                <div class="form-group">
                    <label for="confirmPassword">Confirm Password *</label>
                    <input type="password" id="confirmPassword" name="confirm_password" required>
                </div>
            </div>

            <div class="form-actions">
                <button type="submit" class="btn-submit">Save</button>
                <button type="button" class="btn-cancel" onclick="closeModal()">Cancel</button>
            </div>
        </form>
    </div>
</div>

<script>
function openAddModal() {
    document.getElementById('modalTitle').textContent = 'Add Student';
    document.getElementById('formAction').value = 'add';
    document.getElementById('studentId').value = '';
    document.getElementById('regNumber').value = '';
    document.getElementById('name').value = '';
    document.getElementById('email').value = '';
    document.getElementById('password').value = '';
    document.getElementById('password').required = true;
    document.getElementById('confirmPassword').value = '';
    document.getElementById('confirmPassword').required = true;
    document.getElementById('passwordRow').style.display = 'block';
    document.getElementById('studentModal').classList.add('show');
}

function openEditModal(student) {
    document.getElementById('modalTitle').textContent = 'Edit Student';
    document.getElementById('formAction').value = 'update';
    document.getElementById('studentId').value = student.student_id;
    document.getElementById('regNumber').value = student.reg_number;
    document.getElementById('regNumber').disabled = true;
    document.getElementById('name').value = student.name;
    document.getElementById('email').value = student.email;
    document.getElementById('password').value = '';
    document.getElementById('password').required = false;
    document.getElementById('confirmPassword').value = '';
    document.getElementById('confirmPassword').required = false;
    document.getElementById('passwordRow').style.display = 'none';
    document.getElementById('studentModal').classList.add('show');
}

function closeModal() {
    document.getElementById('studentModal').classList.remove('show');
    document.getElementById('regNumber').disabled = false;
}

window.onclick = function(event) {
    const modal = document.getElementById('studentModal');
    if (event.target === modal) {
        modal.classList.remove('show');
    }
}
</script>

</body>
</html>