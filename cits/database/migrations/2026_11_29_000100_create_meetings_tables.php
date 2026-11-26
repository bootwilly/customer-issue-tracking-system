<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Meeting records: meetings (status via workflow scope "meeting"), attendees, discussion items
 * (status via scope "meeting_item"), comments (one reply level, internal-only flag) and per-user read marks.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('meetings', function (Blueprint $t) {
            $t->id();
            $t->foreignId('project_id')->constrained('projects');
            $t->string('type', 30);
            $t->string('title', 200);
            $t->dateTime('start_at');
            $t->dateTime('end_at')->nullable();
            $t->string('location', 200)->nullable();
            $t->string('status', 16);
            $t->boolean('customer_visible')->default(false);
            $t->longText('notes_html')->nullable();
            $t->longText('internal_html')->nullable();
            $t->unsignedInteger('notes_rev')->default(0);
            $t->dateTime('published_at')->nullable();
            $t->foreignId('prev_meeting_id')->nullable()->constrained('meetings')->nullOnDelete();
            $t->foreignId('created_by')->constrained('users');
            $t->foreignId('updated_by')->nullable()->constrained('users');
            $t->timestamps();
            $t->dateTime('deleted_at')->nullable();
            $t->foreignId('deleted_by')->nullable()->constrained('users');
            $t->index(['project_id', 'deleted_at', 'start_at']);
        });

        Schema::create('meeting_attendees', function (Blueprint $t) {
            $t->id();
            $t->foreignId('meeting_id')->constrained('meetings')->cascadeOnDelete();
            $t->foreignId('user_id')->nullable()->constrained('users');
            $t->string('external_name', 100)->nullable();
            $t->dateTime('created_at');
            $t->index(['meeting_id', 'user_id']);
            $t->index('user_id');
        });

        Schema::create('meeting_items', function (Blueprint $t) {
            $t->id();
            $t->foreignId('meeting_id')->constrained('meetings')->cascadeOnDelete();
            $t->unsignedInteger('sort')->default(0);
            $t->string('topic', 300);
            $t->foreignId('issue_id')->nullable()->constrained('issues')->nullOnDelete();
            $t->text('discussion')->nullable();
            $t->text('conclusion')->nullable();
            $t->foreignId('owner_id')->nullable()->constrained('users');
            $t->date('due_date')->nullable();
            $t->string('status', 16);
            $t->foreignId('carried_from_id')->nullable()->constrained('meeting_items')->nullOnDelete();
            $t->foreignId('created_by')->constrained('users');
            $t->foreignId('updated_by')->nullable()->constrained('users');
            $t->timestamps();
            $t->index(['meeting_id', 'sort']);
            $t->index('issue_id');
            $t->index(['owner_id', 'status']);
        });

        Schema::create('meeting_comments', function (Blueprint $t) {
            $t->id();
            $t->foreignId('meeting_id')->constrained('meetings')->cascadeOnDelete();
            $t->foreignId('parent_id')->nullable()->constrained('meeting_comments')->cascadeOnDelete();
            $t->foreignId('user_id')->constrained('users');
            $t->text('body');
            $t->boolean('is_internal')->default(false);
            $t->dateTime('edited_at')->nullable();
            $t->dateTime('deleted_at')->nullable();
            $t->foreignId('deleted_by')->nullable()->constrained('users');
            $t->timestamps();
            $t->index(['meeting_id', 'id']);
        });

        Schema::create('meeting_reads', function (Blueprint $t) {
            $t->foreignId('meeting_id')->constrained('meetings')->cascadeOnDelete();
            $t->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $t->dateTime('read_at');
            $t->primary(['meeting_id', 'user_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('meeting_reads');
        Schema::dropIfExists('meeting_comments');
        Schema::dropIfExists('meeting_items');
        Schema::dropIfExists('meeting_attendees');
        Schema::dropIfExists('meetings');
    }
};
