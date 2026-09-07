<?php

namespace App\Services\Game;

use App\Domain\Game\ActionRequest;
use App\Domain\Game\BattleEngine;
use App\Domain\Game\LevelRepository;
use App\Domain\Game\Outcome;
use App\Domain\Game\Scenario\ScenarioModifierCalculator;
use App\Domain\Game\Scenario\ScenarioModifiers;
use App\Models\Campaign;
use App\Models\Run;
use App\Models\RunAction;
use App\Services\Game\Exceptions\RunConflictException;
use App\Services\OpenData\SnapshotResolver;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * 對局的交易邊界。
 *
 * 每次行動都在同一個交易內確認擁有者、有效狀態、版本與 action 去重，結算後
 * 原子寫入（TECHNICAL-SPEC §6）。抓取上游資料只在開局前發生，回合中絕不呼叫。
 */
class RunService
{
    public function __construct(
        private readonly BattleEngine $engine,
        private readonly LevelRepository $levels,
        private readonly SnapshotResolver $snapshots,
        private readonly ScenarioModifierCalculator $calculator,
    ) {}

    /**
     * 開局：解析並凍結一組快照，換算情境修正，寫入初始局面。
     */
    public function create(Campaign $campaign, string $levelId, ?int $seed = null): Run
    {
        $level = $this->levels->get($levelId);
        $snapshots = $this->snapshots->resolveSet();
        $modifiers = $this->calculator->calculate($snapshots);
        $state = $this->engine->start($level);

        $snapshotIds = [];

        foreach ($snapshots as $sourceId => $snapshot) {
            $snapshotIds[$sourceId] = $snapshot->snapshotId;
        }

        return DB::transaction(function () use ($campaign, $level, $snapshotIds, $modifiers, $state, $seed): Run {
            $campaign->forceFill(['last_activity_at' => Carbon::now()])->save();

            return Run::query()->create([
                'public_id' => (string) Str::uuid7(),
                'campaign_id' => $campaign->id,
                'level_id' => $level->id,
                'rules_version' => $this->engine->rulesVersion(),
                'snapshot_ids' => $snapshotIds,
                'scenario_modifiers' => $modifiers->toArray(),
                'seed' => $seed ?? random_int(1, PHP_INT_MAX),
                'state' => $state->toArray(),
                'version' => $state->version,
                'outcome' => $state->outcome,
            ]);
        });
    }

    /**
     * 提交一次行動。
     *
     * - 同一 (run_id, action_id) 帶相同 payload：回傳原先結果，不重算、不多扣資源，
     *   即使該局已經結束也一樣。
     * - 同一 action_id 帶不同 payload：409。
     * - expected_version 過期：409。
     * - 規則不合法：由引擎丟 InvalidActionException，呼叫端轉 422，交易不寫入。
     *
     * @return array{action: RunAction, run: Run, replayed: bool}
     */
    public function submit(Run $run, ActionRequest $action): array
    {
        return DB::transaction(function () use ($run, $action): array {
            /*
             * 不用 lockForUpdate()：SQLite 把它編譯成空字串，寫了只是給人安全感。
             * 保護來自下面的條件版本更新——讀到寫入之間如果有人改了這一列，
             * 更新會影響 0 列，我們就回 409 而不是覆蓋掉對方的結果。
             */
            /** @var Run $current */
            $current = Run::query()->whereKey($run->getKey())->firstOrFail();
            $observedVersion = $current->version;

            $existing = RunAction::query()
                ->where('run_id', $current->id)
                ->where('action_id', $action->actionId)
                ->first();

            if ($existing !== null) {
                if ($existing->fingerprint !== $action->fingerprint()) {
                    throw RunConflictException::actionIdReused($action->actionId);
                }

                return ['action' => $existing, 'run' => $current, 'replayed' => true];
            }

            if ($action->expectedVersion !== $observedVersion) {
                throw RunConflictException::staleVersion($action->expectedVersion, $observedVersion);
            }

            $level = $this->levels->get($current->level_id);
            $modifiers = ScenarioModifiers::fromArray($current->scenario_modifiers);

            // 規則不合法會在這裡丟例外，下面任何寫入都不會發生。
            $result = $this->engine->apply($current->battleState(), $action, $level, $modifiers);

            $sequence = (int) RunAction::query()->where('run_id', $current->id)->max('sequence') + 1;

            $record = RunAction::query()->create([
                'run_id' => $current->id,
                'action_id' => $action->actionId,
                'sequence' => $sequence,
                'fingerprint' => $action->fingerprint(),
                'input' => $action->toArray(),
                'events' => $result->eventsToArray(),
                'state_after' => $result->state->toArray(),
                'version_after' => $result->state->version,
            ]);

            $updated = Run::query()
                ->whereKey($current->getKey())
                ->where('version', $observedVersion)
                ->update([
                    'state' => json_encode($result->state->toArray(), JSON_THROW_ON_ERROR),
                    'version' => $result->state->version,
                    'outcome' => $result->state->outcome->value,
                    'finished_at' => $result->state->outcome->isFinished() ? Carbon::now() : null,
                    'updated_at' => Carbon::now(),
                ]);

            if ($updated !== 1) {
                // 有人在我們結算的同時改了這一列；整個交易回滾，這次不算數。
                throw RunConflictException::staleVersion(
                    $observedVersion,
                    (int) Run::query()->whereKey($current->getKey())->value('version'),
                );
            }

            $current->refresh();

            if ($result->state->outcome === Outcome::PlayerVictory) {
                $this->recordClear($current, $result->state->turn);
            }

            $current->campaign()->update(['last_activity_at' => Carbon::now()]);

            return ['action' => $record, 'run' => $current, 'replayed' => false];
        });
    }

    /**
     * 通關紀錄只保存最佳表現，不累積永久增益。
     */
    private function recordClear(Run $run, int $turns): void
    {
        $campaign = $run->campaign;
        $best = $campaign->best_results;
        $current = $best[$run->level_id] ?? null;

        if ($current === null || $turns < $current['turns']) {
            $best[$run->level_id] = ['turns' => $turns, 'run_id' => $run->public_id];
        }

        $unlocked = $campaign->unlocked;

        foreach ($this->levels->all() as $levelId => $level) {
            if ($level->requires === $run->level_id && ! in_array($levelId, $unlocked, true)) {
                $unlocked[] = $levelId;
            }
        }

        $campaign->forceFill(['best_results' => $best, 'unlocked' => $unlocked])->save();
    }
}
