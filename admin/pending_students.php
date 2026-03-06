<?php
/**
 * admin/pending_students.php - Manage Pending Student Approvals
 * CLEANED: Removed extra statistics, fixed header
 */

session_start();
require_once '../config/db.php';
require_once '../config/security_base.php';

// Check admin login
if (!isset($_SESSION['admin_logged_in'])) {
    header("Location: login.php");
    exit();
}

$message = "";
$message_type = "";
$csrf_token = generateCSRF();

// Handle Activate Student
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'activate') {
    validateCSRF($_POST['csrf_token'] ?? '');
    
    $student_id = (int)($_POST['student_id'] ?? 0);
    
    if ($student_id) {
        $stmt = $conn->prepare("UPDATE students SET status = 'active' WHERE student_id = ? AND deleted = 0");
        $stmt->bind_param("i", $student_id);
        
        if ($stmt->execute()) {
            $message = "✓ Student activated successfully!";
            $message_type = "success";
            logAdminAction($conn, $_SESSION['admin_id'], 'activate_student', "Activated student ID: $student_id");
        }
        $stmt->close();
    }
}

// Handle Reject Student
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'reject') {
    validateCSRF($_POST['csrf_token'] ?? '');
    
    $student_id = (int)($_POST['student_id'] ?? 0);
    
    if ($student_id) {
        $stmt = $conn->prepare("UPDATE students SET deleted = 1 WHERE student_id = ?");
        $stmt->bind_param("i", $student_id);
        
        if ($stmt->execute()) {
            $message = "✗ Student registration rejected";
            $message_type = "success";
            logAdminAction($conn, $_SESSION['admin_id'], 'reject_student', "Rejected student ID: $student_id");
        }
        $stmt->close();
    }
}

// Handle Bulk Activate
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'bulk_activate') {
    validateCSRF($_POST['csrf_token'] ?? '');
    
    $student_ids = $_POST['student_ids'] ?? [];
    $activated = 0;
    
    foreach ($student_ids as $student_id) {
        $student_id = (int)$student_id;
        $activate = $conn->prepare("UPDATE students SET status = 'active' WHERE student_id = ?");
        $activate->bind_param("i", $student_id);
        if ($activate->execute()) {
            $activated++;
        }
        $activate->close();
    }
    
    if ($activated > 0) {
        $message = "✓ $activated students activated successfully!";
        $message_type = "success";
        logAdminAction($conn, $_SESSION['admin_id'], 'bulk_activate', "Bulk activated $activated students");
    }
}

// Pagination
$page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
$limit = 20;
$offset = ($page - 1) * $limit;

// Search
$search = isset($_GET['search']) ? sanitizeInput($_GET['search']) : '';
$search_condition = '';
$search_params = [];
$search_types = '';

if (!empty($search)) {
    $search_condition = "AND (s.name LIKE ? OR s.email LIKE ? OR s.reg_number LIKE ?)";
    $search_term = "%$search%";
    $search_params = [$search_term, $search_term, $search_term];
    $search_types = "sss";
}

// Get total count
$count_query = "
    SELECT COUNT(*) as total 
    FROM students s
    JOIN courses c ON s.course_id = c.course_id
    WHERE s.status = 'pending' AND s.deleted = 0
    $search_condition
";
$count_stmt = $conn->prepare($count_query);

if (!empty($search)) {
    $count_stmt->bind_param($search_types, ...$search_params);
}
$count_stmt->execute();
$total = $count_stmt->get_result()->fetch_assoc()['total'];
$pages = ceil($total / $limit);
$count_stmt->close();

// Get pending students
$query = "
    SELECT 
        s.student_id,
        s.reg_number,
        s.name,
        s.email,
        s.year,
        s.semester,
        s.created_at,
        c.course_name
    FROM students s
    JOIN courses c ON s.course_id = c.course_id
    WHERE s.status = 'pending' AND s.deleted = 0
    $search_condition
    ORDER BY s.created_at ASC
    LIMIT ? OFFSET ?
";

$stmt = $conn->prepare($query);
$params = [];

if (!empty($search)) {
    $params = array_merge($search_params, [$limit, $offset]);
    $stmt->bind_param($search_types . "ii", ...$params);
} else {
    $stmt->bind_param("ii", $limit, $offset);
}
$stmt->execute();
$students = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

// Get total pending count
$total_pending = $conn->query("SELECT COUNT(*) as count FROM students WHERE status = 'pending' AND deleted = 0")->fetch_assoc()['count'];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Pending Students - CSMS</title>
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
            background: linear-gradient(135deg, #2dd4bf, #14b8a6);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            background-clip: text;
        }

        .admin-info {
            display: flex;
            align-items: center;
            gap: 2rem;
        }

        .logout-btn {
            background: rgba(255, 255, 255, 0.1);
            color: white;
            padding: 0.5rem 1.2rem;
            border-radius: 8px;
            text-decoration: none;
            transition: all 0.3s;
        }

        .logout-btn:hover {
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
            color: #2dd4bf;
            text-decoration: none;
            font-weight: 500;
        }

        .page-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 2rem;
            width: 100%;
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
            display: inline-block;
            transition: all 0.3s;
            cursor: pointer;
        }

        .btn-primary {
            background: #2dd4bf;
            color: white;
        }

        .btn-primary:hover {
            background: #14b8a6;
        }

        .btn-success {
            background: #10b981;
            color: white;
        }

        .btn-danger {
            background: #ef4444;
            color: white;
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

        .search-box {
            background: white;
            padding: 1.5rem;
            border-radius: 12px;
            margin-bottom: 2rem;
            box-shadow: 0 2px 4px rgba(0, 0, 0, 0.1);
        }

        .search-form {
            display: flex;
            gap: 1rem;
            align-items: center;
            flex-wrap: wrap;
        }

        .search-form input {
            flex: 1;
            min-width: 300px;
            padding: 0.8rem;
            border: 2px solid #e2e8f0;
            border-radius: 8px;
            font-size: 1rem;
        }

        .search-form input:focus {
            outline: none;
            border-color: #2dd4bf;
        }

        .bulk-actions {
            background: white;
            padding: 1.5rem;
            border-radius: 12px;
            margin-bottom: 2rem;
            display: flex;
            gap: 1rem;
            align-items: center;
            flex-wrap: wrap;
            box-shadow: 0 2px 4px rgba(0, 0, 0, 0.1);
        }

        .student-table {
            background: white;
            border-radius: 16px;
            overflow: hidden;
            box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.1);
            width: 100%;
        }

        table {
            width: 100%;
            border-collapse: collapse;
        }

        th {
            background: #f8fafc;
            padding: 1rem;
            text-align: left;
            font-weight: 600;
            color: #0f172a;
            border-bottom: 2px solid #e2e8f0;
        }

        td {
            padding: 1rem;
            border-bottom: 1px solid #e2e8f0;
        }

        tr:hover {
            background: #f8fafc;
        }

        .action-buttons {
            display: flex;
            gap: 0.5rem;
            flex-wrap: wrap;
        }

        .action-btn {
            padding: 0.4rem 0.8rem;
            border: none;
            border-radius: 6px;
            font-weight: 600;
            font-size: 0.8rem;
            cursor: pointer;
            text-decoration: none;
            display: inline-block;
        }

        .btn-activate {
            background: #d1fae5;
            color: #065f46;
        }

        .btn-reject {
            background: #fee2e2;
            color: #991b1b;
        }

        .pagination {
            margin-top: 2rem;
            display: flex;
            justify-content: center;
            gap: 0.5rem;
        }

        .pagination a, .pagination span {
            padding: 0.5rem 1rem;
            border: 1px solid #e2e8f0;
            border-radius: 6px;
            text-decoration: none;
            color: #0f172a;
        }

        .pagination span.active {
            background: #2dd4bf;
            color: white;
            border-color: #2dd4bf;
        }

        .no-data {
            text-align: center;
            padding: 4rem;
            color: #64748b;
        }

        .back-link {
            margin-top: 2rem;
            text-align: center;
        }

        @media (max-width: 768px) {
            .container {
                padding: 0 1rem;
            }

            .bulk-actions {
                flex-direction: column;
            }

            .search-form {
                flex-direction: column;
            }

            .search-form input {
                width: 100%;
                min-width: auto;
            }

            .action-buttons {
                flex-direction: column;
            }
        }
    </style>
</head>
<body>

<div class="header">
    <div class="logo">
        <h1>CSMS Admin</h1>
    </div>
    <div class="admin-info">
        <a href="dashboard.php" class="logout-btn">Dashboard</a>
        <a href="logout.php" class="logout-btn">Logout</a>
    </div>
</div>

<div class="container">
    <div class="breadcrumb">
        <a href="dashboard.php">Dashboard</a> > 
        <strong>Pending Approvals</strong>
    </div>

    <div class="page-header">
        <h1>⏳ Pending Student Approvals</h1>
        <a href="auto_activate_students.php" class="btn btn-primary">⚡ Auto-Activate All</a>
    </div>

    <?php if ($message): ?>
        <div class="alert <?= $message_type ?>"><?= $message ?></div>
    <?php endif; ?>

    <!-- Search -->
    <div class="search-box">
        <form method="GET" class="search-form">
            <input type="text" name="search" placeholder="Search by name, email, or registration number..." value="<?= htmlspecialchars($search) ?>">
            <button type="submit" class="btn btn-primary">🔍 Search</button>
            <?php if (!empty($search)): ?>
                <a href="pending_students.php" class="btn btn-primary" style="background: #6c757d;">Clear</a>
            <?php endif; ?>
        </form>
    </div>

    <!-- Bulk Actions -->
    <form method="POST" id="bulkForm">
        <input type="hidden" name="csrf_token" value="<?= $csrf_token ?>">
        <input type="hidden" name="action" value="bulk_activate">
        
        <div class="bulk-actions">
            <span>Selected: <span id="selectedCount">0</span> students</span>
            <button type="submit" class="btn btn-success" onclick="return confirm('Activate selected students?')">✓ Bulk Activate</button>
            <button type="button" class="btn btn-primary" onclick="selectAll()">Select All</button>
            <button type="button" class="btn btn-primary" onclick="deselectAll()">Deselect All</button>
        </div>

        <!-- Students Table -->
        <div class="student-table">
            <?php if (count($students) > 0): ?>
            <table>
                <thead>
                    <tr>
                        <th style="width: 40px;">
                            <input type="checkbox" id="selectAll" onchange="toggleAll(this)">
                        </th>
                        <th>Reg Number</th>
                        <th>Student Name</th>
                        <th>Email</th>
                        <th>Course</th>
                        <th>Year/Sem</th>
                        <th>Registered</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($students as $student): ?>
                    <tr>
                        <td>
                            <input type="checkbox" name="student_ids[]" value="<?= $student['student_id'] ?>" class="student-checkbox">
                        </td>
                        <td><strong><?= htmlspecialchars($student['reg_number']) ?></strong></td>
                        <td><?= htmlspecialchars($student['name']) ?></td>
                        <td><?= htmlspecialchars($student['email']) ?></td>
                        <td><?= htmlspecialchars($student['course_name']) ?></td>
                        <td>Year <?= $student['year'] ?>, Sem <?= $student['semester'] ?></td>
                        <td><?= date('M d, Y', strtotime($student['created_at'])) ?></td>
                        <td>
                            <div class="action-buttons">
                                <form method="POST" style="display: inline;">
                                    <input type="hidden" name="csrf_token" value="<?= $csrf_token ?>">
                                    <input type="hidden" name="action" value="activate">
                                    <input type="hidden" name="student_id" value="<?= $student['student_id'] ?>">
                                    <button type="submit" class="action-btn btn-activate">✓ Activate</button>
                                </form>
                                <form method="POST" style="display: inline;">
                                    <input type="hidden" name="csrf_token" value="<?= $csrf_token ?>">
                                    <input type="hidden" name="action" value="reject">
                                    <input type="hidden" name="student_id" value="<?= $student['student_id'] ?>">
                                    <button type="submit" class="action-btn btn-reject" onclick="return confirm('Reject this student?')">✗ Reject</button>
                                </form>
                            </div>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
            <?php else: ?>
            <div class="no-data">
                <p style="font-size: 1.2rem; margin-bottom: 1rem;">No pending students found</p>
                <?php if (!empty($search)): ?>
                    <a href="pending_students.php" class="btn btn-primary">Clear Search</a>
                <?php endif; ?>
            </div>
            <?php endif; ?>
        </div>
    </form>

    <!-- Pagination -->
    <?php if ($pages > 1): ?>
    <div class="pagination">
        <?php if ($page > 1): ?>
            <a href="?page=1<?= !empty($search) ? '&search=' . urlencode($search) : '' ?>">« First</a>
            <a href="?page=<?= $page - 1 ?><?= !empty($search) ? '&search=' . urlencode($search) : '' ?>">‹ Previous</a>
        <?php endif; ?>
        
        <?php for ($i = max(1, $page - 2); $i <= min($pages, $page + 2); $i++): ?>
            <?php if ($i == $page): ?>
                <span class="active"><?= $i ?></span>
            <?php else: ?>
                <a href="?page=<?= $i ?><?= !empty($search) ? '&search=' . urlencode($search) : '' ?>"><?= $i ?></a>
            <?php endif; ?>
        <?php endfor; ?>
        
        <?php if ($page < $pages): ?>
            <a href="?page=<?= $page + 1 ?><?= !empty($search) ? '&search=' . urlencode($search) : '' ?>">Next ›</a>
            <a href="?page=<?= $pages ?><?= !empty($search) ? '&search=' . urlencode($search) : '' ?>">Last »</a>
        <?php endif; ?>
    </div>
    <?php endif; ?>

    <div class="back-link">
        <a href="dashboard.php" class="btn btn-primary">← Back to Dashboard</a>
    </div>
</div>

<script>
function toggleAll(source) {
    const checkboxes = document.querySelectorAll('.student-checkbox');
    checkboxes.forEach(cb => cb.checked = source.checked);
    updateSelectedCount();
}

function selectAll() {
    const checkboxes = document.querySelectorAll('.student-checkbox');
    checkboxes.forEach(cb => cb.checked = true);
    document.getElementById('selectAll').checked = true;
    updateSelectedCount();
}

function deselectAll() {
    const checkboxes = document.querySelectorAll('.student-checkbox');
    checkboxes.forEach(cb => cb.checked = false);
    document.getElementById('selectAll').checked = false;
    updateSelectedCount();
}

function updateSelectedCount() {
    const checkboxes = document.querySelectorAll('.student-checkbox:checked');
    document.getElementById('selectedCount').textContent = checkboxes.length;
}

document.querySelectorAll('.student-checkbox').forEach(cb => {
    cb.addEventListener('change', updateSelectedCount);
});
</script>

</body>
</html>