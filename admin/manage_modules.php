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

// Inline validation function
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

// Generate CSRF token
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

$search = "";
if(isset($_GET['search'])){
    $search = trim($_GET['search']);
}

$message = "";
$message_type = "";

/* ================= DELETE ================= */
if(isset($_POST['action']) && $_POST['action'] === 'delete'){
    
    // Verify CSRF
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

/* ================= UPDATE ================= */
if(isset($_POST['action']) && $_POST['action'] === 'update'){
    
    // Verify CSRF
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
            $stmt = $conn->prepare("
                UPDATE modules SET
                module_code=?,
                module_name=?,
                teacher_id=?,
                status=?
                WHERE module_id=?
            ");
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

/* ================= ADD ================= */
if(isset($_POST['action']) && $_POST['action'] === 'add'){
    
    // Verify CSRF
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
            // Check if module code already exists
            $check = $conn->prepare("SELECT module_id FROM modules WHERE module_code = ? AND course_id = ? AND deleted = 0");
            $check->bind_param("si", $module_code, $course_id);
            $check->execute();
            
            if($check->get_result()->num_rows > 0){
                $message = "Module code already exists";
                $message_type = "error";
            } else {
                $stmt = $conn->prepare("
                    INSERT INTO modules
                    (module_code, module_name, course_id, year, semester, teacher_id, status, created_at)
                    VALUES (?, ?, ?, ?, ?, ?, 'active', NOW())
                ");
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

/* ================= PAGINATION ================= */
$limit = 5;
$page  = isset($_GET['page']) ? safe_int($_GET['page']) : 1;
if (!$page) $page = 1;
$start = ($page-1)*$limit;

$whereSearch = "";
$search_term = null;
if($search != ""){
    $search_term = "%" . $search . "%";
    $whereSearch = " AND (module_code LIKE ? OR module_name LIKE ?)";
}

/* ================= COUNT TOTAL ================= */
$total_q = "
    SELECT COUNT(*) as total FROM modules
    WHERE deleted=0
    AND course_id=?
    AND year=?
    AND semester=?
    $whereSearch
";

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

/* ================= FETCH DATA ================= */
// ✅ FIXED: Removed deleted=0 from courses table
$course_q = $conn->prepare("SELECT course_name FROM courses WHERE course_id=?");
$course_q->bind_param("i", $course_id);
$course_q->execute();
$course = $course_q->get_result()->fetch_assoc();
$course_q->close();

if(!$course){
    die("Course not found.");
}

$teachers = $conn->query("SELECT teacher_id, fullname FROM teachers WHERE deleted=0 ORDER BY fullname");

$modules_q = "
    SELECT m.*, t.fullname AS teacher_name
    FROM modules m
    LEFT JOIN teachers t ON m.teacher_id = t.teacher_id
    WHERE m.deleted=0
    AND m.course_id=?
    AND m.year=?
    AND m.semester=?
    $whereSearch
    ORDER BY m.module_code ASC
    LIMIT ? OFFSET ?
";

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
    <title>Manage Modules - <?= htmlspecialchars($course['course_name']) ?></title>
    <link rel="stylesheet" href="../assets/css/auth.css">
    <style>
        .module-container { width: 1000px; margin: 0 auto; }
        
        table { 
            width: 100%; 
            border-collapse: collapse; 
            margin-top: 20px;
            background: white;
        }
        
        th, td { 
            padding: 12px; 
            text-align: left; 
            border-bottom: 1px solid #ddd; 
        }
        
        th { 
            background: #f0f0f0; 
            font-weight: bold; 
        }
        
        tr:hover { 
            background: #f5f5f5; 
        }
        
        .btn { 
            padding: 8px 12px; 
            margin: 5px 2px;
            border: none; 
            border-radius: 5px; 
            cursor: pointer;
            font-size: 14px;
        }
        
        .btn-edit { 
            background: #4CAF50; 
            color: white; 
        }
        
        .btn-edit:hover {
            background: #45a049;
        }
        
        .btn-delete { 
            background: #f44336; 
            color: white; 
        }
        
        .btn-delete:hover {
            background: #da190b;
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
        
        .form-group { 
            margin: 15px 0; 
        }
        
        .form-group label { 
            display: block; 
            margin-bottom: 5px; 
            font-weight: bold; 
        }
        
        .form-group input, 
        .form-group select { 
            width: 100%; 
            padding: 8px; 
            border: 1px solid #ddd; 
            border-radius: 5px;
            font-size: 14px;
            box-sizing: border-box;
        }

        .form-group input:focus,
        .form-group select:focus {
            outline: none;
            border-color: #4CAF50;
            box-shadow: 0 0 5px rgba(76, 175, 80, 0.3);
        }
        
        fieldset {
            margin: 20px 0; 
            padding: 15px; 
            border: 1px solid #ddd; 
            border-radius: 5px;
            background: #fafafa;
        }

        legend {
            padding: 0 10px;
            font-weight: bold;
            color: #333;
        }
        
        .search-box {
            margin: 20px 0;
            display: flex;
            gap: 10px;
        }

        .search-box input {
            flex: 1;
            padding: 8px;
            border: 1px solid #ddd;
            border-radius: 5px;
        }

        .search-box button {
            padding: 8px 20px;
            background: #2196F3;
            color: white;
            border: none;
            border-radius: 5px;
            cursor: pointer;
        }

        .search-box button:hover {
            background: #0b7dda;
        }

        .pagination {
            margin-top: 20px;
            text-align: center;
        }

        .pagination a {
            padding: 5px 10px;
            margin: 0 5px;
            border: 1px solid #ddd;
            border-radius: 3px;
            text-decoration: none;
            color: #333;
        }

        .pagination a:hover {
            background: #f0f0f0;
        }

        .pagination a.active {
            background: #4CAF50;
            color: white;
            border-color: #4CAF50;
        }

        .form-row {
            display: grid;
            grid-template-columns: 1fr 1fr 1fr 1fr;
            gap: 15px;
        }

        .back-link {
            margin-top: 20px;
            display: inline-block;
        }

        .back-link a {
            text-decoration: none;
            color: #2196F3;
            font-weight: bold;
        }

        .back-link a:hover {
            text-decoration: underline;
        }

        .no-data {
            text-align: center;
            padding: 40px;
            color: #666;
        }

        .action-buttons {
            display: flex;
            gap: 5px;
        }

        @media (max-width: 768px) {
            .module-container {
                width: 95%;
            }

            .form-row {
                grid-template-columns: 1fr;
            }

            table {
                font-size: 12px;
            }

            .btn {
                padding: 6px 10px;
                font-size: 12px;
            }
        }
    </style>
</head>
<body>

<div class="auth-card module-container">
    <h2>Manage Modules</h2>
    <p><strong>Course:</strong> <?= htmlspecialchars($course['course_name']) ?> | 
       <strong>Year:</strong> <?= $year ?> | 
       <strong>Semester:</strong> <?= $semester ?></p>

    <?php if ($message): ?>
        <div class="alert <?= $message_type === 'success' ? 'success' : 'error' ?>">
            <?= htmlspecialchars($message) ?>
        </div>
    <?php endif; ?>

    <!-- Add Module Form -->
    <fieldset>
        <legend>➕ Add New Module</legend>
        <form method="POST">
            <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">
            <input type="hidden" name="action" value="add">

            <div class="form-row">
                <div class="form-group">
                    <label>Module Code *</label>
                    <input type="text" name="module_code" placeholder="e.g., CS101" required>
                </div>

                <div class="form-group">
                    <label>Module Name *</label>
                    <input type="text" name="module_name" placeholder="e.g., Intro to Programming" required>
                </div>

                <div class="form-group">
                    <label>Teacher *</label>
                    <select name="teacher_id" required>
                        <option value="">-- Select Teacher --</option>
                        <?php 
                        while ($teacher = $teachers->fetch_assoc()): 
                        ?>
                            <option value="<?= $teacher['teacher_id'] ?>">
                                <?= htmlspecialchars($teacher['fullname']) ?>
                            </option>
                        <?php endwhile; ?>
                    </select>
                </div>

                <div class="form-group">
                    <label>&nbsp;</label>
                    <button type="submit" class="btn btn-edit" style="width: 100%;">Add Module</button>
                </div>
            </div>
        </form>
    </fieldset>

    <!-- Search Form -->
    <div class="search-box">
        <form method="GET" style="display: flex; gap: 10px; width: 100%;">
            <input type="hidden" name="course_id" value="<?= $course_id ?>">
            <input type="hidden" name="year" value="<?= $year ?>">
            <input type="hidden" name="semester" value="<?= $semester ?>">
            <input type="text" name="search" placeholder="Search by code or name..." value="<?= htmlspecialchars($search) ?>">
            <button type="submit">🔍 Search</button>
            <?php if($search): ?>
                <a href="?course_id=<?= $course_id ?>&year=<?= $year ?>&semester=<?= $semester ?>" class="btn" style="background: #999; color: white; text-decoration: none;">Clear</a>
            <?php endif; ?>
        </form>
    </div>

    <!-- Modules Table -->
    <?php if(count($modules) > 0): ?>
        <table>
            <thead>
                <tr>
                    <th>Code</th>
                    <th>Name</th>
                    <th>Teacher</th>
                    <th>Status</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($modules as $module): ?>
                    <tr>
                        <td><strong><?= htmlspecialchars($module['module_code']) ?></strong></td>
                        <td><?= htmlspecialchars($module['module_name']) ?></td>
                        <td><?= htmlspecialchars($module['teacher_name'] ?? 'Unassigned') ?></td>
                        <td>
                            <span style="padding: 5px 10px; border-radius: 3px; background: <?= $module['status'] === 'active' ? '#d4edda' : '#fff3cd' ?>; color: <?= $module['status'] === 'active' ? '#155724' : '#856404' ?>;">
                                <?= ucfirst($module['status']) ?>
                            </span>
                        </td>
                        <td>
                            <div class="action-buttons">
                                <button class="btn btn-edit" onclick="editModule(<?= $module['module_id'] ?>)">Edit</button>
                                <form method="POST" style="display: inline;">
                                    <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">
                                    <input type="hidden" name="action" value="delete">
                                    <input type="hidden" name="module_id" value="<?= $module['module_id'] ?>">
                                    <button type="submit" class="btn btn-delete" onclick="return confirm('Are you sure you want to delete this module?');">Delete</button>
                                </form>
                            </div>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>

        <!-- Pagination -->
        <?php if($pages > 1): ?>
            <div class="pagination">
                <?php for ($i = 1; $i <= $pages; $i++): ?>
                    <a href="?course_id=<?= $course_id ?>&year=<?= $year ?>&semester=<?= $semester ?>&page=<?= $i ?><?= $search ? '&search=' . urlencode($search) : '' ?>" 
                       class="<?= $i === $page ? 'active' : '' ?>">
                        <?= $i ?>
                    </a>
                <?php endfor; ?>
            </div>
        <?php endif; ?>
    <?php else: ?>
        <div class="no-data">
            <p>No modules found. Create your first module!</p>
        </div>
    <?php endif; ?>

    <div class="back-link">
        <a href="manage_courses.php">← Back to Courses</a>
    </div>
</div>

<script>
function editModule(moduleId) {
    alert('Edit functionality coming soon! Module ID: ' + moduleId);
    // TODO: Implement inline edit or modal
}
</script>

</body>
</html>