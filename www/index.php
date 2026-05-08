<?php 
require 'auth.php'; 

// Экспорт пользователей
if (isset($_GET['export'])) {
    export_to_csv('radcheck', 'users_export.csv');
}

// Импорт пользователей (Формат CSV: username,password)
if (isset($_FILES['import_file'])) {
    $file = $_FILES['import_file']['tmp_name'];
    if (($handle = fopen($file, "r")) !== FALSE) {
        while (($data = fgetcsv($handle, 1000, ",")) !== FALSE) {
            if (count($data) >= 2) {
                $username = trim($data[0], " \"");
                $password = trim($data[1], " \"");

                // Проверка на дубликат
                $check = $db->prepare("SELECT id FROM radcheck WHERE username = ? LIMIT 1");
                $check->execute([$username]);
                if (!$check->fetch()) {
                    $stmt = $db->prepare("INSERT INTO radcheck (username, attribute, op, value) VALUES (?, 'Cleartext-Password', ':=', ?)");
                    $stmt->execute([$username, $password]);
                }
            }
        }
        fclose($handle);
        write_log("Массовый импорт пользователей (без дубликатов)");
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

// Добавление пользователя Radius
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['username'])) {
    $stmt = $db->prepare("INSERT INTO radcheck (username, attribute, op, value) VALUES (?, 'Cleartext-Password', ':=', ?)");
    $stmt->execute([$_POST['username'], $_POST['password']]);
    write_log("Создан пользователь Radius: " . $_POST['username']);
    header("Location: index.php"); exit;
}

// Смена пароля админа
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['new_admin_pass'])) {
    $stmt = $db->prepare("UPDATE operators SET password = ? WHERE username = ?");
    $stmt->execute([$_POST['new_admin_pass'], $_SERVER['PHP_AUTH_USER']]);
    write_log("Админ сменил свой пароль");
    header("Location: index.php"); exit;
}

$users = $db->query("SELECT * FROM radcheck ORDER BY id DESC")->fetchAll(PDO::FETCH_ASSOC);
?>
<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <title>Radius Admin</title>
    <link rel="stylesheet" href="style.css">
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
        <strong>Массовые операции:</strong>
	<a href="?export=1" style="text-decoration:none; margin-left:10px;">⬇️ Экспорт в CSV</a>
            <form method="POST" enctype="multipart/form-data" style="display:inline; margin-left:20px;">
	        <input type="file" name="import_file" accept=".csv" required style="font-size:0.8em;">
            <button type="submit" style="background:#6c757d; padding:4px 10px;">⬆️ Импорт CSV</button>
        </form>
    </div>

    <div class="card">
        <h3>Сменить пароль входа (<?=$_SERVER['PHP_AUTH_USER']?>)</h3>
        <form method="POST">
            <input type="text" name="new_admin_pass" placeholder="Новый пароль" required>
            <button type="submit">Сменить</button>
        </form>
    </div>

    <div class="card">
        <h3>Добавить Radius-пользователя</h3>
        <form method="POST">
            <input type="text" name="username" placeholder="Логин" required>
            <input type="text" name="password" placeholder="Пароль" required>
            <button type="submit" class="success">Создать</button>
        </form>
    </div>

<div class="card">
    <h3>Список пользователей</h3>

    <table>
        <?php foreach ($users as $u): ?>
        <tr>

            <td>
                <b><?=htmlspecialchars($u['username'])?></b>
            </td>

            <td>
                <code class="password-field"
                      data-password="<?=htmlspecialchars($u['value'])?>">
                    ****************
                </code>

                <button
                    type="button"
                    onclick="togglePassword(this)"
                    style="margin-left:8px; padding:2px 8px; font-size:0.8em;"
                >
                    👁
                </button>
            </td>

            <td>
                <a href="?del=<?=$u['id']?>"
                   class="danger"
                   onclick="return confirm('Удалить?')">
                    ✕
                </a>
            </td>

        </tr>
        <?php endforeach; ?>
    </table>
</div>

<script>
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
</script>
</body>
</html>
