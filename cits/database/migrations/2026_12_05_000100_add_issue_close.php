<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('issues', function (Blueprint $t) {
            $t->string('stage', 10)->nullable()->change();
            $t->string('close_target', 30)->nullable();
            $t->string('close_from', 30)->nullable();
            $t->string('close_reason', 1000)->nullable();
            $t->foreignId('close_meeting_item_id')->nullable()->constrained('meeting_items')->nullOnDelete();
            $t->dateTime('closed_at')->nullable();
            $t->foreignId('closed_by')->nullable()->constrained('users');
        });
        Schema::create('issue_close_tasks', function (Blueprint $t) {
            $t->id();
            $t->foreignId('issue_id')->constrained();
            $t->unsignedInteger('round');
            $t->string('role', 20);
            $t->foreignId('user_id')->nullable()->constrained();
            $t->string('status', 16);
            $t->string('note', 1000)->nullable();
            $t->foreignId('done_by')->nullable()->constrained('users');
            $t->dateTime('done_at')->nullable();
            $t->timestamps();
            $t->unique(['issue_id', 'round', 'role']);
            $t->index(['issue_id', 'round', 'status']);
            $t->index(['user_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::table('issues', function (Blueprint $t) {
            $t->string('stage', 10)->nullable(false)->change();
        });
        Schema::dropIfExists('issue_close_tasks');
        Schema::table('issues', function (Blueprint $t) {
            $t->dropConstrainedForeignId('close_meeting_item_id');
            $t->dropConstrainedForeignId('closed_by');
            $t->dropColumn(['close_target', 'close_from', 'close_reason', 'closed_at']);
        });
    }
};
