<?php

namespace App\Services\Export;

use App\Services\WorkflowService;

/** Columns of an export = intersection of the code whitelist and lookup category export_<list> (order/labels/active from lookup). */
class ExportColumnService
{
    public function __construct(private WorkflowService $wf) {}

    /**
     * @param  string[]  $whitelist  column keys the caller can resolve
     * @return array<string,string> key => header
     */
    public function columns(string $listKey, array $whitelist): array
    {
        $map = $this->wf->lookupMap('export_'.$listKey);

        return array_intersect_key($map, array_flip($whitelist));
    }
}
