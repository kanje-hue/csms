<?php
session_start();
include '../config/db.php';

if(!isset($_SESSION['student_id'])){
    header("Location: ../index.php");
    exit();
}

function safe_int($value) {
    return filter_var($value, FILTER_VALIDATE_INT);
}

$student_id = $_SESSION['student_id'];
$student_name = $_SESSION['student_name'] ?? 'Student';
$course_id = $_SESSION['course_id'] ?? null;

// Get student info
$student_stmt = $conn->prepare("SELECT student_id, reg_number, name, course_id, year FROM students WHERE student_id = ? AND deleted = 0");
$student_stmt->bind_param("i", $student_id);
$student_stmt->execute();
$student = $student_stmt->get_result()->fetch_assoc();
$student_stmt->close();

if (!$student) {
    header("Location: ../index.php");
    exit();
}

// ... rest of the code continues
<?php
session_start();
include '../config/db.php';

if(!isset($_SESSION['student_id'])){
    header("Location: login.php");
    exit();
}

function safe_int($value) {
    return filter_var($value, FILTER_VALIDATE_INT);
}

$student_id = $_SESSION['student_id'];
$student_name = $_SESSION['student_name'] ?? 'Student';
$course_id = $_SESSION['course_id'] ?? null;

// Get student info
$student_stmt = $conn->prepare("SELECT student_id, reg_number, name, course_id, year FROM students WHERE student_id = ? AND deleted = 0");
$student_stmt->bind_param("i", $student_id);
$student_stmt->execute();
$student = $student_stmt->get_result()->fetch_assoc();
$student_stmt->close();

if (!$student) {
    header("Location: login.php");
    exit();
}

$course_id = $student['course_id'];
$current_year = $student['year'];

// Get course name
$course_stmt = $conn->prepare("SELECT course_name FROM courses WHERE course_id = ? AND deleted = 0");
$course_stmt->bind_param("i", $course_id);
$course_stmt->execute();
$course = $course_stmt->get_result()->fetch_assoc();
$course_stmt->close();

$course_name = $course ? $course['course_name'] : 'Unknown Course';

// ================= MARK NOTIFICATIONS AS READ =================
if(isset($_GET['mark_notif_read'])){
    $notif_id = safe_int($_GET['mark_notif_read']);
    if($notif_id){
        $read_stmt = $conn->prepare("UPDATE notifications SET status = 'read', read_at = NOW() WHERE id = ? AND student_id = ?");
        $read_stmt->bind_param("ii", $notif_id, $student_id);
        $read_stmt->execute();
        $read_stmt->close();
        // Redirect to remove query parameter
        header("Location: dashboard.php");
        exit();
    }
}

// ================= GET UNREAD RESULT NOTIFICATIONS =================
$unread_notif_query = "
    SELECT 
        n.id,
        n.module_id,
        n.message,
        n.created_at,
        m.module_code,
        m.module_name,
        m.semester
    FROM notifications n
    INNER JOIN modules m ON n.module_id = m.module_id
    WHERE n.student_id = ? 
    AND n.type = 'result_published' 
    AND n.status = 'unread'
    ORDER BY n.created_at DESC
";

$unread_notif_stmt = $conn->prepare($unread_notif_query);

if (!$unread_notif_stmt) {
    $unread_notifications = array();
    $unread_count = 0;
} else {
    $unread_notif_stmt->bind_param("i", $student_id);
    $unread_notif_stmt->execute();
    $unread_notifications = $unread_notif_stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $unread_notif_stmt->close();
    $unread_count = count($unread_notifications);
}

// Fetch modules for the student's course and year
$modules_query = "
    SELECT DISTINCT 
        m.module_id,
        m.module_code,
        m.module_name,
        m.year,
        m.semester,
        COALESCE(t.fullname, 'Unassigned') AS teacher_name
    FROM modules m
    LEFT JOIN teachers t ON m.teacher_id = t.teacher_id
    WHERE m.deleted = 0 
    AND m.course_id = ?
    AND m.year = ?
    ORDER BY m.semester ASC, m.module_code ASC
";

$modules_stmt = $conn->prepare($modules_query);
$modules_stmt->bind_param("ii", $course_id, $current_year);
$modules_stmt->execute();
$modules_result = $modules_stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$modules_stmt->close();

// ================= FETCH RESULTS WITH STATUS =================
$results_query = "
    SELECT 
        r.id as result_id,
        r.module_id,
        r.ca_marks,
        r.final_marks,
        r.grade,
        r.status as result_status,
        m.module_code,
        m.module_name,
        m.year,
        m.semester
    FROM results r
    INNER JOIN modules m ON r.module_id = m.module_id
    WHERE r.student_id = ?
    AND m.course_id = ?
    AND m.deleted = 0
    ORDER BY m.year DESC, m.semester DESC, m.module_code ASC
";

$results_stmt = $conn->prepare($results_query);

if (!$results_stmt) {
    $results = array();
} else {
    $results_stmt->bind_param("ii", $student_id, $course_id);
    $results_stmt->execute();
    $results = $results_stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $results_stmt->close();
}

// Calculate statistics
$total_modules = count($modules_result);
$published_results = array_filter($results, function($r) { return $r['result_status'] === 'published'; });
$draft_results = array_filter($results, function($r) { return $r['result_status'] === 'draft'; });

$modules_with_results = count($published_results);
$total_marks = 0;
$results_count = 0;
$passed_count = 0;
$supplementary_count = 0;

foreach ($published_results as $r) {
    $total = ($r['ca_marks'] ?? 0) + ($r['final_marks'] ?? 0);
    $total_marks += $total;
    $results_count++;
    
    if ($total >= 50) {
        $passed_count++;
    } else {
        $supplementary_count++;
    }
}

$avg_marks = $results_count > 0 ? round($total_marks / $results_count) : 0;

?>

<!DOCTYPE html>
<html>
<head>
    <title>Student Dashboard - CSMS</title>
    <link rel="stylesheet" href="../assets/css/auth.css">
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            background: #f5f5f5;
        }

        .container {
            max-width: 1200px;
            margin: 0 auto;
            padding: 20px;
        }

        .auth-card {
            width: 100%;
            max-width: 1100px;
            padding: 30px;
            border-radius: 18px;
            background: white;
            box-shadow: 0 20px 45px rgba(0,0,0,0.15);
            margin: 30px auto;
        }

        h2 {
            text-align: center;
            margin-bottom: 10px;
            color: var(--midnight-garden);
            font-size: 32px;
        }

        .welcome-banner {
            background: linear-gradient(135deg, var(--terra-rosa), var(--honey-glow));
            color: white;
            padding: 20px;
            border-radius: 12px;
            text-align: center;
            margin-bottom: 20px;
        }

        .welcome-banner p {
            margin: 5px 0;
            font-size: 14px;
        }

        .student-info {
            text-align: center;
            color: #666;
            margin-bottom: 20px;
            padding: 15px;
            background: #f9f9f9;
            border-radius: 8px;
            font-size: 14px;
        }

        .student-info strong {
            color: #333;
        }

        /* NOTIFICATIONS SECTION */
        .notifications-alert {
            background: linear-gradient(135deg, #d4edda, #c3e6cb);
            border: 1px solid #c3e6cb;
            border-radius: 10px;
            padding: 20px;
            margin-bottom: 25px;
            animation: slideIn 0.3s ease-in;
        }

        @keyframes slideIn {
            from {
                opacity: 0;
                transform: translateY(-10px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }

        .notifications-alert h3 {
            margin-top: 0;
            margin-bottom: 15px;
            color: #155724;
            display: flex;
            align-items: center;
            gap: 10px;
            font-size: 18px;
        }

        .notification-badge {
            background: #155724;
            color: white;
            padding: 2px 10px;
            border-radius: 12px;
            font-size: 12px;
            font-weight: bold;
        }

        .notification-item {
            background: white;
            padding: 15px;
            margin: 10px 0;
            border-radius: 6px;
            border-left: 4px solid #4CAF50;
            box-shadow: 0 2px 4px rgba(0,0,0,0.05);
            transition: all 0.3s;
        }

        .notification-item:hover {
            box-shadow: 0 4px 8px rgba(0,0,0,0.1);
        }

        .notification-item p {
            margin: 5px 0;
            color: #333;
        }

        .notification-module {
            font-weight: bold;
            color: var(--midnight-garden);
            font-size: 15px;
        }

        .notification-message {
            color: #666;
            font-size: 13px;
            margin: 8px 0;
        }

        .notification-time {
            color: #999;
            font-size: 11px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-top: 10px;
        }

        .btn-mark-read {
            padding: 5px 15px;
            background: #4CAF50;
            color: white;
            border: none;
            border-radius: 4px;
            cursor: pointer;
            font-size: 11px;
            font-weight: bold;
            text-decoration: none;
            display: inline-block;
            transition: all 0.3s;
        }

        .btn-mark-read:hover {
            background: #45a049;
            transform: scale(1.05);
        }

        /* STATS SECTION */
        .stats {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 15px;
            margin: 25px 0;
        }

        .stat-card {
            background: linear-gradient(135deg, var(--terra-rosa), var(--honey-glow));
            color: white;
            padding: 20px;
            border-radius: 10px;
            text-align: center;
            box-shadow: 0 4px 6px rgba(0,0,0,0.1);
            transition: transform 0.3s;
        }

        .stat-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 8px 12px rgba(0,0,0,0.15);
        }

        .stat-number {
            font-size: 32px;
            font-weight: bold;
            margin: 10px 0;
        }

        .stat-label {
            font-size: 12px;
            opacity: 0.9;
        }

        /* TABLES */
        h3 {
            margin-top: 35px;
            margin-bottom: 15px;
            color: var(--midnight-garden);
            border-bottom: 3px solid var(--minty-fresh);
            padding-bottom: 10px;
            font-size: 20px;
        }

        table {
            width: 100%;
            border-collapse: collapse;
            margin-bottom: 30px;
            background: white;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
            border-radius: 8px;
            overflow: hidden;
        }

        th, td {
            padding: 12px 15px;
            border-bottom: 1px solid #eee;
            text-align: center;
            font-size: 13px;
        }

        th:first-child, td:first-child {
            text-align: left;
        }

        th {
            background: linear-gradient(135deg, var(--skipping-stones), var(--minty-fresh));
            color: var(--art-craft);
            font-weight: bold;
            border-bottom: 2px solid #ddd;
        }

        tr:hover {
            background: #f9f9f9;
        }

        tr:last-child td {
            border-bottom: none;
        }

        .badge {
            display: inline-block;
            padding: 4px 12px;
            border-radius: 12px;
            font-size: 10px;
            font-weight: bold;
        }

        .badge-published {
            background: #d4edda;
            color: #155724;
        }

        .badge-draft {
            background: #fff3cd;
            color: #856404;
        }

        .badge-pass {
            background: #d4edda;
            color: #155724;
        }

        .badge-supplementary {
            background: #fff3cd;
            color: #856404;
        }

        .no-data {
            text-align: center;
            padding: 50px 20px;
            color: #999;
            background: #f9f9f9;
            border-radius: 10px;
            margin: 20px 0;
            font-size: 15px;
        }

        /* RESULTS SECTION */
        .results-section {
            margin-top: 40px;
        }

        .results-tabs {
            display: flex;
            gap: 10px;
            margin-bottom: 20px;
            border-bottom: 2px solid #eee;
            flex-wrap: wrap;
        }

        .tab-btn {
            padding: 12px 20px;
            background: none;
            border: none;
            border-bottom: 3px solid transparent;
            cursor: pointer;
            font-weight: bold;
            color: #666;
            transition: all 0.3s;
            font-size: 14px;
        }

        .tab-btn.active {
            color: var(--terra-rosa);
            border-bottom-color: var(--terra-rosa);
        }

        .tab-btn:hover {
            color: var(--terra-rosa);
        }

        .tab-content {
            display: none;
        }

        .tab-content.active {
            display: block;
            animation: fadeIn 0.3s ease-in;
        }

        @keyframes fadeIn {
            from { opacity: 0; }
            to { opacity: 1; }
        }

        /* FOOTER */
        .auth-links {
            text-align: center;
            margin-top: 40px;
            padding-top: 30px;
            border-top: 2px solid #eee;
        }

        .auth-links a {
            color: white;
            text-decoration: none;
            font-weight: bold;
            margin: 0 15px;
            display: inline-block;
            padding: 10px 20px;
            background: var(--terra-rosa);
            border-radius: 6px;
            transition: all 0.3s;
        }

        .auth-links a:hover {
            background: var(--honey-glow);
            transform: scale(1.05);
        }

        /* RESPONSIVE */
        @media (max-width: 768px) {
            .auth-card {
                padding: 15px;
                margin: 15px;
            }

            h2 {
                font-size: 24px;
            }

            h3 {
                font-size: 16px;
            }

            .stats {
                grid-template-columns: repeat(2, 1fr);
            }

            table {
                font-size: 11px;
            }

            th, td {
                padding: 8px;
            }

            .results-tabs {
                flex-wrap: wrap;
            }

            .tab-btn {
                padding: 8px 12px;
                font-size: 12px;
            }

            .stat-number {
                font-size: 24px;
            }

            .stat-label {
                font-size: 11px;
            }
        }
    </style>
</head>
<body>

<div class="container">
    <div class="auth-card">
        <h2>📚 Student Dashboard</h2>
        
        <div class="welcome-banner">
            <p>Welcome, <strong><?= htmlspecialchars($student['name']) ?></strong>!</p>
            <p>Here's your academic progress and results summary</p>
        </div>

        <div class="student-info">
            <strong>Reg #:</strong> <?= htmlspecialchars($student['reg_number']) ?> | 
            <strong>Year:</strong> <?= $current_year ?> | 
            <strong>Course:</strong> <?= htmlspecialchars($course_name) ?>
        </div>

        <!-- ================= NOTIFICATIONS SECTION ================= -->
        <?php if($unread_count > 0): ?>
        <div class="notifications-alert">
            <h3>
                🔔 Results Published!
                <span class="notification-badge"><?= $unread_count ?> New</span>
            </h3>

            <?php foreach($unread_notifications as $notif): ?>
            <div class="notification-item">
                <p class="notification-module">✅ <?= htmlspecialchars($notif['module_code']) ?> - <?= htmlspecialchars($notif['module_name']) ?></p>
                <p class="notification-message"><?= htmlspecialchars($notif['message']) ?></p>
                <div class="notification-time">
                    <span>📅 <?= date('M d, Y H:i', strtotime($notif['created_at'])) ?></span>
                    <a href="?mark_notif_read=<?= $notif['id'] ?>" class="btn-mark-read">✓ Mark as read</a>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>

        <!-- Statistics -->
        <div class="stats">
            <div class="stat-card">
                <div class="stat-label">📚 Total Modules</div>
                <div class="stat-number"><?= $total_modules ?></div>
            </div>
            <div class="stat-card">
                <div class="stat-label">✅ Published Results</div>
                <div class="stat-number"><?= $modules_with_results ?></div>
            </div>
            <div class="stat-card">
                <div class="stat-label">⏳ Pending Results</div>
                <div class="stat-number"><?= count($draft_results) ?></div>
            </div>
            <div class="stat-card">
                <div class="stat-label">📊 Average Marks</div>
                <div class="stat-number"><?= $avg_marks ?>%</div>
            </div>
        </div>

        <!-- Modules by Year -->
        <h3>📘 Registered Modules (Year <?= $current_year ?>)</h3>
        <?php if (count($modules_result) > 0): ?>
            <table>
                <thead>
                    <tr>
                        <th>Code</th>
                        <th>Module Name</th>
                        <th>Semester</th>
                        <th>Teacher</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($modules_result as $m): ?>
                    <tr>
                        <td><strong><?= htmlspecialchars($m['module_code']) ?></strong></td>
                        <td><?= htmlspecialchars($m['module_name']) ?></td>
                        <td><strong>Sem <?= $m['semester'] ?></strong></td>
                        <td><?= htmlspecialchars($m['teacher_name']) ?></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php else: ?>
            <div class="no-data">📭 No modules registered for this course and year</div>
        <?php endif; ?>

        <!-- Results Section with Tabs -->
        <div class="results-section">
            <h3>📊 My Results</h3>

            <!-- Tab Buttons -->
            <div class="results-tabs">
                <button class="tab-btn active" onclick="switchTab(event, 'published')">
                    ✅ Published (<?= count($published_results) ?>)
                </button>
                <button class="tab-btn" onclick="switchTab(event, 'draft')">
                    ⏳ Pending (<?= count($draft_results) ?>)
                </button>
                <button class="tab-btn" onclick="switchTab(event, 'all')">
                    📋 All Results (<?= count($results) ?>)
                </button>
            </div>

            <!-- Published Results Tab -->
            <div id="published" class="tab-content active">
                <?php if (count($published_results) > 0): ?>
                    <table>
                        <thead>
                            <tr>
                                <th>Code</th>
                                <th>Module Name</th>
                                <th>Sem</th>
                                <th>CA (0-60)</th>
                                <th>Final (0-40)</th>
                                <th>Total</th>
                                <th>Grade</th>
                                <th>Status</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($published_results as $r): 
                                $total = ($r['ca_marks'] ?? 0) + ($r['final_marks'] ?? 0);
                                $status = $total >= 50 ? 'Passed' : 'Supplementary';
                                $status_class = $total >= 50 ? 'badge-pass' : 'badge-supplementary';
                            ?>
                            <tr>
                                <td><strong><?= htmlspecialchars($r['module_code']) ?></strong></td>
                                <td><?= htmlspecialchars($r['module_name']) ?></td>
                                <td><?= $r['semester'] ?></td>
                                <td><?= $r['ca_marks'] ?? '-' ?></td>
                                <td><?= $r['final_marks'] ?? '-' ?></td>
                                <td><strong><?= $total ?></strong></td>
                                <td><?= htmlspecialchars($r['grade'] ?? '-') ?></td>
                                <td><span class="badge <?= $status_class ?>">
                                    <?= $status === 'Passed' ? '✓ Passed' : '⚠️ Supplementary' ?>
                                </span></td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php else: ?>
                    <div class="no-data">📭 No published results yet. Check back soon!</div>
                <?php endif; ?>
            </div>

            <!-- Pending Results Tab -->
            <div id="draft" class="tab-content">
                <?php if (count($draft_results) > 0): ?>
                    <table>
                        <thead>
                            <tr>
                                <th>Code</th>
                                <th>Module Name</th>
                                <th>Semester</th>
                                <th>Status</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($draft_results as $r): ?>
                            <tr>
                                <td><strong><?= htmlspecialchars($r['module_code']) ?></strong></td>
                                <td><?= htmlspecialchars($r['module_name']) ?></td>
                                <td><?= $r['semester'] ?></td>
                                <td><span class="badge badge-draft">⏳ Awaiting Publication</span></td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php else: ?>
                    <div class="no-data">✅ No pending results!</div>
                <?php endif; ?>
            </div>

            <!-- All Results Tab -->
            <div id="all" class="tab-content">
                <?php if (count($results) > 0): ?>
                    <table>
                        <thead>
                            <tr>
                                <th>Code</th>
                                <th>Module Name</th>
                                <th>Sem</th>
                                <th>CA</th>
                                <th>Final</th>
                                <th>Total</th>
                                <th>Grade</th>
                                <th>Status</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($results as $r): 
                                $total = ($r['ca_marks'] ?? 0) + ($r['final_marks'] ?? 0);
                                $status = $r['result_status'] === 'published' ? 'Published' : 'Pending';
                                $status_class = $r['result_status'] === 'published' ? 'badge-published' : 'badge-draft';
                            ?>
                            <tr>
                                <td><strong><?= htmlspecialchars($r['module_code']) ?></strong></td>
                                <td><?= htmlspecialchars($r['module_name']) ?></td>
                                <td><?= $r['semester'] ?></td>
                                <td><?= $r['ca_marks'] ?? '-' ?></td>
                                <td><?= $r['final_marks'] ?? '-' ?></td>
                                <td><strong><?= $total ?? '-' ?></strong></td>
                                <td><?= htmlspecialchars($r['grade'] ?? '-') ?></td>
                                <td><span class="badge <?= $status_class ?>">
                                    <?= $status === 'Published' ? '✅ Published' : '⏳ Pending' ?>
                                </span></td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php else: ?>
                    <div class="no-data">📭 No results available yet</div>
                <?php endif; ?>
            </div>
        </div>

        <div class="auth-links">
            <a href="logout.php">🚪 Logout</a>
        </div>
    </div>
</div>

<script>
function switchTab(event, tabName) {
    event.preventDefault();
    
    // Hide all tabs
    const tabs = document.querySelectorAll('.tab-content');
    tabs.forEach(tab => tab.classList.remove('active'));
    
    // Remove active class from all buttons
    const buttons = document.querySelectorAll('.tab-btn');
    buttons.forEach(btn => btn.classList.remove('active'));
    
    // Show selected tab
    const selectedTab = document.getElementById(tabName);
    if (selectedTab) {
        selectedTab.classList.add('active');
    }
    
    // Add active class to clicked button
    event.target.classList.add('active');
}

// Prevent accidental redirect when notification is read
document.addEventListener('click', function(e) {
    if (e.target && e.target.classList.contains('btn-mark-read')) {
        e.preventDefault();
        window.location.href = e.target.href;
    }
});
</script>

</body>
</html>