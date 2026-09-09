<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * P04：限時挑戰與不限時練習分軌。
     *
     * 練習用同一套牌組與城市規則，只關閉截止時間，所以解鎖與最佳表現必須分開
     * 記錄——練習通關不能冒充限時挑戰通關（P04-REVISION-PLAN §5）。
     */
    public function up(): void
    {
        Schema::table('runs', function (Blueprint $table): void {
            $table->string('mode', 16)->default('challenge');
        });

        Schema::table('campaigns', function (Blueprint $table): void {
            $table->json('practice_unlocked')->nullable();
            $table->json('practice_results')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('runs', function (Blueprint $table): void {
            $table->dropColumn('mode');
        });

        Schema::table('campaigns', function (Blueprint $table): void {
            $table->dropColumn(['practice_unlocked', 'practice_results']);
        });
    }
};
