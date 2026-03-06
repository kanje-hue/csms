<?php
/**
 * admin/manage_modules.php - Manage Modules for a Semester
 */

session_start();
require_once '../config/db.php';
require_once '../config/security_base.php';

// Check admin login
checkAdminSession();

$course_id = isset($_GET['course_id']) ? (int)$_GET['course_id'] : 0;
$year = isset($_GET['year']) ? (int)$_GET['year'] : 0;
$semester = isset($_GET['semester']) ? (int)$_GET['semester'] : 0;

if (!$course_id || !$year || !$semester) {
    header("Location: dashboard.php");
    exit();
}

// Get course details
$course_stmt = $conn->prepare("SELECT course_name FROM courses WHERE course_id = ? AND deleted = 0");
$course_stmt->bind_param("i", $course_id);
$course_stmt->execute();
$course = $course_stmt->get_result()->fetch_assoc();
$course_stmt->close();

$message = "";
$message_type = "";
$csrf_token = generateCSRF();

// Handle Add Module
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'add') {
    validateCSRF($_POST['csrf_token'] ?? '');
    
    $module_code = sanitizeInput($_POST['module_code'] ?? '');
    $module_name = sanitizeInput($_POST['module_name'] ?? '');
    $teacher_id = (int)($_POST['teacher_id'] ?? 0);
    
    if (empty($module_code) || empty($module_name)) {
        $message = "Module code and name are required";
        $message_type = "error";
    } else {
        // Check if module code exists
        $check = $conn->prepare("SELECT module_id FROM modules WHERE module_code = ? AND course_id = ? AND deleted = 0");
        $check->bind_param("si", $module_code, $course_id);
        $check->execute();
        
        if ($check->get_result()->num_rows > 0) {
            $message = "Module code already exists for this course";
            $message_type = "error";
        } else {
            $insert = $conn->prepare("
                INSERT INTO modules (module_code, module_name, course_id, year, semester, teacher_id, status, created_at) 
                VALUES (?, ?, ?, ?, ?, ?, 'active', NOW())
            ");
            $insert->bind_param("ssiiii", $module_code, $module_name, $course_id, $year, $semester, $teacher_id);
            
            if ($insert->execute()) {
                $message = "Module added successfully";
                $message_type = "success";
                
                // Log action
                logAdminAction($conn, $_SESSION['admin_id'], 'add_module', "Added module: $module_code");
            } else {
                $message = "Error adding module";
                $message_type = "error";
            }
            $insert->close();
        }
        $check->close();
    }
}

// Handle Delete Module
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'delete') {
    validateCSRF($_POST['csrf_token'] ?? '');
    
    $module_id = (int)($_POST['module_id'] ?? 0);
    
    if ($module_id) {
        $stmt = $conn->prepare("UPDATE modules SET deleted = 1 WHERE module_id = ?");
        $stmt->bind_param("i", $module_id);
        
        if ($stmt->execute()) {
            $message = "Module moved to recycle bin";
            $message_type = "success";
            
            // Log action
            logAdminAction($conn, $_SESSION['admin_id'], 'delete_module', "Deleted module ID: $module_id");
        }
        $stmt->close();
    }
}

// Handle Update Module
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'update') {
    validateCSRF($_POST['csrf_token'] ?? '');
    
    $module_id = (int)($_POST['module_id'] ?? 0);
    $module_code = sanitizeInput($_POST['module_code'] ?? '');
    $module_name = sanitizeInput($_POST['module_name'] ?? '');
    $teacher_id = (int)($_POST['teacher_id'] ?? 0);
    $status = sanitizeInput($_POST['status'] ?? 'active');
    
    if ($module_id && !empty($module_code) && !empty($module_name)) {
        $update = $conn->prepare("
            UPDATE modules 
            SET module_code = ?, module_name = ?, teacher_id = ?, status = ? 
            WHERE module_id = ?
        ");
        $update->bind_param("ssisi", $module_code, $module_name, $teacher_id, $status, $module_id);
        
        if ($update->execute()) {
            $message = "Module updated successfully";
            $message_type = "success";
            
            logAdminAction($conn, $_SESSION['admin_id'], 'update_module', "Updated module ID: $module_id");
        }
        $update->close();
    }
}

// Get all modules for this semester
$modules_stmt = $conn->prepare("
    SELECT 
        m.*,
        t.fullname as teacher_name
    FROM modules m
    LEFT JOIN teachers t ON m.teacher_id = t.teacher_id
    WHERE m.course_id = ? AND m.year = ? AND m.semester = ? AND m.deleted = 0
    ORDER BY m.module_code ASC
");
$modules_stmt->bind_param("iii", $course_id, $year, $semester);
$modules_stmt->execute();
$modules = $modules_stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$modules_stmt->close();

// Get all teachers for dropdown
$teachers = $conn->query("SELECT teacher_id, fullname FROM teachers WHERE status = 'active' AND deleted = 0 ORDER BY fullname ASC");
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Manage Modules - <?= htmlspecialchars($course['course_name']) ?></title>
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

        /* Header */
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
            font-weight: 500;
        }

        .header a:hover {
            background: #2dd4bf;
            transform: translateY(-2px);
        }

        /* Container */
        .container {
            max-width: 1400px;
            margin: 2rem auto;
            padding: 0 2rem;
        }

        /* Breadcrumb */
        .breadcrumb {
            background: white;
            padding: 1rem 1.5rem;
            border-radius: 12px;
            margin-bottom: 2rem;
            box-shadow: 0 2px 4px rgba(0, 0, 0, 0.05);
        }

        .breadcrumb a {
            color: #2dd4bf;
            text-decoration: none;
            font-weight: 500;
        }

        .breadcrumb a:hover {
            text-decoration: underline;
        }

        /* Page Header */
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
            padding: 0.8rem 1.8rem;
            border: none;
            border-radius: 10px;
            font-weight: 600;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
            transition: all 0.3s;
            cursor: pointer;
            font-size: 0.95rem;
        }

        .btn-primary {
            background: linear-gradient(135deg, #2dd4bf, #14b8a6);
            color: white;
        }

        .btn-primary:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 16px -4px rgba(45, 212, 191, 0.4);
        }

        .btn-secondary {
            background: white;
            color: #0f172a;
            border: 1px solid #e2e8f0;
        }

        .btn-secondary:hover {
            border-color: #2dd4bf;
            background: #f8fafc;
        }

        .btn-warning {
            background: #f59e0b;
            color: white;
        }

        /* Alert */
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

        /* Form Card */
        .form-card {
            background: white;
            border-radius: 20px;
            padding: 2rem;
            margin-bottom: 2rem;
            box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.1);
        }

        .form-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 1.5rem;
            margin-bottom: 1.5rem;
        }

        .form-group {
            display: flex;
            flex-direction: column;
        }

        .form-group label {
            font-weight: 600;
            margin-bottom: 0.5rem;
            color: #0f172a;
        }

        .form-group input,
        .form-group select {
            padding: 0.8rem;
            border: 2px solid #e2e8f0;
            border-radius: 8px;
            font-size: 0.95rem;
            transition: all 0.3s;
        }

        .form-group input:focus,
        .form-group select:focus {
            outline: none;
            border-color: #2dd4bf;
            box-shadow: 0 0 0 3px rgba(45, 212, 191, 0.1);
        }

        /* Module List */
        .module-list {
            background: white;
            border-radius: 20px;
            padding: 2rem;
            box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.1);
        }

        .module-item {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 1rem;
            border-bottom: 1px solid #e2e8f0;
        }

        .module-item:last-child {
            border-bottom: none;
        }

        .module-code {
            font-weight: 700;
            color: #2dd4bf;
            min-width: 100px;
        }

        .module-info {
            flex: 1;
            margin-left: 1rem;
        }

        .module-name {
            font-weight: 600;
            color: #0f172a;
        }

        .module-teacher {
            font-size: 0.85rem;
            color: #64748b;
            margin-top: 0.25rem;
        }

        .module-actions {
            display: flex;
            gap: 0.5rem;
        }

        .btn-small {
            padding: 0.4rem 1rem;
            border: none;
            border-radius: 6px;
            font-weight: 500;
            font-size: 0.85rem;
            cursor: pointer;
            text-decoration: none;
            transition: all 0.3s;
        }

        .btn-edit {
            background: #e2e8f0;
            color: #0f172a;
        }

        .btn-edit:hover {
            background: #cbd5e1;
        }

        .btn-delete {
            background: #fee2e2;
            color: #991b1b;
        }

        .btn-delete:hover {
            background: #fecaca;
        }

        .empty-state {
            text-align: center;
            padding: 3rem;
            color: #64748b;
        }

        /* Modal */
        .modal {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0, 0, 0, 0.5);
            align-items: center;
            justify-content: center;
            z-index: 1000;
        }

        .modal.show {
            display: flex;
        }

        .modal-content {
            background: white;
            border-radius: 20px;
            padding: 2rem;
            max-width: 500px;
            width: 90%;
        }

        .modal-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 1.5rem;
        }

        .modal-header h2 {
            color: #0f172a;
        }

        .close-btn {
            background: none;
            border: none;
            font-size: 1.5rem;
            cursor: pointer;
            color: #64748b;
        }

        .modal-actions {
            display: flex;
            gap: 1rem;
            margin-top: 1.5rem;
        }

        @media (max-width: 768px) {
            .container {
                padding: 0 1rem;
            }

            .form-grid {
                grid-template-columns: 1fr;
            }

            .module-item {
                flex-direction: column;
                align-items: flex-start;
                gap: 1rem;
            }

            .module-info {
                margin-left: 0;
            }

            .module-actions {
                width: 100%;
            }

            .btn-small {
                flex: 1;
                text-align: center;
            }
        }
    </style>
</head>
<body>

<div class="header">
    <h1>CSMS Admin</h1>
    <a href="logout.php">🚪 Logout</a>
</div>

<div class="container">
    <!-- Breadcrumb -->
    <div class="breadcrumb">
        <a href="dashboard.php">Dashboard</a> > 
        <a href="manage_course_structure.php?course_id=<?= $course_id ?>"><?= htmlspecialchars($course['course_name']) ?></a> > 
        <a href="manage_semester.php?course_id=<?= $course_id ?>&year=<?= $year ?>&semester=<?= $semester ?>">Year <?= $year ?> - Semester <?= $semester ?></a> > 
        <strong>Modules</strong>
    </div>

    <!-- Page Header -->
    <div class="page-header">
        <h1>📚 Manage Modules</h1>
        <div>
            <button onclick="openAddModal()" class="btn btn-primary">➕ Add Module</button>
            <a href="manage_semester.php?course_id=<?= $course_id ?>&year=<?= $year ?>&semester=<?= $semester ?>" class="btn btn-secondary">← Back</a>
        </div>
    </div>

    <?php if ($message): ?>
        <div class="alert <?= $message_type ?>"><?= $message ?></div>
    <?php endif; ?>

    <!-- Modules List -->
    <div class="module-list">
        <h2 style="margin-bottom: 1.5rem;">Current Modules (<?= count($modules) ?>)</h2>
        
        <?php if (count($modules) > 0): ?>
            <?php foreach ($modules as $module): ?>
            <div class="module-item">
                <div class="module-code"><?= htmlspecialchars($module['module_code']) ?></div>
                <div class="module-info">
                    <div class="module-name"><?= htmlspecialchars($module['module_name']) ?></div>
                    <div class="module-teacher">Teacher: <?= htmlspecialchars($module['teacher_name'] ?? 'Not Assigned') ?></div>
                </div>
                <div class="module-actions">
                    <button onclick="openEditModal(<?= htmlspecialchars(json_encode($module)) ?>)" class="btn-small btn-edit">Edit</button>
                    <form method="POST" style="display: inline;" onsubmit="return confirm('Move this module to recycle bin?')">
                        <input type="hidden" name="csrf_token" value="<?= $csrf_token ?>">
                        <input type="hidden" name="action" value="delete">
                        <input type="hidden" name="module_id" value="<?= $module['module_id'] ?>">
                        <button type="submit" class="btn-small btn-delete">Delete</button>
                    </form>
                </div>
            </div>
            <?php endforeach; ?>
        <?php else: ?>
            <div class="empty-state">
                <p>No modules found for this semester.</p>
                <button onclick="openAddModal()" class="btn btn-primary" style="margin-top: 1rem;">➕ Add Your First Module</button>
            </div>
        <?php endif; ?>
    </div>
</div>

<!-- Add/Edit Module Modal -->
<div id="moduleModal" class="modal">
    <div class="modal-content">
        <div class="modal-header">
            <h2 id="modalTitle">Add Module</h2>
            <button class="close-btn" onclick="closeModal()">&times;</button>
        </div>

        <form id="moduleForm" method="POST">
            <input type="hidden" name="csrf_token" value="<?= $csrf_token ?>">
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
                <label for="teacher">Assign Teacher</label>
                <select id="teacher" name="teacher_id">
                    <option value="0">-- Not Assigned --</option>
                    <?php 
                    $teachers->data_seek(0);
                    while ($teacher = $teachers->fetch_assoc()): 
                    ?>
                        <option value="<?= $teacher['teacher_id'] ?>">
                            <?= htmlspecialchars($teacher['fullname']) ?>
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

            <div class="modal-actions">
                <button type="submit" class="btn btn-primary">Save</button>
                <button type="button" class="btn btn-secondary" onclick="closeModal()">Cancel</button>
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
    document.getElementById('teacher').value = '0';
    document.getElementById('statusGroup').style.display = 'none';
    document.getElementById('moduleModal').classList.add('show');
}

function openEditModal(module) {
    document.getElementById('modalTitle').textContent = 'Edit Module';
    document.getElementById('formAction').value = 'update';
    document.getElementById('moduleId').value = module.module_id;
    document.getElementById('moduleCode').value = module.module_code;
    document.getElementById('moduleName').value = module.module_name;
    document.getElementById('teacher').value = module.teacher_id || '0';
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