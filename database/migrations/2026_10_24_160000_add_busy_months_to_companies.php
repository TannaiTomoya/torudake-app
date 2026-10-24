<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('companies', function (Blueprint $table): void {
            $table->json('busy_months')->nullable()->after('concern_amount_yen');
        });

        $default = '[6,7,10,11,12]';
        DB::table('companies')->whereNull('busy_months')->pluck('id')->each(function (string $id) use ($default): void {
            DB::table('companies')->where('id', $id)->update(['busy_months' => $default]);
        });
    }

    public function down(): void
    {
        Schema::table('companies', function (Blueprint $table): void {
            $table->dropColumn('busy_months');
        });
    }
};
