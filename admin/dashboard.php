<?php
/**
 * admin/dashboard.php - Main Admin Dashboard
 * Clean version - Only essential information
 */

session_start();
require_once '../config/db.php';
require_once '../config/security_base.php';

// Check admin login
checkAdminSession();

$admin_name = $_SESSION['admin_name'] ?? 'Admin';

// Get all courses with statistics
$courses_query = "
    SELECT 
        c.course_id,
        c.course_name,
        c.status,
        COUNT(DISTINCT m.module_id) as module_count,
        COUNT(DISTINCT s.student_id) as student_count,
        SUM(CASE WHEN s.status = 'pending' THEN 1 ELSE 0 END) as pending_count
    FROM courses c
    LEFT JOIN modules m ON c.course_id = m.course_id AND m.deleted = 0
    LEFT JOIN students s ON c.course_id = s.course_id AND s.deleted = 0
    WHERE c.deleted = 0
    GROUP BY c.course_id, c.course_name, c.status
    ORDER BY c.course_name ASC
";

$courses = $conn->query($courses_query)->fetch_all(MYSQLI_ASSOC);

// Get pending students count
$pending_count = $conn->query("SELECT COUNT(*) as count FROM students WHERE status = 'pending' AND deleted = 0")->fetch_assoc()['count'];

// Get pending students for display
$pending_query = "
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
    LEFT JOIN courses c ON s.course_id = c.course_id
    WHERE s.status = 'pending' AND s.deleted = 0
    ORDER BY s.created_at DESC
    LIMIT 5
";

$pending_result = $conn->query($pending_query);
$pending_students = $pending_result ? $pending_result->fetch_all(MYSQLI_ASSOC) : [];

// Get results statistics
$results_stats = $conn->query("
    SELECT 
        COUNT(DISTINCT m.module_id) as total_modules,
        SUM(CASE WHEN r.status = 'draft' THEN 1 ELSE 0 END) as pending_results,
        SUM(CASE WHEN r.status = 'published' THEN 1 ELSE 0 END) as published_results,
        COUNT(DISTINCT CASE WHEN r.id IS NULL THEN m.module_id END) as no_results
    FROM modules m
    LEFT JOIN results r ON m.module_id = r.module_id
    WHERE m.deleted = 0
")->fetch_assoc();

// Get recent activities
$recent_activities = $conn->query("
    SELECT 
        'student' as type,
        name as title,
        COALESCE(email, 'No email') as email,
        created_at,
        status
    FROM students 
    WHERE deleted = 0 
    ORDER BY created_at DESC 
    LIMIT 5
")->fetch_all(MYSQLI_ASSOC);

// Get total statistics
$total_courses = count($courses);
$total_modules = array_sum(array_column($courses, 'module_count'));
$total_students = array_sum(array_column($courses, 'student_count'));

// Get teacher count
$teacher_count = $conn->query("SELECT COUNT(*) as count FROM teachers WHERE deleted = 0")->fetch_assoc()['count'];

// Generate CSRF token
$csrf_token = generateCSRF();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin Dashboard - CSMS</title>
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
            line-height: 1.5;
        }

        /* Header Styles */
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

        .welcome-text {
            font-size: 0.95rem;
            color: #94a3b8;
        }

        .admin-name {
            color: #2dd4bf;
            font-weight: 600;
        }

        .logout-btn {
            background: rgba(255, 255, 255, 0.1);
            color: white;
            padding: 0.5rem 1.2rem;
            border-radius: 8px;
            text-decoration: none;
            font-size: 0.9rem;
            font-weight: 500;
            transition: all 0.3s;
            border: 1px solid rgba(255, 255, 255, 0.1);
        }

        .logout-btn:hover {
            background: #2dd4bf;
            border-color: #2dd4bf;
            transform: translateY(-2px);
        }

        /* Main Container */
        .container {
            max-width: 1400px;
            margin: 2rem auto;
            padding: 0 2rem;
            width: 100%;
        }

        /* Welcome Banner */
        .welcome-banner {
            background: linear-gradient(135deg, #0f172a, #1e293b);
            color: white;
            padding: 2rem;
            border-radius: 20px;
            margin-bottom: 2rem;
            box-shadow: 0 10px 25px -5px rgba(0, 0, 0, 0.2);
            width: 100%;
        }

        .welcome-banner h2 {
            font-size: 2rem;
            margin-bottom: 0.5rem;
        }

        .welcome-banner p {
            color: #94a3b8;
            font-size: 1rem;
        }

        /* Stats Grid */
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(240px, 1fr));
            gap: 1.5rem;
            margin-bottom: 2.5rem;
            width: 100%;
        }

        .stat-card {
            background: white;
            padding: 1.8rem;
            border-radius: 16px;
            box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.1);
            border-left: 4px solid #2dd4bf;
            transition: transform 0.3s, box-shadow 0.3s;
        }

        .stat-card:hover {
            transform: translateY(-4px);
            box-shadow: 0 12px 24px -8px rgba(0, 0, 0, 0.15);
        }

        .stat-label {
            color: #64748b;
            font-size: 0.9rem;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            margin-bottom: 0.5rem;
        }

        .stat-number {
            font-size: 2.5rem;
            font-weight: 700;
            color: #0f172a;
        }

        .stat-sub {
            color: #64748b;
            font-size: 0.85rem;
            margin-top: 0.25rem;
        }

        /* Section Title - Full width */
        .section-title {
            font-size: 1.5rem;
            font-weight: 600;
            color: #0f172a;
            margin: 2rem 0 1.5rem;
            padding-bottom: 0.75rem;
            border-bottom: 3px solid #2dd4bf;
            width: 100%;
            display: block;
        }

        /* Quick Actions Grid */
        .actions-grid {
            display: grid;
            grid-template-columns: repeat(4, 1fr);
            gap: 1.5rem;
            margin: 1.5rem 0 2rem;
            width: 100%;
        }

        .action-btn {
            background: white;
            padding: 2rem 1rem;
            border-radius: 16px;
            text-decoration: none;
            color: #0f172a;
            font-weight: 600;
            text-align: center;
            box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.1);
            transition: all 0.3s;
            border: 1px solid #e2e8f0;
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            gap: 1rem;
            min-height: 160px;
        }

        .action-btn:hover {
            border-color: #2dd4bf;
            transform: translateY(-4px);
            box-shadow: 0 12px 24px -8px rgba(45, 212, 191, 0.3);
        }

        .action-icon {
            font-size: 2.5rem;
            color: #2dd4bf;
        }

        .action-btn span:last-child {
            font-size: 1rem;
        }

        .badge {
            background: #ef4444;
            color: white;
            padding: 0.25rem 0.75rem;
            border-radius: 20px;
            font-size: 0.8rem;
            margin-left: 0.5rem;
        }

        /* Results Overview Cards */
        .results-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 1rem;
            margin: 1.5rem 0;
            width: 100%;
        }

        .result-card {
            background: white;
            padding: 1.5rem;
            border-radius: 16px;
            text-decoration: none;
            color: #0f172a;
            box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.1);
            transition: all 0.3s;
            border-left: 4px solid;
            display: flex;
            flex-direction: column;
            gap: 0.5rem;
        }

        .result-card.pending {
            border-left-color: #f59e0b;
        }
        .result-card.publish {
            border-left-color: #10b981;
        }
        .result-card.request {
            border-left-color: #3b82f6;
        }
        .result-card.dashboard {
            border-left-color: #8b5cf6;
        }

        .result-card:hover {
            transform: translateY(-4px);
            box-shadow: 0 12px 24px -8px rgba(0, 0, 0, 0.15);
        }

        .result-number {
            font-size: 2rem;
            font-weight: 700;
        }

        .result-label {
            color: #64748b;
            font-size: 0.9rem;
        }

        .result-icon {
            font-size: 1.5rem;
            align-self: flex-end;
        }

        /* Pending Students Section */
        .pending-section {
            width: 100%;
            background: white;
            border-radius: 16px;
            padding: 1.5rem;
            margin: 2rem 0;
            box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.1);
        }

        .pending-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 1.5rem;
            padding-bottom: 1rem;
            border-bottom: 2px solid #f59e0b;
            width: 100%;
        }

        .pending-header h3 {
            font-size: 1.3rem;
            color: #92400e;
            margin: 0;
        }

        .pending-count {
            background: #f59e0b;
            color: white;
            padding: 0.25rem 1rem;
            border-radius: 20px;
            font-size: 0.9rem;
            font-weight: 600;
        }

        .view-all-btn {
            background: #17a2b8;
            color: white;
            padding: 0.5rem 1rem;
            border-radius: 6px;
            text-decoration: none;
            font-size: 0.9rem;
            font-weight: 600;
            transition: all 0.3s;
        }

        .view-all-btn:hover {
            background: #138496;
        }

        /* Table Styles */
        .table-responsive {
            overflow-x: auto;
            width: 100%;
        }

        table {
            width: 100%;
            border-collapse: collapse;
            background: white;
            border-radius: 8px;
            overflow: hidden;
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

        .btn-sm {
            padding: 0.4rem 0.8rem;
            border: none;
            border-radius: 4px;
            cursor: pointer;
            font-weight: 600;
            font-size: 0.8rem;
            text-decoration: none;
            display: inline-block;
        }

        .btn-success {
            background: #28a745;
            color: white;
        }

        .btn-success:hover {
            background: #218838;
        }

        .btn-danger {
            background: #dc3545;
            color: white;
        }

        .btn-danger:hover {
            background: #c82333;
        }

        /* Course Grid */
        .course-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(350px, 1fr));
            gap: 1.5rem;
            margin-top: 1.5rem;
            width: 100%;
        }

        .course-card {
            background: white;
            border-radius: 20px;
            overflow: hidden;
            box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.1);
            transition: all 0.3s;
            cursor: pointer;
            border: 1px solid #e2e8f0;
        }

        .course-card:hover {
            transform: translateY(-6px);
            box-shadow: 0 20px 25px -5px rgba(0, 0, 0, 0.15);
            border-color: #2dd4bf;
        }

        .course-header {
            background: linear-gradient(135deg, #0f172a, #1e293b);
            color: white;
            padding: 1.5rem;
        }

        .course-header h3 {
            font-size: 1.25rem;
            margin-bottom: 0.5rem;
        }

        .course-status {
            font-size: 0.8rem;
            color: #94a3b8;
            display: inline-block;
            padding: 0.25rem 0.75rem;
            background: rgba(255, 255, 255, 0.1);
            border-radius: 20px;
        }

        .course-body {
            padding: 1.5rem;
        }

        .course-stats {
            display: flex;
            justify-content: space-around;
            margin-bottom: 1rem;
        }

        .stat-item {
            text-align: center;
        }

        .stat-value {
            font-size: 1.5rem;
            font-weight: 700;
            color: #0f172a;
        }

        .stat-label {
            font-size: 0.8rem;
            color: #64748b;
            margin-top: 0.25rem;
        }

        .pending-badge {
            background: #fef3c7;
            color: #92400e;
            padding: 0.25rem 0.75rem;
            border-radius: 20px;
            font-size: 0.8rem;
            font-weight: 600;
            display: inline-block;
        }

        /* Activity Section */
        .activity-section {
            background: white;
            border-radius: 20px;
            padding: 1.5rem;
            margin-top: 2rem;
            box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.1);
            width: 100%;
        }

        .activity-title {
            font-size: 1.2rem;
            font-weight: 600;
            margin-bottom: 1rem;
            color: #0f172a;
            border-bottom: 2px solid #2dd4bf;
            padding-bottom: 0.75rem;
        }

        .activity-item {
            display: flex;
            align-items: center;
            gap: 1rem;
            padding: 1rem;
            border-bottom: 1px solid #e2e8f0;
        }

        .activity-item:last-child {
            border-bottom: none;
        }

        .activity-icon {
            width: 2.5rem;
            height: 2.5rem;
            background: #f1f5f9;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.2rem;
        }

        .activity-content {
            flex: 1;
        }

        .activity-name {
            font-weight: 600;
            margin-bottom: 0.25rem;
        }

        .activity-email {
            font-size: 0.8rem;
            color: #64748b;
        }

        .activity-time {
            font-size: 0.75rem;
            color: #94a3b8;
            margin-top: 0.25rem;
        }

        .status-badge {
            padding: 0.25rem 0.75rem;
            border-radius: 20px;
            font-size: 0.75rem;
            font-weight: 600;
        }

        .status-pending {
            background: #fef3c7;
            color: #92400e;
        }

        .status-active {
            background: #d1fae5;
            color: #065f46;
        }

        .empty-state {
            text-align: center;
            padding: 4rem;
            background: white;
            border-radius: 20px;
            color: #64748b;
            width: 100%;
        }

        @media (max-width: 768px) {
            .header {
                flex-direction: column;
                gap: 1rem;
                text-align: center;
                padding: 1rem;
            }

            .admin-info {
                flex-direction: column;
                gap: 1rem;
            }

            .container {
                padding: 0 1rem;
            }

            .stats-grid {
                grid-template-columns: 1fr;
            }

            .actions-grid {
                grid-template-columns: repeat(2, 1fr);
            }

            .results-grid {
                grid-template-columns: 1fr;
            }

            .pending-header {
                flex-direction: column;
                gap: 1rem;
                text-align: center;
            }

            .course-grid {
                grid-template-columns: 1fr;
            }
        }
    </style>
</head>
<body>

<!-- Header -->
<div class="header">
    <div class="logo">
        <h1>CSMS Admin</h1>
    </div>
    <div class="admin-info">
        <span class="welcome-text">Welcome back, <span class="admin-name"><?= htmlspecialchars($admin_name) ?></span></span>
        <a href="logout.php" class="logout-btn">🚪 Logout</a>
    </div>
</div>

<div class="container">
    <!-- Welcome Banner -->
    <div class="welcome-banner">
        <h2>👋 Welcome to CSMS Admin</h2>
        <p>Manage courses, approve students, and oversee academic results</p>
    </div>

    <!-- Statistics Overview -->
    <div class="stats-grid">
        <div class="stat-card">
            <div class="stat-label">Total Courses</div>
            <div class="stat-number"><?= $total_courses ?></div>
            <div class="stat-sub">Active programs</div>
        </div>
        <div class="stat-card">
            <div class="stat-label">Total Modules</div>
            <div class="stat-number"><?= $total_modules ?></div>
            <div class="stat-sub">Across all courses</div>
        </div>
        <div class="stat-card">
            <div class="stat-label">Teachers</div>
            <div class="stat-number"><?= $teacher_count ?></div>
            <div class="stat-sub">Active instructors</div>
        </div>
        <div class="stat-card">
            <div class="stat-label">Active Students</div>
            <div class="stat-number"><?= $total_students ?></div>
            <div class="stat-sub">Enrolled</div>
        </div>
        <div class="stat-card">
            <div class="stat-label">Pending Approvals</div>
            <div class="stat-number"><?= $pending_count ?></div>
            <div class="stat-sub">Awaiting activation</div>
        </div>
    </div>

    <!-- Quick Actions -->
    <h2 class="section-title">⚡ Quick Actions</h2>
    <div class="actions-grid">
        <a href="add_course.php" class="action-btn">
            <span class="action-icon">➕</span>
            <span>Add New Course</span>
        </a>
        
        <a href="add_teacher.php" class="action-btn">
            <span class="action-icon">👨‍🏫</span>
            <span>Add Teacher</span>
        </a>
        
        <a href="pending_students.php" class="action-btn">
            <span class="action-icon">⏳</span>
            <span>Pending Students</span>
            <?php if ($pending_count > 0): ?>
                <span class="badge"><?= $pending_count ?></span>
            <?php endif; ?>
        </a>
        
        <a href="student_promotion.php" class="action-btn" style="border-left-color: #f59e0b;">
            <span class="action-icon">📈</span>
            <span>Year-End Promotion</span>
        </a>
    </div>

    <!-- Results Overview Section -->
    <h2 class="section-title">📊 Results Dashboard</h2>
    <div class="results-grid">
        <a href="manage_results.php?status=draft" class="result-card pending">
            <span class="result-number"><?= $results_stats['pending_results'] ?? 0 ?></span>
            <span class="result-label">Pending Review</span>
            <span class="result-icon">⏳</span>
        </a>
        
        <a href="manage_results.php" class="result-card publish">
            <span class="result-number"><?= $results_stats['published_results'] ?? 0 ?></span>
            <span class="result-label">Published Results</span>
            <span class="result-icon">📤</span>
        </a>
        
        <a href="manage_results.php?status=none" class="result-card request">
            <span class="result-number"><?= $results_stats['no_results'] ?? 0 ?></span>
            <span class="result-label">Need Results</span>
            <span class="result-icon">📧</span>
        </a>
        
        <?php if (($results_stats['pending_results'] ?? 0) > 0): ?>
        <form method="POST" action="manage_results.php" style="text-decoration: none; display: contents;">
            <input type="hidden" name="csrf_token" value="<?= $csrf_token ?>">
            <input type="hidden" name="action" value="bulk_publish">
            <button type="submit" class="result-card dashboard" style="width: 100%; border: none; cursor: pointer; background: white;" onclick="return confirm('Publish ALL pending results? This will notify all students.')">
                <span class="result-number">📤</span>
                <span class="result-label">Bulk Publish All</span>
                <span class="result-icon">⚡</span>
            </button>
        </form>
        <?php else: ?>
        <a href="manage_results.php" class="result-card dashboard">
            <span class="result-number">📊</span>
            <span class="result-label">Full Dashboard</span>
            <span class="result-icon">→</span>
        </a>
        <?php endif; ?>
    </div>

    <!-- PENDING STUDENTS SECTION - Only shows if there are pending students -->
    <?php if (count($pending_students) > 0): ?>
    <div class="pending-section">
        <div class="pending-header">
            <h3>
                ⏳ Recent Pending Approvals
                <span class="pending-count"><?= count($pending_students) ?></span>
            </h3>
            <a href="pending_students.php" class="view-all-btn">View All Pending</a>
        </div>

        <div class="table-responsive">
            <table>
                <thead>
                    <tr>
                        <th>Reg Number</th>
                        <th>Name</th>
                        <th>Email</th>
                        <th>Course</th>
                        <th>Year/Sem</th>
                        <th>Registered</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($pending_students as $student): ?>
                    <tr>
                        <td><strong><?= htmlspecialchars($student['reg_number']) ?></strong></td>
                        <td><?= htmlspecialchars($student['name']) ?></td>
                        <td><?= htmlspecialchars($student['email']) ?></td>
                        <td><?= htmlspecialchars($student['course_name'] ?? 'N/A') ?></td>
                        <td>Year <?= $student['year'] ?>, Sem <?= $student['semester'] ?></td>
                        <td><?= date('M d, Y', strtotime($student['created_at'])) ?></td>
                        <td>
                            <form method="POST" action="pending_students.php" style="display: inline;">
                                <input type="hidden" name="csrf_token" value="<?= $csrf_token ?>">
                                <input type="hidden" name="action" value="activate">
                                <input type="hidden" name="student_id" value="<?= $student['student_id'] ?>">
                                <button type="submit" class="btn-sm btn-success" onclick="return confirm('Activate this student?')">✓ Activate</button>
                            </form>
                            <form method="POST" action="pending_students.php" style="display: inline; margin-left: 5px;">
                                <input type="hidden" name="csrf_token" value="<?= $csrf_token ?>">
                                <input type="hidden" name="action" value="reject">
                                <input type="hidden" name="student_id" value="<?= $student['student_id'] ?>">
                                <button type="submit" class="btn-sm btn-danger" onclick="return confirm('Reject this student?')">✗ Reject</button>
                            </form>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
    <?php endif; ?>

    <!-- Courses Overview -->
    <h2 class="section-title">📚 All Courses</h2>
    <p style="color: #64748b; margin-bottom: 1rem;">Click on any course to manage its years, semesters, modules, and students</p>

    <div class="course-grid">
        <?php if (count($courses) > 0): ?>
            <?php foreach ($courses as $course): ?>
            <div class="course-card" onclick="window.location.href='manage_course_structure.php?course_id=<?= $course['course_id'] ?>'">
                <div class="course-header">
                    <h3><?= htmlspecialchars($course['course_name']) ?></h3>
                    <span class="course-status"><?= ucfirst($course['status']) ?></span>
                </div>
                <div class="course-body">
                    <div class="course-stats">
                        <div class="stat-item">
                            <div class="stat-value"><?= $course['module_count'] ?></div>
                            <div class="stat-label">Modules</div>
                        </div>
                        <div class="stat-item">
                            <div class="stat-value"><?= $course['student_count'] ?></div>
                            <div class="stat-label">Students</div>
                        </div>
                    </div>
                    <?php if ($course['pending_count'] > 0): ?>
                        <div class="pending-badge">⏳ <?= $course['pending_count'] ?> pending approvals</div>
                    <?php endif; ?>
                </div>
            </div>
            <?php endforeach; ?>
        <?php else: ?>
            <div class="empty-state">
                <p style="font-size: 1.2rem; margin-bottom: 1rem;">No courses found</p>
                <a href="add_course.php" class="action-btn" style="display: inline-block; padding: 0.8rem 2rem;">➕ Add Your First Course</a>
            </div>
        <?php endif; ?>
    </div>

    <!-- Recent Activity -->
    <div class="activity-section">
        <h3 class="activity-title">🕒 Recent Student Registrations</h3>
        <?php if (count($recent_activities) > 0): ?>
            <?php foreach ($recent_activities as $activity): ?>
            <div class="activity-item">
                <div class="activity-icon">👤</div>
                <div class="activity-content">
                    <div class="activity-name"><?= htmlspecialchars($activity['title'] ?? 'Unknown') ?></div>
                    <div class="activity-email"><?= htmlspecialchars($activity['email'] ?? 'No email') ?></div>
                    <div class="activity-time"><?= date('M d, Y H:i', strtotime($activity['created_at'] ?? 'now')) ?></div>
                </div>
                <span class="status-badge <?= ($activity['status'] ?? 'pending') === 'pending' ? 'status-pending' : 'status-active' ?>">
                    <?= ucfirst($activity['status'] ?? 'pending') ?>
                </span>
            </div>
            <?php endforeach; ?>
        <?php else: ?>
            <p style="color: #64748b; text-align: center; padding: 1rem;">No recent registrations</p>
        <?php endif; ?>
    </div>
</div>

</body>
</html>