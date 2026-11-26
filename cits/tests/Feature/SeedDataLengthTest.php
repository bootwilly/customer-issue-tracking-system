<?php

use Illuminate\Database\Connection;
use Illuminate\Database\MariaDbConnection;
use Illuminate\Support\Facades\DB;

/**
 * SQLite ignores varchar lengths and production (MariaDB, sql_mode '') truncates silently,
 * so read the real sizes from the MariaDB DDL of every migration (pretend mode, no connection) and check the seed data.
 *
 * @return array<string, array<string,int>> table => column => varchar length
 */
function seedLenVarchars(): array
{
    Connection::resolverFor('mariadb', fn ($pdo, $db, $prefix, $config) => new class($pdo, $db, $prefix, $config) extends MariaDbConnection
    {
        public function getServerVersion(): string
        {
            return '11.4.10-MariaDB';
        }
    });
    config(['database.connections.lenprobe' => [
        'driver' => 'mariadb', 'host' => '127.0.0.1', 'port' => 1, 'database' => 'x', 'username' => 'x', 'password' => '',
        'charset' => 'utf8mb4', 'collation' => 'utf8mb4_uca1400_ai_ci', 'prefix' => '', 'strict' => true,
    ]]);
    $conn = DB::connection('lenprobe');
    $files = glob(database_path('migrations/*.php'));
    sort($files);
    $sizes = [];
    foreach ($files as $file) {
        $migration = require $file;
        $queries = $conn->pretend(function () use ($migration) {
            $prev = DB::getDefaultConnection();
            DB::setDefaultConnection('lenprobe');
            try {
                $migration->up();
            } finally {
                DB::setDefaultConnection($prev);
            }
        });
        foreach ($queries as $q) {
            if (preg_match('/^(?:create|alter) table `(\w+)`/i', $q['query'], $t) && preg_match_all('/`(\w+)` varchar\((\d+)\)/i', $q['query'], $cols, PREG_SET_ORDER)) {
                foreach ($cols as [, $col, $n]) {
                    $sizes[$t[1]][$col] = (int) $n;
                }
            }
        }
    }

    return $sizes;
}

it('keeps seed data within the column sizes', function () {
    $sizes = seedLenVarchars();
    expect($sizes['workflow_statuses']['scope'] ?? null)->toBe(30);
    $d = require database_path('seeders/data/workflow.php');
    $check = function (string $table, string $column, mixed $value) use ($sizes) {
        $max = $sizes[$table][$column] ?? throw new RuntimeException("no varchar size for $table.$column");
        expect(mb_strlen((string) $value))->toBeLessThanOrEqual($max, "$table.$column ($max) too short for '$value'");
    };

    foreach ($d['statuses'] as [$scope, $code, $name, $stage, $tab]) {
        foreach (['scope' => $scope, 'code' => $code, 'name' => $name, 'stage' => $stage, 'client_tab' => $tab] as $col => $v) {
            $check('workflow_statuses', $col, $v);
        }
    }
    foreach ($d['transitions'] as [$scope, $from, $to, $perm]) {
        foreach (['scope' => $scope, 'from_code' => $from, 'to_code' => $to, 'permission' => $perm] as $col => $v) {
            $check('workflow_transitions', $col, $v);
        }
    }
    foreach ($d['lookups'] as [$cat, $code, $name]) {
        $check('lookup_items', 'category', $cat);
        $check('lookup_items', 'code', $code);
        $check('lookup_items', 'name', $name);
        if ($cat === 'event_label') {
            $check('events', 'event_type', $code);
        }
    }
    foreach ($d['system_settings'] as [$key]) {
        $check('system_settings', 'key', $key);
    }
});
