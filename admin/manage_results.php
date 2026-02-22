<?php
session_start();
include '../config/db.php';

if(!isset($_SESSION['admin_logged_in'])){
    header("Location: login.php");
    exit();
}

$admin_id = $_SESSION['admin_id'] ?? 1;
$message = "";
$message_type = "";

// Get filter parameters
$course_id = isset($_GET['course_id']) ? (int)$_GET['course_id'] : null;
$module_id = isset($_GET['module_id']) ? (int)$_GET['module_id'] : null;
$status_filter = isset($_GET['status']) ? trim($_GET['status']) : null;

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

// Handle publish results
if($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'publish'){
    if (!hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'] ?? '')) {
        $message = "Security token verification failed";
        $message_type = "error";
    } else {
        $module_id_pub = (int)($_POST['module_id'] ?? 0);
        
        if($module_id_pub > 0){
            // Update all draft results for this module to published
            $stmt = $conn->prepare("
                UPDATE results 
                SET status = 'published', published_at = NOW(), published_by = ?
                WHERE module_id = ? AND status = 'draft'
            ");
            $stmt->bind_param("ii", $admin_id, $module_id_pub);
            
            if($stmt->execute()){
                $affected = $stmt->affected_rows;
                
                // Log the action
                $log_stmt = $conn->prepare("
                    INSERT INTO result_audit_log (admin_id, module_id, action, description)
                    VALUES (?, ?, 'publish', ?)
                ");
                $description = "Published $affected result(s) for module";
                $log_stmt->bind_param("iis", $admin_id, $module_id_pub, $description);
                $log_stmt->execute();
                $log_stmt->close();
                
                $message = "✓ Published $affected result(s) successfully! Students can now view their marks.";
                $message_type = "success";
            } else {
                $message = "Error publishing results";
                $message_type = "error";
            }
            $stmt->close();
        }
    }
}

// Handle unpublish results
if($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'unpublish'){
    if (!hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'] ?? '')) {
        $message = "Security token verification failed";
        $message_type = "error";
    } else {
        $module_id_unpub = (int)($_POST['module_id'] ?? 0);
        
        if($module_id_unpub > 0){
            // Update all published results for this module back to draft
            $stmt = $conn->prepare("
                UPDATE results 
                SET status = 'draft', published_at = NULL, published_by = NULL
                WHERE module_id = ? AND status = 'published'
            ");
            $stmt->bind_param("i", $module_id_unpub);
            
            if($stmt->execute()){
                $affected = $stmt->affected_rows;
                
                // Log the action
                $log_stmt = $conn->prepare("
                    INSERT INTO result_audit_log (admin_id, module_id, action, description)
                    VALUES (?, ?, 'unpublish', ?)
                ");
                $description = "Unpublished $affected result(s) for module";
                $log_stmt->bind_param("iis", $admin_id, $module_id_unpub, $description);
                $log_stmt->execute();
                $log_stmt->close();
                
                $message = "⚠️ Unpublished $affected result(s). Students can no longer view these marks.";
                $message_type = "success";
            } else {
                $message = "Error unpublishing results";
                $message_type = "error";
            }
            $stmt->close();
        }
    }
}

// Get all courses
$courses_query = "SELECT course_id, course_name FROM courses WHERE deleted = 0 ORDER BY course_name ASC";
$courses_stmt = $conn->prepare($courses_query);
$courses_stmt->execute();
$courses = $courses_stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$courses_stmt->close();

// Get modules for selected course or all modules
if($course_id){
    $modules_query = "SELECT m.module_id, m.module_code, m.module_name, c.course_name, m.teacher_id, t.name as teacher_name
                      FROM modules m
                      LEFT JOIN courses c ON m.course_id = c.course_id
                      LEFT JOIN teachers t ON m.teacher_id = t.teacher_id
                      WHERE m.course_id = ? AND m.deleted = 0
                      ORDER BY m.module_code ASC";
    $modules_stmt = $conn->prepare($modules_query);
    $modules_stmt->bind_param("i", $course_id);
} else {
    $modules_query = "SELECT m.module_id, m.module_code, m.module_name, c.course_name, m.teacher_id, t.name as teacher_name
                      FROM modules m
                      LEFT JOIN courses c ON m.course_id = c.course_id
                      LEFT JOIN teachers t ON m.teacher_id = t.teacher_id
                      WHERE m.deleted = 0
                      ORDER BY c.course_name ASC, m.module_code ASC";
    $modules_stmt = $conn->prepare($modules_query);
}

$modules_stmt->execute();
$modules = $modules_stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$modules_stmt->close();

// Get results data for display
$results_query = "
    SELECT 
        r.id,
        r.student_id,
        s.reg_number,
        s.name as student_name,
        r.module_id,
        m.module_code,
        m.module_name,
        c.course_name,
        r.ca_marks,
        r.final_marks,
        r.total_marks,
        r.grade,
        r.status,
        r.created_at,
        r.published_at,
        CASE 
            WHEN r.ca_marks < 30 OR r.final_marks < 20 OR r.total_marks < 50 THEN 'Supplementary'
            ELSE 'Passed'
        END as result_status
    FROM results r
    INNER JOIN students s ON r.student_id = s.student_id
    INNER JOIN modules m ON r.module_id = m.module_id
    INNER JOIN courses c ON m.course_id = c.course_id
    WHERE 1=1
";

$params = [];
$types = "";

if($course_id){
    $results_query .= " AND m.course_id = ?";
    $params[] = $course_id;
    $types .= "i";
}

if($module_id){
    $results_query .= " AND r.module_id = ?";
    $params[] = $module_id;
    $types .= "i";
}

if($status_filter){
    $results_query .= " AND r.status = ?";
    $params[] = $status_filter;
    $types .= "s";
}

$results_query .= " ORDER BY c.course_name ASC, m.module_code ASC, s.name ASC";

$results_stmt = $conn->prepare($results_query);

if(!empty($params)){
    $results_stmt->bind_param($types, ...$params);
}

$results_stmt->execute();
$results = $results_stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$results_stmt->close();

// Calculate statistics
$stats = [
    'total_results' => count($results),
    'published' => count(array_filter($results, function($r) { return $r['status'] === 'published'; })),
    'draft' => count(array_filter($results, function($r) { return $r['status'] === 'draft'; })),
    'passed' => count(array_filter($results, function($r) { return $r['result_status'] === 'Passed'; })),
    'supplementary' => count(array_filter($results, function($r) { return $r['result_status'] === 'Supplementary'; }))
];

// Group results by module for publication management
$modules_data = [];
foreach($results as $result){
    $key = $result['module_id'];
    if(!isset($modules_data[$key])){
        $modules_data[$key] = [
            'module_code' => $result['module_code'],
            'module_name' => $result['module_name'],
            'course_name' => $result['course_name'],
            'teacher_name' => $result['teacher_name'],
            'total' => 0,
            'published' => 0,
            'draft' => 0,
            'results' => []
        ];
    }
    $modules_data[$key]['total']++;
    if($result['status'] === 'published') $modules_data[$key]['published']++;
    if($result['status'] === 'draft') $modules_data[$key]['draft']++;
    $modules_data[$key]['results'][] = $result;
}

?>

<!DOCTYPE html>
<html>
<head>
    <title>Manage Results</title>
    <link rel="stylesheet" href="../assets/css/auth.css">
    <style>
        .auth-card {
            width: 1300px;
            max-width: 100%;
            padding: 30px;
            border-radius: 18px;
            background: var(--white);
            box-shadow: 0 20px 45px rgba(0,0,0,0.15);
            margin: 30px auto;
        }

        h2 {
            text-align: center;
            color: var(--midnight-garden);
            margin-bottom: 20px;
        }

        .stats {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 15px;
            margin: 20px 0;
        }

        .stat-card {
            background: linear-gradient(135deg, var(--terra-rosa), var(--honey-glow));
            color: white;
            padding: 20px;
            border-radius: 10px;
            text-align: center;
        }

        .stat-number {
            font-size: 28px;
            font-weight: bold;
            margin: 10px 0;
        }

        .stat-label {
            font-size: 12px;
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

        .filters {
            background: #f9f9f9;
            padding: 15px;
            border-radius: 8px;
            margin: 20px 0;
            display: flex;
            gap: 10px;
            flex-wrap: wrap;
            align-items: center;
        }

        .filters select {
            padding: 8px 12px;
            border: 1px solid #ddd;
            border-radius: 6px;
            font-size: 14px;
        }

        .filters button {
            padding: 8px 15px;
            background: var(--terra-rosa);
            color: white;
            border: none;
            border-radius: 6px;
            cursor: pointer;
            font-weight: bold;
        }

        .filters button:hover {
            opacity: 0.9;
        }

        .filters a {
            padding: 8px 15px;
            background: #999;
            color: white;
            text-decoration: none;
            border-radius: 6px;
            font-weight: bold;
        }

        .filters a:hover {
            opacity: 0.9;
        }

        h3 {
            margin-top: 30px;
            margin-bottom: 15px;
            color: var(--midnight-garden);
            border-bottom: 2px solid var(--minty-fresh);
            padding-bottom: 10px;
        }

        .module-section {
            background: linear-gradient(135deg, var(--skipping-stones), var(--minty-fresh));
            border-radius: 10px;
            padding: 20px;
            margin-bottom: 20px;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
        }

        .module-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 15px;
            flex-wrap: wrap;
        }

        .module-header h4 {
            margin: 0;
            color: var(--midnight-garden);
            flex: 1;
        }

        .module-info {
            font-size: 12px;
            color: #666;
            margin: 5px 0;
        }

        .module-stats {
            display: flex;
            gap: 15px;
            margin-bottom: 15px;
            flex-wrap: wrap;
        }

        .stat-badge {
            background: white;
            padding: 8px 12px;
            border-radius: 6px;
            font-size: 12px;
            font-weight: bold;
        }

        .stat-badge.draft {
            color: #FF9800;
            border-left: 4px solid #FF9800;
        }

        .stat-badge.published {
            color: #4CAF50;
            border-left: 4px solid #4CAF50;
        }

        .module-actions {
            display: flex;
            gap: 10px;
        }

        .action-btn {
            padding: 8px 15px;
            border: none;
            border-radius: 6px;
            cursor: pointer;
            font-weight: bold;
            font-size: 12px;
            transition: all 0.3s;
        }

        .action-btn.publish {
            background: #4CAF50;
            color: white;
        }

        .action-btn.publish:hover {
            opacity: 0.9;
        }

        .action-btn.unpublish {
            background: #FF9800;
            color: white;
        }

        .action-btn.unpublish:hover {
            opacity: 0.9;
        }

        .action-btn:disabled {
            opacity: 0.5;
            cursor: not-allowed;
        }

        table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 15px;
        }

        th, td {
            padding: 10px;
            border: 1px solid #ddd;
            text-align: left;
            font-size: 12px;
        }

        th {
            background: white;
            color: var(--art-craft);
            font-weight: bold;
        }

        tr:nth-child(even) {
            background: rgba(255,255,255,0.5);
        }

        .status-badge {
            display: inline-block;
            padding: 4px 8px;
            border-radius: 4px;
            font-size: 11px;
            font-weight: bold;
        }

        .status-draft {
            background: #fff3cd;
            color: #856404;
        }

        .status-published {
            background: #d4edda;
            color: #155724;
        }

        .result-passed {
            color: #4CAF50;
            font-weight: bold;
        }

        .result-supplementary {
            color: #FF9800;
            font-weight: bold;
        }

        .no-data {
            text-align: center;
            padding: 40px;
            color: #666;
        }

        .back-link {
            text-align: center;
            margin-top: 30px;
        }

        .back-link a {
            color: var(--terra-rosa);
            text-decoration: none;
            font-weight: bold;
            padding: 10px 20px;
            border: 2px solid var(--terra-rosa);
            border-radius: 8px;
            display: inline-block;
        }

        .back-link a:hover {
            background: var(--terra-rosa);
            color: white;
        }

        @media (max-width: 768px) {
            .auth-card {
                width: 95%;
                padding: 15px;
            }

            .stats {
                grid-template-columns: repeat(2, 1fr);
            }

            .filters {
                flex-direction: column;
            }

            .filters select,
            .filters button,
            .filters a {
                width: 100%;
            }

            .module-header {
                flex-direction: column;
                align-items: flex-start;
            }

            .module-actions {
                flex-direction: column;
            }

            .action-btn {
                width: 100%;
            }

            table {
                font-size: 11px;
            }

            th, td {
                padding: 6px;
            }
        }
    </style>
</head>
<body>

<div class="auth-card">
    <h2>📊 Manage Results</h2>

    <?php if ($message): ?>
        <div class="alert <?= $message_type === 'success' ? 'success' : 'error' ?>">
            <?= htmlspecialchars($message) ?>
        </div>
    <?php endif; ?>

    <!-- Statistics -->
    <div class="stats">
        <div class="stat-card">
            <div class="stat-label">Total Results</div>
            <div class="stat-number"><?= $stats['total_results'] ?></div>
        </div>
        <div class="stat-card">
            <div class="stat-label">Published</div>
            <div class="stat-number"><?= $stats['published'] ?></div>
        </div>
        <div class="stat-card">
            <div class="stat-label">Draft</div>
            <div class="stat-number"><?= $stats['draft'] ?></div>
        </div>
        <div class="stat-card">
            <div class="stat-label">Passed</div>
            <div class="stat-number"><?= $stats['passed'] ?></div>
        </div>
        <div class="stat-card">
            <div class="stat-label">Supplementary</div>
            <div class="stat-number"><?= $stats['supplementary'] ?></div>
        </div>
    </div>

    <!-- Filters -->
    <div class="filters">
        <form method="GET" style="display: flex; gap: 10px; flex-wrap: wrap; width: 100%;">
            <select name="course_id" onchange="this.form.submit()">
                <option value="">-- All Courses --</option>
                <?php foreach ($courses as $c): ?>
                <option value="<?= $c['course_id'] ?>" <?= $course_id === $c['course_id'] ? 'selected' : '' ?>>
                    <?= htmlspecialchars($c['course_name']) ?>
                </option>
                <?php endforeach; ?>
            </select>

            <select name="status">
                <option value="">-- All Status --</option>
                <option value="draft" <?= $status_filter === 'draft' ? 'selected' : '' ?>>Draft</option>
                <option value="published" <?= $status_filter === 'published' ? 'selected' : '' ?>>Published</option>
            </select>

            <button type="submit">🔍 Filter</button>
            <a href="manage_results.php">✕ Clear</a>
        </form>
    </div>

    <!-- Results by Module -->
    <h3>Results by Module</h3>

    <?php if (!empty($modules_data)): ?>
        <?php foreach ($modules_data as $mod_id => $mod_data): ?>
        <div class="module-section">
            <div class="module-header">
                <div>
                    <h4><?= htmlspecialchars($mod_data['module_code']) ?> - <?= htmlspecialchars($mod_data['module_name']) ?></h4>
                    <div class="module-info">
                        📚 <?= htmlspecialchars($mod_data['course_name']) ?> | 👨‍🏫 <?= htmlspecialchars($mod_data['teacher_name'] ?? 'Unassigned') ?>
                    </div>
                </div>
                <div class="module-stats">
                    <div class="stat-badge draft">📋 Draft: <?= $mod_data['draft'] ?></div>
                    <div class="stat-badge published">✓ Published: <?= $mod_data['published'] ?></div>
                </div>
                <div class="module-actions">
                    <?php if($mod_data['draft'] > 0): ?>
                    <form method="POST" style="display: inline;">
                        <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">
                        <input type="hidden" name="action" value="publish">
                        <input type="hidden" name="module_id" value="<?= $mod_id ?>">
                        <button type="submit" class="action-btn publish" onclick="return confirm('Publish all draft results for this module?');">
                            📤 Publish All
                        </button>
                    </form>
                    <?php endif; ?>
                    
                    <?php if($mod_data['published'] > 0): ?>
                    <form method="POST" style="display: inline;">
                        <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">
                        <input type="hidden" name="action" value="unpublish">
                        <input type="hidden" name="module_id" value="<?= $mod_id ?>">
                        <button type="submit" class="action-btn unpublish" onclick="return confirm('Unpublish all results for this module?');">
                            ⬇️ Unpublish
                        </button>
                    </form>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Results Table -->
            <table>
                <thead>
                    <tr>
                        <th>Reg No</th>
                        <th>Student Name</th>
                        <th>CA</th>
                        <th>Final</th>
                        <th>Total</th>
                        <th>Grade</th>
                        <th>Result</th>
                        <th>Status</th>
                        <th>Published</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($mod_data['results'] as $result): ?>
                    <tr>
                        <td><strong><?= htmlspecialchars($result['reg_number']) ?></strong></td>
                        <td><?= htmlspecialchars($result['student_name']) ?></td>
                        <td><?= number_format($result['ca_marks'], 1) ?></td>
                        <td><?= number_format($result['final_marks'], 1) ?></td>
                        <td><strong><?= number_format($result['total_marks'], 1) ?></strong></td>
                        <td><strong><?= htmlspecialchars($result['grade']) ?></strong></td>
                        <td>
                            <span class="result-<?= strtolower(str_replace(' ', '-', $result['result_status'])) ?>">
                                <?= $result['result_status'] === 'Passed' ? '✓ Passed' : '⚠️ Supplementary' ?>
                            </span>
                        </td>
                        <td>
                            <span class="status-badge status-<?= $result['status'] ?>">
                                <?= strtoupper($result['status']) ?>
                            </span>
                        </td>
                        <td>
                            <?php if($result['published_at']): ?>
                                <?= date('M d, Y', strtotime($result['published_at'])) ?>
                            <?php else: ?>
                                -
                            <?php endif; ?>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php endforeach; ?>
    <?php else: ?>
        <div class="no-data">
            <p>❌ No results found</p>
        </div>
    <?php endif; ?>

    <div class="back-link">
        <a href="dashboard.php">← Back to Dashboard</a>
    </div>
</div>

</body>
</html>