<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('projects', function (Blueprint $t) {
            $t->id();
            $t->string('code', 30)->unique();
            $t->string('name', 100);
            $t->string('ticket_prefix', 10);
            $t->string('status', 10)->default('active');
            $t->datetimes();
        });

        Schema::create('project_members', function (Blueprint $t) {
            $t->foreignId('project_id')->constrained();
            $t->foreignId('user_id')->constrained();
            $t->datetimes();
            $t->primary(['project_id', 'user_id']);
        });

        Schema::create('project_settings', function (Blueprint $t) {
            $t->foreignId('project_id')->constrained();
            $t->string('key', 100);
            $t->text('value')->nullable();
            $t->primary(['project_id', 'key']);
        });

        Schema::create('system_settings', function (Blueprint $t) {
            $t->string('key', 100)->primary();
            $t->text('value')->nullable();
            $t->boolean('is_encrypted')->default(false);
            $t->datetimes();
        });

        Schema::create('lookup_items', function (Blueprint $t) {
            $t->id();
            $t->string('category', 30);
            $t->string('code', 30);
            $t->string('name', 50);
            $t->unsignedSmallInteger('sort')->default(0);
            $t->boolean('is_active')->default(true);
            $t->unique(['category', 'code']);
        });

        Schema::create('mes_pages', function (Blueprint $t) {
            $t->id();
            $t->foreignId('project_id')->constrained();
            $t->foreignId('parent_id')->nullable()->constrained('mes_pages');
            $t->string('node_type', 10); // folder | page
            $t->string('name', 100);
            $t->string('page_path', 255)->nullable();
            $t->string('page_file', 150)->nullable()->index();
            $t->unsignedInteger('sort')->default(0);
            $t->boolean('is_active')->default(true);
            $t->string('remark', 255)->nullable();
            $t->datetimes();
            $t->unique(['project_id', 'page_path']);
        });

        Schema::create('workflow_statuses', function (Blueprint $t) {
            $t->id();
            $t->string('scope', 10); // ticket | issue
            $t->string('code', 30);
            $t->string('name', 50);
            $t->string('stage', 10)->nullable(); // PM | DEV | TEST | RELEASE
            $t->string('client_tab', 10)->nullable(); // open | pending | closed | cancelled
            $t->boolean('is_terminal')->default(false);
            $t->boolean('is_excluded')->default(false);
            $t->boolean('is_released')->default(false);
            $t->unsignedSmallInteger('sort')->default(0);
            $t->unique(['scope', 'code']);
        });

        Schema::create('workflow_transitions', function (Blueprint $t) {
            $t->id();
            $t->string('scope', 10);
            $t->string('from_code', 30);
            $t->string('to_code', 30);
            $t->string('permission', 50);
            $t->json('requires_fields')->nullable();
            $t->unique(['scope', 'from_code', 'to_code']);
        });

        Schema::create('field_edit_rules', function (Blueprint $t) {
            $t->id();
            $t->string('origin', 10);
            $t->string('field', 50);
            $t->string('stage', 10)->nullable();
            $t->string('permission', 50);
            $t->string('actor_rule', 10)->default('any'); // any | creator
        });

        Schema::create('project_counters', function (Blueprint $t) {
            $t->foreignId('project_id')->constrained();
            $t->string('kind', 10); // ticket | issue
            $t->unsignedSmallInteger('year');
            $t->unsignedInteger('last_no')->default(0);
            $t->primary(['project_id', 'kind', 'year']);
        });

        Schema::create('client_tickets', function (Blueprint $t) {
            $t->id();
            $t->foreignId('project_id')->constrained();
            $t->string('ticket_no', 30)->unique();
            $t->string('ticket_type', 30);
            $t->string('anomaly_category', 30)->nullable();
            $t->foreignId('mes_page_id')->nullable()->constrained('mes_pages');
            $t->string('title', 200);
            $t->longText('content_html');
            $t->string('status', 30);
            $t->unsignedInteger('current_rev')->default(1);
            $t->foreignId('edit_lock_user_id')->nullable()->constrained('users');
            $t->dateTime('edit_lock_expires_at')->nullable();
            $t->unsignedInteger('row_version')->default(1);
            $t->unsignedInteger('reopen_count')->default(0);
            $t->dateTime('dispatched_at')->nullable();
            $t->boolean('needs_pm_action')->default(false);
            $t->foreignId('accepted_by')->nullable()->constrained('users');
            $t->dateTime('accepted_at')->nullable();
            $t->dateTime('closed_at')->nullable();
            $t->dateTime('cancelled_at')->nullable();
            $t->string('cancel_reason', 500)->nullable();
            $t->foreignId('created_by')->constrained('users');
            $t->datetimes();
            $t->index(['project_id', 'status']);
        });

        Schema::create('ticket_revisions', function (Blueprint $t) {
            $t->id();
            $t->foreignId('ticket_id')->constrained('client_tickets');
            $t->unsignedInteger('rev_no');
            $t->string('title', 200);
            $t->longText('content_snapshot');
            $t->foreignId('created_by')->constrained('users');
            $t->dateTime('created_at');
            $t->foreignId('pm_ack_by')->nullable()->constrained('users');
            $t->dateTime('pm_ack_at')->nullable();
            $t->unique(['ticket_id', 'rev_no']);
        });

        Schema::create('ticket_reopens', function (Blueprint $t) {
            $t->id();
            $t->foreignId('ticket_id')->constrained('client_tickets');
            $t->unsignedInteger('round');
            $t->longText('content_html');
            $t->foreignId('created_by')->constrained('users');
            $t->dateTime('created_at');
        });

        Schema::create('packages', function (Blueprint $t) {
            $t->id();
            $t->foreignId('project_id')->constrained();
            $t->string('name', 150);
            $t->dateTime('built_at')->nullable();
            $t->dateTime('released_at')->nullable();
            $t->foreignId('created_by')->constrained('users');
            $t->datetimes();
        });

        Schema::create('issues', function (Blueprint $t) {
            $t->id();
            $t->foreignId('project_id')->constrained();
            $t->string('issue_no', 30)->unique();
            $t->string('origin', 10); // client | pm | dev | test
            $t->string('process_type', 30)->nullable();
            $t->foreignId('mes_page_id')->nullable()->constrained('mes_pages');
            $t->unsignedTinyInteger('severity')->nullable();
            $t->unsignedTinyInteger('difficulty')->nullable();
            $t->string('title', 200);
            $t->longText('content_html');
            $t->string('stage', 10);
            $t->string('status', 30);
            $t->foreignId('assignee_dev_id')->nullable()->constrained('users');
            $t->foreignId('assignee_test_id')->nullable()->constrained('users');
            $t->date('planned_start')->nullable();
            $t->date('planned_due')->nullable();
            $t->date('current_due')->nullable();
            $t->dateTime('actual_done_at')->nullable();
            $t->date('test_scheduled_at')->nullable();
            $t->date('release_planned_at')->nullable();
            $t->dateTime('released_at')->nullable();
            $t->foreignId('package_id')->nullable()->constrained('packages');
            $t->unsignedInteger('rework_count')->default(0);
            $t->dateTime('content_locked_at')->nullable();
            $t->string('cancel_reason', 500)->nullable();
            $t->string('client_note', 500)->nullable();
            $t->text('dev_note')->nullable();
            $t->foreignId('created_by')->constrained('users');
            $t->datetimes();
            $t->index(['project_id', 'stage', 'status']);
        });

        Schema::create('ticket_issue_links', function (Blueprint $t) {
            $t->foreignId('ticket_id')->constrained('client_tickets');
            $t->foreignId('issue_id')->constrained('issues');
            $t->unsignedInteger('based_on_rev');
            $t->foreignId('linked_by')->constrained('users');
            $t->dateTime('linked_at');
            $t->primary(['ticket_id', 'issue_id']);
        });

        Schema::create('issue_addenda', function (Blueprint $t) {
            $t->id();
            $t->foreignId('issue_id')->constrained();
            $t->longText('content_html');
            $t->unsignedBigInteger('source_comment_id')->nullable();
            $t->foreignId('created_by')->constrained('users');
            $t->dateTime('created_at');
        });

        Schema::create('due_change_requests', function (Blueprint $t) {
            $t->id();
            $t->foreignId('issue_id')->constrained();
            $t->date('old_due')->nullable();
            $t->date('new_due');
            $t->string('reason', 500);
            $t->string('status', 10)->default('pending');
            $t->foreignId('requested_by')->constrained('users');
            $t->foreignId('reviewed_by')->nullable()->constrained('users');
            $t->dateTime('reviewed_at')->nullable();
            $t->datetimes();
        });

        Schema::create('events', function (Blueprint $t) {
            $t->id();
            $t->foreignId('project_id')->constrained();
            $t->string('subject_type', 10); // ticket | issue | mes
            $t->unsignedBigInteger('subject_id');
            $t->string('event_type', 30);
            $t->string('from_status', 30)->nullable();
            $t->string('to_status', 30)->nullable();
            $t->json('payload')->nullable();
            $t->foreignId('actor_id')->constrained('users');
            $t->dateTime('created_at');
            $t->index(['subject_type', 'subject_id']);
        });

        Schema::create('ticket_comments', function (Blueprint $t) {
            $t->id();
            $t->foreignId('ticket_id')->constrained('client_tickets');
            $t->string('kind', 15); // supplement | question | reply
            $t->longText('content_html');
            $t->foreignId('created_by')->constrained('users');
            $t->dateTime('created_at');
        });

        Schema::create('issue_comments', function (Blueprint $t) {
            $t->id();
            $t->foreignId('issue_id')->constrained();
            $t->longText('content_html');
            $t->foreignId('created_by')->constrained('users');
            $t->dateTime('created_at');
        });

        Schema::create('comment_reads', function (Blueprint $t) {
            $t->foreignId('comment_id')->constrained('ticket_comments');
            $t->foreignId('user_id')->constrained();
            $t->dateTime('read_at');
            $t->primary(['comment_id', 'user_id']);
        });

        Schema::create('attachments', function (Blueprint $t) {
            $t->id();
            $t->string('owner_type', 20)->nullable();
            $t->unsignedBigInteger('owner_id')->nullable();
            $t->string('draft_context_key', 100)->nullable()->index();
            $t->string('disk_path', 255);
            $t->string('original_name', 255);
            $t->string('mime', 100);
            $t->unsignedBigInteger('size');
            $t->char('sha256', 64);
            $t->string('state', 10)->default('temp'); // temp | bound
            $t->foreignId('uploaded_by')->constrained('users');
            $t->datetimes();
            $t->index(['owner_type', 'owner_id']);
        });

        Schema::create('upload_sessions', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignId('user_id')->constrained();
            $t->string('original_name', 255);
            $t->unsignedBigInteger('total_size');
            $t->unsignedInteger('total_chunks');
            $t->unsignedInteger('received_chunks')->default(0);
            $t->string('draft_context_key', 100)->nullable();
            $t->string('status', 10)->default('open');
            $t->datetimes();
        });

        Schema::create('editor_drafts', function (Blueprint $t) {
            $t->foreignId('user_id')->constrained();
            $t->string('context_key', 100);
            $t->longText('content_html');
            $t->dateTime('updated_at');
            $t->primary(['user_id', 'context_key']);
        });

        Schema::create('notification_outbox', function (Blueprint $t) {
            $t->id();
            $t->foreignId('user_id')->nullable()->constrained();
            $t->string('to_email', 255);
            $t->string('subject', 255);
            $t->longText('body_html');
            $t->string('status', 10)->default('pending'); // pending | sent | failed | skipped
            $t->unsignedTinyInteger('attempts')->default(0);
            $t->string('last_error', 500)->nullable();
            $t->dateTime('sent_at')->nullable();
            $t->datetimes();
            $t->index('status');
        });

        Schema::create('user_notifications', function (Blueprint $t) {
            $t->id();
            $t->foreignId('user_id')->constrained();
            $t->foreignId('project_id')->nullable()->constrained();
            $t->string('title', 255);
            $t->string('url', 255)->nullable();
            $t->dateTime('read_at')->nullable();
            $t->dateTime('created_at');
            $t->index(['user_id', 'read_at']);
        });
    }

    public function down(): void
    {
        foreach ([
            'user_notifications', 'notification_outbox', 'editor_drafts', 'upload_sessions', 'attachments',
            'comment_reads', 'issue_comments', 'ticket_comments', 'events', 'due_change_requests',
            'issue_addenda', 'ticket_issue_links', 'issues', 'packages', 'ticket_reopens', 'ticket_revisions',
            'client_tickets', 'project_counters', 'field_edit_rules', 'workflow_transitions',
            'workflow_statuses', 'mes_pages', 'lookup_items', 'system_settings', 'project_settings',
            'project_members', 'projects',
        ] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
