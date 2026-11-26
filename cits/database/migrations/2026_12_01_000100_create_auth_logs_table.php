<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Sign-in audit: login ok / failed / locked, logout, idle and disabled sign-out, password change / reset, with source IP.
// user_id has no foreign key so rows outlive a deleted user.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('auth_logs', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('user_id')->nullable();
            $t->string('login', 255)->nullable();         // what was typed (account or email)
            $t->string('event', 30);                      // lookup auth_log_event
            $t->string('detail', 30)->nullable();         // lookup auth_log_detail
            $t->string('ip', 45)->nullable();             // Request::ip() (behind the host proxy)
            $t->string('forwarded_for', 255)->nullable(); // raw X-Forwarded-For, kept for disputes
            $t->string('user_agent', 255)->nullable();
            $t->dateTime('created_at');
            $t->index(['user_id', 'created_at']);
            $t->index(['ip', 'created_at']);
            $t->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('auth_logs');
    }
};
