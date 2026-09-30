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

$genderText = match($user['gender'] ?? '') {
    'male' => 'Мужчина',
    'female' => 'Женщина',
    default => 'Не указано'
};
?>

<!DOCTYPE html>
<html lang="ru">
<head>
<meta charset="UTF-8">
<title>Профиль</title>

<style>
body {
    margin:0;
    font-family: Arial;
    background:#0b1220;
    display:flex;
    justify-content:center;
    align-items:center;
    height:100vh;
}

.card {
    width: 400px;
    background:#111827;
    color:white;
    padding:25px;
    border-radius:15px;
    box-shadow:0 0 25px rgba(0,0,0,0.6);
}

h2 { text-align:center; }

p {
    padding:10px;
    border-bottom:1px solid #1f2937;
}

a {
    display:block;
    text-align:center;
    margin-top:10px;
    color:#60a5fa;
    text-decoration:none;
}

a:hover {
    text-decoration:underline;
}
</style>
</head>

<body>

<div class="card">

<h2>👤 Профиль</h2>

<p><b>Email:</b> <?= htmlspecialchars($user['email']) ?></p>
<p><b>Имя:</b> <?= htmlspecialchars($user['name'] ?? 'Не указано') ?></p>
<p><b>Пол:</b> <?= $genderText ?></p>
<p><b>Возраст:</b> <?= $user['age'] ?? 'Не указан' ?></p>

<p><b>Дата регистрации:</b>
<?= $user['created_at'] ?? 'Нет данных' ?>
</p>

<a href="index.php">← Главная</a>
<a href="logout.php" style="color:#f87171;">Выйти</a>

</div>

</body>
</html>