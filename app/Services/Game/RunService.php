<?php

namespace App\Services\Game;

use App\Domain\Game\ActionRequest;
use App\Domain\Game\ActionType;
use App\Domain\Game\BattleEngine;
use App\Domain\Game\BattleState;
use App\Domain\Game\Exceptions\InvalidActionException;
use App\Domain\Game\LevelRepository;
use App\Domain\Game\Outcome;
use App\Domain\Game\RunMode;
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
 * 對局的交易邊界，也是唯一的時鐘。
 *
 * 引擎沒有時間概念——它只知道「這一次揭牌的截止時間是什麼」與「這一次是出牌
 * 還是逾時」。決策窗口的開啟與逾時判定都在這裡做，因此重播只要照著已保存的
 * 行動序列跑就會得到同一串事件，不會因為重播當下的時間不同而改變結果。
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
     * 開局：解析並凍結一組快照，換算情境修正，依 seed 洗好牌，寫入初始局面。
     */
    public function create(Campaign $campaign, string $levelId, RunMode $mode, ?int $seed = null): Run
    {
        $level = $this->levels->get($levelId);
        $snapshots = $this->snapshots->resolveSet();
        $modifiers = $this->calculator->calculate($snapshots);
        $seed ??= random_int(1, PHP_INT_MAX);
        $state = $this->engine->start($level, $seed);

        $snapshotIds = [];
        $metadata = [];

        foreach ($snapshots as $sourceId => $snapshot) {
            $snapshotIds[$sourceId] = $snapshot->snapshotId;
            $metadata[$sourceId] = [
                'snapshot_id' => $snapshot->snapshotId,
                'quality' => $snapshot->quality->value,
                'observed_at' => $snapshot->observedAt?->toIso8601String(),
                'period' => $snapshot->period?->toArray(),
                'warnings' => array_column($snapshot->warnings, 'message'),
            ];
        }

        return DB::transaction(function () use ($campaign, $level, $snapshotIds, $metadata, $modifiers, $state, $seed, $mode): Run {
            $campaign->forceFill(['last_activity_at' => Carbon::now()])->save();

            return Run::query()->create([
                'public_id' => (string) Str::uuid7(),
                'campaign_id' => $campaign->id,
                'level_id' => $level->id,
                'mode' => $mode->value,
                'rules_version' => $this->engine->rulesVersion(),
                'snapshot_ids' => $snapshotIds,
                'snapshot_metadata' => $metadata,
                'scenario_modifiers' => $modifiers->toArray(),
                'seed' => $seed,
                'state' => $state->toArray(),
                'version' => $state->version,
                'outcome' => $state->outcome,
            ]);
        });
    }

    public function assertCompatible(Run $run): void
    {
        if ($run->rules_version !== $this->engine->rulesVersion()) {
            throw new RunConflictException('rules_version_mismatch', '這一局使用舊版規則，可查看紀錄，請以目前規則另開新局');
        }
    }

    /**
     * 同情境重試：保留快照、牌組與 seed，方便比較不同選擇（P04-REVISION-PLAN §4.7）。
     */
    public function retry(Run $original): Run
    {
        $this->assertCompatible($original);
        $state = $this->engine->start($this->levels->get($original->level_id), (int) $original->seed);

        return DB::transaction(function () use ($original, $state): Run {
            $original->campaign()->update(['last_activity_at' => Carbon::now()]);

            return Run::query()->create([
                'public_id' => (string) Str::uuid7(),
                'campaign_id' => $original->campaign_id,
                'level_id' => $original->level_id,
                'mode' => $original->mode,
                'rules_version' => $original->rules_version,
                'snapshot_ids' => $original->snapshot_ids,
                'snapshot_metadata' => $original->snapshot_metadata,
                'scenario_modifiers' => $original->scenario_modifiers,
                'seed' => $original->seed,
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

            $this->assertCompatible($current);

            if ($action->expectedVersion !== $observedVersion) {
                throw RunConflictException::staleVersion($action->expectedVersion, $observedVersion);
            }

            $level = $this->levels->get($current->level_id);
            $modifiers = ScenarioModifiers::fromArray($current->scenario_modifiers);
            $state = $current->battleState();
            $resolved = $this->resolveAgainstClock($state, $action, $current->runMode());

            // 規則不合法會在這裡丟例外，下面任何寫入都不會發生。
            $result = $this->engine->apply($state, $resolved, $level, $modifiers);

            $sequence = (int) RunAction::query()->where('run_id', $current->id)->max('sequence') + 1;

            $record = RunAction::query()->create([
                'run_id' => $current->id,
                'action_id' => $action->actionId,
                'sequence' => $sequence,
                // 指紋用客戶端送來的原始 payload，不是收斂後的：重送同一筆遲到的出牌
                // 才會拿回同一次逾時結果，而不是被判成「不同 payload」。
                'fingerprint' => $action->fingerprint(),
                'input' => $resolved->toArray(),
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
     * 把伺服器時間套到這一次提交上。這是唯一採信的時鐘：客戶端聲稱的點擊時間
     * 一概不採用（P04-REVISION-PLAN §5）。
     *
     * - 揭牌：由伺服器決定截止時間（練習模式沒有截止時間）。
     * - 已到截止時間（含相等）才送到的出牌或換牌：改判為同一筆逾時結算。
     *   遲到的出牌不會先逾時再補打，整個回合只結算一次。
     * - 明確送 timeout 但還沒到截止：422，決策窗口不會被別人提早關掉。
     */
    private function resolveAgainstClock(BattleState $state, ActionRequest $action, RunMode $mode): ActionRequest
    {
        if ($action->type === ActionType::Reveal) {
            return $action->withDeadline($this->deadlineFor($mode));
        }

        $inWindow = $state->turnPhase === BattleState::PHASE_DECISION;
        $expired = $inWindow && $this->hasExpired($state);

        /*
         * 窗口還開著但時間沒到才是「還不能判逾時」。根本沒有揭牌的情況留給引擎回
         * hand_not_revealed——那才是客戶端真正要處理的狀況。
         */
        if ($action->type === ActionType::Timeout && $inWindow && ! $expired) {
            throw InvalidActionException::notTimedOut();
        }

        if ($expired && in_array($action->type, [ActionType::Play, ActionType::Swap], true)) {
            return $action->asTimeout();
        }

        return $action;
    }

    private function hasExpired(BattleState $state): bool
    {
        if ($state->deadlineAt === null) {
            return false;
        }

        // 相等即逾時：截止時間本身屬於「已經來不及」。
        return Carbon::now()->getTimestampMs() >= Carbon::parse($state->deadlineAt)->getTimestampMs();
    }

    private function deadlineFor(RunMode $mode): ?string
    {
        if (! $mode->isTimed()) {
            return null;
        }

        return Carbon::now()
            ->addSeconds((int) config('game.timer.decision_seconds'))
            ->toIso8601ZuluString('millisecond');
    }

    /**
     * 通關紀錄只保存最佳表現，不累積永久增益。挑戰與練習分開記錄：
     * 練習可以解鎖練習關，但不會讓限時挑戰的關卡跟著解鎖。
     */
    private function recordClear(Run $run, int $turns): void
    {
        $campaign = $run->campaign;
        $practice = $run->runMode() === RunMode::Practice;

        $best = $practice ? $campaign->practiceResults() : $campaign->best_results;
        $current = $best[$run->level_id] ?? null;

        if ($current === null || $turns < $current['turns']) {
            $best[$run->level_id] = ['turns' => $turns, 'run_id' => $run->public_id];
        }

        $unlocked = $practice ? $campaign->practiceUnlocked() : $campaign->unlocked;

        foreach ($this->levels->all() as $levelId => $level) {
            if ($level->requires === $run->level_id && ! in_array($levelId, $unlocked, true)) {
                $unlocked[] = $levelId;
            }
        }

        $campaign->forceFill($practice
            ? ['practice_results' => $best, 'practice_unlocked' => $unlocked]
            : ['best_results' => $best, 'unlocked' => $unlocked],
        )->save();
    }
}
