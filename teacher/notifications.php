<?php
/**
 * teacher/notifications.php - Teacher Notification Center
 */

session_start();
require_once '../config/db.php';
require_once '../config/security_base.php';

// Check teacher login
if (!isset($_SESSION['teacher_logged_in']) || !isset($_SESSION['teacher_id'])) {
    header("Location: login.php");
    exit();
}

$teacher_id = $_SESSION['teacher_id'];
$teacher_name = $_SESSION['teacher_name'] ?? 'Teacher';

// Mark notification as read
if (isset($_GET['read_id'])) {
    $notif_id = (int)$_GET['read_id'];
    $stmt = $conn->prepare("UPDATE notifications SET status = 'read' WHERE id = ? AND user_id = ? AND user_type = 'teacher'");
    $stmt->bind_param("ii", $notif_id, $teacher_id);
    $stmt->execute();
    $stmt->close();
    header("Location: notifications.php");
    exit();
}

// Mark all as read
if (isset($_GET['read_all'])) {
    $stmt = $conn->prepare("UPDATE notifications SET status = 'read' WHERE user_id = ? AND user_type = 'teacher' AND status = 'unread'");
    $stmt->bind_param("i", $teacher_id);
    $stmt->execute();
    $stmt->close();
    header("Location: notifications.php");
    exit();
}

// Get notifications
$notif_stmt = $conn->prepare("
    SELECT 
        n.id,
        n.type,
        n.message,
        n.status,
        n.created_at,
        m.module_code,
        m.module_name,
        c.course_name
    FROM notifications n
    LEFT JOIN modules m ON n.module_id = m.module_id
    LEFT JOIN courses c ON m.course_id = c.course_id
    WHERE n.user_id = ? AND n.user_type = 'teacher'
    ORDER BY n.created_at DESC
    LIMIT 50
");
$notif_stmt->bind_param("i", $teacher_id);
$notif_stmt->execute();
$notifications = $notif_stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$notif_stmt->close();

// Count unread
$unread_count = count(array_filter($notifications, fn($n) => $n['status'] === 'unread'));
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Notifications - CSMS Teacher</title>
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
        }

        .container {
            max-width: 900px;
            margin: 2rem auto;
            padding: 0 2rem;
        }

        .page-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 2rem;
        }

        .page-header h2 {
            font-size: 1.8rem;
            color: #0f172a;
        }

        .badge {
            background: #2dd4bf;
            color: white;
            padding: 0.25rem 0.75rem;
            border-radius: 20px;
            font-size: 0.8rem;
            font-weight: 600;
        }

        .btn {
            padding: 0.6rem 1.2rem;
            border: none;
            border-radius: 8px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s;
            text-decoration: none;
            display: inline-block;
        }

        .btn-primary {
            background: #2dd4bf;
            color: white;
        }

        .btn-primary:hover {
            background: #14b8a6;
            transform: translateY(-2px);
        }

        .btn-secondary {
            background: #e2e8f0;
            color: #0f172a;
        }

        .btn-secondary:hover {
            background: #cbd5e1;
        }

        .notifications-list {
            background: white;
            border-radius: 20px;
            overflow: hidden;
            box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.1);
        }

        .notification-item {
            padding: 1.5rem;
            border-bottom: 1px solid #e2e8f0;
            transition: background 0.3s;
            display: flex;
            gap: 1rem;
        }

        .notification-item:last-child {
            border-bottom: none;
        }

        .notification-item.unread {
            background: #f0f9ff;
            border-left: 4px solid #2dd4bf;
        }

        .notification-item:hover {
            background: #f8fafc;
        }

        .notification-icon {
            width: 40px;
            height: 40px;
            background: #e0f2fe;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.2rem;
        }

        .notification-content {
            flex: 1;
        }

        .notification-title {
            font-weight: 600;
            margin-bottom: 0.5rem;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .notification-type {
            font-size: 0.7rem;
            background: #e2e8f0;
            padding: 0.2rem 0.5rem;
            border-radius: 4px;
            color: #475569;
        }

        .notification-message {
            color: #1e293b;
            margin-bottom: 0.5rem;
        }

        .notification-meta {
            display: flex;
            gap: 1rem;
            font-size: 0.8rem;
            color: #64748b;
        }

        .notification-module {
            background: #f1f5f9;
            padding: 0.25rem 0.75rem;
            border-radius: 4px;
            color: #0f172a;
        }

        .notification-actions {
            margin-top: 0.5rem;
        }

        .btn-small {
            padding: 0.25rem 0.75rem;
            font-size: 0.8rem;
            border: none;
            border-radius: 4px;
            cursor: pointer;
            text-decoration: none;
            display: inline-block;
        }

        .btn-mark-read {
            background: #2dd4bf;
            color: white;
        }

        .empty-state {
            text-align: center;
            padding: 4rem;
            color: #64748b;
        }

        .back-link {
            margin-top: 2rem;
            text-align: center;
        }

        .back-link a {
            color: #2dd4bf;
            text-decoration: none;
            font-weight: 600;
        }

        @media (max-width: 768px) {
            .container {
                padding: 0 1rem;
            }

            .page-header {
                flex-direction: column;
                gap: 1rem;
                text-align: center;
            }

            .notification-item {
                flex-direction: column;
            }
        }
    </style>
</head>
<body>

<div class="header">
    <h1>CSMS Teacher</h1>
    <a href="logout.php">🚪 Logout</a>
</div>

<div class="container">
    <div class="page-header">
        <h2>🔔 Notifications</h2>
        <div>
            <?php if ($unread_count > 0): ?>
                <a href="?read_all=1" class="btn btn-primary" onclick="return confirm('Mark all as read?')">✓ Mark All Read</a>
            <?php endif; ?>
            <a href="dashboard.php" class="btn btn-secondary">← Back</a>
        </div>
    </div>

    <?php if (count($notifications) > 0): ?>
        <div class="notifications-list">
            <?php foreach ($notifications as $notif): ?>
            <div class="notification-item <?= $notif['status'] === 'unread' ? 'unread' : '' ?>">
                <div class="notification-icon">
                    <?php 
                    if ($notif['type'] === 'result_request') echo '📧';
                    elseif ($notif['type'] === 'result_published') echo '📊';
                    else echo '🔔';
                    ?>
                </div>
                <div class="notification-content">
                    <div class="notification-title">
                        <?php if ($notif['type'] === 'result_request'): ?>
                            Results Request
                        <?php elseif ($notif['type'] === 'result_published'): ?>
                            Results Published
                        <?php else: ?>
                            Notification
                        <?php endif; ?>
                        <span class="notification-type"><?= ucfirst($notif['type']) ?></span>
                    </div>
                    
                    <div class="notification-message">
                        <?= htmlspecialchars($notif['message']) ?>
                    </div>
                    
                    <?php if ($notif['module_code']): ?>
                    <div class="notification-module">
                        📚 <?= htmlspecialchars($notif['module_code']) ?> - <?= htmlspecialchars(substr($notif['module_name'], 0, 30)) ?>
                        <?php if ($notif['course_name']): ?>
                            <span style="color: #64748b;">(<?= htmlspecialchars($notif['course_name']) ?>)</span>
                        <?php endif; ?>
                    </div>
                    <?php endif; ?>
                    
                    <div class="notification-meta">
                        <span>📅 <?= date('M d, Y H:i', strtotime($notif['created_at'])) ?></span>
                    </div>
                    
                    <?php if ($notif['status'] === 'unread'): ?>
                    <div class="notification-actions">
                        <a href="?read_id=<?= $notif['id'] ?>" class="btn-small btn-mark-read">✓ Mark as Read</a>
                    </div>
                    <?php endif; ?>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
    <?php else: ?>
        <div class="empty-state">
            <p style="font-size: 1.2rem; margin-bottom: 1rem;">📭 No notifications</p>
            <p>You're all caught up!</p>
        </div>
    <?php endif; ?>

    <div class="back-link">
        <a href="dashboard.php">← Back to Dashboard</a>
    </div>
</div>

</body>
</html>