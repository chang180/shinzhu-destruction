<?php

namespace Tests\Feature\Game;

use App\Domain\Game\ActionRequest;
use App\Domain\Game\ActionType;
use App\Domain\Game\BattleEngine;
use App\Domain\Game\BattleState;
use App\Domain\Game\Cards\CardCatalog;
use App\Domain\Game\LevelDefinition;
use App\Domain\Game\Scenario\ScenarioModifiers;
use App\Domain\Game\SkillCatalog;
use App\Domain\Game\TurnResult;
use App\Models\Run;
use App\Models\RunAction;
use App\Services\Game\Exceptions\RunConflictException;
use App\Services\Game\RunService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * TECHNICAL-SPEC §5：「採條件版本更新避免雙分頁覆寫；不依賴 SQLite 不支援的
 * row lock 語意。」SQLite 把 lockForUpdate() 編譯成空字串，所以讀取到寫入之間
 * 存在一段窗口；這裡用一個會在 apply() 期間搶先寫入的引擎把那段窗口固定重現。
 */
class ConcurrentSubmitTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_competing_write_during_settlement_is_rejected_instead_of_overwritten(): void
    {
        $this->postJson(route('api.v1.runs.store'), ['level_id' => 'empty-cup'])->assertCreated();
        $run = Run::query()->firstOrFail();

        $this->app->bind(BattleEngine::class, fn ($app) => new CompetingWriteEngine(
            $app->make(SkillCatalog::class),
            $app->make(CardCatalog::class),
            $app->make('config')->get('game'),
            $run->id,
        ));

        $service = $this->app->make(RunService::class);
        $stateBefore = $run->state;

        try {
            $service->submit($run, new ActionRequest('act-1', 1, ActionType::Reveal));
            $this->fail('搶先寫入之後仍然結算成功，代表發生了 lost update');
        } catch (RunConflictException $exception) {
            $this->assertSame('stale_version', $exception->reasonCode);
        }

        /*
         * 整個交易回滾，所以這次結算完全沒有留下痕跡：沒有行動紀錄，局面也沒動。
         * （模擬的搶先寫入同樣在這個交易內，因此一併被撤銷；重點是我們沒有把它蓋掉。）
         */
        $this->assertSame(0, RunAction::query()->count());

        $after = Run::query()->firstOrFail();
        $this->assertSame(1, $after->version);
        $this->assertSame($stateBefore, $after->state);
    }
}

/**
 * 在 apply() 期間模擬「另一個請求先一步 commit」。
 */
class CompetingWriteEngine extends BattleEngine
{
    /**
     * @param  array<string, mixed>  $config
     */
    public function __construct(SkillCatalog $skills, CardCatalog $cards, array $config, private readonly int $runId)
    {
        parent::__construct($skills, $cards, $config);
    }

    public function apply(
        BattleState $state,
        ActionRequest $action,
        LevelDefinition $level,
        ScenarioModifiers $modifiers,
    ): TurnResult {
        DB::table('runs')->where('id', $this->runId)->update(['version' => 99]);

        return parent::apply($state, $action, $level, $modifiers);
    }
}
