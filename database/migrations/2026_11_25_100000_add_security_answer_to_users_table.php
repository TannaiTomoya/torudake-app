<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * 2段階目を秘密の質問1問にする（#78）。TOTP の古い列は残す。
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->string('security_answer_hash')->nullable();
            $table->timestamp('security_answer_set_at')->nullable();
            $table->unsignedTinyInteger('security_answer_failed_attempts')->default(0);
            $table->timestamp('security_answer_locked_until')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->dropColumn([
                'security_answer_hash',
                'security_answer_set_at',
                'security_answer_failed_attempts',
                'security_answer_locked_until',
            ]);
        });
    }
};
