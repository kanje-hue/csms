<?php
session_start();
include '../config/db.php';

if(!isset($_SESSION['teacher_logged_in'])){
    header("Location: login.php");
    exit();
}

$teacher_id = $_SESSION['teacher_id'] ?? null;

// Mark notification as read
if(isset($_GET['read_id'])){
    $notif_id = filter_var($_GET['read_id'], FILTER_VALIDATE_INT);
    if($notif_id){
        $stmt = $conn->prepare("UPDATE notifications SET status = 'read', read_at = NOW() WHERE id = ? AND teacher_id = ?");
        $stmt->bind_param("ii", $notif_id, $teacher_id);
        $stmt->execute();
        $stmt->close();
    }
}

// Get unread notifications
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
    JOIN modules m ON n.module_id = m.module_id
    JOIN courses c ON m.course_id = c.course_id
    WHERE n.teacher_id = ? 
    ORDER BY n.created_at DESC
");
$notif_stmt->bind_param("i", $teacher_id);
$notif_stmt->execute();
$notifications = $notif_stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$notif_stmt->close();

$unread_count = count(array_filter($notifications, function($n) { return $n['status'] === 'unread'; }));
?>

<!DOCTYPE html>
<html>
<head>
    <title>Notifications - CSMS</title>
    <link rel="stylesheet" href="../assets/css/auth.css">
    <style>
        .container {
            max-width: 900px;
            margin: 0 auto;
            padding: 20px;
        }

        .auth-card {
            width: 100%;
        }

        h2 {
            text-align: center;
            color: #333;
            margin-bottom: 20px;
        }

        .notif-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 20px;
        }

        .badge {
            background: var(--terra-rosa);
            color: white;
            padding: 5px 12px;
            border-radius: 20px;
            font-size: 14px;
            font-weight: bold;
        }

        .notification-item {
            background: white;
            padding: 15px;
            border-left: 4px solid var(--terra-rosa);
            border-radius: 6px;
            margin-bottom: 15px;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
            transition: all 0.3s;
        }

        .notification-item:hover {
            box-shadow: 0 4px 8px rgba(0,0,0,0.15);
        }

        .notification-item.unread {
            background: #f0f8ff;
            border-left-color: #2196F3;
        }

        .notif-title {
            font-weight: bold;
            color: var(--midnight-garden);
            margin-bottom: 5px;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .email-badge {
            background: #4CAF50;
            color: white;
            padding: 2px 8px;
            border-radius: 4px;
            font-size: 11px;
            font-weight: bold;
        }

        .notif-module {
            font-size: 13px;
            color: #666;
            margin-bottom: 8px;
        }

        .notif-message {
            font-size: 14px;
            color: #333;
            margin-bottom: 10px;
        }

        .notif-time {
            font-size: 12px;
            color: #999;
        }

        .notif-actions {
            text-align: right;
            margin-top: 10px;
        }

        .btn-mark-read {
            padding: 5px 12px;
            background: #2196F3;
            color: white;
            border: none;
            border-radius: 4px;
            cursor: pointer;
            font-size: 12px;
            text-decoration: none;
            display: inline-block;
        }

        .btn-mark-read:hover {
            background: #0b7dda;
        }

        .no-notif {
            text-align: center;
            padding: 40px;
            color: #999;
        }

        .back-link {
            text-align: center;
            margin-top: 20px;
        }

        .back-link a {
            color: #2196F3;
            text-decoration: none;
            font-weight: bold;
        }
    </style>
</head>
<body>

<div class="container">
    <div class="auth-card">
        <h2>🔔 Notifications</h2>

        <div class="notif-header">
            <h3 style="margin: 0; color: #333;">Your Requests</h3>
            <?php if($unread_count > 0): ?>
            <span class="badge"><?= $unread_count ?> Unread</span>
            <?php endif; ?>
        </div>

        <?php if(count($notifications) > 0): ?>
            <?php foreach($notifications as $notif): ?>
            <div class="notification-item <?= $notif['status'] === 'unread' ? 'unread' : '' ?>">
                <div class="notif-title">
                    📧 <?= $notif['type'] === 'result_request' ? 'Result Request' : 'Notification' ?>
                    <span class="email-badge">✓ Email Sent</span>
                </div>
                <div class="notif-module">
                    <strong><?= htmlspecialchars($notif['course_name']) ?></strong> - 
                    <?= htmlspecialchars($notif['module_code']) ?> 
                    (<?= htmlspecialchars(substr($notif['module_name'], 0, 20)) ?>)
                </div>
                <div class="notif-message">
                    <?= htmlspecialchars($notif['message']) ?>
                </div>
                <div style="display: flex; justify-content: space-between;">
                    <span class="notif-time">
                        📅 <?= date('M d, Y H:i', strtotime($notif['created_at'])) ?>
                    </span>
                    <?php if($notif['status'] === 'unread'): ?>
                    <a href="?read_id=<?= $notif['id'] ?>" class="btn-mark-read">✓ Mark as Read</a>
                    <?php endif; ?>
                </div>
            </div>
            <?php endforeach; ?>
        <?php else: ?>
            <div class="no-notif">
                <p>✅ No notifications yet!</p>
            </div>
        <?php endif; ?>

        <div class="back-link">
            <a href="dashboard.php">← Back to Dashboard</a>
        </div>
    </div>
</div>

</body>
</html>