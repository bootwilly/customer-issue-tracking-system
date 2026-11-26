<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Prod was upgraded by hand with the exact same DDL (see SESSION_LOG 2026-11-25): keep both in sync.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('project_flows', function (Blueprint $t) {
            $t->string('format', 16)->default('mermaid')->after('title');
            $t->mediumText('search_text')->nullable()->after('source_md');
        });
        Schema::table('project_flow_revisions', function (Blueprint $t) {
            $t->string('format', 16)->default('mermaid')->after('title');
        });
    }

    public function down(): void
    {
        Schema::table('project_flow_revisions', fn (Blueprint $t) => $t->dropColumn('format'));
        Schema::table('project_flows', fn (Blueprint $t) => $t->dropColumn(['format', 'search_text']));
    }
};
