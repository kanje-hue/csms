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

/* ================= REJECT STUDENT ================= */
if($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'reject'){
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
                $message = "✗ Student registration rejected.";
                $message_type = "success";
            } else {
                $message = "Error rejecting student";
                $message_type = "error";
            }
            $stmt->close();
        }
    }
}

/* ================= FETCH PENDING STUDENTS ================= */
$search = "";
$search_term = null;
$where = "WHERE deleted = 0";

if (isset($_GET['search']) && !empty($_GET['search'])) {
    $search = safe_string($_GET['search']);
    $search_term = "%" . $search . "%";
    $where .= " AND (name LIKE ? OR email LIKE ? OR reg_number LIKE ?)";
}

// Pagination
$page = isset($_GET['page']) ? safe_int($_GET['page']) : 1;
if (!$page) $page = 1;

$limit = 15;
$offset = ($page - 1) * $limit;

// Count total
$count_query = "SELECT COUNT(*) as total FROM students $where";
$count_stmt = $conn->prepare($count_query);

if ($search_term) {
    $count_stmt->bind_param("sss", $search_term, $search_term, $search_term);
}
$count_stmt->execute();
$total = $count_stmt->get_result()->fetch_assoc()['total'];
$pages = ceil($total / $limit);
$count_stmt->close();

// Fetch pending students
$query = "
    SELECT 
        s.student_id,
        s.reg_number,
        s.name,
        s.email,
        s.status,
        s.year,
        s.semester,
        c.course_name,
        s.created_at
    FROM students s
    LEFT JOIN courses c ON s.course_id = c.course_id
    $where
    ORDER BY s.status ASC, s.created_at DESC
    LIMIT ?, ?
";

$stmt = $conn->prepare($query);

if ($search_term) {
    $stmt->bind_param("sssii", $search_term, $search_term, $search_term, $limit, $offset);
} else {
    $stmt->bind_param("ii", $limit, $offset);
}

$stmt->execute();
$students = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

// Count by status
$status_counts = $conn->query("
    SELECT 
        COUNT(CASE WHEN status = 'pending' THEN 1 END) as pending,
        COUNT(CASE WHEN status = 'active' THEN 1 END) as active,
        COUNT(CASE WHEN status = 'inactive' THEN 1 END) as inactive
    FROM students WHERE deleted = 0
");
$counts = $status_counts->fetch_assoc();

?>

<!DOCTYPE html>
<html>
<head>
    <title>Pending Students Approval</title>
    <link rel="stylesheet" href="../assets/css/auth.css">
    <style>
        .auth-card {
            width: 1100px;
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

        .stats {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 15px;
            margin: 20px 0;
        }

        .stat-card {
            background: linear-gradient(135deg, var(--terra-rosa), var(--honey-glow));
            color: white;
            padding: 20px;
            border-radius: 10px;
            text-align: center;
            box-shadow: 0 4px 6px rgba(0,0,0,0.1);
        }

        .stat-number {
            font-size: 32px;
            font-weight: bold;
        }

        .stat-label {
            font-size: 14px;
            margin-top: 5px;
            opacity: 0.9;
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

        th:first-child, td:first-child {
            text-align: left;
        }

        tr:nth-child(even) {
            background: #f9f9f9;
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

        .badge-pending {
            background: #cce5ff;
            color: #004085;
        }

        .badge-active {
            background: #d4edda;
            color: #155724;
        }

        .badge-inactive {
            background: #fff3cd;
            color: #856404;
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

        .back-btn {
            float: right;
        }

        .no-data {
            text-align: center;
            padding: 40px;
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

        .auth-links {
            text-align: center;
            margin-top: 15px;
        }

        @media (max-width: 768px) {
            .auth-card {
                width: 95%;
                padding: 15px;
            }

            .stats {
                grid-template-columns: 1fr;
            }

            table {
                font-size: 12px;
            }

            th, td {
                padding: 8px;
            }

            .back-btn {
                float: none;
                display: block;
                width: 100%;
                text-align: center;
            }
        }
    </style>
</head>
<body>

<div class="auth-card">
    <h2>📋 Student Registration Approval</h2>

    <?php if ($message): ?>
        <div class="alert <?= $message_type === 'success' ? 'success' : 'error' ?>">
            <?= htmlspecialchars($message) ?>
        </div>
    <?php endif; ?>

    <!-- Statistics -->
    <div class="stats">
        <div class="stat-card" style="background: linear-gradient(135deg, #cce5ff, #e3f2fd);">
            <div class="stat-number" style="color: #004085;"><?= $counts['pending'] ?></div>
            <div class="stat-label" style="color: #004085;">Pending Approval</div>
        </div>
        <div class="stat-card" style="background: linear-gradient(135deg, #d4edda, #c3e6cb);">
            <div class="stat-number" style="color: #155724;"><?= $counts['active'] ?></div>
            <div class="stat-label" style="color: #155724;">Active Students</div>
        </div>
        <div class="stat-card" style="background: linear-gradient(135deg, #fff3cd, #ffeeba);">
            <div class="stat-number" style="color: #856404;"><?= $counts['inactive'] ?></div>
            <div class="stat-label" style="color: #856404;">Inactive</div>
        </div>
    </div>

    <a href="dashboard.php" class="btn back-btn">← Back to Dashboard</a>
    <div style="clear: both;"></div>

    <!-- Search -->
    <div class="search-box">
        <form method="GET" style="display: flex; gap: 10px; width: 100%;">
            <input type="text" name="search" placeholder="Search by name, email, or reg number..." value="<?= htmlspecialchars($search) ?>">
            <button type="submit">🔍 Search</button>
            <?php if(!empty($search)): ?>
                <a href="pending_students.php" style="padding: 8px 15px; background: #999; color: white; text-decoration: none; border-radius: 8px;">Clear</a>
            <?php endif; ?>
        </form>
    </div>

    <!-- Students Table -->
    <?php if (count($students) > 0): ?>
        <table>
            <tr>
                <th>Reg Number</th>
                <th>Name</th>
                <th>Email</th>
                <th>Course</th>
                <th>Year</th>
                <th>Semester</th>
                <th>Status</th>
                <th>Registered</th>
                <th>Actions</th>
            </tr>

            <?php foreach ($students as $student): ?>
            <tr>
                <td><strong><?= htmlspecialchars($student['reg_number']) ?></strong></td>
                <td><?= htmlspecialchars($student['name']) ?></td>
                <td><?= htmlspecialchars($student['email']) ?></td>
                <td><?= htmlspecialchars($student['course_name'] ?? 'N/A') ?></td>
                <td><?= $student['year'] ?></td>
                <td><?= $student['semester'] ?></td>
                <td>
                    <span class="badge badge-<?= $student['status'] ?>">
                        <?php 
                            if($student['status'] === 'pending') {
                                echo '⏳ Pending';
                            } elseif($student['status'] === 'active') {
                                echo '✓ Active';
                            } else {
                                echo '⚠️ Inactive';
                            }
                        ?>
                    </span>
                </td>
                <td><?= date('M d, Y', strtotime($student['created_at'])) ?></td>
                <td>
                    <?php if ($student['status'] === 'pending'): ?>
                        <form method="POST" style="display: inline;">
                            <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">
                            <input type="hidden" name="action" value="activate">
                            <input type="hidden" name="student_id" value="<?= $student['student_id'] ?>">
                            <a class="action-link" onclick="this.parentForm.submit();" style="color: #4CAF50;">Activate</a>
                        </form>
                    <?php endif; ?>
                    
                    <?php if ($student['status'] === 'pending'): ?>
                        <form method="POST" style="display: inline;">
                            <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">
                            <input type="hidden" name="action" value="reject">
                            <input type="hidden" name="student_id" value="<?= $student['student_id'] ?>">
                            <a class="action-link" onclick="if(confirm('Reject this registration?')) this.parentForm.submit(); return false;" style="color: #f44336;">Reject</a>
                        </form>
                    <?php endif; ?>
                </td>
            </tr>
            <?php endforeach; ?>
        </table>

        <?php if ($pages > 1): ?>
            <div class="pagination">
                <?php if ($page > 1): ?>
                    <a href="?page=1<?= !empty($search) ? '&search=' . urlencode($search) : '' ?>">« First</a>
                    <a href="?page=<?= $page - 1 ?><?= !empty($search) ? '&search=' . urlencode($search) : '' ?>">‹ Previous</a>
                <?php endif; ?>

                <?php for ($i = max(1, $page - 2); $i <= min($pages, $page + 2); $i++): ?>
                    <?php if ($i === $page): ?>
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
    <?php else: ?>
        <div class="no-data">
            <p>No students found.</p>
        </div>
    <?php endif; ?>

    <div class="auth-links">
        <a href="dashboard.php">Back to Dashboard</a>
    </div>
</div>

</body>
</html>