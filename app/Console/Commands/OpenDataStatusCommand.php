<?php

namespace App\Console\Commands;

use App\Services\OpenData\OpenDataRegistry;
use App\Services\OpenData\SnapshotRepository;
use App\Services\OpenData\SnapshotResolver;
use Illuminate\Console\Command;

class OpenDataStatusCommand extends Command
{
    protected $signature = 'opendata:status';

    protected $description = '顯示各資料來源目前的快照品質、觀測期間與備援狀態';

    public function handle(
        OpenDataRegistry $registry,
        SnapshotRepository $snapshots,
        SnapshotResolver $resolver,
    ): int {
        $status = $snapshots->status($registry->sourceIds());
        $rows = [];

        foreach ($status as $sourceId => $entry) {
            $resolved = $resolver->resolve($sourceId);

            $rows[] = [
                $sourceId,
                $entry['quality_label'],
                $entry['observed_at'] ?? ($entry['period']['label'] ?? '—'),
                $entry['snapshot_id'] ?? '—',
                $resolved->quality->label(),
            ];
        }

        $this->table(['來源', '快照品質', '觀測時間／期間', 'snapshot_id', '開局將使用'], $rows);

        return self::SUCCESS;
    }
}
