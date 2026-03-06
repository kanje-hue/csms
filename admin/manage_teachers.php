<?php
/**
 * admin/manage_teachers.php - Teacher Management
 */

session_start();
require_once '../config/db.php';
require_once '../config/security_base.php';

// Check admin login
checkAdminSession();

$course_id = isset($_GET['course_id']) ? (int)$_GET['course_id'] : 0;

// Get course name if provided
$course_name = '';
if ($course_id) {
    $stmt = $conn->prepare("SELECT course_name FROM courses WHERE course_id = ? AND deleted = 0");
    $stmt->bind_param("i", $course_id);
    $stmt->execute();
    $result = $stmt->get_result();
    $course = $result->fetch_assoc();
    $course_name = $course['course_name'] ?? '';
    $stmt->close();
}

$message = "";
$message_type = "";
$csrf_token = generateCSRF();

// Handle teacher status changes
if (isset($_GET['action']) && isset($_GET['id'])) {
    $action = $_GET['action'];
    $id = (int)$_GET['id'];
    
    if (in_array($action, ['activate', 'deactivate', 'delete'])) {
        if ($action === 'delete') {
            $stmt = $conn->prepare("UPDATE teachers SET deleted = 1, deleted_at = NOW() WHERE teacher_id = ?");
            $message_text = "Teacher moved to recycle bin";
        } elseif ($action === 'activate') {
            $stmt = $conn->prepare("UPDATE teachers SET status = 'active' WHERE teacher_id = ?");
            $message_text = "Teacher activated";
        } elseif ($action === 'deactivate') {
            $stmt = $conn->prepare("UPDATE teachers SET status = 'inactive' WHERE teacher_id = ?");
            $message_text = "Teacher deactivated";
        }
        
        $stmt->bind_param("i", $id);
        if ($stmt->execute()) {
            $message = $message_text;
            $message_type = "success";
            
            logAdminAction($conn, $_SESSION['admin_id'], $action . '_teacher', "Teacher ID: $id");
        }
        $stmt->close();
    }
}

// Get all teachers
$teachers_query = "
    SELECT 
        t.*,
        COUNT(DISTINCT m.module_id) as module_count
    FROM teachers t
    LEFT JOIN modules m ON t.teacher_id = m.teacher_id AND m.deleted = 0
    WHERE t.deleted = 0
    GROUP BY t.teacher_id
    ORDER BY t.created_at DESC
";
$teachers = $conn->query($teachers_query)->fetch_all(MYSQLI_ASSOC);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Manage Teachers - CSMS</title>
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

        .header a {
            color: white;
            text-decoration: none;
            padding: 0.5rem 1.2rem;
            border-radius: 8px;
            background: rgba(255, 255, 255, 0.1);
            transition: all 0.3s;
        }

        .header a:hover {
            background: #2dd4bf;
            transform: translateY(-2px);
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
            color: #2dd4bf;
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
            border-left: 4px solid #2dd4bf;
        }

        .stat-number {
            font-size: 2rem;
            font-weight: 700;
            color: #0f172a;
        }

        .teacher-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(350px, 1fr));
            gap: 1.5rem;
        }

        .teacher-card {
            background: white;
            border-radius: 16px;
            overflow: hidden;
            box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.1);
            border-left: 4px solid #2dd4bf;
        }

        .teacher-header {
            background: linear-gradient(135deg, #0f172a, #1e293b);
            color: white;
            padding: 1.5rem;
        }

        .teacher-name {
            font-size: 1.2rem;
            font-weight: 600;
            margin-bottom: 0.3rem;
        }

        .teacher-email {
            font-size: 0.9rem;
            opacity: 0.8;
        }

        .teacher-body {
            padding: 1.5rem;
        }

        .teacher-stats {
            display: flex;
            gap: 1rem;
            margin-bottom: 1rem;
        }

        .teacher-stat {
            flex: 1;
            text-align: center;
            padding: 0.5rem;
            background: #f8fafc;
            border-radius: 8px;
        }

        .teacher-stat-value {
            font-weight: 700;
            color: #2dd4bf;
        }

        .teacher-stat-label {
            font-size: 0.8rem;
            color: #64748b;
        }

        .status-badge {
            display: inline-block;
            padding: 0.3rem 0.8rem;
            border-radius: 20px;
            font-size: 0.8rem;
            font-weight: 600;
        }

        .status-active {
            background: #d1fae5;
            color: #065f46;
        }

        .status-inactive {
            background: #fee2e2;
            color: #991b1b;
        }

        .force-change {
            background: #fef3c7;
            color: #92400e;
            padding: 0.3rem 0.8rem;
            border-radius: 20px;
            font-size: 0.8rem;
            margin-left: 0.5rem;
        }

        .teacher-actions {
            display: flex;
            gap: 0.5rem;
            margin-top: 1rem;
        }

        .action-btn {
            flex: 1;
            padding: 0.5rem;
            border: none;
            border-radius: 6px;
            font-weight: 500;
            font-size: 0.85rem;
            cursor: pointer;
            text-decoration: none;
            text-align: center;
        }

        .btn-edit {
            background: #e2e8f0;
            color: #0f172a;
        }

        .btn-activate {
            background: #d1fae5;
            color: #065f46;
        }

        .btn-deactivate {
            background: #fee2e2;
            color: #991b1b;
        }

        .btn-delete {
            background: #fee2e2;
            color: #991b1b;
        }

        @media (max-width: 768px) {
            .container {
                padding: 0 1rem;
            }

            .teacher-grid {
                grid-template-columns: 1fr;
            }

            .teacher-actions {
                flex-direction: column;
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
    <div class="breadcrumb">
        <a href="dashboard.php">Dashboard</a>
        <?php if ($course_id && $course_name): ?>
            > <a href="manage_course_structure.php?course_id=<?= $course_id ?>"><?= htmlspecialchars($course_name) ?></a>
        <?php endif; ?>
        > <strong>Teachers</strong>
    </div>

    <div class="page-header">
        <h1>👨‍🏫 Teacher Management</h1>
        <div>
            <a href="add_teacher.php<?= $course_id ? '?course_id=' . $course_id : '' ?>" class="btn btn-primary">➕ Add Teacher</a>
            <a href="recycle_teachers.php" class="btn btn-secondary">🗑️ Recycle Bin</a>
        </div>
    </div>

    <?php if ($message): ?>
        <div class="alert <?= $message_type ?>"><?= $message ?></div>
    <?php endif; ?>

    <?php
    $total_teachers = count($teachers);
    $active_teachers = count(array_filter($teachers, fn($t) => $t['status'] === 'active'));
    $force_change = count(array_filter($teachers, fn($t) => $t['force_password_change'] == 1));
    ?>
    <div class="stats-grid">
        <div class="stat-card">
            <div class="stat-number"><?= $total_teachers ?></div>
            <div>Total Teachers</div>
        </div>
        <div class="stat-card">
            <div class="stat-number"><?= $active_teachers ?></div>
            <div>Active</div>
        </div>
        <div class="stat-card">
            <div class="stat-number"><?= $force_change ?></div>
            <div>Must Change Password</div>
        </div>
    </div>

    <div class="teacher-grid">
        <?php if (count($teachers) > 0): ?>
            <?php foreach ($teachers as $teacher): ?>
            <div class="teacher-card">
                <div class="teacher-header">
                    <div class="teacher-name"><?= htmlspecialchars($teacher['fullname']) ?></div>
                    <div class="teacher-email"><?= htmlspecialchars($teacher['email']) ?></div>
                </div>
                <div class="teacher-body">
                    <div class="teacher-stats">
                        <div class="teacher-stat">
                            <div class="teacher-stat-value"><?= $teacher['module_count'] ?></div>
                            <div class="teacher-stat-label">Modules</div>
                        </div>
                        <div class="teacher-stat">
                            <div class="teacher-stat-value">
                                <span class="status-badge status-<?= $teacher['status'] ?>">
                                    <?= ucfirst($teacher['status']) ?>
                                </span>
                            </div>
                            <div class="teacher-stat-label">Status</div>
                        </div>
                    </div>
                    
                    <?php if ($teacher['force_password_change']): ?>
                        <div style="text-align: center; margin-bottom: 0.5rem;">
                            <span class="force-change">⚠️ Must change password</span>
                        </div>
                    <?php endif; ?>
                    
                    <div class="teacher-actions">
                        <a href="edit_teacher.php?id=<?= $teacher['teacher_id'] ?>" class="action-btn btn-edit">✏️ Edit</a>
                        
                        <?php if ($teacher['status'] === 'active'): ?>
                            <a href="?action=deactivate&id=<?= $teacher['teacher_id'] ?><?= $course_id ? '&course_id=' . $course_id : '' ?>" class="action-btn btn-deactivate">⏸️ Deactivate</a>
                        <?php else: ?>
                            <a href="?action=activate&id=<?= $teacher['teacher_id'] ?><?= $course_id ? '&course_id=' . $course_id : '' ?>" class="action-btn btn-activate">▶️ Activate</a>
                        <?php endif; ?>
                        
                        <a href="?action=delete&id=<?= $teacher['teacher_id'] ?><?= $course_id ? '&course_id=' . $course_id : '' ?>" class="action-btn btn-delete" onclick="return confirm('Move this teacher to recycle bin?')">🗑️ Delete</a>
                    </div>
                </div>
            </div>
            <?php endforeach; ?>
        <?php else: ?>
            <p style="grid-column: 1/-1; text-align: center; padding: 3rem; color: #64748b;">No teachers found.</p>
        <?php endif; ?>
    </div>
</div>

</body>
</html>