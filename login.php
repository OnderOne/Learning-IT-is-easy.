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

            $_SESSION['user_id'] = $user['id'];
            $_SESSION['email'] = $user['email'];
            $_SESSION['username'] = $user['username'];
            $_SESSION['name'] = $user['name'];

            header("Location: index.php");
            exit;

        } else {
            $error = "Неверный логин или пароль!";
        }

    } catch (PDOException $e) {
        $error = "Ошибка БД: " . $e->getMessage();
    }
}
?>

<!DOCTYPE html>
<html lang="ru">
<head>
<meta charset="UTF-8">
<title>Вход</title>

<style>
body {
    margin:0;
    font-family: Arial;
    background: #0b1220;
    display:flex;
    justify-content:center;
    align-items:center;
    height:100vh;
}

.container {
    width: 380px;
    background: #111827;
    padding: 25px;
    border-radius: 15px;
    box-shadow: 0 0 25px rgba(0,0,0,0.6);
    color: white;
}

h2 {
    text-align:center;
    margin-bottom:15px;
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
    color:#9ca3af;
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
    text-align:center;
}
</style>
</head>

<body>

<div class="container">

<h2>Вход</h2>

<?php if(isset($error)): ?>
<div class="error"><?= $error ?></div>
<?php endif; ?>

<form method="POST">

<input type="text" name="login" placeholder="Email или логин" required>

<input type="password" name="password" placeholder="Пароль" required>

<button class="btn-login" type="submit">Войти</button>

</form>

<a href="register.php">
    <button class="btn-register">Нет аккаунта? Регистрация</button>
</a>

</div>

</body>
</html>