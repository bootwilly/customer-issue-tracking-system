<?php

namespace App\Repositories;

use App\Models\Project;
use Illuminate\Support\Facades\DB;

class CounterRepository
{
    /** Must be called inside a transaction. Row lock keeps numbers unique under concurrency. */
    public function next(int $projectId, string $kind, int $year): int
    {
        $key = ['project_id' => $projectId, 'kind' => $kind, 'year' => $year];
        DB::table('project_counters')->insertOrIgnore($key + ['last_no' => 0]);
        $row = DB::table('project_counters')->where($key)->lockForUpdate()->first();
        $no = (int) $row->last_no + 1;
        DB::table('project_counters')->where($key)->update(['last_no' => $no]);

        return $no;
    }

    /** Number columns are unique across all projects (deleted issues included): kind => [table, column]. */
    private const NUMBER_COLUMNS = ['ticket' => ['client_tickets', 'ticket_no'], 'issue' => ['issues', 'issue_no']];

    /** Whether a ticket / issue number is already used by any project (a prefix may have belonged to another project before). */
    public function numberTaken(string $kind, string $number): bool
    {
        [$table, $column] = self::NUMBER_COLUMNS[$kind] ?? throw new \InvalidArgumentException("unknown number kind {$kind}");

        return DB::table($table)->where($column, $number)->exists();
    }

    public function projectPrefix(int $projectId): string
    {
        return (string) Project::whereKey($projectId)->value('ticket_prefix');
    }
}
