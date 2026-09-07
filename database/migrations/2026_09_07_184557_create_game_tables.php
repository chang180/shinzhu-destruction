<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * 匿名存檔的最小模型（TECHNICAL-SPEC §5）。
     *
     * 擁有者以不可猜測的 anonymous_id 綁定 HttpOnly 工作階段；知道 run 的
     * public_id 不等於能讀寫別人的局面。
     */
    public function up(): void
    {
        Schema::create('campaigns', function (Blueprint $table): void {
            $table->id();
            $table->string('anonymous_id', 64)->unique();
            $table->json('unlocked');
            $table->json('best_results');
            $table->timestamp('last_activity_at');
            $table->timestamps();

            $table->index('last_activity_at');
        });

        Schema::create('runs', function (Blueprint $table): void {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->foreignId('campaign_id')->constrained()->cascadeOnDelete();
            $table->string('level_id');
            $table->string('rules_version');
            // 開局綁定的快照組，之後凍結；重播一定讀這些 snapshot_id。
            $table->json('snapshot_ids');
            $table->json('scenario_modifiers');
            $table->unsignedBigInteger('seed');
            $table->json('state');
            $table->unsignedInteger('version');
            $table->string('outcome');
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();

            $table->index(['campaign_id', 'level_id']);
            $table->index(['campaign_id', 'updated_at']);
        });

        Schema::create('run_actions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('run_id')->constrained()->cascadeOnDelete();
            // 客戶端產生的行動識別，用於重送去重。
            $table->string('action_id', 64);
            $table->unsignedInteger('sequence');
            // payload 指紋：同 action_id 不同 payload 要能判成衝突。
            $table->string('fingerprint', 64);
            $table->json('input');
            $table->json('events');
            $table->json('state_after');
            $table->unsignedInteger('version_after');
            $table->timestamps();

            $table->unique(['run_id', 'action_id']);
            $table->unique(['run_id', 'sequence']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('run_actions');
        Schema::dropIfExists('runs');
        Schema::dropIfExists('campaigns');
    }
};
