<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * workflow scope was varchar(10): "meeting_item" got truncated to "meeting_it" on production (sql_mode '' truncates silently).
 * Widen it and repair the truncated rows.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('workflow_statuses', fn (Blueprint $t) => $t->string('scope', 30)->change());
        Schema::table('workflow_transitions', fn (Blueprint $t) => $t->string('scope', 30)->change());

        DB::statement("UPDATE workflow_statuses SET scope = 'meeting_item' WHERE scope = 'meeting_it'");
        DB::statement("UPDATE workflow_transitions SET scope = 'meeting_item' WHERE scope = 'meeting_it'");
    }

    public function down(): void
    {
        // narrowing back would truncate "meeting_item" again
    }
};
