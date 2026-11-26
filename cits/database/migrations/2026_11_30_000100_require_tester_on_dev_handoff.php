<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

// DEV -> test_pending must name a tester (assignee_test_id); an issue that already has one keeps it (IssueService::transition).
// The upgrade SQL only INSERT IGNOREs transitions, so the changed requires_fields of this existing row ships here.
return new class extends Migration
{
    public function up(): void
    {
        // raw statement: the seed-length test compiles migrations in pretend mode without a connection
        DB::statement("UPDATE workflow_transitions SET requires_fields = '[\"package_id\",\"assignee_test_id\"]' WHERE scope = 'issue' AND from_code = 'dev_doing' AND to_code = 'test_pending'");
    }

    public function down(): void
    {
        DB::statement("UPDATE workflow_transitions SET requires_fields = '[\"package_id\"]' WHERE scope = 'issue' AND from_code = 'dev_doing' AND to_code = 'test_pending'");
    }
};
