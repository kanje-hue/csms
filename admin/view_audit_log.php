<?php
session_start();
include '../config/db.php';

if(!isset($_SESSION['admin_logged_in'])){
    header("Location: login.php");
    exit();
}

// Get audit logs
$audit_query = "
    SELECT 
        ral.id,
        ral.admin_id,
        a.name as admin_name,
        ral.module_id,
        m.module_code,
        m.module_name,
        ral.action,
        ral.description,
        ral.action_date
    FROM result_audit_log ral
    LEFT JOIN admins a ON ral.admin_id = a.admin_id
    LEFT JOIN modules m ON ral.module_id = m.module_id
    ORDER BY ral.action_date DESC
    LIMIT 100
";

$audit_stmt = $conn->prepare($audit_query);
$audit_stmt->execute();
$audit_logs = $audit_stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$audit_stmt->close();

?>

<!DOCTYPE html>
<html>
<head>
    <title>Audit Log</title>
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
            margin-bottom: 20px;
        }

        table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 20px;
        }

        th, td {
            padding: 12px;
            border: 1px solid #ddd;
            text-align: left;
        }

        th {
            background: var(--minty-fresh);
            color: var(--art-craft);
            font-weight: bold;
        }

        tr:nth-child(even) {
            background: #f9f9f9;
        }

        tr:hover {
            background: #f0f0f0;
        }

        .action-badge {
            display: inline-block;
            padding: 4px 12px;
            border-radius: 6px;
            font-size: 11px;
            font-weight: bold;
        }

        .action-publish {
            background: #d4edda;
            color: #155724;
        }

        .action-unpublish {
            background: #fff3cd;
            color: #856404;
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

        .no-data {
            text-align: center;
            padding: 40px;
            color: #666;
        }

        @media (max-width: 768px) {
            .auth-card {
                width: 95%;
                padding: 15px;
            }

            table {
                font-size: 12px;
            }

            th, td {
                padding: 8px;
            }
        }
    </style>
</head>
<body>

<div class="auth-card">
    <h2>📋 Results Audit Log</h2>

    <p style="text-align: center; color: #666; margin-bottom: 20px;">
        Last 100 actions on results management
    </p>

    <?php if (count($audit_logs) > 0): ?>
        <table>
            <thead>
                <tr>
                    <th>Date & Time</th>
                    <th>Admin</th>
                    <th>Module</th>
                    <th>Action</th>
                    <th>Description</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($audit_logs as $log): ?>
                <tr>
                    <td><?= date('M d, Y H:i', strtotime($log['action_date'])) ?></td>
                    <td><strong><?= htmlspecialchars($log['admin_name'] ?? 'System') ?></strong></td>
                    <td><?= htmlspecialchars($log['module_code'] ?? '-') ?></td>
                    <td>
                        <span class="action-badge action-<?= strtolower($log['action']) ?>">
                            <?= strtoupper($log['action']) ?>
                        </span>
                    </td>
                    <td><?= htmlspecialchars($log['description'] ?? '') ?></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    <?php else: ?>
        <div class="no-data">
            <p>No audit logs found</p>
        </div>
    <?php endif; ?>

    <div class="back-link">
        <a href="dashboard.php">← Back to Dashboard</a>
    </div>
</div>

</body>
</html>