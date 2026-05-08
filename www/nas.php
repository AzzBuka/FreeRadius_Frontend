<?php 
require 'auth.php'; 

// Экспорт NAS
if (isset($_GET['export'])) {
    export_to_csv('nas', 'nas_export.csv');
}

// Импорт NAS (Формат CSV: nasname,shortname,secret,description)
if (isset($_FILES['import_nas'])) {
    $file = $_FILES['import_nas']['tmp_name'];
    if (($handle = fopen($file, "r")) !== FALSE) {
        while (($data = fgetcsv($handle, 1000, ",")) !== FALSE) {
            if (count($data) >= 3) {
                $ip     = trim($data[0], " \"");
                $name   = trim($data[1], " \"");
                $secret = trim($data[2], " \"");
                $desc   = isset($data[3]) ? trim($data[3], " \"") : 'Imported';

                $clean_ip = str_replace(',', '.', $ip);

                // Проверка на существование IP в базе
                $check = $db->prepare("SELECT id FROM nas WHERE nasname = ? LIMIT 1");
                $check->execute([$clean_ip]);
                if (!$check->fetch()) {
                    $stmt = $db->prepare("INSERT INTO nas (nasname, shortname, secret, description) VALUES (?, ?, ?, ?)");
                    $stmt->execute([$clean_ip, $name, $secret, $desc]);
                }
            }
        }
        fclose($handle);
        write_log("Массовый импорт NAS (без дубликатов)");
    }
    header("Location: nas.php"); exit;
}

// Добавление нового устройства
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['nasname'])) {
    $stmt = $db->prepare("INSERT INTO nas (nasname, shortname, secret, description) VALUES (?, ?, ?, ?)");
    $stmt->execute([$_POST['nasname'], $_POST['shortname'], $_POST['secret'], $_POST['description']]);
    write_log("Добавлен коммутатор: " . $_POST['nasname']);
    header("Location: nas.php"); exit;
}

// Удаление устройства
if (isset($_GET['del'])) {
    $stmt = $db->prepare("DELETE FROM nas WHERE id = ?");
    $stmt->execute([$_GET['del']]);
    write_log("Удалено устройство ID: " . $_GET['del']);
    header("Location: nas.php"); exit;
}

$nas_list = $db->query("SELECT * FROM nas ORDER BY id DESC")->fetchAll(PDO::FETCH_ASSOC);
?>
<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <title>Устройства - Radius Admin</title>
    <link rel="stylesheet" href="style.css">
</head>
<body>
    <nav>
        <div>
            <a href="index.php">Пользователи</a> | 
            <a href="nas.php"><b>Устройства (NAS)</b></a> | 
            <a href="logs.php">Логи</a>
        </div>
        <a href="?logout=1" class="danger">Выйти</a>
    </nav>

    <?php if (isset($_GET['reloaded'])): ?>
        <div style="background: #d4edda; color: #155724; padding: 10px; border-radius: 8px; margin-bottom: 20px; border: 1px solid #c3e6cb;">
            Сигнал на обновление конфигурации отправлен! Если устройство не заработало, перезапустите контейнер вручную.
        </div>
    <?php endif; ?>

    <div class="card" style="background: #f8f9fa;">
        <strong>Массовые операции:</strong>
        <a href="?export=1" style="text-decoration:none; margin-left:10px;">⬇️ Скачать список (CSV)</a>
        <form method="POST" enctype="multipart/form-data" style="display:inline; margin-left:20px;">
            <input type="file" name="import_nas" accept=".csv" required style="font-size:0.8em;">
            <button type="submit" style="background:#6c757d; padding:4px 10px;">⬆️ Загрузить из CSV</button>
        </form>
    </div>

    <div class="card" style="display: flex; justify-content: space-between; align-items: center; border-left: 5px solid #ffc107;">
	<span><b>Конфигурация изменилась?</b> FreeRADIUS должен перечитать базу устройств.</span>
        <form method="POST">
            <button type="submit" name="reload_services" style="background: #ffc107; color: #000;">Обновить конфигурацию</button>
        </form>
    </div>

    <div class="card">
        <h3>Добавить новый коммутатор / точку доступа</h3>
        <form method="POST">
            <input type="text" name="nasname" placeholder="IP адрес (или подсеть)" required>
            <input type="text" name="shortname" placeholder="Краткое имя (Alias)" required>
            <input type="text" name="secret" placeholder="Shared Secret" required>
            <input type="text" name="description" placeholder="Описание/Расположение">
            <button type="submit" class="success">Добавить NAS</button>
        </form>
        <p style="font-size: 0.8em; color: #666; margin-top: 10px;">
            * После добавления устройства требуется перезагрузка FreeRADIUS.
        </p>
    </div>

    <div class="card">
        <h3>Список доверенных устройств</h3>
        <table>
            <thead>
                <tr>
                    <th>IP Адрес</th>
                    <th>Имя (Alias)</th>
                    <th>Секретный ключ</th>
                    <th>Описание</th>
                    <th></th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($nas_list as $n): ?>
                <tr>
                    <td><b><?=htmlspecialchars($n['nasname'])?></b></td>
                    <td><?=htmlspecialchars($n['shortname'])?></td>
                    <td><code><?=htmlspecialchars($n['secret'])?></code></td>
                    <td><small><?=htmlspecialchars($n['description'])?></small></td>
                    <td><a href="?del=<?=$n['id']?>" class="danger" onclick="return confirm('Удалить устройство?')">✕</a></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</body>
</html>
