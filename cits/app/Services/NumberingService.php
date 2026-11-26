<?php

namespace App\Services;

use App\Repositories\CounterRepository;

class NumberingService
{
    public function __construct(private CounterRepository $repo) {}

    /** Ticket: PREFIX-2026-0001; Issue: PREFIX-I2026-0001. Call inside a transaction. */
    public function next(int $projectId, string $kind): string
    {
        $year = (int) now('Asia/Taipei')->format('Y');
        $no = $this->repo->next($projectId, $kind, $year);
        $prefix = $this->repo->projectPrefix($projectId);

        return sprintf('%s-%s%d-%04d', $prefix, $kind === 'issue' ? 'I' : '', $year, $no);
    }
}
