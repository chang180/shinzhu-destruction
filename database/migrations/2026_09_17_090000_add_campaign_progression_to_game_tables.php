<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * P05：五關戰役的進度與牌組獎勵。
     *
     * `deck_choices` 記玩家在第 2、4 關後選了哪一張新牌，`milestones` 記主線完成、
     * 收手與進階完成；`pending_reward` 是「這一關通關了但還沒挑牌」。
     *
     * 牌組組成另外凍結在 `runs.deck`：獎勵是戰役層級的選擇，但一局開始後不能再變，
     * 否則同情境重試與重播會抽到不同的牌。
     */
    public function up(): void
    {
        Schema::table('campaigns', function (Blueprint $table): void {
            $table->json('deck_choices')->nullable();
            $table->json('milestones')->nullable();
            $table->string('pending_reward')->nullable();
        });

        Schema::table('runs', function (Blueprint $table): void {
            $table->json('deck')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('campaigns', function (Blueprint $table): void {
            $table->dropColumn(['deck_choices', 'milestones', 'pending_reward']);
        });

        Schema::table('runs', function (Blueprint $table): void {
            $table->dropColumn('deck');
        });
    }
};
