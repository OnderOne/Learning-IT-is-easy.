<?php
session_start();

if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit;
}

require_once 'db.php';

$stmt = $pdo->prepare("SELECT * FROM users WHERE id = ?");
$stmt->execute([$_SESSION['user_id']]);
$user = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$user) {
    session_unset();
    session_destroy();
    header("Location: login.php");
    exit;
}

$genderText = match ($user['gender'] ?? '') {
    'male' => 'Чоловіча',
    'female' => 'Жіноча',
    'other' => 'Інша',
    default => 'Не вказано'
};
?>

<!DOCTYPE html>
<html lang="uk">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Профіль</title>

<style>
body {
    margin: 0;
    font-family: Arial, sans-serif;
    background: #0b1220;
    display: flex;
    justify-content: center;
    align-items: center;
    min-height: 100vh;
    padding: 20px 0;
    box-sizing: border-box;
}

.card {
    width: 400px;
    max-width: calc(100% - 40px);
    background: #111827;
    color: white;
    padding: 25px;
    border-radius: 15px;
    box-shadow: 0 0 25px rgba(0,0,0,0.6);
    box-sizing: border-box;
}

h2 {
    text-align: center;
}

p {
    padding: 10px;
    border-bottom: 1px solid #1f2937;
    overflow-wrap: anywhere;
}

a {
    display: block;
    text-align: center;
    margin-top: 10px;
    color: #60a5fa;
    text-decoration: none;
}

a:hover {
    text-decoration: underline;
}
</style>
</head>

<body>
<div class="card">
    <h2>👤 Профіль</h2>

    <p><b>Електронна пошта:</b> <?= htmlspecialchars($user['email'] ?? 'Не вказано', ENT_QUOTES, 'UTF-8') ?></p>
    <p><b>Логін:</b> <?= htmlspecialchars($user['username'] ?? 'Не вказано', ENT_QUOTES, 'UTF-8') ?></p>
    <p><b>Ім’я:</b> <?= htmlspecialchars($user['name'] ?: 'Не вказано', ENT_QUOTES, 'UTF-8') ?></p>
    <p><b>Стать:</b> <?= htmlspecialchars($genderText, ENT_QUOTES, 'UTF-8') ?></p>
    <p><b>Вік:</b> <?= $user['age'] !== null && $user['age'] !== '' ? (int)$user['age'] : 'Не вказано' ?></p>

    <p><b>Дата реєстрації:</b>
        <?= htmlspecialchars($user['created_at'] ?? 'Немає даних', ENT_QUOTES, 'UTF-8') ?>
    </p>

    <a href="index.php">← На головну</a>
    <a href="logout.php" style="color:#f87171;">Вийти з облікового запису</a>
</div>
</body>
</html>
