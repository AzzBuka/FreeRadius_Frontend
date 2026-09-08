<?php
$db_path = '/data/radius.db';

// Проверяем, пустая ли база или файла нет, чтобы запустить автоинициализацию
$need_init = (!file_exists($db_path) || filesize($db_path) === 0);

try {
    $db = new PDO("sqlite:$db_path");
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    
    // Если база новая/пустая, автоматически накатываем структуру таблиц
    if ($need_init) {
        // 1. Таблица администраторов веб-интерфейса (операторов)
        $db->exec("CREATE TABLE IF NOT EXISTS operators (
            id INTEGER PRIMARY KEY AUTOINCREMENT, 
            username TEXT NOT NULL UNIQUE, 
            password TEXT NOT NULL
        );");

        // 2. Основная таблица FreeRADIUS для проверки пользователей (radcheck)
        $db->exec("CREATE TABLE IF NOT EXISTS radcheck (
            id INTEGER PRIMARY KEY AUTOINCREMENT, 
            username TEXT NOT NULL, 
            attribute TEXT NOT NULL, 
            op VARCHAR(2) NOT NULL DEFAULT '==', 
            value TEXT NOT NULL
        );");

        // 3. Таблица ответов FreeRADIUS (radreply)
        $db->exec("CREATE TABLE IF NOT EXISTS radreply (
            id INTEGER PRIMARY KEY AUTOINCREMENT, 
            username TEXT NOT NULL, 
            attribute TEXT NOT NULL, 
            op VARCHAR(2) NOT NULL DEFAULT '=', 
            value TEXT NOT NULL
        );");

        // 4. Таблица сетевого оборудования (nas)
        $db->exec("CREATE TABLE IF NOT EXISTS nas (
            id INTEGER PRIMARY KEY AUTOINCREMENT, 
            nasname TEXT NOT NULL, 
            shortname TEXT, 
            type TEXT, 
            ports INTEGER, 
            secret TEXT NOT NULL, 
            community TEXT, 
            description TEXT
        );");

        // 5. Таблица аккаунтинга и логов сессий (radacct)
        $db->exec("CREATE TABLE IF NOT EXISTS radacct (
            radacctid INTEGER PRIMARY KEY AUTOINCREMENT, 
            acctsessionid TEXT, 
            acctuniqueid TEXT, 
            username TEXT, 
            groupname TEXT, 
            realm TEXT, 
            nasipaddress TEXT, 
            nasportid TEXT, 
            nasporttype TEXT, 
            acctstarttime DATETIME, 
            acctstoptime DATETIME, 
            acctsessiontime INTEGER, 
            acctauthentic TEXT, 
            connectinfo_start TEXT, 
            connectinfo_stop TEXT, 
            acctinputoctets INTEGER, 
            acctoutputoctets INTEGER, 
            calledstationid TEXT, 
            callingstationid TEXT, 
            acctterminatecause TEXT, 
            servicetype TEXT, 
            framedprotocol TEXT, 
            framedipaddress TEXT, 
            acctstartdelay INTEGER, 
            acctstopdelay INTEGER
        );");

        // 6. Таблица истории аутентификации (radpostauth)
        $db->exec("CREATE TABLE IF NOT EXISTS radpostauth (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            username TEXT NOT NULL,
            pass TEXT,
            reply TEXT,
            authdate DATETIME DEFAULT CURRENT_TIMESTAMP
        );");
    }
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

// Авторизация с защитой от Undefined array key и исправленным синтаксисом кавычек
if (!isset($_SERVER['PHP_AUTH_USER']) || !isset($_SERVER['PHP_AUTH_PW'])) {
    auth_header();
} else {
    $stmt = $db->prepare("SELECT password FROM operators WHERE username = ? LIMIT 1");
    $stmt->execute([$_SERVER['PHP_AUTH_USER']]);
    $db_pass = $stmt->fetchColumn();

    // Если в базе пусто, а вводят дефолтные admin/admin — создаем запись администратора
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

// ЛОГИКА ПЕРЕЗАПУСКА (SIGHUP для FreeRADIUS контейнера)
if (isset($_POST['reload_services'])) {
    shell_exec("docker exec freeradius kill -HUP 1 2>&1");
    write_log("Запрошена синхронизация конфигурации (Reload SIGHUP)");
    header("Location: " . $_SERVER['PHP_SELF'] . "?reloaded=1");
    exit;
}

// Экспорт таблиц в CSV файл
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
            $ip = str_replace(['"', ','], ['', '.'], $row['nasname']);
            $line = [
                $ip,
                $row['shortname'],
                $row['secret'],
                $row['description']
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
