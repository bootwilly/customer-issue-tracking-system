<?php

namespace App\Repositories;

use App\Models\MesPage;

class ClientMesLookupRepository
{
    /** @return array<int,string> active page node id => "folder / page (file)" */
    public function pageOptions(int $projectId): array
    {
        $nodes = MesPage::where('project_id', $projectId)->where('is_active', true)
            ->orderBy('sort')->orderBy('id')->get(['id', 'parent_id', 'node_type', 'name', 'page_file'])->keyBy('id');

        $out = [];
        foreach ($nodes as $n) {
            if ($n->node_type !== 'page') {
                continue;
            }
            $parts = [$n->name];
            $p = $n->parent_id ? $nodes->get($n->parent_id) : null;
            $guard = 0;
            while ($p && $guard++ < 20) {
                array_unshift($parts, $p->name);
                $p = $p->parent_id ? $nodes->get($p->parent_id) : null;
            }
            $label = implode(' / ', $parts);
            $out[$n->id] = $n->page_file ? "$label ($n->page_file)" : $label;
        }
        asort($out);

        return $out;
    }
}
