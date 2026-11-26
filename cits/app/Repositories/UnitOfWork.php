<?php

namespace App\Repositories;

use Illuminate\Support\Facades\DB;

class UnitOfWork
{
    /**
     * Run a callback inside one database transaction.
     *
     * @template T
     *
     * @param  callable(): T  $fn
     * @return T
     */
    public function run(callable $fn): mixed
    {
        return DB::transaction($fn, 3);
    }
}
