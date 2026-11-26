<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Collaboration features (docs/bugzilla-inspired-suggestions.md): needinfo, watchers, saved queries, issue tags, issue dependencies.
return new class extends Migration
{
    public function up(): void
    {
        // needinfo: PM asks the client for more information; one open row per ticket, history kept
        Schema::create('ticket_needinfo', function (Blueprint $t) {
            $t->id();
            $t->foreignId('ticket_id')->constrained('client_tickets');
            $t->text('note');
            $t->foreignId('requested_by')->constrained('users');
            $t->dateTime('requested_at');
            $t->dateTime('resolved_at')->nullable();
            $t->foreignId('resolved_by')->nullable()->constrained('users');
            $t->string('resolution', 20)->nullable(); // answered (client replied) | withdrawn (PM cleared)
            $t->dateTime('reminded_at')->nullable();
            $t->dateTime('pm_seen_at')->nullable(); // PM opened the ticket after the client answered
            $t->index(['ticket_id', 'resolved_at']);
        });

        // watchers: extra notification recipients besides assignees / ticket owner
        Schema::create('ticket_watchers', function (Blueprint $t) {
            $t->foreignId('ticket_id')->constrained('client_tickets');
            $t->foreignId('user_id')->constrained('users');
            $t->dateTime('created_at');
            $t->primary(['ticket_id', 'user_id']);
        });
        Schema::create('issue_watchers', function (Blueprint $t) {
            $t->foreignId('issue_id')->constrained('issues');
            $t->foreignId('user_id')->constrained('users');
            $t->dateTime('created_at');
            $t->primary(['issue_id', 'user_id']);
        });

        // saved list filters: only whitelisted query keys are stored (never SQL)
        Schema::create('saved_queries', function (Blueprint $t) {
            $t->id();
            $t->foreignId('user_id')->constrained('users');
            $t->foreignId('project_id')->constrained('projects');
            $t->string('scope', 20); // pm | dev | test | client
            $t->string('name', 100);
            $t->json('params');
            $t->unsignedInteger('sort')->default(0);
            $t->timestamps();
            $t->index(['user_id', 'project_id', 'scope']);
        });

        // controlled tags (lookup issue_tag), internal only
        Schema::create('issue_tags', function (Blueprint $t) {
            $t->foreignId('issue_id')->constrained('issues');
            $t->string('tag_code', 30);
            $t->foreignId('created_by')->constrained('users');
            $t->dateTime('created_at');
            $t->primary(['issue_id', 'tag_code']);
            $t->index('tag_code');
        });

        // issue -> depends_on (kind: lookup dependency_kind; only "blocks" kinds are enforced on dispatch)
        Schema::create('issue_dependencies', function (Blueprint $t) {
            $t->id();
            $t->foreignId('issue_id')->constrained('issues');
            $t->foreignId('depends_on_id')->constrained('issues');
            $t->string('kind', 20);
            $t->foreignId('created_by')->constrained('users');
            $t->dateTime('created_at');
            $t->unique(['issue_id', 'depends_on_id']);
            $t->index('depends_on_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('issue_dependencies');
        Schema::dropIfExists('issue_tags');
        Schema::dropIfExists('saved_queries');
        Schema::dropIfExists('issue_watchers');
        Schema::dropIfExists('ticket_watchers');
        Schema::dropIfExists('ticket_needinfo');
    }
};
