<?php
define('DB_HOST', 'localhost');
define('DB_USER', 'visa');
define('DB_PASS', 'visa');
define('DB_NAME', 'visas');


try {
    $pdo = new PDO(
        "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=utf8",
        DB_USER,
        DB_PASS
    );
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch(PDOException $e) {
    echo "Connection failed: " . $e->getMessage() . "<br>";
    echo "Error code: " . $e->getCode() . "<br>";
    exit;
}
?> 