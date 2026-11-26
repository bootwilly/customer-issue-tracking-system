<?php

namespace App\Services\Export;

/** A cell value rendered as an external hyperlink (absolute URL, e.g. url('/issues/5')). */
final class ExportLink
{
    public function __construct(public string $text, public string $url) {}
}
