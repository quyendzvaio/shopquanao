<?php
// Fix DB: creates all missing tables
require_once __DIR__ . '/config/db.php';
header('Content-Type: text/plain; charset=utf-8');

$db = $pdo->query("SELECT DATABASE()")->fetchColumn();
echo "DB: $db\n";

$tables = $pdo->query("SELECT TABLE_NAME FROM information_schema.tables WHERE table_schema='$db'")->fetchAll(PDO::FETCH_COLUMN);
echo "Tables: " . count($tables) . " - " . implode(', ', $tables) . "\n\n";

if (in_array('users', $tables)) {
    echo "users OK\n";
    exit;
}

echo "Creating tables...\n";

$sql = file_get_contents(__DIR__ . '/sql/shop_db.sql');
$lines = explode(";\n", $sql);
$done = 0;
foreach ($lines as $line) {
    $line = trim($line);
    if (empty($line) || substr($line, 0, 2) === '--' || substr($line, 0, 2) === '/*') continue;
    try {
        $pdo->exec($line);
        $done++;
    } catch (Exception $e) {
        echo "SKIP: " . substr($line, 0, 40) . "... => " . $e->getMessage() . "\n";
    }
}

$tables2 = $pdo->query("SELECT TABLE_NAME FROM information_schema.tables WHERE table_schema='$db'")->fetchAll(PDO::FETCH_COLUMN);
echo "\nDone. Now have " . count($tables2) . " tables: " . implode(', ', $tables2) . "\n";
echo "Has users: " . (in_array('users', $tables2) ? 'YES' : 'NO') . "\n";
