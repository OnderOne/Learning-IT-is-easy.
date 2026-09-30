<?php
$host = 'dpg-dauk1m0473hc73bk5hag-a'; // Внутренний хост в Render
$db   = 'my_database_7vkd';     
$user = 'my_database_7vkd_user';
$pass = '4s34tqHLipR5hb0uLHsshtX66F1c1jcJ';           
$port = 5432; 

try {
    // Подключаемся строго через стандартный драйвер mysql
    $pdo = new PDO("mysql:host=$host;port=$port;dbname=$db;charset=utf8mb4", $user, $pass);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    // Этот код сам автоматически создаст таблицу users на MySQL, если её нет
    $sql = "CREATE TABLE IF NOT EXISTS users (
        id INT AUTO_INCREMENT PRIMARY KEY,
        email VARCHAR(255) NOT NULL UNIQUE,
        username VARCHAR(100) NOT NULL UNIQUE,
        name VARCHAR(100),
        gender VARCHAR(20),
        age INT,
        password VARCHAR(255) NOT NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;";
    
    $pdo->exec($sql);

} catch(PDOException $e) {
    die("Ошибка подключения к базе данных: " . $e->getMessage());
}
?>
