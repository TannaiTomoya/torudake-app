<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ai_runs', function (Blueprint $table): void {
            $table->char('photo_sha256', 64)->nullable()->after('subject_id');
            $table->index(['photo_sha256', 'task']);
        });
    }

    public function down(): void
    {
        Schema::table('ai_runs', function (Blueprint $table): void {
            $table->dropIndex(['photo_sha256', 'task']);
            $table->dropColumn('photo_sha256');
        });
    }
};
