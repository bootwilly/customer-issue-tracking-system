<?php

namespace App\Repositories;

use Illuminate\Support\Facades\DB;

class IssueTagRepository
{
    /** @return string[] */
    public function codesOf(int $issueId): array
    {
        return DB::table('issue_tags')->where('issue_id', $issueId)->orderBy('tag_code')->pluck('tag_code')->all();
    }

    /** @param int[] $issueIds @return array<int, string[]> issue id => tag codes */
    public function codesOfMany(array $issueIds): array
    {
        if (! $issueIds) {
            return [];
        }
        $out = [];
        foreach (DB::table('issue_tags')->whereIn('issue_id', $issueIds)->orderBy('tag_code')->get(['issue_id', 'tag_code']) as $r) {
            $out[(int) $r->issue_id][] = $r->tag_code;
        }

        return $out;
    }

    /** @param string[] $add @param string[] $remove */
    public function change(int $issueId, array $add, array $remove, int $userId): void
    {
        if ($remove) {
            DB::table('issue_tags')->where('issue_id', $issueId)->whereIn('tag_code', $remove)->delete();
        }
        $now = now();
        DB::table('issue_tags')->insertOrIgnore(array_map(fn ($c) => ['issue_id' => $issueId, 'tag_code' => $c, 'created_by' => $userId, 'created_at' => $now], $add));
    }
}
