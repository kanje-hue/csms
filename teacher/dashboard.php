<?php
session_start();
include '../config/db.php';

if(!isset($_SESSION['teacher_id'])){
    header("Location: login.php");
    exit();
}

$teacher_id = $_SESSION['teacher_id'];
$teacher_name = $_SESSION['teacher_name'] ?? 'Teacher';

// Get teacher's modules
$modules_query = "SELECT module_id, module_code, module_name, course_id FROM modules WHERE teacher_id = ? AND deleted = 0 ORDER BY module_code ASC";
$modules_stmt = $conn->prepare($modules_query);
$modules_stmt->bind_param("i", $teacher_id);
$modules_stmt->execute();
$modules_result = $modules_stmt->get_result();
$modules = $modules_result->fetch_all(MYSQLI_ASSOC);
$modules_stmt->close();

// Get course names for each module
foreach($modules as &$m){
    $course_stmt = $conn->prepare("SELECT course_name FROM courses WHERE course_id = ?");
    $course_stmt->bind_param("i", $m['course_id']);
    $course_stmt->execute();
    $course = $course_stmt->get_result()->fetch_assoc();
    $m['course_name'] = $course ? $course['course_name'] : 'Unknown';
    $course_stmt->close();
}

?>

<!DOCTYPE html>
<html>
<head>
    <title>Teacher Dashboard</title>
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
            color: var(--midnight-garden);
            margin-bottom: 10px;
        }

        .welcome {
            text-align: center;
            color: #666;
            font-size: 16px;
            margin-bottom: 30px;
        }

        h3 {
            margin-top: 30px;
            margin-bottom: 20px;
            color: var(--midnight-garden);
            border-bottom: 2px solid var(--minty-fresh);
            padding-bottom: 10px;
        }

        .modules-container {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(350px, 1fr));
            gap: 20px;
            margin-top: 20px;
        }

        .module-card {
            background: linear-gradient(135deg, var(--skipping-stones), var(--minty-fresh));
            border-radius: 12px;
            padding: 20px;
            box-shadow: 0 4px 6px rgba(0,0,0,0.1);
            transition: all 0.3s;
        }

        .module-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 10px 20px rgba(0,0,0,0.15);
        }

        .module-card h4 {
            color: var(--midnight-garden);
            margin-top: 0;
            margin-bottom: 10px;
            font-size: 18px;
        }

        .module-code {
            color: var(--terra-rosa);
            font-weight: bold;
            font-size: 14px;
        }

        .module-course {
            color: #666;
            font-size: 13px;
            margin-bottom: 15px;
        }

        .action-buttons {
            display: flex;
            flex-direction: column;
            gap: 8px;
            margin-top: 15px;
        }

        .action-link {
            display: block;
            padding: 10px 15px;
            background: linear-gradient(135deg, var(--terra-rosa), var(--honey-glow));
            color: white;
            text-decoration: none;
            font-weight: bold;
            border-radius: 8px;
            text-align: center;
            transition: all 0.3s;
            font-size: 13px;
        }

        .action-link:hover {
            opacity: 0.9;
            transform: scale(1.02);
        }

        .action-link.secondary {
            background: var(--minty-fresh);
            color: var(--art-craft);
        }

        .action-link.danger {
            background: #FF9800;
        }

        .table-view {
            width: 100%;
            border-collapse: collapse;
            margin-top: 20px;
        }

        .table-view th,
        .table-view td {
            padding: 12px;
            border: 1px solid #ddd;
            text-align: left;
        }

        .table-view th {
            background: var(--minty-fresh);
            color: var(--art-craft);
            font-weight: bold;
        }

        .table-view tr:nth-child(even) {
            background: #f9f9f9;
        }

        .table-view tr:hover {
            background: #f0f0f0;
        }

        .logout-link {
            text-align: center;
            margin-top: 40px;
        }

        .logout-link a {
            color: var(--terra-rosa);
            text-decoration: none;
            font-weight: bold;
            padding: 12px 25px;
            border: 2px solid var(--terra-rosa);
            border-radius: 8px;
            display: inline-block;
            transition: all 0.3s;
        }

        .logout-link a:hover {
            background: var(--terra-rosa);
            color: white;
        }

        .no-modules {
            text-align: center;
            padding: 60px 40px;
            color: #666;
            background: #f9f9f9;
            border-radius: 8px;
            margin: 20px 0;
        }

        .view-toggle {
            text-align: right;
            margin: 20px 0;
        }

        .view-toggle button {
            padding: 8px 15px;
            margin-left: 10px;
            background: var(--minty-fresh);
            color: var(--art-craft);
            border: none;
            border-radius: 8px;
            cursor: pointer;
            font-weight: bold;
        }

        .view-toggle button.active {
            background: var(--terra-rosa);
            color: white;
        }

        .grid-view {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(350px, 1fr));
            gap: 20px;
        }

        .list-view {
            display: none;
        }

        .list-view.active {
            display: block;
        }

        .grid-view.hidden {
            display: none;
        }

        @media (max-width: 768px) {
            .auth-card {
                width: 95%;
                padding: 15px;
            }

            .modules-container {
                grid-template-columns: 1fr;
            }

            .action-buttons {
                flex-direction: row;
            }

            .action-link {
                flex: 1;
                padding: 8px 10px;
                font-size: 12px;
            }

            .table-view {
                font-size: 12px;
            }

            .table-view th,
            .table-view td {
                padding: 8px;
            }

            .view-toggle {
                text-align: center;
            }

            .view-toggle button {
                margin: 5px;
            }
        }
    </style>
</head>
<body>

<div class="auth-card">
    <h2>👨‍🏫 Teacher Dashboard</h2>
    <p class="welcome">Welcome, <strong><?= htmlspecialchars($teacher_name) ?></strong></p>

    <h3>📚 Your Modules</h3>

    <?php if (count($modules) > 0): ?>
        
        <!-- View Toggle -->
        <div class="view-toggle">
            <button class="active" onclick="toggleView('grid')">📊 Grid View</button>
            <button onclick="toggleView('list')">📋 List View</button>
        </div>

        <!-- GRID VIEW (Cards) -->
        <div id="grid-view" class="grid-view">
            <?php foreach ($modules as $module): ?>
            <div class="module-card">
                <div class="module-code"><?= htmlspecialchars($module['module_code']) ?></div>
                <h4><?= htmlspecialchars($module['module_name']) ?></h4>
                <div class="module-course">
                    📚 <?= htmlspecialchars($module['course_name']) ?>
                </div>

                <div class="action-buttons">
                    <a href="view_attendance.php?module_id=<?= $module['module_id'] ?>" class="action-link">
                        📊 View Attendance
                    </a>
                    <a href="upload_results.php?module_id=<?= $module['module_id'] ?>" class="action-link secondary">
                        📝 Upload Results
                    </a>
                    <a href="manage_attendance.php?module_id=<?= $module['module_id'] ?>" class="action-link danger">
                        ✏️ Mark Attendance
                    </a>
                </div>
            </div>
            <?php endforeach; ?>
        </div>

        <!-- LIST VIEW (Table) -->
        <div id="list-view" class="list-view">
            <table class="table-view">
                <thead>
                    <tr>
                        <th>Module Code</th>
                        <th>Module Name</th>
                        <th>Course</th>
                        <th colspan="3" style="text-align: center;">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($modules as $module): ?>
                    <tr>
                        <td><strong><?= htmlspecialchars($module['module_code']) ?></strong></td>
                        <td><?= htmlspecialchars($module['module_name']) ?></td>
                        <td><?= htmlspecialchars($module['course_name']) ?></td>
                        <td style="text-align: center;">
                            <a href="view_attendance.php?module_id=<?= $module['module_id'] ?>" class="action-link" style="display: inline-block; padding: 5px 10px; margin: 0; font-size: 12px;">
                                📊 Attendance
                            </a>
                        </td>
                        <td style="text-align: center;">
                            <a href="upload_results.php?module_id=<?= $module['module_id'] ?>" class="action-link secondary" style="display: inline-block; padding: 5px 10px; margin: 0; font-size: 12px;">
                                📝 Results
                            </a>
                        </td>
                        <td style="text-align: center;">
                            <a href="manage_attendance.php?module_id=<?= $module['module_id'] ?>" class="action-link danger" style="display: inline-block; padding: 5px 10px; margin: 0; font-size: 12px;">
                                ✏️ Mark
                            </a>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>

    <?php else: ?>
        <div class="no-modules">
            <p>❌ You have not been assigned any modules yet.</p>
            <p>Please contact the administrator to assign modules to your account.</p>
        </div>
    <?php endif; ?>

    <div class="logout-link">
        <a href="logout.php">🚪 Logout</a>
    </div>
</div>

<script>
function toggleView(view) {
    const gridView = document.getElementById('grid-view');
    const listView = document.getElementById('list-view');
    const buttons = document.querySelectorAll('.view-toggle button');

    if (view === 'grid') {
        gridView.classList.remove('hidden');
        listView.classList.remove('active');
        buttons[0].classList.add('active');
        buttons[1].classList.remove('active');
    } else {
        gridView.classList.add('hidden');
        listView.classList.add('active');
        buttons[0].classList.remove('active');
        buttons[1].classList.add('active');
    }
}
</script>

</body>
</html>