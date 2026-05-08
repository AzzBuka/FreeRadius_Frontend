<?php 
require 'auth.php'; 
$auth_logs = $db->query("SELECT * FROM radpostauth ORDER BY authdate DESC LIMIT 50")->fetchAll(PDO::FETCH_ASSOC);
$admin_log = '/var/www/html/admin.log';
?>
<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <title>Logs - Radius Admin</title>
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

    <div class="card">
        <h3>Журнал авторизаций FreeRADIUS</h3>
        <table style="font-size: 13px;">
            <thead><tr><th>Дата</th><th>Логин</th><th>Результат</th></tr></thead>
            <?php foreach ($auth_logs as $l): ?>
            <tr class="<?=($l['reply']=='Access-Accept'?'row-ok':'row-fail')?>">
                <td><?=$l['authdate']?></td>
                <td><?=htmlspecialchars($l['username'])?></td>
                <td><?=$l['reply']?></td>
            </tr>
            <?php endforeach; ?>
        </table>
    </div>

    <div class="card">
        <h3>Лог действий в панели</h3>
        <pre class="console"><?php 
            if (file_exists($admin_log)) {
                $lines = array_reverse(explode("\n", trim(file_get_contents($admin_log))));
                echo htmlspecialchars(implode("\n", $lines));
            }
        ?></pre>
    </div>
</body>
</html>
