<?php
$db_path = '/data/radius.db';
try {
    $db = new PDO("sqlite:$db_path");
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch (PDOException $e) {
    die("Ошибка БД: " . $e->getMessage());
}

// ЛОГИКА ВЫХОДА
if (isset($_GET['logout'])) {
    header('WWW-Authenticate: Basic realm="Radius Admin"');
    header('HTTP/1.0 401 Unauthorized');
    echo '<script>window.location.href="index.php";</script>'; // Перенаправляем на чистый вход
    die('Вы вышли из системы. <a href="index.php">Войти снова</a>');
}

// Авторизация
if (!isset($_SERVER['PHP_AUTH_USER'])) {
    auth_header();
} else {
    $stmt = $db->prepare("SELECT password FROM operators WHERE username = ? LIMIT 1");
    $stmt->execute([$_SERVER['PHP_AUTH_USER']]);
    $db_pass = $stmt->fetchColumn();

    if (!$db_pass && $_SERVER['PHP_AUTH_USER'] === 'admin' && $_SERVER['PHP_AUTH_PW'] === 'admin') {
        $db->prepare("INSERT INTO operators (username, password) VALUES ('admin', 'admin')")->execute();
    } elseif ($db_pass !== $_SERVER['PHP_AUTH_PW']) {
        auth_header();
    }
}

function auth_header() {
    header('WWW-Authenticate: Basic realm="Radius Admin"');
    header('HTTP/1.0 401 Unauthorized');
    die('Авторизация требуется.');
}

function write_log($message) {
    $log_file = '/var/www/html/admin.log';
    if (file_exists($log_file) && date("Y-m-d", filemtime($log_file)) !== date("Y-m-d")) {
        rename($log_file, $log_file . '.' . date("Y-m-d", filemtime($log_file)));
    }
    $entry = "[" . date("Y-m-d H:i:s") . "] [" . ($_SERVER['PHP_AUTH_USER'] ?? 'System') . "] " . $message . "\n";
    @file_put_contents($log_file, $entry, FILE_APPEND);
}

// ЛОГИКА ПЕРЕЗАПУСКА (в конец auth.php)
if (isset($_POST['reload_services'])) {
    // Попытка отправить сигнал перечитывания конфигов процессу внутри другого контейнера
    // Внимание: это сработает только если контейнеры в одной сети и есть права, 
    // поэтому мы добавим визуальное подтверждение.
    shell_exec("docker exec freeradius kill -HUP 1 2>&1");
    write_log("Запрошена синхронизация конфигурации (Reload SIGHUP)");
    header("Location: " . $_SERVER['PHP_SELF'] . "?reloaded=1");
    exit;
}


//Экспорт в файл
function export_to_csv($table, $filename) {
    global $db;
    $stmt = $db->query("SELECT * FROM $table");
    $data = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    if (!$data) return;

    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename=' . $filename);
    
    $output = fopen('php://output', 'w');
    
    foreach ($data as $row) {
        if ($table === 'radcheck') {
            $line = [$row['username'], $row['value']];
        } elseif ($table === 'nas') {
            // Очищаем IP и берем поле description
            $ip = str_replace(['"', ','], ['', '.'], $row['nasname']);
            $line = [
                $ip, 
                $row['shortname'], 
                $row['secret'], 
                $row['description'] // Добавили четвертое поле
            ];
        } else {
            $line = $row;
        }
        
        fputcsv($output, $line, ",", '"', "\\"); 
    }
    fclose($output);
    exit;
}

?>
