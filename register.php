<?php
session_start();
require_once 'db.php';

error_reporting(E_ALL);
ini_set('display_errors', 1);

if ($_SERVER['REQUEST_METHOD'] == 'POST') {

    $email    = trim($_POST['email'] ?? '');
    $username = trim($_POST['username'] ?? '');
    $name     = trim($_POST['name'] ?? '');
    $gender   = $_POST['gender'] ?? '';
    $age      = !empty($_POST['age']) ? (int)$_POST['age'] : null;
    $password = $_POST['password'] ?? '';

    try {

        if (!$email || !$username || !$password) {
            $error = "Заполни все обязательные поля!";
        }
        elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $error = "Некорректный email!";
        }
        elseif (strlen($password) < 6) {
            $error = "Пароль минимум 6 символов!";
        }
        else {

            $stmt = $pdo->prepare("SELECT id FROM users WHERE email=? OR username=?");
            $stmt->execute([$email, $username]);

            if ($stmt->rowCount() > 0) {
                $error = "Пользователь уже существует!";
            } else {

                $hash = password_hash($password, PASSWORD_DEFAULT);

                $stmt = $pdo->prepare("
                    INSERT INTO users (email, username, name, gender, age, password)
                    VALUES (?, ?, ?, ?, ?, ?)
                ");

                $stmt->execute([$email, $username, $name, $gender, $age, $hash]);

                header("Location: login.php");
                exit;
            }
        }

    } catch (PDOException $e) {
        $error = "DB ошибка: " . $e->getMessage();
    }
}
?>

<!DOCTYPE html>
<html lang="ru">
<head>
<meta charset="UTF-8">
<title>Регистрация</title>

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

input, select {
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

.btn-register {
    background: #22c55e;
    color: white;
    margin-top: 10px;
}

.btn-register:hover {
    background: #16a34a;
}

.btn-login {
    background: transparent;
    border: 1px solid #374151;
    color: #cbd5e1;
    margin-top: 10px;
}

.btn-login:hover {
    background: #1f2937;
}

.error {
    background: #ef4444;
    padding: 10px;
    border-radius: 8px;
    margin-bottom: 10px;
    text-align:center;
}

.pass-box {
    position: relative;
}

.eye {
    position:absolute;
    right:12px;
    top:50%;
    transform:translateY(-50%);
    cursor:pointer;
    color:#9ca3af;
}
</style>
</head>

<body>

<div class="container">

<h2>Регистрация</h2>

<?php if(isset($error)): ?>
<div class="error"><?= $error ?></div>
<?php endif; ?>

<form method="POST">

<input type="email" name="email" placeholder="Email" required>

<input type="text" name="username" placeholder="Логин" required>

<input type="text" name="name" placeholder="Имя">

<select name="gender">
    <option value="">Пол</option>
    <option value="male">Мужчина</option>
    <option value="female">Женщина</option>
    <option value="other">Другое</option>
</select>

<input type="number" name="age" placeholder="Возраст">

<div class="pass-box">
    <input type="password" id="password" name="password" placeholder="Пароль" required>
    <span class="eye" onclick="togglePass()">👁</span>
</div>

<button class="btn-register" type="submit">Создать аккаунт</button>

</form>

<a href="login.php">
    <button class="btn-login">Уже есть аккаунт? Войти</button>
</a>

</div>

<script>
function togglePass() {
    let p = document.getElementById("password");
    p.type = (p.type === "password") ? "text" : "password";
}
</script>

</body>
</html>