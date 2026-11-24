<?php
session_start();
require_once 'db.php';

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $login = trim($_POST['login'] ?? '');
    $password = $_POST['password'] ?? '';

    try {
        $stmt = $pdo->prepare("SELECT * FROM users WHERE email = ? OR username = ?");
        $stmt->execute([$login, $login]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($user && password_verify($password, $user['password'])) {
            session_regenerate_id(true);

            $_SESSION['user_id'] = $user['id'];
            $_SESSION['email'] = $user['email'];
            $_SESSION['username'] = $user['username'];
            $_SESSION['name'] = $user['name'];

            header("Location: index.php");
            exit;
        } else {
            $error = "Неправильна електронна пошта, логін або пароль!";
        }
    } catch (PDOException $e) {
        $error = "Помилка бази даних: " . $e->getMessage();
    }
}
?>

<!DOCTYPE html>
<html lang="uk">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Вхід</title>

<style>
body {
    margin: 0;
    font-family: Arial, sans-serif;
    background: #0b1220;
    display: flex;
    justify-content: center;
    align-items: center;
    min-height: 100vh;
}

.container {
    width: 380px;
    max-width: calc(100% - 40px);
    background: #111827;
    padding: 25px;
    border-radius: 15px;
    box-shadow: 0 0 25px rgba(0,0,0,0.6);
    color: white;
    box-sizing: border-box;
}

h2 {
    text-align: center;
    margin-bottom: 15px;
}

input {
    width: 100%;
    padding: 12px;
    margin: 8px 0;
    border-radius: 8px;
    border: none;
    outline: none;
    background: #1f2937;
    color: white;
    box-sizing: border-box;
}

input::placeholder {
    color: #9ca3af;
}

button {
    width: 100%;
    padding: 12px;
    border: none;
    border-radius: 8px;
    cursor: pointer;
    font-weight: bold;
}

.btn-login {
    background: #3b82f6;
    color: white;
    margin-top: 10px;
}

.btn-login:hover {
    background: #2563eb;
}

.btn-register {
    background: transparent;
    border: 1px solid #374151;
    color: #cbd5e1;
    margin-top: 10px;
}

.btn-register:hover {
    background: #1f2937;
}

.error {
    background: #ef4444;
    padding: 10px;
    border-radius: 8px;
    margin-bottom: 10px;
    text-align: center;
    overflow-wrap: anywhere;
}
</style>
</head>

<body>
<div class="container">
    <h2>Вхід</h2>

    <?php if (isset($error)): ?>
        <div class="error"><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></div>
    <?php endif; ?>

    <form method="POST">
        <input type="text" name="login" placeholder="Електронна пошта або логін" autocomplete="username" required>
        <input type="password" name="password" placeholder="Пароль" autocomplete="current-password" required>
        <button class="btn-login" type="submit">Увійти</button>
    </form>

    <a href="register.php" style="text-decoration:none;">
        <button class="btn-register" type="button">Немає облікового запису? Зареєструватися</button>
    </a>
</div>
</body>
</html>
