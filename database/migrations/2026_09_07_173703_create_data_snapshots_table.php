<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * 正規化資料快照。內容刻意保持精簡：只存遊戲需要的欄位，不存整份上游檔案。
     */
    public function up(): void
    {
        Schema::create('data_snapshots', function (Blueprint $table): void {
            $table->id();
            $table->uuid('snapshot_id')->unique();
            $table->string('source_id')->index();
            $table->string('schema_version');
            $table->text('resource_url');
            $table->string('content_hash', 64);
            $table->string('quality');
            $table->string('quality_reason');
            $table->timestamp('fetched_at');
            $table->timestamp('observed_at')->nullable();
            $table->timestamp('period_start')->nullable();
            $table->timestamp('period_end')->nullable();
            $table->unsignedInteger('byte_size')->nullable();
            $table->json('payload');
            $table->boolean('is_current')->default(false);
            $table->timestamps();

            $table->index(['source_id', 'is_current']);
            $table->index(['source_id', 'fetched_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('data_snapshots');
    }
};
