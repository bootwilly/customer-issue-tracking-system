<?php

/**
 * Generate deploy/out/upgrade-<date>.sql for an EXISTING production database, WITHOUT connecting to it.
 *  - Applied migrations = names listed in the migrations INSERTs of deploy/out/install.sql
 *    plus any extra names passed with --applied=a,b (for databases already upgraded before).
 *  - DDL: every migration file NOT yet applied, run in "pretend" mode on a faked MariaDB 11.4 connection.
 *  - Data: INSERT IGNORE of lookup_items / system_settings rows from database/seeders/data/workflow.php
 *    (unique keys make it safe to run twice; existing rows are never changed).
 * The user executes the SQL in phpMyAdmin. AI never changes the production database.
 *
 * Usage: php deploy/make_upgrade_sql.php [--applied=name1,name2]
 */

use Illuminate\Database\Connection;
use Illuminate\Database\MariaDbConnection;
use Illuminate\Support\Facades\DB;

$root = dirname(__DIR__).'/cits';
require $root.'/vendor/autoload.php';
$app = require $root.'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

class FakeVersionMariaDbUpgrade extends MariaDbConnection
{
    public function getServerVersion(): string
    {
        return '11.4.10-MariaDB';
    }
}

Connection::resolverFor('mariadb', fn ($pdo, $db, $prefix, $config) => new FakeVersionMariaDbUpgrade($pdo, $db, $prefix, $config));
config(['database.connections.pretend' => [
    'driver' => 'mariadb', 'host' => '127.0.0.1', 'port' => 1, 'database' => 'x', 'username' => 'x', 'password' => '',
    'charset' => 'utf8mb4', 'collation' => 'utf8mb4_uca1400_ai_ci', 'prefix' => '', 'strict' => true, 'engine' => 'InnoDB',
]]);
$conn = DB::connection('pretend');

// ---- applied migrations ----
$install = @file_get_contents(__DIR__.'/out/install.sql') ?: throw new RuntimeException('deploy/out/install.sql not found (baseline).');
preg_match_all("/INSERT INTO `migrations` \\(`id`, `migration`, `batch`\\) VALUES \\(\\d+, '([^']+)'/", $install, $m);
$applied = $m[1];
foreach ($argv as $a) {
    if (str_starts_with($a, '--applied=')) {
        $applied = array_merge($applied, array_filter(explode(',', substr($a, 10))));
    }
}
if (! $applied) {
    throw new RuntimeException('No baseline migrations found in install.sql.');
}

$files = glob($root.'/database/migrations/*.php');
sort($files);
$pending = array_values(array_filter($files, fn ($f) => ! in_array(basename($f, '.php'), $applied, true)));

$out = [];
$out[] = '-- CITS upgrade script for MariaDB 11.4 ('.date('Y-m-d H:i').')';
$out[] = '-- Run ONCE in phpMyAdmin (SQL tab) on the production database. Back up first (Export).';
$out[] = '-- Contains: new tables/columns from migrations, migration records, new lookup/setting rows (INSERT IGNORE).';
$out[] = 'SET NAMES utf8mb4;';

foreach ($pending as $file) {
    $migration = require $file;
    $queries = $conn->pretend(function () use ($migration) {
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
        if ($q['bindings']) {
            throw new RuntimeException('Unexpected bindings in DDL: '.$q['query']);
        }
        $out[] = rtrim($q['query'], ';').';';
    }
}

$quote = fn ($v) => $v === null ? 'NULL' : (is_int($v) || is_float($v) ? (string) $v : (is_bool($v) ? ($v ? '1' : '0') : "'".str_replace(['\\', "'"], ['\\\\', "''"], (string) $v)."'"));

if ($pending) {
    $out[] = '';
    $out[] = '-- migration records';
    $batch = '(SELECT COALESCE(MAX(`batch`), 0) + 1 FROM `migrations` m)';
    foreach ($pending as $file) {
        $out[] = sprintf('INSERT INTO `migrations` (`migration`, `batch`) SELECT %s, %s;', $quote(basename($file, '.php')), $batch);
    }
}

$d = require $root.'/database/seeders/data/workflow.php';
$out[] = '';
$out[] = '-- workflow_statuses / workflow_transitions (unique scope+code / scope+from+to; existing rows untouched)';
foreach ($d['statuses'] as [$scope, $code, $name, $stage, $tab, $terminal, $excluded, $released, $sort]) {
    $out[] = sprintf('INSERT IGNORE INTO `workflow_statuses` (`scope`, `code`, `name`, `stage`, `client_tab`, `is_terminal`, `is_excluded`, `is_released`, `sort`) VALUES (%s, %s, %s, %s, %s, %s, %s, %s, %s);',
        $quote($scope), $quote($code), $quote($name), $quote($stage), $quote($tab), $quote($terminal), $quote($excluded), $quote($released), $quote((int) $sort));
}
foreach ($d['transitions'] as [$scope, $from, $to, $perm, $req]) {
    $out[] = sprintf('INSERT IGNORE INTO `workflow_transitions` (`scope`, `from_code`, `to_code`, `permission`, `requires_fields`) VALUES (%s, %s, %s, %s, %s);',
        $quote($scope), $quote($from), $quote($to), $quote($perm), $quote($req === null ? null : json_encode($req)));
}
$out[] = '';
$out[] = '-- lookup_items (existing rows untouched)';
foreach ($d['lookups'] as [$cat, $code, $name, $sort]) {
    $out[] = sprintf('INSERT IGNORE INTO `lookup_items` (`category`, `code`, `name`, `sort`, `is_active`) VALUES (%s, %s, %s, %s, 1);',
        $quote($cat), $quote($code), $quote($name), $quote((int) $sort));
}
$out[] = '';
$out[] = '-- system_settings (existing rows untouched)';
foreach ($d['system_settings'] as $row) {
    [$key, $value] = $row;
    $out[] = sprintf('INSERT IGNORE INTO `system_settings` (`key`, `value`) VALUES (%s, %s);', $quote($key), $quote($value));
}

$out[] = '';
$out[] = '-- permissions and role grants (spatie; existing rows untouched). After running, the app clears the permission cache on deploy.';
$now = $quote(date('Y-m-d H:i:s'));
$perms = [];
foreach ($d['roles'] as $code => [$type, $label, $list]) {
    $perms = array_merge($perms, $list);
}
// roles are global (project_id NULL): NULLs never collide in a unique key, so guard with NOT EXISTS
foreach ($d['roles'] as $code => $role) {
    $out[] = sprintf("INSERT INTO `roles` (`project_id`, `name`, `guard_name`, `created_at`, `updated_at`) SELECT NULL, %s, 'web', %s, %s FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM `roles` WHERE `name` = %s AND `guard_name` = 'web' AND `project_id` IS NULL);", $quote($code), $now, $now, $quote($code));
}
foreach (array_unique($perms) as $p) {
    $out[] = sprintf("INSERT IGNORE INTO `permissions` (`name`, `guard_name`, `created_at`, `updated_at`) VALUES (%s, 'web', %s, %s);", $quote($p), $now, $now);
}
foreach ($d['roles'] as $code => [$type, $label, $list]) {
    foreach ($list as $p) {
        $out[] = sprintf("INSERT IGNORE INTO `role_has_permissions` (`permission_id`, `role_id`) SELECT p.`id`, r.`id` FROM `permissions` p JOIN `roles` r ON r.`name` = %s AND r.`guard_name` = 'web' WHERE p.`name` = %s AND p.`guard_name` = 'web';", $quote($code), $quote($p));
    }
}

@mkdir(__DIR__.'/out', 0777, true);
$name = 'upgrade-'.date('Ymd-Hi').'.sql';
file_put_contents(__DIR__.'/out/'.$name, implode("\n", $out)."\n");
echo "$name: ".count($pending).' migrations, '.count($out)." lines\n";
foreach ($pending as $f) {
    echo '  + '.basename($f)."\n";
}
