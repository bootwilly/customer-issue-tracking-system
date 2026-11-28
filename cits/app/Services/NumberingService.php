<?php

namespace App\Services;

use App\Repositories\CounterRepository;

class NumberingService
{
    /** Numbers skipped in one call before giving up (a prefix reused from another project leaves gaps to jump). */
    private const MAX_SKIP = 10000;

    public function __construct(private CounterRepository $repo) {}

    /**
     * Ticket: PREFIX-2026-0001; Issue: PREFIX-I2026-0001. Call inside a transaction.
     * The counter is per project but numbers are unique system-wide: a number already used (e.g. by a project that had
     * this prefix before) is skipped, and the counter moves past it.
     */
    public function next(int $projectId, string $kind): string
    {
        $year = (int) now('Asia/Taipei')->format('Y');
        $prefix = $this->repo->projectPrefix($projectId);
        for ($i = 0; $i <= self::MAX_SKIP; $i++) {
            $number = sprintf('%s-%s%d-%04d', $prefix, $kind === 'issue' ? 'I' : '', $year, $this->repo->next($projectId, $kind, $year));
            if (! $this->repo->numberTaken($kind, $number)) {
                return $number;
            }
        }

        throw new \RuntimeException("no free {$kind} number for project {$projectId}");
    }
}
