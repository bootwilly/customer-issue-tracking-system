<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('api_tokens', function (Blueprint $t) {
            $t->id();
            $t->foreignId('user_id')->constrained('users');
            $t->string('name', 100);
            $t->char('token_hash', 64)->unique();
            $t->dateTime('last_used_at')->nullable();
            $t->dateTime('expires_at')->nullable();
            $t->dateTime('revoked_at')->nullable();
            $t->datetimes();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('api_tokens');
    }
};
