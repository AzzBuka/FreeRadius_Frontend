<?php
$db_path = '/data/radius.db';

// 1. Принудительное извлечение Basic Auth из заголовков (Фикс для Docker/Apache)
if (isset($_SERVER['HTTP_AUTHORIZATION']) && preg_match('/Basic\s+(.*)$/i', $_SERVER['HTTP_AUTHORIZATION'], $matches)) {
    list($extracted_user, $extracted_pw) = explode(':', base64_decode($matches[1]), 2);
    $_SERVER['PHP_AUTH_USER'] = $extracted_user;
    $_SERVER['PHP_AUTH_PW'] = $extracted_pw;
} elseif (isset($_SERVER['REDIRECT_HTTP_AUTHORIZATION']) && preg_match('/Basic\s+(.*)$/i', $_SERVER['REDIRECT_HTTP_AUTHORIZATION'], $matches)) {
    list($extracted_user, $extracted_pw) = explode(':', base64_decode($matches[1]), 2);
    $_SERVER['PHP_AUTH_USER'] = $extracted_user;
    $_SERVER['PHP_AUTH_PW'] = $extracted_pw;
}

// Проверяем, пустая ли база или файла нет
$need_init = (!file_exists($db_path) || filesize($db_path) === 0);

try {
    $db = new PDO("sqlite:$db_path");
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    
    if ($need_init) {
        $db->exec("CREATE TABLE IF NOT EXISTS operators (id INTEGER PRIMARY KEY AUTOINCREMENT, username TEXT NOT NULL UNIQUE, password TEXT NOT NULL);");
        $db->exec("CREATE TABLE IF NOT EXISTS radcheck (id INTEGER PRIMARY KEY AUTOINCREMENT, username TEXT NOT NULL, attribute TEXT NOT NULL, op VARCHAR(2) NOT NULL DEFAULT '==', value TEXT NOT NULL);");
        $db->exec("CREATE TABLE IF NOT EXISTS radreply (id INTEGER PRIMARY KEY AUTOINCREMENT, username TEXT NOT NULL, attribute TEXT NOT NULL, op VARCHAR(2) NOT NULL DEFAULT '=', value TEXT NOT NULL);");
        $db->exec("CREATE TABLE IF NOT EXISTS nas (id INTEGER PRIMARY KEY AUTOINCREMENT, nasname TEXT NOT NULL, shortname TEXT, type TEXT, ports INTEGER, secret TEXT NOT NULL, community TEXT, description TEXT);");
        $db->exec("CREATE TABLE IF NOT EXISTS radacct (radacctid INTEGER PRIMARY KEY AUTOINCREMENT, acctsessionid TEXT, acctuniqueid TEXT, username TEXT, groupname TEXT, realm TEXT, nasipaddress TEXT, nasportid TEXT, nasporttype TEXT, acctstarttime DATETIME, acctstoptime DATETIME, acctsessiontime INTEGER, acctauthentic TEXT, connectinfo_start TEXT, connectinfo_stop TEXT, acctinputoctets INTEGER, acctoutputoctets INTEGER, calledstationid TEXT, callingstationid TEXT, acctterminatecause TEXT, servicetype TEXT, framedprotocol TEXT, framedipaddress TEXT, acctstartdelay INTEGER, acctstopdelay INTEGER);");
        $db->exec("CREATE TABLE IF NOT EXISTS radpostauth (id INTEGER PRIMARY KEY AUTOINCREMENT, username TEXT NOT NULL, pass TEXT, reply TEXT, authdate DATETIME DEFAULT CURRENT_TIMESTAMP);");
    }
} catch (PDOException $e) {
    die("Ошибка БД: " . $e->getMessage());
}

// ЛОГИКА ВЫХОДА
if (isset($_GET['logout'])) {
    header('WWW-Authenticate: Basic realm="Radius Admin"');
    header('HTTP/1.0 401 Unauthorized');
    echo '<script>window.location.href="index.php";</script>';
    die('Вы вышли из системы.');
}

// Авторизация
if (!isset($_SERVER['PHP_AUTH_USER']) || !isset($_SERVER['PHP_AUTH_PW'])) {
    auth_header();
} else {
    $input_user = trim($_SERVER['PHP_AUTH_USER']);
    $input_pass = trim($_SERVER['PHP_AUTH_PW']);

    $stmt = $db->prepare("SELECT password FROM operators WHERE username = ? LIMIT 1");
    $stmt->execute([$input_user]);
    $db_pass = $stmt->fetchColumn();

    if ($db_pass === false) {
        // Если пользователя нет, но это дефолтный admin/admin - создаем
        if ($input_user === 'admin' && $input_pass === 'admin') {
            $db->prepare("INSERT INTO operators (username, password) VALUES ('admin', 'admin')")->execute();
            write_log("Первоначальная инициализация админа 'admin'");
        } else {
            write_log("Отказ входа: пользователь '{$input_user}' не найден в базе данных operators");
            auth_header();
        }
    } else {
        // Очищаем пароль из базы от возможных пробелов при записи
        $db_pass = trim($db_pass);
        
        if ($db_pass !== $input_pass) {
            write_log("Отказ входа: неверный пароль для пользователя '{$input_user}'");
            auth_header();
        }
        // Если всё совпало - пускаем дальше
    }
}

function auth_header() {
    header('WWW-Authenticate: Basic realm="Radius Admin"');
    header('HTTP/1.0 401 Unauthorized');
    die('Авторизация требуется.');
}

function write_log($message) {
    $log_file = '/var/www/html/admin.log';
    $entry = "[" . date("Y-m-d H:i:s") . "] [" . ($_SERVER['PHP_AUTH_USER'] ?? 'System') . "] " . $message . "\n";
    @file_put_contents($log_file, $entry, FILE_APPEND);
}

if (isset($_POST['reload_services'])) {
    shell_exec("docker exec freeradius kill -HUP 1 2>&1");
    write_log("Запрошена синхронизация конфигурации (Reload SIGHUP)");
    header("Location: " . $_SERVER['PHP_SELF'] . "?reloaded=1");
    exit;
}

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
            $line = [$ip, $row['shortname'], $row['secret'], $row['description']];
        } else {
            $line = $row;
        }
        fputcsv($output, $line, ",", '"', "\\");
    }
    fclose($output);
    exit;
}
?>
