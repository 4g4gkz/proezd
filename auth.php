<?php
session_start();
header('Content-Type: application/json');

$db_file = __DIR__ . '/database.sqlite';
$is_new_db = !file_exists($db_file);

try {
    $pdo = new PDO("sqlite:$db_file");
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->exec("PRAGMA foreign_keys = ON;");

    if ($is_new_db) {
        $pdo->exec("CREATE TABLE IF NOT EXISTS `users` (
            `id` INTEGER PRIMARY KEY AUTOINCREMENT,
            `phone` TEXT NOT NULL UNIQUE,
            `name` TEXT NOT NULL,
            `password` TEXT NOT NULL,
            `status` INTEGER DEFAULT 0,
            `default_price` INTEGER DEFAULT 80,
            `default_letter` TEXT DEFAULT '',
            `total_actions` INTEGER DEFAULT 0,
            `created_at` TEXT DEFAULT CURRENT_TIMESTAMP
        );");

        $pdo->exec("CREATE TABLE IF NOT EXISTS `tickets` (
            `id` INTEGER PRIMARY KEY AUTOINCREMENT,
            `user_id` INTEGER NOT NULL,
            `route` TEXT NOT NULL,
            `gos` TEXT NOT NULL,
            `ticket_code` TEXT NOT NULL,
            `price` TEXT NOT NULL,
            `date_pay` TEXT NOT NULL,
            `time_pay` TEXT NOT NULL,
            `end_time` INTEGER NOT NULL,
            `created_at` TEXT DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE
        );");

        $pdo->exec("CREATE TABLE IF NOT EXISTS `routes` (
            `id` INTEGER PRIMARY KEY AUTOINCREMENT,
            `code` TEXT NOT NULL UNIQUE,
            `route` TEXT NOT NULL,
            `gos` TEXT NOT NULL,
            `price` INTEGER DEFAULT NULL,
            `created_at` TEXT DEFAULT CURRENT_TIMESTAMP
        );");

        $adminPhone = '123456';
        $adminName = '123456';
        $adminPasswordHash = password_hash('123456', PASSWORD_DEFAULT);
        $adminStatus = 4;

        $stmt = $pdo->prepare("INSERT INTO users (phone, name, password, status, default_price, default_letter, total_actions) VALUES (?, ?, ?, ?, 80, '', 0)");
        $stmt->execute([$adminPhone, $adminName, $adminPasswordHash, $adminStatus]);
    }

} catch (PDOException $e) {
    error_log('Auth DB error: ' . $e->getMessage());
    die(json_encode(['status' => 'error', 'message' => 'Ошибка подключения или инициализации БД']));
}

$action = $_GET['action'] ?? '';

if ($action == 'register') {
    $phone = trim($_POST['phone'] ?? '');
    $name = trim($_POST['name'] ?? '');
    $password = $_POST['password'] ?? '';
    
    if (empty($phone) || empty($name) || empty($password)) {
        echo json_encode(['status' => 'error', 'message' => 'Заполните все поля']);
        exit;
    }
    if (strlen($password) < 4) {
        echo json_encode(['status' => 'error', 'message' => 'Пароль должен быть минимум 4 символа']);
        exit;
    }
    
    $check = $pdo->prepare("SELECT id FROM users WHERE phone = ?");
    $check->execute([$phone]);
    if ($check->fetch()) {
        echo json_encode(['status' => 'error', 'message' => 'Этот номер уже зарегистрирован']);
    } else {
        $passHash = password_hash($password, PASSWORD_DEFAULT);
        $stmt = $pdo->prepare("INSERT INTO users (phone, name, password, status, default_price, default_letter, total_actions) VALUES (?, ?, ?, 0, 80, '', 0)");
        if($stmt->execute([$phone, $name, $passHash])) {
            echo json_encode(['status' => 'success']);
        } else {
            echo json_encode(['status' => 'error', 'message' => 'Ошибка при регистрации']);
        }
    }
    exit;
}

if ($action == 'login') {
    $phone = $_POST['phone'] ?? '';
    $pass = $_POST['password'] ?? '';
    
    $stmt = $pdo->prepare("SELECT * FROM users WHERE phone = ?");
    $stmt->execute([$phone]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if ($user && password_verify($pass, $user['password'])) {
        $_SESSION['user_id'] = $user['id'];
        $_SESSION['status'] = $user['status'];
        $_SESSION['user_name'] = $user['name'];
        $_SESSION['def_price'] = $user['default_price'];
        $_SESSION['def_letter'] = $user['default_letter'];
        echo json_encode(['status' => 'success']);
    } else {
        echo json_encode(['status' => 'error', 'message' => 'Неверный номер или пароль']);
    }
    exit;
}

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['status' => 'error', 'message' => 'Не авторизован']);
    exit;
}

$userId = $_SESSION['user_id'];

if ($action == 'get_tickets') {
    $stmt = $pdo->prepare("SELECT * FROM tickets WHERE user_id = ? ORDER BY id DESC");
    $stmt->execute([$userId]);
    echo json_encode($stmt->fetchAll(PDO::FETCH_ASSOC));
    exit;
}

if ($action == 'save_ticket') {
    $stmt = $pdo->prepare("INSERT INTO tickets (user_id, route, gos, ticket_code, price, date_pay, time_pay, end_time) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
    $res = $stmt->execute([
        $userId,
        $_POST['route'] ?? '',
        $_POST['gos'] ?? '',
        $_POST['code'] ?? '',
        $_POST['price'] ?? '',
        $_POST['date'] ?? '',
        $_POST['time'] ?? '',
        $_POST['end'] ?? 0
    ]);
    
    if($res) {
        $pdo->prepare("UPDATE users SET total_actions = total_actions + 1 WHERE id = ?")->execute([$userId]);
        echo json_encode(['status' => 'success']);
    } else {
        echo json_encode(['status' => 'error', 'message' => 'Ошибка сохранения']);
    }
    exit;
}

if ($action == 'update_ticket_data') {
    $id = $_POST['id'] ?? 0;
    $field = $_POST['field'] ?? '';
    $val = $_POST['value'] ?? '';
    
    $allowed_fields = ['route', 'gos', 'price', 'ticket_code', 'end_time'];
    if (in_array($field, $allowed_fields)) {
        $sql = "UPDATE tickets SET $field = ? WHERE id = ? AND user_id = ?";
        $stmt = $pdo->prepare($sql);
        $res = $stmt->execute([$val, $id, $userId]);
        
        if($res && $field == 'end_time') {
            $pdo->prepare("UPDATE users SET total_actions = total_actions + 1 WHERE id = ?")->execute([$userId]);
        }
        echo json_encode(['status' => 'success']);
    } else {
        echo json_encode(['status' => 'error', 'message' => 'Недопустимое поле']);
    }
    exit;
}

if ($action == 'delete_ticket') {
    $stmt = $pdo->prepare("DELETE FROM tickets WHERE id = ? AND user_id = ?");
    $stmt->execute([$_POST['id'] ?? 0, $userId]);
    echo json_encode(['status' => 'success']);
    exit;
}

if ($action == 'clear_history') {
    $stmt = $pdo->prepare("DELETE FROM tickets WHERE user_id = ?");
    $stmt->execute([$userId]);
    echo json_encode(['status' => 'success']);
    exit;
}

if ($action == 'save_settings') {
    $stmt = $pdo->prepare("UPDATE users SET default_price = ?, default_letter = ? WHERE id = ?");
    $stmt->execute([$_POST['price'] ?? 80, $_POST['letter'] ?? '', $userId]);
    $_SESSION['def_price'] = $_POST['price'] ?? 80;
    $_SESSION['def_letter'] = $_POST['letter'] ?? '';
    echo json_encode(['status' => 'success']);
    exit;
}

if ($action == 'get_routes') {
    if (($_SESSION['status'] ?? 0) != 4) {
        echo json_encode(['status' => 'error', 'message' => 'Доступ запрещён']);
        exit;
    }
    $stmt = $pdo->query("SELECT * FROM routes ORDER BY id DESC");
    echo json_encode($stmt->fetchAll(PDO::FETCH_ASSOC));
    exit;
}

if ($action == 'add_route') {
    if (($_SESSION['status'] ?? 0) != 4) {
        echo json_encode(['status' => 'error', 'message' => 'Доступ запрещён']);
        exit;
    }
    $code = trim($_POST['code'] ?? '');
    $route = trim($_POST['route'] ?? '');
    $gos = trim($_POST['gos'] ?? '');
    $price = !empty($_POST['price']) ? (int)$_POST['price'] : null;
    
    if (empty($code) || empty($route) || empty($gos)) {
        echo json_encode(['status' => 'error', 'message' => 'Заполните код, маршрут и госномер']);
        exit;
    }
    
    $check = $pdo->prepare("SELECT id FROM routes WHERE code = ?");
    $check->execute([$code]);
    if ($check->fetch()) {
        echo json_encode(['status' => 'error', 'message' => 'Код уже существует']);
        exit;
    }
    
    $stmt = $pdo->prepare("INSERT INTO routes (code, route, whitespaces, price) VALUES (?, ?, ?, ?)" ? "INSERT INTO routes (code, route, gos, price) VALUES (?, ?, ?, ?)" : "");
    $stmt = $pdo->prepare("INSERT INTO routes (code, route, gos, price) VALUES (?, ?, ?, ?)");
    if ($stmt->execute([$code, $route, $gos, $price])) {
        echo json_encode(['status' => 'success']);
    } else {
        echo json_encode(['status' => 'error', 'message' => 'Ошибка при добавлении']);
    }
    exit;
}

if ($action == 'update_route') {
    if (($_SESSION['status'] ?? 0) != 4) {
        echo json_encode(['status' => 'error', 'message' => 'Доступ запрещён']);
        exit;
    }
    $id = $_POST['id'] ?? 0;
    $code = trim($_POST['code'] ?? '');
    $route = trim($_POST['route'] ?? '');
    $gos = trim($_POST['gos'] ?? '');
    $price = !empty($_POST['price']) ? (int)$_POST['price'] : null;
    
    if (empty($id) || empty($code) || empty($route) || empty($gos)) {
        echo json_encode(['status' => 'error', 'message' => 'Заполните все поля']);
        exit;
    }
    
    $check = $pdo->prepare("SELECT id FROM routes WHERE code = ? AND id != ?");
    $check->execute([$code, $id]);
    if ($check->fetch()) {
        echo json_encode(['status' => 'error', 'message' => 'Код уже используется другим маршрутом']);
        exit;
    }
    
    $stmt = $pdo->prepare("UPDATE routes SET code = ?, route = ?, gos = ?, price = ? WHERE id = ?");
    if ($stmt->execute([$code, $route, $gos, $price, $id])) {
        echo json_encode(['status' => 'success']);
    } else {
        echo json_encode(['status' => 'error', 'message' => 'Ошибка при обновлении']);
    }
    exit;
}

if ($action == 'delete_route') {
    if (($_SESSION['status'] ?? 0) != 4) {
        echo json_encode(['status' => 'error', 'message' => 'Доступ запрещён']);
        exit;
    }
    $id = $_POST['id'] ?? 0;
    $stmt = $pdo->prepare("DELETE FROM routes WHERE id = ?");
    if ($stmt->execute([$id])) {
        echo json_encode(['status' => 'success']);
    } else {
        echo json_encode(['status' => 'error', 'message' => 'Ошибка при удалении']);
    }
    exit;
}

if ($action == 'create_ticket_by_code') {
    $code = trim($_POST['code'] ?? '');
    if (empty($code)) {
        echo json_encode(['status' => 'error', 'message' => 'Код не указан']);
        exit;
    }
    
    $stmt = $pdo->prepare("SELECT * FROM routes WHERE code = ?");
    $stmt->execute([$code]);
    $routeData = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$routeData) {
        echo json_encode(['status' => 'error', 'message' => 'Код не найден']);
        exit;
    }
    
    $priceValue = $routeData['price'] ?? $_SESSION['def_price'] ?? 80;
    $price = $priceValue . ' ₸';
    
    $tz = new DateTimeZone('Asia/Almaty');
    $now = new DateTime('now', $tz);
    
    $date = $now->format('d.m.Y');
    $time = $now->format('H:i');
    
    $end = ($now->getTimestamp() + 7200) * 1000; 
    
    $ticketCode = strtoupper(substr(md5(uniqid()), 0, 8));
    
    $insert = $pdo->prepare("INSERT INTO tickets (user_id, route, gos, ticket_code, price, date_pay, time_pay, end_time) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
    $res = $insert->execute([
        $userId,
        $routeData['route'],
        $routeData['gos'],
        $ticketCode,
        $price,
        $date,
        $time,
        $end
    ]);
    
    if ($res) {
        $pdo->prepare("UPDATE users SET total_actions = total_actions + 1 WHERE id = ?")->execute([$userId]);
        echo json_encode([
            'status' => 'success',
            'ticket' => [
                'route' => $routeData['route'],
                'gos' => $routeData['gos'],
                'price' => $price,
                'date' => $date,
                'time' => $time,
                'code' => $ticketCode,
                'end' => $end
            ]
        ]);
    } else {
        echo json_encode(['status' => 'error', 'message' => 'Ошибка при создании билета']);
    }
    exit;
}

if ($action == 'delete_user') {
    if (($_SESSION['status'] ?? 0) != 4) {
        echo json_encode(['status' => 'error', 'message' => 'Доступ запрещён']);
        exit;
    }
    $targetUserId = $_POST['id'] ?? 0;
    if ($targetUserId == $userId) {
        echo json_encode(['status' => 'error', 'message' => 'Нельзя удалить самого себя']);
        exit;
    }
    
    $stmt = $pdo->prepare("DELETE FROM users WHERE id = ?");
    if ($stmt->execute([$targetUserId])) {
        echo json_encode(['status' => 'success']);
    } else {
        echo json_encode(['status' => 'error', 'message' => 'Ошибка при удалении']);
    }
    exit;
}

if ($action == 'get_all_users') {
    if (($_SESSION['status'] ?? 0) != 4) {
        echo json_encode(['status' => 'error', 'message' => 'Доступ запрещён']);
        exit;
    }
    $stmt = $pdo->query("SELECT id, name, phone, status, total_actions FROM users ORDER BY id DESC");
    echo json_encode($stmt->fetchAll(PDO::FETCH_ASSOC));
    exit;
}

if ($action == 'update_user_status') {
    if (($_SESSION['status'] ?? 0) != 4) {
        echo json_encode(['status' => 'error', 'message' => 'Доступ запрещён']);
        exit;
    }
    $stmt = $pdo->prepare("UPDATE users SET status = ? WHERE id = ?");
    $stmt->execute([$_POST['status'] ?? 0, $_POST['id'] ?? 0]);
    echo json_encode(['status' => 'success']);
    exit;
}

if ($action == 'reset_user_counter') {
    if (($_SESSION['status'] ?? 0) != 4) {
        echo json_encode(['status' => 'error', 'message' => 'Доступ запрещён']);
        exit;
    }
    $stmt = $pdo->prepare("UPDATE users SET total_actions = 0 WHERE id = ?");
    if($stmt->execute([$_POST['id'] ?? 0])) {
        echo json_encode(['status' => 'success']);
    } else {
        echo json_encode(['status' => 'error', 'message' => 'Не удалось обнулить']);
    }
    exit;
}

if ($action == 'logout') {
    session_destroy();
    header("Location: index.php");
    exit;
}

echo json_encode(['status' => 'error', 'message' => 'Неизвестное действие']);
?>