<?php
/**
 * admin/manage_course_structure.php - Manage Course Years and Semesters
 * With safe handling of year 0 data
 */

session_start();
require_once '../config/db.php';
require_once '../config/security_base.php';

// Check admin login
checkAdminSession();

$course_id = isset($_GET['course_id']) ? (int)$_GET['course_id'] : 0;

if (!$course_id) {
    header("Location: dashboard.php");
    exit();
}

// Get course details
$course_stmt = $conn->prepare("SELECT course_name, status FROM courses WHERE course_id = ? AND deleted = 0");
$course_stmt->bind_param("i", $course_id);
$course_stmt->execute();
$course = $course_stmt->get_result()->fetch_assoc();
$course_stmt->close();

if (!$course) {
    header("Location: dashboard.php");
    exit();
}

// Check if there are any year 0 modules and show warning
$year_zero_check = $conn->prepare("SELECT COUNT(*) as count FROM modules WHERE course_id = ? AND (year = 0 OR year IS NULL) AND deleted = 0");
$year_zero_check->bind_param("i", $course_id);
$year_zero_check->execute();
$year_zero_count = $year_zero_check->get_result()->fetch_assoc()['count'];
$year_zero_check->close();

// Get all years and semesters from modules (including year 0 but we'll handle it)
$years_semesters = "
    SELECT DISTINCT year, semester 
    FROM modules 
    WHERE course_id = ? AND deleted = 0 
    ORDER BY 
        CASE 
            WHEN year <= 0 THEN 1  -- Put year 0 at the end
            ELSE year 
        END ASC, 
        semester ASC
";
$ys_stmt = $conn->prepare($years_semesters);
$ys_stmt->bind_param("i", $course_id);
$ys_stmt->execute();
$structure = $ys_stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$ys_stmt->close();

// Group by year, handling year 0 specially
$years = [];
$has_year_zero = false;

foreach ($structure as $item) {
    $year_val = $item['year'];
    if ($year_val <= 0) {
        $has_year_zero = true;
        // Put year 0 data into year 1 for display purposes
        $years[1][] = $item['semester'];
    } else {
        $years[$year_val][] = $item['semester'];
    }
}

// Remove duplicates in semesters
foreach ($years as $year => $semesters) {
    $years[$year] = array_unique($semesters);
    sort($years[$year]);
}

// Ensure all years 1-3 exist
for ($y = 1; $y <= 3; $y++) {
    if (!isset($years[$y])) {
        $years[$y] = [1, 2];
    } else {
        // Ensure both semesters exist
        if (!in_array(1, $years[$y])) $years[$y][] = 1;
        if (!in_array(2, $years[$y])) $years[$y][] = 2;
        sort($years[$y]);
    }
}

// Sort years
ksort($years);

// Get statistics for each year/semester (including migrated year 0 data)
$stats = [];
foreach ($years as $year => $semesters) {
    foreach ($semesters as $semester) {
        // Get module count - include both this year AND year 0 (counted as year 1)
        $module_stmt = $conn->prepare("
            SELECT COUNT(DISTINCT module_id) as module_count
            FROM modules 
            WHERE course_id = ? AND semester = ? AND deleted = 0
            AND (year = ? OR (year <= 0 AND ? = 1))  -- Include year 0 data in year 1
        ");
        $module_stmt->bind_param("iiii", $course_id, $semester, $year, $year);
        $module_stmt->execute();
        $module_count = $module_stmt->get_result()->fetch_assoc()['module_count'];
        $module_stmt->close();
        
        // Get student count - include both this year AND year 0
        $student_stmt = $conn->prepare("
            SELECT COUNT(DISTINCT student_id) as student_count
            FROM students 
            WHERE course_id = ? AND semester = ? AND deleted = 0 AND status = 'active'
            AND (year = ? OR (year <= 0 AND ? = 1))
        ");
        $student_stmt->bind_param("iiii", $course_id, $semester, $year, $year);
        $student_stmt->execute();
        $student_count = $student_stmt->get_result()->fetch_assoc()['student_count'];
        $student_stmt->close();
        
        $stats["{$year}_{$semester}"] = [
            'module_count' => $module_count,
            'student_count' => $student_count
        ];
    }
}

$csrf_token = generateCSRF();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Manage Course - <?= htmlspecialchars($course['course_name']) ?></title>
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

        /* Warning Banner */
        .warning-banner {
            background: #fef3c7;
            border-left: 4px solid #f59e0b;
            color: #92400e;
            padding: 1rem 1.5rem;
            border-radius: 8px;
            margin-bottom: 1.5rem;
            display: flex;
            align-items: center;
            gap: 1rem;
        }

        .warning-banner .icon {
            font-size: 1.5rem;
        }

        .warning-banner .message {
            flex: 1;
        }

        .warning-banner .btn-small {
            background: #f59e0b;
            color: white;
            padding: 0.5rem 1rem;
            border-radius: 6px;
            text-decoration: none;
            font-weight: 500;
            font-size: 0.9rem;
        }

        .warning-banner .btn-small:hover {
            background: #d97706;
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
            font-size: 0.95rem;
        }

        .breadcrumb a {
            color: #2dd4bf;
            text-decoration: none;
            font-weight: 500;
        }

        .breadcrumb a:hover {
            text-decoration: underline;
        }

        /* Course Header */
        .course-header {
            background: linear-gradient(135deg, #0f172a, #1e293b);
            color: white;
            padding: 2.5rem;
            border-radius: 20px;
            margin-bottom: 2.5rem;
            box-shadow: 0 10px 25px -5px rgba(0, 0, 0, 0.2);
            width: 100%;
        }

        .course-header h1 {
            font-size: 2.2rem;
            margin-bottom: 0.5rem;
            font-weight: 600;
        }

        .course-header p {
            color: #94a3b8;
            font-size: 1rem;
        }

        /* Years Grid */
        .years-grid {
            display: flex;
            flex-direction: column;
            gap: 2rem;
            width: 100%;
        }

        .year-card {
            background: white;
            border-radius: 20px;
            overflow: hidden;
            box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.1);
            width: 100%;
        }

        .year-header {
            background: linear-gradient(135deg, #0f172a, #1e293b);
            color: white;
            padding: 1.2rem 2rem;
            font-size: 1.3rem;
            font-weight: 600;
        }

        .semesters-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));
            gap: 1.5rem;
            padding: 1.5rem;
        }

        .semester-card {
            background: #f8fafc;
            border-radius: 16px;
            overflow: hidden;
            border: 1px solid #e2e8f0;
            transition: all 0.3s;
            cursor: pointer;
        }

        .semester-card:hover {
            transform: translateY(-4px);
            border-color: #2dd4bf;
            box-shadow: 0 10px 20px -5px rgba(45, 212, 191, 0.2);
        }

        .semester-header {
            background: linear-gradient(135deg, #2dd4bf, #14b8a6);
            color: white;
            padding: 1.2rem;
            font-weight: 600;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .semester-body {
            padding: 1.2rem;
        }

        .stat-row {
            display: flex;
            justify-content: space-between;
            margin-bottom: 0.8rem;
            padding: 0.5rem 0;
            border-bottom: 1px solid #e2e8f0;
        }

        .stat-label {
            color: #64748b;
            font-size: 0.9rem;
        }

        .stat-value {
            font-weight: 600;
            color: #0f172a;
        }

        .migrated-badge {
            background: #fef3c7;
            color: #92400e;
            font-size: 0.75rem;
            padding: 0.2rem 0.5rem;
            border-radius: 12px;
            margin-left: 0.5rem;
        }

        .manage-link {
            display: inline-block;
            margin-top: 1rem;
            color: #2dd4bf;
            text-decoration: none;
            font-weight: 600;
            font-size: 0.9rem;
        }

        .manage-link:hover {
            text-decoration: underline;
        }

        /* Action Buttons */
        .action-buttons {
            margin-top: 2.5rem;
            display: flex;
            gap: 1rem;
            flex-wrap: wrap;
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

        .btn-warning:hover {
            background: #d97706;
        }

        @media (max-width: 768px) {
            .container {
                padding: 0 1rem;
            }

            .semesters-grid {
                grid-template-columns: 1fr;
            }

            .action-buttons {
                flex-direction: column;
            }

            .btn {
                width: 100%;
                justify-content: center;
            }

            .course-header {
                padding: 1.5rem;
            }

            .course-header h1 {
                font-size: 1.8rem;
            }

            .warning-banner {
                flex-direction: column;
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
        <strong><?= htmlspecialchars($course['course_name']) ?></strong>
    </div>

    <!-- Warning for Year 0 Data -->
    <?php if ($year_zero_count > 0): ?>
    <div class="warning-banner">
        <span class="icon">⚠️</span>
        <span class="message">
            <strong>Data Migration Notice:</strong> Found <?= $year_zero_count ?> module(s) with Year 0. 
            These have been moved to Year 1 for display. No data will be lost.
        </span>
        <a href="fix_year_zero.php?course_id=<?= $course_id ?>" class="btn-small">Fix Permanently</a>
    </div>
    <?php endif; ?>

    <!-- Course Header -->
    <div class="course-header">
        <h1><?= htmlspecialchars($course['course_name']) ?></h1>
        <p>Select a year and semester to manage modules, teachers, students, and results</p>
    </div>

    <!-- Years and Semesters -->
    <div class="years-grid">
        <?php foreach ($years as $year => $semesters): ?>
        <div class="year-card">
            <div class="year-header">
                Year <?= $year ?>
                <?php if ($has_year_zero && $year == 1): ?>
                <span style="font-size: 0.8rem; background: rgba(255,255,255,0.2); padding: 0.2rem 0.8rem; border-radius: 20px; margin-left: 1rem;">
                    Includes migrated data
                </span>
                <?php endif; ?>
            </div>
            <div class="semesters-grid">
                <?php foreach ($semesters as $semester): 
                    $stats_key = "{$year}_{$semester}";
                    $stat = $stats[$stats_key] ?? ['module_count' => 0, 'student_count' => 0];
                ?>
                <div class="semester-card" onclick="window.location.href='manage_semester.php?course_id=<?= $course_id ?>&year=<?= $year ?>&semester=<?= $semester ?>'">
                    <div class="semester-header">
                        <span>Semester <?= $semester ?></span>
                        <span>📚</span>
                    </div>
                    <div class="semester-body">
                        <div class="stat-row">
                            <span class="stat-label">Modules</span>
                            <span class="stat-value">
                                <?= $stat['module_count'] ?>
                                <?php if ($year == 1 && $has_year_zero): ?>
                                <span class="migrated-badge">includes legacy</span>
                                <?php endif; ?>
                            </span>
                        </div>
                        <div class="stat-row">
                            <span class="stat-label">Students</span>
                            <span class="stat-value"><?= $stat['student_count'] ?></span>
                        </div>
                        <a href="manage_semester.php?course_id=<?= $course_id ?>&year=<?= $year ?>&semester=<?= $semester ?>" class="manage-link">Manage →</a>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
        </div>
        <?php endforeach; ?>
    </div>

    <!-- Action Buttons -->
    <div class="action-buttons">
        <a href="add_module.php?course_id=<?= $course_id ?>" class="btn btn-primary">
            <span>➕</span> Add New Module
        </a>
        <a href="add_teacher.php" class="btn btn-secondary">
            <span>👨‍🏫</span> Add Teacher
        </a>
        <a href="verify_enrollments.php?course_id=<?= $course_id ?>" class="btn btn-secondary">
            <span>🔍</span> Verify Enrollments
        </a>
        <?php if ($year_zero_count > 0): ?>
        <a href="fix_year_zero.php?course_id=<?= $course_id ?>" class="btn btn-warning">
            <span>🔄</span> Fix Year 0 Data
        </a>
        <?php endif; ?>
    </div>
</div>

</body>
</html>