<?php
session_start();
include '../config/db.php';

if(!isset($_SESSION['admin_logged_in'])){
    header("Location: login.php");
    exit();
}

if(!isset($_GET['course_id'], $_GET['year'], $_GET['semester'])){
    die("Invalid Access.");
}

function safe_int($value) {
    return filter_var($value, FILTER_VALIDATE_INT);
}

function safe_string($value) {
    return htmlspecialchars(trim($value), ENT_QUOTES, 'UTF-8');
}

$course_id = safe_int($_GET['course_id']);
$year = safe_int($_GET['year']);
$semester = safe_int($_GET['semester']);

if (!$course_id || !$year || !$semester) {
    die("Invalid parameters.");
}

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

$search = "";
if(isset($_GET['search'])){
    $search = safe_string($_GET['search']);
}

$message = "";
$message_type = "";

/* DELETE */
if(isset($_POST['action']) && $_POST['action'] === 'delete'){
    if (!hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'] ?? '')) {
        $message = "Security token verification failed";
        $message_type = "error";
    } else {
        $module_id = safe_int($_POST['module_id']);
        if (!$module_id) {
            $message = "Invalid module ID";
            $message_type = "error";
        } else {
            $stmt = $conn->prepare("UPDATE modules SET deleted=1 WHERE module_id=?");
            $stmt->bind_param("i", $module_id);
            if($stmt->execute()){
                $message = "Module deleted successfully";
                $message_type = "success";
            } else {
                $message = "Error deleting module";
                $message_type = "error";
            }
            $stmt->close();
        }
    }
}

/* UPDATE */
if(isset($_POST['action']) && $_POST['action'] === 'update'){
    if (!hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'] ?? '')) {
        $message = "Security token verification failed";
        $message_type = "error";
    } else {
        $module_id   = safe_int($_POST['module_id']);
        $module_code = safe_string($_POST['module_code'] ?? '');
        $module_name = safe_string($_POST['module_name'] ?? '');
        $teacher_id  = safe_int($_POST['teacher_id']);
        $status      = safe_string($_POST['status'] ?? 'active');

        if(empty($module_code) || empty($module_name) || !$module_id){
            $message = "Please fill all required fields";
            $message_type = "error";
        } else {
            $stmt = $conn->prepare("UPDATE modules SET module_code=?, module_name=?, teacher_id=?, status=? WHERE module_id=?");
            $stmt->bind_param("ssisi", $module_code, $module_name, $teacher_id, $status, $module_id);
            if($stmt->execute()){
                $message = "Module updated successfully";
                $message_type = "success";
            } else {
                $message = "Error updating module: " . $stmt->error;
                $message_type = "error";
            }
            $stmt->close();
        }
    }
}

/* ADD */
if(isset($_POST['action']) && $_POST['action'] === 'add'){
    if (!hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'] ?? '')) {
        $message = "Security token verification failed";
        $message_type = "error";
    } else {
        $module_code = safe_string($_POST['module_code'] ?? '');
        $module_name = safe_string($_POST['module_name'] ?? '');
        $teacher_id  = safe_int($_POST['teacher_id']);

        if(empty($module_code) || empty($module_name) || !$teacher_id){
            $message = "Please fill all required fields";
            $message_type = "error";
        } else {
            $check = $conn->prepare("SELECT module_id FROM modules WHERE module_code = ? AND course_id = ? AND deleted = 0");
            $check->bind_param("si", $module_code, $course_id);
            $check->execute();
            
            if($check->get_result()->num_rows > 0){
                $message = "Module code already exists";
                $message_type = "error";
            } else {
                $stmt = $conn->prepare("INSERT INTO modules (module_code, module_name, course_id, year, semester, teacher_id, status, created_at) VALUES (?, ?, ?, ?, ?, ?, 'active', NOW())");
                $stmt->bind_param("ssiiii", $module_code, $module_name, $course_id, $year, $semester, $teacher_id);
                
                if($stmt->execute()){
                    $message = "Module added successfully";
                    $message_type = "success";
                } else {
                    $message = "Error adding module: " . $stmt->error;
                    $message_type = "error";
                }
                $stmt->close();
            }
            $check->close();
        }
    }
}

/* PAGINATION */
$limit = 10;
$page  = isset($_GET['page']) ? safe_int($_GET['page']) : 1;
if (!$page) $page = 1;
$start = ($page-1)*$limit;

$whereSearch = "";
$search_term = null;
if($search != ""){
    $search_term = "%" . $search . "%";
    $whereSearch = " AND (module_code LIKE ? OR module_name LIKE ?)";
}

$total_q = "SELECT COUNT(*) as total FROM modules WHERE deleted=0 AND course_id=? AND year=? AND semester=? $whereSearch";
$total_stmt = $conn->prepare($total_q);
if($search_term){
    $total_stmt->bind_param("iiiss", $course_id, $year, $semester, $search_term, $search_term);
} else {
    $total_stmt->bind_param("iii", $course_id, $year, $semester);
}
$total_stmt->execute();
$total = $total_stmt->get_result()->fetch_assoc()['total'];
$pages = ceil($total/$limit);
$total_stmt->close();

$course_q = $conn->prepare("SELECT course_name FROM courses WHERE course_id=?");
$course_q->bind_param("i", $course_id);
$course_q->execute();
$course = $course_q->get_result()->fetch_assoc();
$course_q->close();

if(!$course){
    die("Course not found.");
}

$teachers = $conn->query("SELECT teacher_id, fullname FROM teachers WHERE deleted=0 ORDER BY fullname");

$modules_q = "SELECT m.*, t.fullname AS teacher_name FROM modules m LEFT JOIN teachers t ON m.teacher_id = t.teacher_id WHERE m.deleted=0 AND m.course_id=? AND m.year=? AND m.semester=? $whereSearch ORDER BY m.module_code ASC LIMIT ? OFFSET ?";

$modules_stmt = $conn->prepare($modules_q);
if($search_term){
    $modules_stmt->bind_param("iiissii", $course_id, $year, $semester, $search_term, $search_term, $limit, $start);
} else {
    $modules_stmt->bind_param("iiiii", $course_id, $year, $semester, $limit, $start);
}
$modules_stmt->execute();
$modules = $modules_stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$modules_stmt->close();

?>

<!DOCTYPE html>
<html>
<head>
    <title>Manage Modules</title>
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
    <h2>Manage Modules</h2>

    <div class="breadcrumb">
        <?= htmlspecialchars($course['course_name']) ?> | Year <?= $year ?> | Semester <?= $semester ?>
    </div>

    <?php if ($message): ?>
        <div class="alert <?= $message_type === 'success' ? 'success' : 'error' ?>">
            <?= htmlspecialchars($message) ?>
        </div>
    <?php endif; ?>

    <button onclick="openAddModal()" class="btn add-btn">+ Add Module</button>
    <a href="manage_courses.php" class="btn back-btn">← Back</a>
    <div style="clear: both;"></div>

    <div class="search-box">
        <form method="GET" style="display: flex; gap: 10px; width: 100%;">
            <input type="hidden" name="course_id" value="<?= $course_id ?>">
            <input type="hidden" name="year" value="<?= $year ?>">
            <input type="hidden" name="semester" value="<?= $semester ?>">
            <input type="text" name="search" placeholder="Search by code or name..." value="<?= htmlspecialchars($search) ?>">
            <button type="submit">🔍 Search</button>
            <?php if(!empty($search)): ?>
                <a href="?course_id=<?= $course_id ?>&year=<?= $year ?>&semester=<?= $semester ?>" style="padding: 8px 15px; background: #999; color: white; text-decoration: none; border-radius: 8px;">Clear</a>
            <?php endif; ?>
        </form>
    </div>

    <?php if(count($modules) > 0): ?>
        <table>
            <tr>
                <th>Code</th>
                <th>Name</th>
                <th>Teacher</th>
                <th>Status</th>
                <th>Actions</th>
            </tr>

            <?php foreach ($modules as $module): ?>
            <tr>
                <td><?= htmlspecialchars($module['module_code']) ?></td>
                <td><?= htmlspecialchars($module['module_name']) ?></td>
                <td><?= htmlspecialchars($module['teacher_name'] ?? 'Unassigned') ?></td>
                <td><?= $module['status'] === 'active' ? '✓ Active' : '⚠️ Inactive' ?></td>
                <td>
                    <a class="action-link" onclick="openEditModal(<?= htmlspecialchars(json_encode($module)) ?>)">Edit</a>
                    <form method="POST" style="display: inline;">
                        <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">
                        <input type="hidden" name="action" value="delete">
                        <input type="hidden" name="module_id" value="<?= $module['module_id'] ?>">
                        <a class="action-link" onclick="if(confirm('Delete this module?')) this.parentForm.submit(); return false;">Delete</a>
                    </form>
                </td>
            </tr>
            <?php endforeach; ?>
        </table>

        <?php if($pages > 1): ?>
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
            <p>No modules found.</p>
        </div>
    <?php endif; ?>

    <div class="auth-links">
        <a href="manage_courses.php">Back to Dashboard</a>
    </div>
</div>

<!-- Modal -->
<div id="moduleModal" class="modal">
    <div class="modal-content">
        <div class="modal-header">
            <h3 id="modalTitle">Add Module</h3>
            <button class="close-btn" onclick="closeModal()">×</button>
        </div>

        <form id="moduleForm" method="POST">
            <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">
            <input type="hidden" name="action" id="formAction" value="add">
            <input type="hidden" name="module_id" id="moduleId" value="">

            <div class="form-group">
                <label for="moduleCode">Module Code *</label>
                <input type="text" id="moduleCode" name="module_code" required>
            </div>

            <div class="form-group">
                <label for="moduleName">Module Name *</label>
                <input type="text" id="moduleName" name="module_name" required>
            </div>

            <div class="form-group">
                <label for="teacher">Teacher *</label>
                <select id="teacher" name="teacher_id" required>
                    <option value="">-- Select --</option>
                    <?php 
                    $teachers->data_seek(0);
                    while ($t = $teachers->fetch_assoc()): 
                    ?>
                        <option value="<?= $t['teacher_id'] ?>">
                            <?= htmlspecialchars($t['fullname']) ?>
                        </option>
                    <?php endwhile; ?>
                </select>
            </div>

            <div class="form-group" id="statusGroup" style="display: none;">
                <label for="status">Status</label>
                <select id="status" name="status">
                    <option value="active">Active</option>
                    <option value="inactive">Inactive</option>
                </select>
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
    document.getElementById('modalTitle').textContent = 'Add Module';
    document.getElementById('formAction').value = 'add';
    document.getElementById('moduleId').value = '';
    document.getElementById('moduleCode').value = '';
    document.getElementById('moduleName').value = '';
    document.getElementById('teacher').value = '';
    document.getElementById('statusGroup').style.display = 'none';
    document.getElementById('moduleModal').classList.add('show');
}

function openEditModal(module) {
    document.getElementById('modalTitle').textContent = 'Edit Module';
    document.getElementById('formAction').value = 'update';
    document.getElementById('moduleId').value = module.module_id;
    document.getElementById('moduleCode').value = module.module_code;
    document.getElementById('moduleName').value = module.module_name;
    document.getElementById('teacher').value = module.teacher_id;
    document.getElementById('status').value = module.status;
    document.getElementById('statusGroup').style.display = 'block';
    document.getElementById('moduleModal').classList.add('show');
}

function closeModal() {
    document.getElementById('moduleModal').classList.remove('show');
}

window.onclick = function(event) {
    const modal = document.getElementById('moduleModal');
    if (event.target === modal) {
        modal.classList.remove('show');
    }
}
</script>

</body>
</html>