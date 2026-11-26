<?php

/**
 * Generate deploy/out/install.sql for MariaDB 11.4 WITHOUT connecting to any database.
 *  - DDL: every migration run in "pretend" mode on a MariaDB connection whose server version is faked.
 *  - Data: rows of seed tables dumped from the local seeded SQLite database (php artisan migrate:fresh --seed).
 * The SQL file is executed by the user in phpMyAdmin (AI never changes the production schema).
 */

use Illuminate\Database\Connection;
use Illuminate\Database\MariaDbConnection;
use Illuminate\Support\Facades\DB;

$root = dirname(__DIR__).'/cits';
require $root.'/vendor/autoload.php';
$app = require $root.'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

class FakeVersionMariaDb extends MariaDbConnection
{
    public function getServerVersion(): string
    {
        return '11.4.10-MariaDB';
    }
}

Connection::resolverFor('mariadb', fn ($pdo, $db, $prefix, $config) => new FakeVersionMariaDb($pdo, $db, $prefix, $config));
config(['database.connections.pretend' => [
    'driver' => 'mariadb', 'host' => '127.0.0.1', 'port' => 1, 'database' => 'x', 'username' => 'x', 'password' => '',
    'charset' => 'utf8mb4', 'collation' => 'utf8mb4_uca1400_ai_ci', 'prefix' => '', 'strict' => true, 'engine' => 'InnoDB',
]]);
$conn = DB::connection('pretend');

$out = [];
$out[] = '-- CITS install script for MariaDB 11.4 ('.date('Y-m-d H:i').')';
$out[] = '-- Run once on an EMPTY database in phpMyAdmin (SQL tab).';
$out[] = 'SET NAMES utf8mb4;';
$out[] = 'SET FOREIGN_KEY_CHECKS = 0;';
$out[] = "CREATE TABLE `migrations` (`id` int unsigned NOT NULL AUTO_INCREMENT PRIMARY KEY, `migration` varchar(255) NOT NULL, `batch` int NOT NULL) DEFAULT CHARACTER SET utf8mb4 COLLATE 'utf8mb4_uca1400_ai_ci' ENGINE = InnoDB;";

$files = glob($root.'/database/migrations/*.php');
sort($files);
foreach ($files as $file) {
    $migration = require $file;
    $queries = $conn->pretend(function () use ($migration, $conn) {
        $prev = DB::getDefaultConnection();
        DB::setDefaultConnection('pretend');
        try {
            $migration->up();
        } finally {
            DB::setDefaultConnection($prev);
        }
    });
    $out[] = '';
    $out[] = '-- '.basename($file);
    foreach ($queries as $q) {
        $sql = $q['query'];
        if ($q['bindings']) {
            throw new RuntimeException('Unexpected bindings in DDL: '.$sql);
        }
        $out[] = rtrim($sql, ';').';';
    }
}

// ---- seed data from local SQLite ----
$local = DB::connection('sqlite');
$quote = function ($v) {
    if ($v === null) {
        return 'NULL';
    }
    if (is_int($v) || is_float($v)) {
        return (string) $v;
    }

    return "'".str_replace(["\\", "'"], ["\\\\", "''"], (string) $v)."'";
};
$tables = ['migrations', 'workflow_statuses', 'workflow_transitions', 'field_edit_rules', 'lookup_items', 'system_settings',
    'permissions', 'roles', 'role_has_permissions', 'users'];
$out[] = '';
$out[] = '-- seed data';
foreach ($tables as $t) {
    $q = $local->table($t);
    if ($t === 'users') {
        $q->where('account', 'admin')->where('is_admin', 1);
    }
    $rows = $q->get();
    foreach ($rows as $r) {
        $r = (array) $r;
        if ($t === 'migrations') {
            $r['batch'] = 1;
        }
        $out[] = sprintf('INSERT INTO `%s` (%s) VALUES (%s);', $t,
            implode(', ', array_map(fn ($c) => "`$c`", array_keys($r))),
            implode(', ', array_map($quote, array_values($r))));
    }
}
$out[] = '';
$out[] = 'SET FOREIGN_KEY_CHECKS = 1;';

@mkdir(__DIR__.'/out', 0777, true);
file_put_contents(__DIR__.'/out/install.sql', implode("\n", $out)."\n");
echo 'install.sql: '.count($out)." lines\n";
