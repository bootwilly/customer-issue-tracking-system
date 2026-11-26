<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('project_flows', function (Blueprint $t) {
            $t->id();
            $t->foreignId('project_id')->constrained('projects')->cascadeOnDelete();
            $t->string('title', 200);
            $t->longText('source_md');
            $t->unsignedInteger('version')->default(1);
            $t->unsignedSmallInteger('sort')->default(0);
            $t->boolean('is_active')->default(true);
            $t->foreignId('updated_by')->nullable()->constrained('users');
            $t->datetimes();
            $t->index(['project_id', 'is_active', 'sort']);
        });

        Schema::create('project_flow_revisions', function (Blueprint $t) {
            $t->id();
            $t->foreignId('flow_id')->constrained('project_flows')->cascadeOnDelete();
            $t->unsignedInteger('version');
            $t->string('title', 200);
            $t->longText('source_md');
            $t->foreignId('created_by')->nullable()->constrained('users');
            $t->dateTime('created_at');
            $t->unique(['flow_id', 'version']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('project_flow_revisions');
        Schema::dropIfExists('project_flows');
    }
};
