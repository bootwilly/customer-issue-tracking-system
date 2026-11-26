<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Flow v2: one working draft per flow (saving never bumps the version; publishing does),
 * status via workflow scope "flow", per-flow editors, draft snapshots, soft delete.
 * project_flows.title/source_md/search_text/version now mean "latest published" (version 0 = never published).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('project_flows', function (Blueprint $t) {
            $t->string('status', 16)->default('draft')->after('format');
            $t->string('draft_title', 200)->nullable()->after('search_text');
            $t->longText('draft_source')->nullable()->after('draft_title');
            $t->mediumText('draft_search_text')->nullable()->after('draft_source');
            $t->unsignedInteger('draft_rev')->default(0)->after('draft_search_text');
            $t->foreignId('draft_updated_by')->nullable()->after('draft_rev')->constrained('users');
            $t->dateTime('draft_updated_at')->nullable()->after('draft_updated_by');
            $t->dateTime('deleted_at')->nullable();
            $t->foreignId('deleted_by')->nullable()->constrained('users');
            $t->index(['project_id', 'deleted_at', 'status']);
        });

        Schema::table('project_flow_revisions', function (Blueprint $t) {
            $t->string('note', 500)->nullable()->after('title');
        });

        Schema::create('project_flow_editors', function (Blueprint $t) {
            $t->id();
            $t->foreignId('flow_id')->constrained('project_flows')->cascadeOnDelete();
            $t->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $t->foreignId('created_by')->nullable()->constrained('users');
            $t->dateTime('created_at');
            $t->unique(['flow_id', 'user_id']);
        });

        Schema::create('project_flow_draft_snapshots', function (Blueprint $t) {
            $t->id();
            $t->foreignId('flow_id')->constrained('project_flows')->cascadeOnDelete();
            $t->unsignedInteger('draft_rev');
            $t->string('title', 200);
            $t->longText('source');
            $t->foreignId('created_by')->nullable()->constrained('users');
            $t->dateTime('created_at');
            $t->index(['flow_id', 'id']);
        });

        // existing flows were all published: active -> effective, inactive -> obsolete
        DB::statement("UPDATE project_flows SET status = CASE WHEN is_active = 1 THEN 'effective' ELSE 'obsolete' END");
    }

    public function down(): void
    {
        Schema::dropIfExists('project_flow_draft_snapshots');
        Schema::dropIfExists('project_flow_editors');
        Schema::table('project_flow_revisions', fn (Blueprint $t) => $t->dropColumn('note'));
        Schema::table('project_flows', function (Blueprint $t) {
            $t->dropIndex(['project_id', 'deleted_at', 'status']);
            $t->dropConstrainedForeignId('draft_updated_by');
            $t->dropConstrainedForeignId('deleted_by');
            $t->dropColumn(['status', 'draft_title', 'draft_source', 'draft_search_text', 'draft_rev', 'draft_updated_at', 'deleted_at']);
        });
    }
};
