<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Excel import of PM internal issues: one row per imported spreadsheet line, so importing the same (growing) file again
// skips lines already turned into issues. source = code typed on the import page, source_no = the 序號 column.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('issue_import_rows', function (Blueprint $t) {
            $t->id();
            $t->foreignId('project_id')->constrained('projects');
            $t->string('source', 50);
            $t->string('source_no', 30);
            $t->foreignId('issue_id')->constrained('issues');
            $t->char('content_hash', 64);              // title + content + notes + detail sheet, to flag later edits
            $t->foreignId('created_by')->constrained('users');
            $t->timestamps();
            $t->unique(['project_id', 'source', 'source_no']);
            $t->index('issue_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('issue_import_rows');
    }
};
