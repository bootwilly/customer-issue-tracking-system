<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

// Editor attachments: add Office (doc/docx/xls/ppt/pptx), json, md, rar, 7z. Only rewrites the old default;
// a list an admin already changed is left alone. Raw SQL so the upgrade-SQL generator can emit it (pretend mode).
return new class extends Migration
{
    public function up(): void
    {
        DB::statement("UPDATE system_settings SET value = 'png,jpg,jpeg,gif,webp,mp4,webm,mov,pdf,doc,docx,xls,xlsx,csv,ppt,pptx,txt,log,json,md,zip,rar,7z' WHERE `key` = 'attachment.allowed_ext' AND value = 'png,jpg,jpeg,gif,webp,mp4,webm,mov,txt,log,zip,pdf,xlsx,csv'");
    }

    public function down(): void
    {
        DB::statement("UPDATE system_settings SET value = 'png,jpg,jpeg,gif,webp,mp4,webm,mov,txt,log,zip,pdf,xlsx,csv' WHERE `key` = 'attachment.allowed_ext' AND value = 'png,jpg,jpeg,gif,webp,mp4,webm,mov,pdf,doc,docx,xls,xlsx,csv,ppt,pptx,txt,log,json,md,zip,rar,7z'");
    }
};
