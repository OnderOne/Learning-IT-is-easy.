<?php
ini_set('display_errors', '0');
error_reporting(E_ALL);
session_start();

if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit;
}

require_once 'db.php';
$uid = (int)$_SESSION['user_id'];
$stmt = $pdo->prepare('SELECT * FROM users WHERE id = ?');
$stmt->execute([$uid]);
$user = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$user) {
    session_destroy();
    header('Location: login.php');
    exit;
}
$isAdmin = (strtolower(trim((string)($user['role'] ?? ''))) === 'admin') || ((int)($user['is_admin'] ?? 0) === 1);
$username = trim((string)($user['username'] ?? $user['name'] ?? 'Участник'));
if ($username === '') $username = 'Участник';

$pdo->exec("CREATE TABLE IF NOT EXISTS site_meetings (
    id INT AUTO_INCREMENT PRIMARY KEY,
    title VARCHAR(150) NOT NULL,
    meeting_url VARCHAR(1000) NOT NULL,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    created_by INT NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX (is_active)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
$pdo->exec("CREATE TABLE IF NOT EXISTS meeting_join_requests (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    meeting_id INT NOT NULL,
    user_id INT NOT NULL,
    username VARCHAR(100) NOT NULL,
    status ENUM('pending','approved','denied') NOT NULL DEFAULT 'pending',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uniq_meeting_user (meeting_id, user_id),
    INDEX idx_meeting_request_status (meeting_id, status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
$pdo->exec("CREATE TABLE IF NOT EXISTS meeting_chat_messages (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    meeting_id INT NOT NULL,
    user_id INT NOT NULL,
    username VARCHAR(100) NOT NULL,
    message VARCHAR(2000) NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_meeting_message (meeting_id, id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
$pdo->exec("CREATE TABLE IF NOT EXISTS meeting_user_bans (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id INT NULL,
    username VARCHAR(100) NOT NULL,
    banned_by INT NOT NULL,
    reason VARCHAR(255) NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uniq_banned_user_id (user_id),
    UNIQUE KEY uniq_banned_username (username)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

// The creator of the currently active meeting remains its site organizer, even if the role field is formatted differently.
$activeCreatorCheck = $pdo->query('SELECT created_by FROM site_meetings WHERE is_active = 1 ORDER BY id DESC LIMIT 1')->fetchColumn();
if ($activeCreatorCheck !== false && (int)$activeCreatorCheck === $uid) $isAdmin = true;

if (empty($_SESSION['meeting_csrf'])) $_SESSION['meeting_csrf'] = bin2hex(random_bytes(32));
$csrf = $_SESSION['meeting_csrf'];

function meeting_json(array $data, int $status = 200): void {
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}
function meeting_e($value): string {
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

// API endpoints for shared chat and meeting status.
if (isset($_GET['api'])) {
    $api = (string)$_GET['api'];
    if ($api === 'status') {
        $active = $pdo->query('SELECT id, title, meeting_url, created_by, created_at FROM site_meetings WHERE is_active = 1 ORDER BY id DESC LIMIT 1')->fetch(PDO::FETCH_ASSOC);
        $request = null;
        $requests = [];
        $q = $pdo->prepare('SELECT id FROM meeting_user_bans WHERE user_id = ? LIMIT 1');
        $q->execute([$uid]);
        $banned = (bool)$q->fetchColumn();
        if ($active) {
            $q = $pdo->prepare('SELECT status FROM meeting_join_requests WHERE meeting_id = ? AND user_id = ? LIMIT 1');
            $q->execute([(int)$active['id'], $uid]);
            $request = $q->fetchColumn() ?: null;
            if ($isAdmin) {
                $q = $pdo->prepare("SELECT id, user_id, username, created_at FROM meeting_join_requests WHERE meeting_id = ? AND status = 'pending' ORDER BY id ASC");
                $q->execute([(int)$active['id']]);
                $requests = $q->fetchAll(PDO::FETCH_ASSOC);
            }
        }
        meeting_json(['active' => (bool)$active, 'meeting' => $active ?: null, 'request_status' => $request, 'requests' => $requests, 'banned' => $banned]);
    }
    if ($api === 'request' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        if (!hash_equals($csrf, (string)($_SERVER['HTTP_X_CSRF_TOKEN'] ?? ''))) meeting_json(['error'=>'Сесія завершилася. Онови сторінку.'], 403);
        $body = json_decode(file_get_contents('php://input'), true) ?: [];
        $q = $pdo->prepare('SELECT id FROM meeting_user_bans WHERE user_id = ? LIMIT 1');
        $q->execute([$uid]);
        if ($q->fetchColumn()) meeting_json(['error'=>'Твій обліковий запис заблоковано для онлайн-зустрічей.'], 403);
        $meetingId = max(0, (int)($body['meeting_id'] ?? 0));
        $q = $pdo->prepare('SELECT id FROM site_meetings WHERE id = ? AND is_active = 1');
        $q->execute([$meetingId]);
        if (!$q->fetchColumn()) meeting_json(['error'=>'Зустріч уже завершена.'], 409);
        if ($isAdmin) meeting_json(['ok'=>true, 'status'=>'approved']);
        $q = $pdo->prepare("INSERT INTO meeting_join_requests (meeting_id,user_id,username,status) VALUES (?,?,?,'pending') ON DUPLICATE KEY UPDATE username=VALUES(username), status=IF(status='approved','approved','pending'), updated_at=CURRENT_TIMESTAMP");
        $q->execute([$meetingId, $uid, mb_substr($username, 0, 100)]);
        $q = $pdo->prepare('SELECT status FROM meeting_join_requests WHERE meeting_id=? AND user_id=?');
        $q->execute([$meetingId,$uid]);
        meeting_json(['ok'=>true,'status'=>$q->fetchColumn()]);
    }
    if (($api === 'approve' || $api === 'deny') && $_SERVER['REQUEST_METHOD'] === 'POST') {
        if (!$isAdmin) meeting_json(['error'=>'Доступ заборонено.'], 403);
        if (!hash_equals($csrf, (string)($_SERVER['HTTP_X_CSRF_TOKEN'] ?? ''))) meeting_json(['error'=>'Сесія завершилася. Онови сторінку.'], 403);
        $body = json_decode(file_get_contents('php://input'), true) ?: [];
        $requestId = max(0, (int)($body['request_id'] ?? 0));
        $status = $api === 'approve' ? 'approved' : 'denied';
        $q = $pdo->prepare("UPDATE meeting_join_requests r JOIN site_meetings m ON m.id=r.meeting_id SET r.status=?, r.updated_at=CURRENT_TIMESTAMP WHERE r.id=? AND r.status='pending' AND m.is_active=1");
        $q->execute([$status,$requestId]);
        meeting_json(['ok'=>true]);
    }
    if ($api === 'ban' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        if (!$isAdmin) meeting_json(['error'=>'Доступ заборонено.'], 403);
        if (!hash_equals($csrf, (string)($_SERVER['HTTP_X_CSRF_TOKEN'] ?? ''))) meeting_json(['error'=>'Сесія завершилася. Онови сторінку.'], 403);
        $body = json_decode(file_get_contents('php://input'), true) ?: [];
        $targetName = trim((string)($body['username'] ?? ''));
        if ($targetName === '' || mb_strlen($targetName) > 100) meeting_json(['error'=>'Не вдалося визначити обліковий запис учасника.'], 422);
        if (mb_strtolower($targetName) === mb_strtolower($username)) meeting_json(['error'=>'Не можна заблокувати власний обліковий запис.'], 422);
        $q = $pdo->prepare('SELECT id, username FROM users WHERE username = ? LIMIT 1');
        $q->execute([$targetName]);
        $target = $q->fetch(PDO::FETCH_ASSOC);
        if (!$target) meeting_json(['error'=>'За цим ім’ям не знайдено користувача сайту. Ім’я у Jitsi має збігатися з логіном.'], 404);
        $q = $pdo->prepare('INSERT INTO meeting_user_bans (user_id, username, banned_by, reason) VALUES (?, ?, ?, ?) ON DUPLICATE KEY UPDATE user_id=VALUES(user_id), username=VALUES(username), banned_by=VALUES(banned_by), reason=VALUES(reason), created_at=CURRENT_TIMESTAMP');
        $q->execute([(int)$target['id'], (string)$target['username'], $uid, 'Блокування адміністратором зустрічі']);
        $q = $pdo->prepare("UPDATE meeting_join_requests SET status='denied', updated_at=CURRENT_TIMESTAMP WHERE user_id=? AND status IN ('pending','approved')");
        $q->execute([(int)$target['id']]);
        meeting_json(['ok'=>true, 'username'=>(string)$target['username']]);
    }
    if ($api === 'messages') {
        $meetingId = max(0, (int)($_GET['meeting_id'] ?? 0));
        $after = max(0, (int)($_GET['after'] ?? 0));
        $check = $pdo->prepare('SELECT id FROM site_meetings WHERE id = ? AND is_active = 1');
        $check->execute([$meetingId]);
        if (!$check->fetchColumn()) meeting_json(['messages' => []]);
        $q = $pdo->prepare('SELECT id, user_id, username, message, created_at FROM meeting_chat_messages WHERE meeting_id = ? AND id > ? ORDER BY id ASC LIMIT 100');
        $q->execute([$meetingId, $after]);
        meeting_json(['messages' => $q->fetchAll(PDO::FETCH_ASSOC)]);
    }
    if ($api === 'send' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $sentCsrf = (string)($_SERVER['HTTP_X_CSRF_TOKEN'] ?? '');
        if (!hash_equals($csrf, $sentCsrf)) meeting_json(['error' => 'Сесія завершилася. Онови сторінку.'], 403);
        $body = json_decode(file_get_contents('php://input'), true) ?: [];
        $meetingId = max(0, (int)($body['meeting_id'] ?? 0));
        $message = trim((string)($body['message'] ?? ''));
        if ($message === '' || mb_strlen($message) > 2000) meeting_json(['error' => 'Повідомлення має містити від 1 до 2000 символів.'], 422);
        $check = $pdo->prepare('SELECT id FROM site_meetings WHERE id = ? AND is_active = 1');
        $check->execute([$meetingId]);
        if (!$check->fetchColumn()) meeting_json(['error' => 'Зустріч уже завершена.'], 409);
        $q = $pdo->prepare('INSERT INTO meeting_chat_messages (meeting_id, user_id, username, message) VALUES (?, ?, ?, ?)');
        $q->execute([$meetingId, $uid, mb_substr($username, 0, 100), $message]);
        meeting_json(['ok' => true]);
    }
    meeting_json(['error' => 'Невідомий запит.'], 404);
}

$msg = '';
$err = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['meeting_action'])) {
    if (!$isAdmin) {
        http_response_code(403);
        exit('Доступ заборонено.');
    }
    if (!hash_equals($csrf, (string)($_POST['csrf'] ?? ''))) {
        $err = 'Сесія форми завершилася. Онови сторінку.';
    } elseif ($_POST['meeting_action'] === 'start') {
        $title = trim((string)($_POST['title'] ?? ''));
        if ($title === '' || mb_strlen($title) > 150) {
            $err = 'Вкажи назву зустрічі (до 150 символів).';
        } else {
            try {
                $pdo->beginTransaction();
                $pdo->exec('UPDATE site_meetings SET is_active = 0 WHERE is_active = 1');
                $room = 'it-tasks-pro-' . bin2hex(random_bytes(10));
                $url = 'https://meet.jit.si/' . $room;
                $q = $pdo->prepare('INSERT INTO site_meetings (title, meeting_url, is_active, created_by) VALUES (?, ?, 1, ?)');
                $q->execute([$title, $url, $uid]);
                $pdo->commit();
                header('Location: meeting.php');
                exit;
            } catch (Throwable $e) {
                if ($pdo->inTransaction()) $pdo->rollBack();
                error_log('Meeting start error: ' . $e->getMessage());
                $err = 'Не вдалося запустити зустріч. Перевір журнал помилок PHP.';
            }
        }
    } elseif ($_POST['meeting_action'] === 'end') {
        try {
            $pdo->exec('UPDATE site_meetings SET is_active = 0 WHERE is_active = 1');
            $msg = 'Зустріч завершено.';
        } catch (Throwable $e) {
            error_log('Meeting end error: ' . $e->getMessage());
            $err = 'Не вдалося завершити зустріч.';
        }
    }
}

$meeting = $pdo->query('SELECT id, title, meeting_url, created_by, created_at FROM site_meetings WHERE is_active = 1 ORDER BY id DESC LIMIT 1')->fetch(PDO::FETCH_ASSOC);
$meetingHistory = $pdo->query('SELECT id, title, is_active, created_by, created_at, updated_at FROM site_meetings WHERE is_active = 0 ORDER BY id DESC LIMIT 12')->fetchAll(PDO::FETCH_ASSOC);
$totalMeetings = (int)$pdo->query('SELECT COUNT(*) FROM site_meetings')->fetchColumn();
$completedMeetings = (int)$pdo->query('SELECT COUNT(*) FROM site_meetings WHERE is_active = 0')->fetchColumn();
$creatorLookup = $pdo->prepare('SELECT username FROM users WHERE id = ? LIMIT 1');
foreach ($meetingHistory as &$historyItem) {
    $creatorLookup->execute([(int)$historyItem['created_by']]);
    $historyItem['creator_name'] = (string)($creatorLookup->fetchColumn() ?: ('Користувач #' . (int)$historyItem['created_by']));
}
unset($historyItem);
$requestStatus = null;
$q = $pdo->prepare('SELECT id FROM meeting_user_bans WHERE user_id = ? LIMIT 1');
$q->execute([$uid]);
$isBanned = (bool)$q->fetchColumn();
if ($meeting) {
    $q = $pdo->prepare('SELECT status FROM meeting_join_requests WHERE meeting_id=? AND user_id=? LIMIT 1');
    $q->execute([(int)$meeting['id'], $uid]);
    $requestStatus = $q->fetchColumn() ?: null;
}
$isMeetingCreator = $meeting && (int)$meeting['created_by'] === $uid;
$joined = $meeting && !$isBanned && isset($_GET['join']) && $_GET['join'] === '1' && ($isAdmin || $requestStatus === 'approved');

// Load the current user's exact theme format used by settings.php.
$theme = [];
try {
    $q = $pdo->prepare('SELECT settings_json FROM user_settings WHERE user_id = ? LIMIT 1');
    $q->execute([$uid]);
    $rawTheme = $q->fetchColumn();
    if ($rawTheme) {
        $decoded = json_decode((string)$rawTheme, true);
        if (is_array($decoded)) $theme = $decoded;
    }
} catch (Throwable $e) {
    // Older installations may not have saved settings yet.
}
$presetColors = [
    'dark' => ['bg-main'=>'#0b1020','bg-sidebar'=>'#10172a','bg-header'=>'#111a30','bg-card'=>'#151f35','bg-input'=>'#0c1427','bg-hover'=>'#202d49','bg-active'=>'#283b60','text-main'=>'#edf3ff','text-muted'=>'#96a6c5','text-accent'=>'#7c9cff','border'=>'#293957'],
    'light' => ['bg-main'=>'#f1f5fb','bg-sidebar'=>'#ffffff','bg-header'=>'#ffffff','bg-card'=>'#ffffff','bg-input'=>'#f5f7fc','bg-hover'=>'#e8eef9','bg-active'=>'#dce7ff','text-main'=>'#172033','text-muted'=>'#68758d','text-accent'=>'#3867e8','border'=>'#dce3ef'],
    'metal' => ['bg-main'=>'#17191e','bg-sidebar'=>'#202329','bg-header'=>'#252931','bg-card'=>'#292e36','bg-input'=>'#191c22','bg-hover'=>'#363d48','bg-active'=>'#424c5c','text-main'=>'#f0f2f5','text-muted'=>'#a4adb9','text-accent'=>'#a8c7ff','border'=>'#454c58'],
    'wood' => ['bg-main'=>'#211811','bg-sidebar'=>'#2c2017','bg-header'=>'#35271b','bg-card'=>'#3a2a1d','bg-input'=>'#251a12','bg-hover'=>'#493424','bg-active'=>'#59402c','text-main'=>'#fff3e3','text-muted'=>'#d0b69b','text-accent'=>'#e9b875','border'=>'#604630'],
    'sand' => ['bg-main'=>'#e9dfcc','bg-sidebar'=>'#f5eddf','bg-header'=>'#f8f1e5','bg-card'=>'#fff8ed','bg-input'=>'#eee4d3','bg-hover'=>'#e3d4bc','bg-active'=>'#d8c4a5','text-main'=>'#352b20','text-muted'=>'#766753','text-accent'=>'#98672e','border'=>'#d8c7ac'],
    'neon' => ['bg-main'=>'#090d16','bg-sidebar'=>'#0e1421','bg-header'=>'#101a29','bg-card'=>'#121e2e','bg-input'=>'#080e19','bg-hover'=>'#172d3b','bg-active'=>'#1c414a','text-main'=>'#eaffff','text-muted'=>'#8baeb8','text-accent'=>'#41f5c4','border'=>'#244651'],
];
$selectedTheme = (string)($theme['theme'] ?? 'dark');
$colors = $presetColors[$selectedTheme] ?? $presetColors['dark'];
$customColors = is_array($theme['custom'] ?? null) ? $theme['custom'] : [];
$validColor = static function($value): bool {
    return is_string($value) && (bool)preg_match('/^(#[0-9a-fA-F]{3,8}|rgba?\([0-9.,%\s]+\)|hsla?\([0-9.,%\s]+\))$/', trim($value));
};
foreach ($customColors as $key => $value) {
    if (array_key_exists($key, $colors) && $validColor($value)) $colors[$key] = $value;
}
$themeVars = [];
foreach ($colors as $key => $value) $themeVars['--' . $key] = $value;
$themeVars['--site-bg-image'] = 'none';
$themeVars['--header-bg-image'] = 'none';
$themeVars['--card-bg-image'] = 'none';
$images = is_array($theme['images'] ?? null) ? $theme['images'] : [];
$imageSlots = ['site'=>'--site-bg-image','header'=>'--header-bg-image','card'=>'--card-bg-image'];
foreach ($imageSlots as $slot => $cssVar) {
    $image = (string)($images[$slot] ?? '');
    if ($image !== '' && preg_match('~^uploads/theme_images/' . preg_quote((string)$uid, '~') . '/[a-f0-9]{32}\.(jpg|png|webp|gif)$~', $image)) {
        $themeVars[$cssVar] = 'url("' . $image . '")';
    }
}
$font = isset($theme['font']) && is_string($theme['font']) ? preg_replace('/[^a-zA-Z0-9 ,"\'-]/', '', $theme['font']) : 'Arial';
$fontSize = isset($theme['fontSize']) && is_numeric($theme['fontSize']) ? max(12, min(24, (int)$theme['fontSize'])) : 16;

?>
<!DOCTYPE html>
<html lang="uk">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Онлайн-зустріч · IT Tasks Pro</title>
<link rel="stylesheet" href="style.css">
<style>
:root{<?php foreach($themeVars as $key=>$value): ?><?= $key ?>:<?= meeting_e($value) ?>;<?php endforeach; ?>--site-font:<?= meeting_e($font) ?>;--site-font-size:<?= (int)$fontSize ?>px;}
*{box-sizing:border-box}html,body{margin:0;width:100%;min-height:100%;}body{background:var(--bg-main);background-image:var(--site-bg-image);background-position:center;background-size:cover;background-attachment:fixed;color:var(--text-main);font-family:var(--site-font),Arial,sans-serif;font-size:var(--site-font-size);}
.meet-app{height:100dvh;min-height:570px;width:100%;padding:12px 14px;display:flex;flex-direction:column;gap:10px;overflow:hidden;}
.meet-head{flex:none;display:flex;align-items:center;justify-content:space-between;gap:12px;flex-wrap:wrap;padding:0 2px;}.meet-head h1{margin:2px 0;font-size:clamp(20px,2vw,29px);}.meet-muted{color:var(--text-muted);}.meet-btn{display:inline-flex;align-items:center;justify-content:center;gap:7px;border:1px solid var(--border);border-radius:10px;padding:9px 12px;background:var(--bg-card);color:var(--text-main);font:inherit;font-size:12px;font-weight:750;cursor:pointer;text-decoration:none;white-space:nowrap;}.meet-btn:hover{border-color:var(--text-accent);background:var(--bg-hover)}.meet-btn:disabled{opacity:.55;cursor:not-allowed}.meet-btn.primary{background:var(--text-accent);color:#fff;border-color:transparent}.meet-btn.danger{background:#a92f3e;color:#fff;border-color:transparent}.meet-card{min-height:0;flex:1;padding:14px;border:1px solid var(--border);border-radius:16px;background:var(--bg-card);display:flex;flex-direction:column;overflow:hidden;}
.meet-form{width:min(520px,100%);margin:22px auto 0;text-align:left}.meet-form input{width:100%;padding:12px;border:1px solid var(--border);border-radius:10px;background:var(--bg-input);color:var(--text-main);font:inherit}.meet-form label{display:block;margin:12px 0}.meet-form label span{display:block;margin-bottom:6px;font-size:13px;font-weight:700}.meet-notice{padding:10px 12px;border-radius:10px;flex:none}.meet-error{background:#4b2027;color:#ffdce0}.meet-success{background:#173c30;color:#c5ffe3}.meet-status{display:inline-flex;align-items:center;gap:7px;padding:5px 9px;border-radius:30px;background:var(--bg-hover);color:var(--text-muted);font-size:11px}.meet-dot{width:8px;height:8px;border-radius:50%;background:#f59e0b}.meet-dot.live{background:#35d39a}.room-empty{margin:auto;max-width:800px;width:100%;height:auto;min-height:0;display:block;text-align:center;padding:35px 20px;}
.live-head{display:flex;align-items:center;justify-content:space-between;gap:12px;flex-wrap:wrap;flex:none;padding:0 2px 11px;border-bottom:1px solid var(--border);margin-bottom:11px}.live-title{min-width:0}.live-title h2{font-size:18px;margin:5px 0 1px;overflow-wrap:anywhere}.room-actions{display:flex;align-items:center;gap:8px;flex-wrap:wrap}.request-status-text{font-size:12px;color:var(--text-muted)}
.meeting-room{position:relative;display:grid;grid-template-columns:270px minmax(260px,1fr) 285px;gap:10px;flex:1;min-height:0;overflow:hidden}.meeting-room.chat-hidden{grid-template-columns:0 minmax(260px,1fr) 285px}.chat-panel,.right-panel{min-width:0;min-height:0;display:flex;flex-direction:column;overflow:hidden;border:1px solid var(--border);border-radius:13px;background:var(--bg-sidebar);}.meeting-room.chat-hidden .chat-panel{display:none}.panel-head{height:48px;flex:none;display:flex;align-items:center;justify-content:space-between;gap:6px;padding:8px 10px;background:var(--bg-header);border-bottom:1px solid var(--border);font-weight:800;font-size:13px}.panel-head-actions{display:flex;gap:5px}.chat-mini{border:1px solid var(--border);border-radius:7px;background:var(--bg-hover);color:var(--text-main);padding:4px 8px;cursor:pointer}.chat-messages{flex:1;min-height:0;overflow:auto;padding:10px;display:flex;flex-direction:column;gap:9px}.chat-msg{max-width:96%;align-self:flex-start;padding:8px 10px;border-radius:10px;background:var(--bg-hover);overflow-wrap:anywhere;font-size:12px}.chat-msg.mine{align-self:flex-end;background:color-mix(in srgb,var(--text-accent) 22%,var(--bg-hover))}.chat-msg strong{display:block;color:var(--text-accent);font-size:11px;margin-bottom:3px}.chat-msg small{display:block;color:var(--text-muted);font-size:9px;margin-top:4px}.chat-send{flex:none;display:flex;gap:6px;padding:8px;border-top:1px solid var(--border)}.chat-send input{flex:1;min-width:0;width:50%;border:1px solid var(--border);border-radius:9px;padding:9px;background:var(--bg-input);color:var(--text-main);font:inherit;font-size:12px}.chat-send button{border:0;border-radius:9px;padding:0 11px;background:var(--text-accent);color:#fff;font-weight:800;cursor:pointer}.video-area{position:relative;min-width:0;min-height:0;overflow:hidden;border:1px solid var(--border);border-radius:13px;background:#080d18}.jitsi-container{width:100%;height:100%;min-height:0;background:#080d18}.jitsi-container iframe{width:100%!important;height:100%!important;border:0!important}.video-error{padding:25px;color:#fff;font-size:13px}.room-foot{flex:none;padding:8px 2px 0;font-size:10px;color:var(--text-muted);line-height:1.4;display:flex;justify-content:space-between;gap:10px;flex-wrap:wrap}
.right-tabs{display:flex;gap:5px;padding:7px;border-bottom:1px solid var(--border);background:var(--bg-header);flex:none}.right-tab{flex:1;min-width:0;border:1px solid transparent;border-radius:8px;background:transparent;color:var(--text-muted);padding:8px 5px;font:inherit;font-size:11px;font-weight:800;cursor:pointer}.right-tab.active{background:var(--bg-active);color:var(--text-main);border-color:var(--border)}.right-pane{min-height:0;flex:1;overflow:auto;padding:8px}.right-pane.hidden-pane{display:none}.pane-caption{font-size:10px;line-height:1.45;color:var(--text-muted);padding:3px 3px 9px}.join-requests{display:flex;flex-direction:column;gap:8px}.join-request,.participant-card{padding:9px;border:1px solid var(--border);border-radius:10px;background:var(--bg-card);}.join-request-top,.participant-row{display:flex;align-items:center;gap:8px;min-width:0}.person-avatar{height:30px;width:30px;flex:none;display:grid;place-items:center;border-radius:50%;background:var(--bg-active);color:var(--text-main);font-size:12px;font-weight:900}.person-copy{min-width:0;flex:1}.person-name{font-size:12px;font-weight:800;overflow-wrap:anywhere}.person-meta{font-size:10px;color:var(--text-muted);margin-top:3px;overflow-wrap:anywhere}.join-request-actions{display:flex;gap:6px;margin-top:8px}.join-request-actions .meet-btn{flex:1;padding:7px 5px;font-size:11px}.participant-actions{display:flex;flex-wrap:wrap;gap:5px;margin-top:8px}.participant-actions .meet-btn{padding:6px 7px;font-size:10px;white-space:normal}.participant-count{font-size:10px;color:var(--text-muted);padding:0 3px 8px}.mod-badge{font-size:10px;padding:5px 7px;border-radius:7px;background:var(--bg-hover);color:var(--text-muted)}.mod-badge.is-mod{background:#173c30;color:#c5ffe3}.room-empty h2{margin-top:14px}.meet-hidden{display:none!important}.chat-open{display:none}.toolbar-hint{font-size:10px;color:var(--text-muted)}
.meet-dashboard{height:auto;min-height:100dvh;overflow:visible;gap:14px;padding:22px clamp(12px,2.4vw,32px) 28px}.meet-dashboard .room-empty{flex:none;max-width:none;margin:0;display:grid;grid-template-columns:minmax(0,1fr) minmax(300px,.72fr);align-items:center;gap:20px;text-align:left;padding:clamp(20px,3vw,34px);background:linear-gradient(130deg,color-mix(in srgb,var(--bg-card) 93%,var(--text-accent)),var(--bg-card));position:relative;isolation:isolate}.meet-dashboard .room-empty:before{content:"";position:absolute;right:-35px;top:-68px;width:240px;height:240px;border-radius:50%;background:color-mix(in srgb,var(--text-accent) 11%,transparent);filter:blur(3px);z-index:-1;pointer-events:none}.room-empty-copy{min-width:0}.room-empty-copy h2{font-size:clamp(23px,3vw,34px);line-height:1.15;margin:16px 0 10px;letter-spacing:-.6px}.room-empty-copy p{max-width:560px;line-height:1.7;font-size:13px}.room-empty .meet-form{width:100%;max-width:none;margin:0;padding:16px;border:1px solid var(--border);border-radius:14px;background:color-mix(in srgb,var(--bg-sidebar) 88%,transparent)}.room-empty .meet-form label{margin:0 0 12px}.room-empty .meet-form button{width:100%;padding:12px 15px}.room-empty-stat{display:flex;gap:8px;flex-wrap:wrap;margin-top:18px}.room-stat{display:flex;gap:9px;align-items:center;padding:10px 12px;border:1px solid var(--border);border-radius:11px;background:color-mix(in srgb,var(--bg-sidebar) 80%,transparent)}.room-stat-icon{height:32px;width:32px;display:grid;place-items:center;border-radius:9px;background:var(--bg-hover);font-size:15px}.room-stat b{display:block;font-size:16px;line-height:1.1}.room-stat small{display:block;margin-top:4px;color:var(--text-muted);font-size:10px}.history-section{flex:none;border:1px solid var(--border);border-radius:17px;background:var(--bg-card);overflow:hidden}.history-header{display:flex;align-items:flex-end;justify-content:space-between;gap:15px;flex-wrap:wrap;padding:19px 20px 15px;border-bottom:1px solid var(--border);background:linear-gradient(120deg,var(--bg-header),color-mix(in srgb,var(--bg-header) 78%,var(--text-accent)))}.history-eyebrow{color:var(--text-accent);font-size:10px;letter-spacing:1.4px;font-weight:900}.history-header h2{font-size:20px;letter-spacing:-.3px;margin:5px 0}.history-header p{font-size:12px;color:var(--text-muted);margin:0}.history-tools{display:flex;align-items:center;gap:8px}.history-search{width:min(250px,50vw);padding:10px 12px;border:1px solid var(--border);border-radius:10px;background:var(--bg-input);color:var(--text-main);font:inherit;font-size:12px;outline:none}.history-search:focus{border-color:var(--text-accent);box-shadow:0 0 0 3px color-mix(in srgb,var(--text-accent) 15%,transparent)}.history-count{font-size:11px;color:var(--text-muted);white-space:nowrap}.history-grid{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:12px;padding:15px}.history-card{min-width:0;display:flex;flex-direction:column;gap:12px;padding:15px;border:1px solid var(--border);border-radius:13px;background:var(--bg-sidebar);transition:border-color .18s,transform .18s,background .18s}.history-card:hover{border-color:var(--text-accent);background:var(--bg-hover);transform:translateY(-2px)}.history-card-top{display:flex;align-items:center;justify-content:space-between;gap:8px}.history-icon{width:39px;height:39px;display:grid;place-items:center;flex:none;border-radius:11px;background:color-mix(in srgb,var(--text-accent) 15%,var(--bg-hover));font-size:18px}.history-state{display:inline-flex;align-items:center;gap:6px;padding:5px 8px;border-radius:99px;font-size:10px;font-weight:850;background:var(--bg-hover);color:var(--text-muted);white-space:nowrap}.history-state:before{content:"";height:6px;width:6px;border-radius:50%;background:currentColor}.history-state.is-live{background:#143c31;color:#6ee7b7}.history-card h3{font-size:14px;line-height:1.45;margin:0;overflow-wrap:anywhere}.history-meta{display:flex;flex-direction:column;gap:7px;color:var(--text-muted);font-size:11px}.history-meta span{display:flex;align-items:center;gap:7px;overflow-wrap:anywhere}.history-meta strong{color:var(--text-main);font-weight:650}.history-card-footer{margin-top:auto;padding-top:11px;border-top:1px solid var(--border);display:flex;align-items:center;justify-content:space-between;gap:8px}.history-kind{font-size:10px;color:var(--text-muted)}.history-card[hidden]{display:none!important}.history-empty{grid-column:1/-1;padding:34px 14px;text-align:center;color:var(--text-muted);font-size:12px;line-height:1.7}.history-empty .history-empty-icon{font-size:28px;display:block;margin-bottom:7px}.history-footer{padding:0 17px 15px;color:var(--text-muted);font-size:10px}

@media(max-width:1100px){.meeting-room{grid-template-columns:230px minmax(220px,1fr) 250px}.meeting-room.chat-hidden{grid-template-columns:0 minmax(220px,1fr) 250px}.meet-app{padding:9px}.meet-card{padding:10px}.panel-head{font-size:12px}}
@media(max-width:780px){.meet-app{height:auto;min-height:100dvh;overflow:visible}.meet-card{flex:none;min-height:720px;overflow:visible}.meet-dashboard .meet-card{min-height:0;overflow:visible}.meet-dashboard .room-empty{grid-template-columns:minmax(0,1fr);gap:16px;padding:22px 17px}.meet-dashboard .room-empty .meet-form{margin-top:4px}.history-grid{grid-template-columns:repeat(2,minmax(0,1fr));padding:11px}.history-header{padding:16px}.history-tools{width:100%}.history-search{width:100%;flex:1}.meeting-room,.meeting-room.chat-hidden{grid-template-columns:minmax(0,1fr);grid-template-rows:minmax(350px,55vh) 260px 300px;overflow:visible}.meeting-room.chat-hidden{grid-template-rows:minmax(350px,55vh) 300px}.meeting-room.chat-hidden .chat-panel{display:none}.video-area{min-height:350px}.meet-head{align-items:flex-start}.live-head{align-items:flex-start}.right-panel,.chat-panel{min-height:230px}.room-foot{padding-bottom:8px}}@media(max-width:480px){.history-grid{grid-template-columns:minmax(0,1fr)}.history-header h2{font-size:18px}.room-empty-stat{display:grid;grid-template-columns:repeat(2,minmax(0,1fr))}.history-card{padding:13px}}
</style>
</head>
<body>
<main class="meet-app <?= $joined ? 'meet-live-app' : 'meet-dashboard' ?>">
<header class="meet-head">
  <div><div class="meet-muted" style="font-size:10px;letter-spacing:1.4px;font-weight:800">IT TASKS PRO · COMMUNITY</div><h1>📹 Онлайн-зустріч</h1></div>
  <a class="meet-btn" href="index.php">← На головну</a>
</header>
<?php if ($msg): ?><div class="meet-notice meet-success"><?=meeting_e($msg)?></div><?php endif; ?>
<?php if ($err): ?><div class="meet-notice meet-error"><?=meeting_e($err)?></div><?php endif; ?>
<?php if (!$meeting): ?>
<section class="meet-card room-empty">
  <div class="room-empty-copy">
    <div class="meet-status"><span class="meet-dot"></span> Немає активної зустрічі</div>
    <h2>Твій простір для онлайн-зустрічей</h2>
    <p class="meet-muted">Тут зберігається історія створених зустрічей. Коли нова зустріч запуститься, вона з’явиться у списку зі статусом «В ефірі».</p>
    <div class="room-empty-stat">
      <div class="room-stat"><span class="room-stat-icon">🎥</span><span><b><?= (int)$totalMeetings ?></b><small>Усього створено</small></span></div>
      <div class="room-stat"><span class="room-stat-icon">🗂️</span><span><b><?= (int)$completedMeetings ?></b><small>Завершено</small></span></div>
    </div>
    <?php if ($isBanned): ?><div class="meet-notice meet-error" style="margin-top:14px">Твій обліковий запис заблоковано для онлайн-зустрічей.</div><?php endif; ?>
  </div>
  <?php if ($isAdmin): ?><form class="meet-form" method="post"><div style="font-size:10px;letter-spacing:1.2px;color:var(--text-accent);font-weight:900;margin-bottom:8px">НОВА ПОДІЯ</div><h3 style="margin:0 0 4px;font-size:17px">Створити зустріч</h3><p class="meet-muted" style="margin:0 0 15px;font-size:12px;line-height:1.6">Задай назву — після запуску учасники зможуть запитувати доступ.</p><input type="hidden" name="csrf" value="<?=meeting_e($csrf)?>"><input type="hidden" name="meeting_action" value="start"><label><span>Назва зустрічі</span><input name="title" maxlength="150" required placeholder="Наприклад, IT Tasks Pro — загальна зустріч"></label><button class="meet-btn primary" type="submit">＋ Створити онлайн-зустріч</button></form><?php else: ?><div class="meet-form"><div style="font-size:34px;margin-bottom:8px">🗓️</div><h3 style="margin:0 0 8px;font-size:17px">Поки все спокійно</h3><p class="meet-muted" style="font-size:12px;line-height:1.6;margin:0">Переглянь минулі зустрічі нижче або повернись пізніше, коли організатор запустить нову.</p></div><?php endif; ?>
</section>
<?php else: ?>
<section class="meet-card" id="liveCard">
  <div class="live-head">
    <div class="live-title"><div class="meet-status"><span class="meet-dot live"></span> Зустріч активна</div><h2><?=meeting_e($meeting['title'])?></h2></div>
    <div class="room-actions">
    <?php if ($isBanned): ?><span class="meet-notice meet-error">Доступ до зустрічей заблоковано адміністратором.</span>
    <?php elseif (!$joined): ?>
      <?php if ($isAdmin): ?><a class="meet-btn primary" href="meeting.php?join=1">Відкрити кімнату</a><?php else: ?><button class="meet-btn primary" id="requestJoin" type="button"><?= $requestStatus === 'pending' ? 'Запит надіслано' : ($requestStatus === 'denied' ? 'Повторити запит' : 'Запросити приєднання') ?></button><span class="request-status-text" id="requestStatusText"><?= $requestStatus === 'pending' ? 'Очікуємо підтвердження адміністратора…' : ($requestStatus === 'denied' ? 'Попередній запит відхилено.' : 'Потрібне підтвердження адміністратора.') ?></span><?php endif; ?>
    <?php else: ?><button class="meet-btn" id="toggleChatTop" type="button">💬 Сховати чат</button><button class="meet-btn" id="showChatTop" type="button" style="display:none">💬 Показати чат</button><?php endif; ?>
    <?php if ($isAdmin): ?><form method="post" onsubmit="return confirm('Завершити зустріч для всіх?')"><input type="hidden" name="csrf" value="<?=meeting_e($csrf)?>"><input type="hidden" name="meeting_action" value="end"><button class="meet-btn danger" type="submit">Завершити</button></form><?php endif; ?>
    </div>
  </div>
  <?php if ($isAdmin && $joined): ?><div class="meet-notice" style="display:flex;align-items:center;justify-content:space-between;gap:10px;flex-wrap:wrap;background:var(--bg-hover);margin-bottom:10px;font-size:12px"><span><b>Важливо:</b> права модератора відеокімнати видає сам Jitsi, а не IT Tasks Pro. Якщо з’явиться повідомлення «no moderators have yet arrived», натисни <b>Log-in</b> у Jitsi.</span><a class="meet-btn primary" href="<?=meeting_e((string)$meeting['meeting_url'])?>" target="_blank" rel="noopener noreferrer">Відкрити Jitsi окремо</a></div><?php endif; ?>
  <?php if ($isAdmin && !$joined && !$isBanned): ?><div style="padding:10px 0"><h3 style="margin:0 0 6px;font-size:14px">Запити на приєднання</h3><div class="meet-muted" style="font-size:12px;margin-bottom:8px">Відкрий кімнату, щоб керувати запитами та учасниками.</div><div class="join-requests" id="joinRequests"><div class="meet-muted">Перевіряємо запити…</div></div></div><?php endif; ?>
  <?php if ($joined): ?>
  <div class="meeting-room" id="meetingRoom">
    <aside class="chat-panel" id="chatPanel" aria-label="Чат зустрічі">
      <div class="panel-head"><span>💬 Чат зустрічі</span><span class="panel-head-actions"><button class="chat-mini" id="chatCollapse" type="button" title="Згорнути чат">−</button></span></div>
      <div class="chat-messages" id="chatMessages" aria-live="polite"><div class="meet-muted" id="chatLoading">Завантаження повідомлень…</div></div>
      <form class="chat-send" id="chatForm"><input id="chatInput" maxlength="2000" autocomplete="off" placeholder="Написати повідомлення…" aria-label="Повідомлення"><button type="submit" aria-label="Надіслати">➤</button></form>
    </aside>
    <section class="video-area" aria-label="Відеозустріч"><div class="jitsi-container" id="jitsi-container"><div class="video-error">Підключення до відеозустрічі…</div></div></section>
    <aside class="right-panel" aria-label="Учасники та запити">
      <div class="right-tabs">
        <button type="button" class="right-tab <?= $isAdmin ? '' : 'active' ?>" id="participantsTab" data-tab="participants">👥 Учасники <span id="participantCount">0</span></button>
        <?php if ($isAdmin): ?><button type="button" class="right-tab active" id="requestsTab" data-tab="requests">🔔 Запити <span id="requestCount">0</span></button><?php endif; ?>
      </div>
      <div class="right-pane <?= $isAdmin ? 'hidden-pane' : '' ?>" id="participantsPane"><div class="participant-count" id="moderatorStatus">Завантаження учасників…</div><div id="participantsList" class="join-requests"><div class="meet-muted">Підключаємо список учасників…</div></div></div>
      <?php if ($isAdmin): ?><div class="right-pane" id="requestsPane"><div class="pane-caption">Підтверджуй запити на вхід. Після прийняття користувач зможе відкрити кімнату.</div><div id="joinRequests" class="join-requests"><div class="meet-muted">Перевіряємо запити…</div></div></div><?php endif; ?>
    </aside>
  </div>
  <div class="room-foot"><span>Кнопки мікрофона, камери й демонстрації екрана розташовані знизу у відеопанелі.</span><span>Голосове керування іншими доступне лише модератору Jitsi.</span></div>
  <?php endif; ?>
</section>
<?php endif; ?>
<?php if (!$joined): ?>
<section class="history-section" aria-label="Історія онлайн-зустрічей">
  <div class="history-header">
    <div><div class="history-eyebrow">IT TASKS PRO · MEETINGS</div><h2>Історія зустрічей</h2><p>Збережені зустрічі, організатори та час проведення.</p></div>
    <div class="history-tools"><input class="history-search" id="historySearch" type="search" placeholder="⌕  Знайти зустріч…" aria-label="Знайти зустріч"><span class="history-count" id="historyCount"><?= count($meetingHistory) ?> записів</span></div>
  </div>
  <div class="history-grid" id="historyGrid">
    <?php if (!$meetingHistory): ?>
      <div class="history-empty"><span class="history-empty-icon">🗓️</span><?php if ($meeting): ?>Попередніх зустрічей поки немає.<br>Активна зустріч показана вище.<?php elseif ($totalMeetings > 0): ?>Історія попередніх зустрічей порожня.<?php else: ?>Поки що зустрічей немає.<br>Створи першу зустріч, і вона з’явиться тут після завершення.<?php endif; ?></div>
    <?php else: foreach ($meetingHistory as $item): ?>
      <article class="history-card" data-search="<?=meeting_e(mb_strtolower((string)$item['title'].' '.$item['creator_name']))?>">
        <div class="history-card-top"><span class="history-icon">🎥</span><span class="history-state <?= (int)$item['is_active']===1?'is-live':'' ?>"><?= (int)$item['is_active']===1?'В ефірі':'Завершена' ?></span></div>
        <h3><?=meeting_e($item['title'])?></h3>
        <div class="history-meta"><span>👤 <strong><?=meeting_e($item['creator_name'])?></strong></span><span>🕒 Створена: <strong><?=meeting_e(date('d.m.Y · H:i', strtotime((string)$item['created_at'])))?></strong></span><?php if ((int)$item['is_active']===0): ?><span>✓ Завершена: <strong><?=meeting_e(date('d.m.Y · H:i', strtotime((string)$item['updated_at'])))?></strong></span><?php endif; ?></div>
        <div class="history-card-footer"><span class="history-kind">ЗУСТРІЧ #<?= (int)$item['id'] ?></span><?php if ((int)$item['is_active']===1): ?><?php if ($isAdmin): ?><a class="meet-btn primary" href="meeting.php?join=1">Відкрити →</a><?php else: ?><button class="meet-btn primary history-request" type="button" data-meeting-id="<?= (int)$item['id'] ?>">Приєднатися →</button><?php endif; ?><?php else: ?><span class="history-kind">Запис збережено</span><?php endif; ?></div>
      </article>
    <?php endforeach; endif; ?>
    <div class="history-empty" id="historyNoResults" hidden><span class="history-empty-icon">🔎</span>Нічого не знайдено за цим запитом.</div>
  </div>
  <div class="history-footer">Показано останні <?= count($meetingHistory) ?> завершених зустрічей. Старі записи зберігаються в базі даних.</div>
</section>
<?php endif; ?>
</main>
<?php if (!$joined): ?>
<script>
const historySearch = document.getElementById('historySearch');
if (historySearch) historySearch.addEventListener('input', () => {
  const term = historySearch.value.trim().toLocaleLowerCase();
  const cards = [...document.querySelectorAll('.history-card')];
  let visible = 0;
  cards.forEach(card => { const show = (card.dataset.search || '').includes(term); card.hidden = !show; if (show) visible++; });
  const noResults = document.getElementById('historyNoResults'); if (noResults) noResults.hidden = visible !== 0;
  const count = document.getElementById('historyCount'); if (count) count.textContent = visible + (visible === 1 ? ' запис' : ' записів');
});
document.querySelectorAll('.history-request').forEach(button => button.addEventListener('click', async () => {
  button.disabled = true;
  try {
    const response = await fetch('meeting.php?api=request', {method:'POST', headers:{'Content-Type':'application/json','X-CSRF-Token':<?=json_encode($csrf)?>}, body:JSON.stringify({meeting_id:Number(button.dataset.meetingId)})});
    const data = await response.json();
    if (!response.ok) throw new Error(data.error || 'Не вдалося надіслати запит.');
    if (data.status === 'approved') location.href = 'meeting.php?join=1';
    else { button.textContent = data.status === 'denied' ? 'Відхилено' : 'Запит надіслано'; button.disabled = true; }
  } catch (error) { alert(error.message || 'Помилка з’єднання.'); button.disabled = false; }
}));
</script>
<?php endif; ?>
<?php if ($meeting && $joined): ?>
<script src="https://meet.jit.si/external_api.js"></script>
<script>
const meetingId = <?= (int)$meeting['id'] ?>;
const myUserId = <?= (int)$uid ?>;
const myUsername = <?= json_encode($username, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?>;
const isAdmin = <?= $isAdmin ? 'true' : 'false' ?>;
const csrfToken = <?= json_encode($csrf) ?>;
const roomUrl = <?= json_encode((string)$meeting['meeting_url'], JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?>;
const roomName = (() => { try { return new URL(roomUrl).pathname.replace(/^\/+/, ''); } catch(e) { return ''; } })();
let jitsiApi = null;
let jitsiIsModerator = false;
let localJitsiId = null;
const participants = new Map();
const localMutedParticipants = new Set();
const joinButton = document.getElementById('requestJoin');
const chatPanel = document.getElementById('chatPanel');
const chatMessages = document.getElementById('chatMessages');
const chatInput = document.getElementById('chatInput');
let lastMessageId = 0;
let pollBusy = false;

function safeText(v) { return String(v == null ? '' : v); }
function initials(name) { const t=safeText(name).trim(); return (t.charAt(0)||'?').toUpperCase(); }
function showJitsiMessage(text) { const el=document.getElementById('jitsi-container'); if(el && !window.JitsiMeetExternalAPI) el.innerHTML='<div class="video-error"></div>'; if(el && !window.JitsiMeetExternalAPI) el.firstElementChild.textContent=text; }
function switchRightTab(name) {
  document.querySelectorAll('.right-tab').forEach(b=>b.classList.toggle('active',b.dataset.tab===name));
  const pp=document.getElementById('participantsPane'), rp=document.getElementById('requestsPane');
  if(pp) pp.classList.toggle('hidden-pane',name!=='participants');
  if(rp) rp.classList.toggle('hidden-pane',name!=='requests');
}
const pTab=document.getElementById('participantsTab'); if(pTab) pTab.addEventListener('click',()=>switchRightTab('participants'));
const rTab=document.getElementById('requestsTab'); if(rTab) rTab.addEventListener('click',()=>switchRightTab('requests'));

if (joinButton) joinButton.addEventListener('click', async () => {
  joinButton.disabled = true;
  try {
    const r = await fetch('meeting.php?api=request', {method:'POST',headers:{'Content-Type':'application/json','X-CSRF-Token':csrfToken},body:JSON.stringify({meeting_id:meetingId})});
    const d = await r.json(); if (!r.ok) throw new Error(d.error || 'Не вдалося надіслати запит.');
    if (d.status === 'approved') location.href='meeting.php?join=1';
    else { joinButton.textContent='Запит надіслано'; document.getElementById('requestStatusText').textContent='Очікуємо підтвердження адміністратора…'; }
  } catch(e) { alert(e.message || 'Помилка з’єднання.'); }
  finally { joinButton.disabled=false; }
});

function renderRequests(requests) {
  const list=document.getElementById('joinRequests'); if(!list) return;
  const badge=document.getElementById('requestCount'); if(badge) badge.textContent=String((requests||[]).length);
  list.replaceChildren();
  if(!requests || !requests.length){const empty=document.createElement('div');empty.className='meet-muted';empty.style.fontSize='12px';empty.textContent='Наразі ніхто не очікує підтвердження.';list.appendChild(empty);return;}
  requests.forEach(req=>{
    const card=document.createElement('div');card.className='join-request';
    const row=document.createElement('div');row.className='join-request-top';
    const avatar=document.createElement('div');avatar.className='person-avatar';avatar.textContent=initials(req.username);
    const name=document.createElement('div');name.className='person-copy';
    const title=document.createElement('div');title.className='person-name';title.textContent=req.username||'Учасник';
    const meta=document.createElement('div');meta.className='person-meta';meta.textContent='Очікує на допуск';name.append(title,meta);row.append(avatar,name);card.append(row);
    const actions=document.createElement('div');actions.className='join-request-actions';
    [['approve','✓ Прийняти','primary'],['deny','✕ Відхилити','']].forEach(([action,label,sty])=>{const b=document.createElement('button');b.type='button';b.className='meet-btn '+sty;b.textContent=label;b.addEventListener('click',async()=>{b.disabled=true;try{const resp=await fetch('meeting.php?api='+action,{method:'POST',headers:{'Content-Type':'application/json','X-CSRF-Token':csrfToken},body:JSON.stringify({request_id:Number(req.id)})});const result=await resp.json();if(!resp.ok)throw new Error(result.error||'Не вдалося змінити запит.');await refreshMeetingStatus();}catch(e){alert(e.message);b.disabled=false;}});actions.appendChild(b);});
    card.append(actions);list.appendChild(card);
  });
}
async function refreshMeetingStatus() {
  try {
    const r=await fetch('meeting.php?api=status',{cache:'no-store'});const d=await r.json();
    if(d.banned){if(jitsiApi){try{jitsiApi.executeCommand('hangup');}catch(e){}}alert('Доступ до онлайн-зустрічей заблоковано адміністратором.');location.href='meeting.php';return;}
    if(!d.active||!d.meeting||Number(d.meeting.id)!==meetingId){if(jitsiApi){try{jitsiApi.dispose();}catch(e){}}location.href='meeting.php';return;}
    if(joinButton){if(d.request_status==='approved'){location.href='meeting.php?join=1';return;}if(d.request_status==='pending'){joinButton.textContent='Запит надіслано';const t=document.getElementById('requestStatusText');if(t)t.textContent='Очікуємо підтвердження адміністратора…';}else if(d.request_status==='denied'){joinButton.textContent='Повторити запит';const t=document.getElementById('requestStatusText');if(t)t.textContent='Запит відхилено. Можна надіслати його ще раз.';}}
    if(isAdmin)renderRequests(d.requests||[]);
  } catch(e) {}
}

function addMessage(m) {
  if(!m||Number(m.id)<=lastMessageId)return;lastMessageId=Number(m.id);
  const empty=document.getElementById('chatLoading');if(empty)empty.remove();
  const item=document.createElement('div');item.className='chat-msg'+(Number(m.user_id)===myUserId?' mine':'');
  const name=document.createElement('strong');name.textContent=m.username||'Учасник';
  const body=document.createElement('div');body.textContent=m.message||'';
  const time=document.createElement('small');time.textContent=m.created_at||'';
  item.append(name,body,time);chatMessages.appendChild(item);chatMessages.scrollTop=chatMessages.scrollHeight;
}
async function loadMessages(){if(pollBusy)return;pollBusy=true;try{const r=await fetch(`meeting.php?api=messages&meeting_id=${meetingId}&after=${lastMessageId}`,{cache:'no-store'});if(r.ok){const d=await r.json();(d.messages||[]).forEach(addMessage);}}catch(e){}finally{pollBusy=false;}}
document.getElementById('chatForm').addEventListener('submit',async e=>{e.preventDefault();const message=chatInput.value.trim();if(!message)return;chatInput.disabled=true;try{const r=await fetch('meeting.php?api=send',{method:'POST',headers:{'Content-Type':'application/json','X-CSRF-Token':csrfToken},body:JSON.stringify({meeting_id:meetingId,message})});const d=await r.json();if(!r.ok)throw new Error(d.error||'Не вдалося відправити повідомлення.');chatInput.value='';await loadMessages();}catch(e){alert(e.message||'Помилка з’єднання.');}finally{chatInput.disabled=false;chatInput.focus();}});
setInterval(loadMessages,2000);loadMessages();

function setChatHidden(hidden){const room=document.getElementById('meetingRoom');room.classList.toggle('chat-hidden',hidden);const hide=document.getElementById('toggleChatTop'),show=document.getElementById('showChatTop');if(hide)hide.style.display=hidden?'none':'inline-flex';if(show)show.style.display=hidden?'inline-flex':'none';if(jitsiApi){setTimeout(()=>{try{jitsiApi.executeCommand('resizeLargeVideo',document.querySelector('.video-area').clientWidth,document.querySelector('.video-area').clientHeight);}catch(e){}},100);}}
document.getElementById('toggleChatTop')?.addEventListener('click',()=>setChatHidden(true));
document.getElementById('showChatTop')?.addEventListener('click',()=>setChatHidden(false));
document.getElementById('chatCollapse')?.addEventListener('click',()=>{const msgs=document.getElementById('chatMessages'),form=document.getElementById('chatForm');const collapsed=msgs.style.display==='none';msgs.style.display=collapsed?'flex':'none';form.style.display=collapsed?'flex':'none';document.getElementById('chatCollapse').textContent=collapsed?'−':'+';});

function renderParticipants(){
  const list=document.getElementById('participantsList');if(!list)return;const arr=[...participants.values()];
  const count=document.getElementById('participantCount');if(count)count.textContent=String(arr.length);
  list.replaceChildren();
  if(!arr.length){const empty=document.createElement('div');empty.className='meet-muted';empty.style.fontSize='12px';empty.textContent='Поки що немає даних про учасників.';list.appendChild(empty);return;}
  arr.sort((a,b)=>(a.isLocal?-1:0)-(b.isLocal?-1:0)||safeText(a.displayName).localeCompare(safeText(b.displayName)));
  arr.forEach(p=>{
    const card=document.createElement('div');card.className='participant-card';
    const row=document.createElement('div');row.className='participant-row';const avatar=document.createElement('div');avatar.className='person-avatar';avatar.textContent=initials(p.displayName);
    const copy=document.createElement('div');copy.className='person-copy';const name=document.createElement('div');name.className='person-name';name.textContent=(p.displayName||'Учасник')+(p.isLocal?' (ти)':'');
    const meta=document.createElement('div');meta.className='person-meta';meta.textContent=(p.role==='moderator'?'Модератор Jitsi · ':'')+(p.audioMuted?'🔇 мікрофон вимкнений':'🎙 мікрофон')+' · '+(p.videoMuted?'камера вимкнена':'камера увімкнена');copy.append(name,meta);row.append(avatar,copy);card.append(row);
    if(!p.isLocal){
      const actions=document.createElement('div');actions.className='participant-actions';
      const pin=document.createElement('button');pin.type='button';pin.className='meet-btn';pin.textContent='📌 Закріпити';pin.title='Закріпити відео тільки для себе';pin.addEventListener('click',()=>{try{jitsiApi.executeCommand('pinParticipant',p.id);}catch(e){alert('Не вдалося закріпити учасника.');}});actions.appendChild(pin);
      const silence=document.createElement('button');silence.type='button';silence.className='meet-btn';silence.textContent=localMutedParticipants.has(p.id)?'🔊 Чути':'🔇 Не чути';silence.title='Змінює гучність лише на твоєму пристрої';silence.addEventListener('click',()=>{const mute= !localMutedParticipants.has(p.id);try{jitsiApi.executeCommand('setParticipantVolume',p.id,mute?0:1);if(mute)localMutedParticipants.add(p.id);else localMutedParticipants.delete(p.id);renderParticipants();}catch(e){alert('Ця версія Jitsi не підтримала зміну гучності.');}});actions.appendChild(silence);
      if(isAdmin){
        const mute=document.createElement('button');mute.type='button';mute.className='meet-btn';mute.textContent=p.audioMuted?'🎙 Попросити ввімкнути':'🔇 Заглушити';mute.title=p.audioMuted?'Надіслати прохання ввімкнути мікрофон':'Заглушити мікрофон через права модератора';mute.addEventListener('click',()=>{if(!jitsiIsModerator){alert('Jitsi не призначив цьому обліковому запису права модератора. Керування мікрофонами недоступне.');return;}try{if(p.audioMuted)jitsiApi.executeCommand('askToUnmute',p.id);else jitsiApi.executeCommand('muteRemoteParticipant',p.id,'audio');}catch(e){alert('Jitsi не дозволив виконати цю дію.');}});actions.appendChild(mute);
        const cam=document.createElement('button');cam.type='button';cam.className='meet-btn';cam.textContent=p.videoMuted?'📹 Дозволити відео':'📷 Вимкнути відео';cam.title=p.videoMuted?'Спробувати дозволити відео, якщо ввімкнена модерація відео':'Вимкнути відео учасника';cam.addEventListener('click',()=>{if(!jitsiIsModerator){alert('Jitsi не призначив цьому обліковому запису права модератора. Керування відео недоступне.');return;}try{if(p.videoMuted)jitsiApi.executeCommand('approveVideo',p.id);else jitsiApi.executeCommand('muteRemoteParticipant',p.id,'video');}catch(e){alert('Jitsi не дозволив виконати цю дію.');}});actions.appendChild(cam);
        const kick=document.createElement('button');kick.type='button';kick.className='meet-btn danger';kick.textContent='⛔ Викинути';kick.addEventListener('click',()=>{if(!jitsiIsModerator){alert('Jitsi не призначив цьому обліковому запису права модератора. Викинути учасника зараз неможливо.');return;}if(confirm('Викинути '+(p.displayName||'учасника')+' з кімнати?')){try{jitsiApi.executeCommand('kickParticipant',p.id);}catch(e){alert('Jitsi не дозволив викинути учасника.');}}});actions.appendChild(kick);
        const ban=document.createElement('button');ban.type='button';ban.className='meet-btn danger';ban.textContent='🚫 Заблокувати';ban.title='Заборонити цьому логіну запитувати вхід на сайт';ban.addEventListener('click',async()=>{const label=p.displayName||'учасника';if(!confirm('Заблокувати обліковий запис '+label+' для майбутніх зустрічей?'))return;ban.disabled=true;try{const r=await fetch('meeting.php?api=ban',{method:'POST',headers:{'Content-Type':'application/json','X-CSRF-Token':csrfToken},body:JSON.stringify({username:label})});const d=await r.json();if(!r.ok)throw new Error(d.error||'Не вдалося заблокувати користувача.');if(jitsiIsModerator){try{jitsiApi.executeCommand('kickParticipant',p.id);}catch(e){}}alert('Обліковий запис '+d.username+' заблоковано в IT Tasks Pro.');}catch(e){alert(e.message||'Помилка блокування.');}finally{ban.disabled=false;}});actions.appendChild(ban);
      }
      card.append(actions);
    }
    list.appendChild(card);
  });
}
function rememberParticipant(p){if(!p||!p.id)return;const old=participants.get(String(p.id))||{};participants.set(String(p.id),{...old,...p,id:String(p.id),displayName:p.displayName||old.displayName||'Учасник'});renderParticipants();}
function forgetParticipant(id){participants.delete(String(id));localMutedParticipants.delete(String(id));renderParticipants();}
async function syncParticipants(){if(!jitsiApi)return;try{const info=await jitsiApi.getRoomsInfo();const rooms=(info&&info.rooms)||[];const room=rooms.find(r=>r.isMainRoom)||rooms[0];if(room&&Array.isArray(room.participants)){const seen=new Set();room.participants.forEach(p=>{seen.add(String(p.id));rememberParticipant({...p,isLocal:!!localJitsiId&&String(p.id)===localJitsiId});});for(const key of [...participants.keys()])if(!seen.has(key))participants.delete(key);const local=localJitsiId?participants.get(localJitsiId):null;if(local){jitsiIsModerator=local.role==='moderator';const badge=document.getElementById('moderatorStatus');if(badge){badge.textContent=jitsiIsModerator?'Ти — модератор Jitsi':'Ти не модератор Jitsi: частина дій недоступна';badge.classList.add('mod-badge');badge.classList.toggle('is-mod',jitsiIsModerator);}}renderParticipants();}}catch(e){try{const info=await jitsiApi.getParticipantsInfo();(info||[]).forEach(p=>rememberParticipant({...p,isLocal:!!localJitsiId&&String(p.id)===localJitsiId}));}catch(err){}}}

if(window.JitsiMeetExternalAPI&&roomName){
  document.getElementById('jitsi-container').replaceChildren();
  jitsiApi=new JitsiMeetExternalAPI('meet.jit.si',{roomName,parentNode:document.getElementById('jitsi-container'),width:'100%',height:'100%',userInfo:{displayName:myUsername},configOverwrite:{prejoinPageEnabled:false,startWithAudioMuted:false,startWithVideoMuted:false,disableDeepLinking:true,disableModeratorIndicator:false},interfaceConfigOverwrite:{SHOW_JITSI_WATERMARK:false,SHOW_BRAND_WATERMARK:false,SHOW_WATERMARK_FOR_GUESTS:false,TOOLBAR_BUTTONS:['microphone','camera','desktop','chat','participants-pane','raisehand','tileview','hangup','settings','fullscreen']}});
  jitsiApi.addListener('videoConferenceJoined',e=>{localJitsiId=String(e.id||'');rememberParticipant({id:e.id,displayName:e.displayName||myUsername,isLocal:true,role:'participant'});syncParticipants();});
  jitsiApi.addListener('participantJoined',p=>{rememberParticipant({id:p.id,displayName:p.displayName||'Учасник',isLocal:false,role:p.role||'participant'});setTimeout(syncParticipants,500);});
  jitsiApi.addListener('participantLeft',p=>forgetParticipant(p.id));
  jitsiApi.addListener('participantRoleChanged',e=>{if(e&&e.id){const p=participants.get(String(e.id))||{id:String(e.id),displayName:myUsername,isLocal:true};p.role=e.role;participants.set(String(e.id),p);}jitsiIsModerator=!!(e&&e.role==='moderator');const badge=document.getElementById('moderatorStatus');if(badge){badge.textContent=jitsiIsModerator?'Ти — модератор Jitsi':'Ти не модератор Jitsi: частина дій недоступна';badge.classList.toggle('mod-badge',true);badge.classList.toggle('is-mod',jitsiIsModerator);}renderParticipants();});
  jitsiApi.addListener('participantMuted',e=>{const p=participants.get(String(e.participantId));if(p){if(e.mediaType==='audio')p.audioMuted=!!e.isMuted;if(e.mediaType==='video')p.videoMuted=!!e.isMuted;renderParticipants();}});
  jitsiApi.addListener('readyToClose',()=>{location.href='meeting.php';});
  jitsiApi.addListener('videoConferenceLeft',()=>{location.href='meeting.php';});
  jitsiApi.addListener('participantKickedOut',e=>{if(e&&e.kicked&&e.kicked.local)location.href='meeting.php';});
  setTimeout(syncParticipants,1200);setInterval(syncParticipants,7000);
}else{document.getElementById('jitsi-container').innerHTML='<div class="video-error">Не вдалося завантажити Jitsi. Перевір інтернет та онови сторінку.</div>';}
setInterval(refreshMeetingStatus,2500);refreshMeetingStatus();
</script>
<?php elseif ($meeting && !$joined && !$isBanned): ?>
<script>
const waitMeetingId=<?= (int)$meeting['id'] ?>, waitCsrf=<?= json_encode($csrf) ?>, waitIsAdmin=<?= $isAdmin?'true':'false' ?>;
const waitJoinButton=document.getElementById('requestJoin');
async function refreshWaiting(){try{const r=await fetch('meeting.php?api=status',{cache:'no-store'});const d=await r.json();if(d.banned){location.href='meeting.php';return;}if(!d.active||!d.meeting||Number(d.meeting.id)!==waitMeetingId){location.href='meeting.php';return;}if(d.request_status==='approved'){location.href='meeting.php?join=1';return;}if(waitJoinButton&&d.request_status==='pending'){waitJoinButton.textContent='Запит надіслано';document.getElementById('requestStatusText').textContent='Очікуємо підтвердження адміністратора…';}if(waitJoinButton&&d.request_status==='denied'){waitJoinButton.textContent='Повторити запит';document.getElementById('requestStatusText').textContent='Запит відхилено. Можна надіслати ще раз.';}if(waitIsAdmin){const list=document.getElementById('joinRequests');if(list){const reqs=d.requests||[];const badge=document.getElementById('requestCount');if(badge)badge.textContent=String(reqs.length);list.replaceChildren();if(!reqs.length){const e=document.createElement('div');e.className='meet-muted';e.textContent='Немає запитів на вхід.';list.appendChild(e);}reqs.forEach(req=>{const card=document.createElement('div');card.className='join-request';const name=document.createElement('div');name.className='person-name';name.textContent=req.username||'Учасник';card.append(name);const actions=document.createElement('div');actions.className='join-request-actions';[['approve','✓ Прийняти','primary'],['deny','✕ Відхилити','']].forEach(([act,label,cls])=>{const b=document.createElement('button');b.type='button';b.className='meet-btn '+cls;b.textContent=label;b.addEventListener('click',async()=>{b.disabled=true;try{const x=await fetch('meeting.php?api='+act,{method:'POST',headers:{'Content-Type':'application/json','X-CSRF-Token':waitCsrf},body:JSON.stringify({request_id:Number(req.id)})});const z=await x.json();if(!x.ok)throw new Error(z.error||'Не вдалося змінити запит.');await refreshWaiting();}catch(err){alert(err.message);b.disabled=false;}});actions.appendChild(b);});card.append(actions);list.append(card);});}}}catch(e){}}
if(waitJoinButton)waitJoinButton.addEventListener('click',async()=>{waitJoinButton.disabled=true;try{const r=await fetch('meeting.php?api=request',{method:'POST',headers:{'Content-Type':'application/json','X-CSRF-Token':waitCsrf},body:JSON.stringify({meeting_id:waitMeetingId})});const d=await r.json();if(!r.ok)throw new Error(d.error||'Не вдалося надіслати запит.');if(d.status==='approved')location.href='meeting.php?join=1';else{waitJoinButton.textContent='Запит надіслано';document.getElementById('requestStatusText').textContent='Очікуємо підтвердження адміністратора…';}}catch(e){alert(e.message);}finally{waitJoinButton.disabled=false;}});
setInterval(refreshWaiting,2500);refreshWaiting();
</script>
<?php endif; ?>
</body>
</html>
