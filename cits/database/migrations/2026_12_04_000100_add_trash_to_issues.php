<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Recycle bin for internal issues: a deleted (new) issue keeps every row and is hidden by the Issue model's
// SoftDeletes scope until a project admin restores it or deletes it for good.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('issues', function (Blueprint $t) {
            $t->dateTime('deleted_at')->nullable();
            $t->foreignId('deleted_by')->nullable()->constrained('users');
            $t->index(['project_id', 'deleted_at']);
        });
    }

    public function down(): void
    {
        Schema::table('issues', function (Blueprint $t) {
            $t->dropIndex(['project_id', 'deleted_at']);
            $t->dropConstrainedForeignId('deleted_by');
            $t->dropColumn('deleted_at');
        });
    }
};
