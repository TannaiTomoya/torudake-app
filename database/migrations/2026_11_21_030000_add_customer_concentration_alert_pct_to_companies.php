<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * 取引先への依存の知らせ（#75）。上位2社の売上の割合がこの%を超えたら、社長の画面の ⑤ で知らせる。
     * 既存の会社にも70が入る。
     */
    public function up(): void
    {
        Schema::table('companies', function (Blueprint $table): void {
            $table->unsignedTinyInteger('customer_concentration_alert_pct')->default(70)->after('busy_months');
        });
    }

    public function down(): void
    {
        Schema::table('companies', function (Blueprint $table): void {
            $table->dropColumn('customer_concentration_alert_pct');
        });
    }
};
