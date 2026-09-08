<?php
require 'auth.php';

// Экспорт пользователей Radius
if (isset($_GET['export'])) {
    export_to_csv('radcheck', 'users_export.csv');
}

// Импорт пользователей Radius (Формат CSV: username,password)
if (isset($_FILES['import_file'])) {
    $file = $_FILES['import_file']['tmp_name'];
    if (($handle = fopen($file, "r")) !== FALSE) {
        while (($data = fgetcsv($handle, 1000, ",")) !== FALSE) {
            if (count($data) >= 2) {
                $username = trim($data[0], " \"");
                $password = trim($data[1], " \"");

                // Проверка на дубликат в radcheck
                $check = $db->prepare("SELECT id FROM radcheck WHERE username = ? LIMIT 1");
                $check->execute([$username]);
                if (!$check->fetch()) {
                    $stmt = $db->prepare("INSERT INTO radcheck (username, attribute, op, value) VALUES (?, 'Cleartext-Password', ':=', ?)");
                    $stmt->execute([$username, $password]);
                }
            }
        }
        fclose($handle);
        write_log("Массовый импорт пользователей Radius (без дубликатов)");
    }
    header("Location: index.php"); exit;
}

// Удаление пользователя Radius
if (isset($_GET['del'])) {
    $stmt = $db->prepare("DELETE FROM radcheck WHERE id = ?");
    $stmt->execute([$_GET['del']]);
    write_log("Удален пользователь Radius ID: " . $_GET['del']);
    header("Location: index.php"); exit;
}

// Удаление администратора панели (operators)
if (isset($_GET['del_op'])) {
    // Защита: нельзя удалить самого себя или главного админа 'admin'
    $stmt = $db->prepare("SELECT username FROM operators WHERE id = ?");
    $stmt->execute([$_GET['del_op']]);
    $op_username = $stmt->fetchColumn();

    if ($op_username !== 'admin' && $op_username !== $_SERVER['PHP_AUTH_USER']) {
        $stmt = $db->prepare("DELETE FROM operators WHERE id = ?");
        $stmt->execute([$_GET['del_op']]);
        write_log("Удален оператор панели: " . $op_username);
    }
    header("Location: index.php"); exit;
}

// ОБРАБОТКА ФОРМ: Добавление пользователя Radius ИЛИ Оператора панели
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    
    // 1. Создание пользователя Radius (в таблицу radcheck)
    if (isset($_POST['action']) && $_POST['action'] === 'add_radius_user') {
        $username = trim($_POST['username']);
        $password = trim($_POST['password']);
        
        $stmt = $db->prepare("INSERT INTO radcheck (username, attribute, op, value) VALUES (?, 'Cleartext-Password', ':=', ?)");
        $stmt->execute([$username, $password]);
        write_log("Создан пользователь Radius: " . $username);
        header("Location: index.php"); exit;
    }
    
    // 2. Создание НОВОГО АДМИНИСТРАТОРА веб-панели (в таблицу operators)
    if (isset($_POST['action']) && $_POST['action'] === 'add_panel_operator') {
        $username = trim($_POST['op_username']);
        $password = trim($_POST['op_password']);
        
        try {
            $stmt = $db->prepare("INSERT INTO operators (username, password) VALUES (?, ?)");
            $stmt->execute([$username, $password]);
            write_log("Создан новый оператор панели: " . $username);
        } catch (PDOException $e) {
            // Игнорируем дубликаты имен операторов
        }
        header("Location: index.php"); exit;
    }

    // 3. Смена пароля текущего админа
    if (isset($_POST['new_admin_pass'])) {
        $stmt = $db->prepare("UPDATE operators SET password = ? WHERE username = ?");
        $stmt->execute([trim($_POST['new_admin_pass']), $_SERVER['PHP_AUTH_USER']]);
        write_log("Админ сменил свой пароль");
        header("Location: index.php"); exit;
    }
}

// Получаем списки из базы
$users = $db->query("SELECT * FROM radcheck ORDER BY id DESC")->fetchAll(PDO::FETCH_ASSOC);
$operators = $db->query("SELECT * FROM operators ORDER BY id DESC")->fetchAll(PDO::FETCH_ASSOC);
?>
<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <title>Radius Admin</title>
    <link rel="stylesheet" href="style.css">
    <style>
        .password-input-container {
            display: inline-flex;
            align-items: center;
            position: relative;
        }
        .password-input-container button {
            position: absolute; right: 5px; background: none; border: none; cursor: pointer; padding: 2px 5px; font-size: 0.9em;
        }
        .password-input-container input { padding-right: 30px; }
        .flex-container { display: flex; gap: 20px; flex-wrap: wrap; }
        .flex-child { flex: 1; min-width: 300px; }
    </style>
</head>
<body>
    <nav>
        <div>
            <a href="index.php">Пользователи</a> |
            <a href="nas.php">Устройства (NAS)</a> |
            <a href="logs.php">Логи</a>
        </div>
        <a href="?logout=1" class="danger">Выйти</a>
    </nav>

    <div class="card" style="background: #f8f9fa;">
        <strong>Массовые операции Radius:</strong>
        <a href="?export=1" style="text-decoration:none; margin-left:10px;">⬇️ Экспорт в CSV</a>
        <form method="POST" enctype="multipart/form-data" style="display:inline; margin-left:20px;">
            <input type="file" name="import_file" accept=".csv" required style="font-size:0.8em;">
            <button type="submit" style="background:#6c757d; padding:4px 10px;">⬆️ Импорт CSV</button>
        </form>
    </div>
    <div class="flex-container">
        <!-- ФОРМА 1: Создание пользователя Radius -->
        <div class="card flex-child" style="border-left: 5px solid #28a745;">
            <h3>➕ Создать пользователя Radius (для VPN/Свитчей)</h3>
            <form method="POST">
                <input type="hidden" name="action" value="add_radius_user">
                <p>
                    <input type="text" name="username" placeholder="Имя пользователя (логин)" required style="width:100%; max-width:250px;">
                </p>
                <div class="password-input-container">
                    <input type="password" id="radius_password" name="password" placeholder="Пароль" required style="width:100%; max-width:250px;">
                    <button type="button" onclick="toggleInputPassword('radius_password', this)">👁</button>
                </div>
                <p>
                    <button type="submit" style="background: #28a745;">Создать пользователя</button>
                </p>
            </form>
        </div>

        <!-- ФОРМА 2: Создание Администратора веб-интерфейса -->
        <div class="card flex-child" style="border-left: 5px solid #007bff;">
            <h3>🔑 Создать Администратора Панели (Вход на сайт)</h3>
            <form method="POST">
                <input type="hidden" name="action" value="add_panel_operator">
                <p>
                    <input type="text" name="op_username" placeholder="Новый логин для сайта" required style="width:100%; max-width:250px;">
                </p>
                <div class="password-input-container">
                    <input type="password" id="op_password" name="op_password" placeholder="Пароль для сайта" required style="width:100%; max-width:250px;">
                    <button type="button" onclick="toggleInputPassword('op_password', this)">👁</button>
                </div>
                <p>
                    <button type="submit" style="background: #007bff;">Создать администратора</button>
                </p>
            </form>
        </div>
    </div>

    <div class="card">
        <h3>Сменить пароль текущего входа (<?=$_SERVER['PHP_AUTH_USER']?>)</h3>
        <form method="POST">
            <input type="text" name="new_admin_pass" placeholder="Новый пароль" required>
            <button type="submit">Сменить</button>
        </form>
    </div>

    <div class="flex-container">
        <!-- ТАБЛИЦА 1: Список пользователей Radius -->
        <div class="card flex-child">
            <h3>Список пользователей Radius</h3>
            <table>
                <?php foreach ($users as $u): ?>
                <tr>
                    <td><b><?=htmlspecialchars($u['username'])?></b></td>
                    <td>
                        <code class="password-field" data-password="<?=htmlspecialchars($u['value'])?>">****************</code>
                        <button type="button" onclick="togglePassword(this)" style="margin-left:8px; padding:2px 8px; font-size:0.8em;">👁</button>
                    </td>
                    <td>
                        <a href="?del=<?=$u['id']?>" class="danger" onclick="return confirm('Удалить?')">✕</a>
                    </td>
                </tr>
                <?php endforeach; ?>
            </table>
        </div>

        <!-- ТАБЛИЦА 2: Список Администраторов сайта -->
        <div class="card flex-child">
            <h3>Операторы веб-панели</h3>
            <table>
                <?php foreach ($operators as $o): ?>
                <tr>
                    <td><b><?=htmlspecialchars($o['username'])?></b></td>
                    <td>
                        <code class="password-field" data-password="<?=htmlspecialchars($o['password'])?>">****************</code>
                        <button type="button" onclick="togglePassword(this)" style="margin-left:8px; padding:2px 8px; font-size:0.8em;">👁</button>
                    </td>
                    <td>
                        <?php if($o['username'] !== 'admin' && $o['username'] !== $_SERVER['PHP_AUTH_USER']): ?>
                            <a href="?del_op=<?=$o['id']?>" class="danger" onclick="return confirm('Удалить администратора?')">✕</a>
                        <?php endif; ?>
                    </td>
                </tr>
                <?php endforeach; ?>
            </table>
        </div>
    </div>

<script>
// Переключение видимости сохраненных паролей в таблицах
function togglePassword(button) {
    const passField = button.parentElement.querySelector('.password-field');
    const realPassword = passField.dataset.password;
    const hiddenPassword = '****************';
    if (passField.textContent.trim() === hiddenPassword) {
        passField.textContent = realPassword; 
        button.textContent = '🙈';
    } else {
        passField.textContent = hiddenPassword; 
        button.textContent = '👁';
    }
}

// Переключение типа инпута (password <-> text) внутри форм создания
function toggleInputPassword(inputId, button) {
    const input = document.getElementById(inputId);
    if (input.type === "password") {
        input.type = "text"; 
        button.textContent = "🙈";
    } else {
        input.type = "password"; 
        button.textContent = "👁";
    }
}
</script>
</body>
</html>
