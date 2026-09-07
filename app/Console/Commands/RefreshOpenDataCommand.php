<?php

namespace App\Console\Commands;

use App\Services\OpenData\OpenDataRefresher;
use App\Services\OpenData\OpenDataRegistry;
use App\Services\OpenData\SnapshotRepository;
use Illuminate\Console\Command;

class RefreshOpenDataCommand extends Command
{
    protected $signature = 'opendata:refresh
                            {--source=* : 只更新指定的 source_id，預設全部}
                            {--schedule= : 只更新設定為此排程頻率的來源（hourly/daily/weekly）}
                            {--prune= : 更新後每個來源保留幾筆快照，未給則不清理}';

    protected $description = '抓取中央部會開放資料、驗證清洗後原子發布新快照；失敗時保留最近一次有效快照';

    public function handle(
        OpenDataRegistry $registry,
        OpenDataRefresher $refresher,
        SnapshotRepository $snapshots,
    ): int {
        $sourceIds = $this->resolveSourceIds($registry);

        if ($sourceIds === []) {
            $this->components->error('沒有符合條件的資料來源');

            return self::FAILURE;
        }

        $failures = 0;

        foreach ($sourceIds as $sourceId) {
            $result = $refresher->refresh($sourceId);

            if ($result->published) {
                $snapshot = $result->snapshot;

                $this->components->info(sprintf(
                    '%s 已發布 %s（%s：%s）',
                    $sourceId,
                    $snapshot->snapshotId,
                    $snapshot->quality->value,
                    $snapshot->qualityReason,
                ));

                foreach ($snapshot->warnings as $warning) {
                    $this->components->warn(sprintf('  %s：%s', $warning['code'], $warning['message']));
                }
            } else {
                $failures++;

                $this->components->error(sprintf(
                    '%s 更新失敗（%s）：%s',
                    $sourceId,
                    $result->reasonCode,
                    $result->message,
                ));

                $this->components->info(sprintf(
                    '  沿用快照：%s',
                    $result->keptSnapshotId ?? '無，開局時將使用示範情境',
                ));
            }

            $keep = $this->option('prune');

            if ($keep !== null && $keep !== '') {
                $deleted = $snapshots->prune($sourceId, (int) $keep);
                $this->components->info(sprintf('  已清理 %d 筆舊快照', $deleted));
            }
        }

        return $failures === 0 ? self::SUCCESS : self::FAILURE;
    }

    /**
     * @return list<string>
     */
    private function resolveSourceIds(OpenDataRegistry $registry): array
    {
        /** @var list<string> $requested */
        $requested = $this->option('source');

        if ($requested !== []) {
            $unknown = array_values(array_filter(
                $requested,
                static fn (string $sourceId): bool => ! $registry->has($sourceId),
            ));

            if ($unknown !== []) {
                $this->components->error('未知的資料來源：'.implode('、', $unknown));

                return [];
            }

            return $requested;
        }

        $schedule = $this->option('schedule');

        return $schedule === null || $schedule === ''
            ? $registry->sourceIds()
            : $registry->sourceIdsForSchedule((string) $schedule);
    }
}
