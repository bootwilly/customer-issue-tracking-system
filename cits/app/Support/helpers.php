<?php

use Illuminate\Support\Carbon;

if (! function_exists('tw')) {
    /** Format a UTC datetime for display in Asia/Taipei (Y-m-d H:i). */
    function tw($dt, string $format = 'Y-m-d H:i'): string
    {
        if ($dt === null || $dt === '') {
            return '';
        }

        return Carbon::parse($dt)->timezone('Asia/Taipei')->format($format);
    }
}
