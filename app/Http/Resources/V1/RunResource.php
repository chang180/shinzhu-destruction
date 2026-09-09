<?php

namespace App\Http\Resources\V1;

use App\Domain\Game\BattleEngine;
use App\Domain\Game\Cards\CardCatalog;
use App\Domain\Game\Element;
use App\Domain\Game\LevelRepository;
use App\Models\Run;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Carbon;

/**
 * 對外的一局。
 *
 * 只送 `toPublicArray()`：手牌、棄牌與剩餘張數是公開資訊，未抽牌序不是。
 *
 * @mixin Run
 */
class RunResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var BattleEngine $engine */
        $engine = app(BattleEngine::class);
        /** @var CardCatalog $cards */
        $cards = app(CardCatalog::class);
        /** @var LevelRepository $levels */
        $levels = app(LevelRepository::class);

        $state = $this->battleState();
        $compatible = $this->rules_version === $engine->rulesVersion();

        return [
            'run_id' => $this->public_id,
            'level_id' => $this->level_id,
            'mode' => $this->mode,
            'rules_version' => $this->rules_version,
            'version' => $this->version,
            'outcome' => $this->outcome->value,
            'state' => $state->toPublicArray(),
            'compatible' => $compatible,
            'available_actions' => $compatible ? $engine->availableActions($state) : [],
            'cards' => $cards->toArray(),
            'snapshots' => $this->snapshot_metadata ?? [],
            'history' => $this->actions->map(static fn ($action): array => [
                'sequence' => $action->sequence,
                'input' => $action->input,
                'events' => $action->events,
            ])->all(),
            // 去秘密化：只送情境修正與推導理由，不送內部快照路徑或錯誤細節。
            'scenario' => $this->scenario_modifiers,
            'snapshot_ids' => $this->snapshot_ids,
            'data_notes' => $this->dataNotes($levels),
            // 倒數的唯一依據是 state.deadline_at；客戶端拿這個時間算時差，
            // 不用自己的時鐘判定逾時。
            'server_time' => Carbon::now()->toIso8601ZuluString('millisecond'),
        ];
    }

    /**
     * 每一系在本局實際生效的資料修正。
     *
     * 「本關未採用」是明確狀態，不是 0%：沒被這一關採用的系別不會因為有快照就
     * 在卡面上暗示已經加成（P04-REVISION-PLAN §6）。
     *
     * @return array<string, array{applied: bool, modifier: float|null, reason: string|null}>
     */
    private function dataNotes(LevelRepository $levels): array
    {
        $level = $levels->has($this->level_id) ? $levels->get($this->level_id) : null;
        $modifiers = $this->scenario_modifiers['modifiers'] ?? [];
        $reasons = $this->scenario_modifiers['reasons'] ?? [];
        $notes = [];

        foreach (Element::all() as $element) {
            $applied = $level !== null && $level->affectsElement($element);

            $notes[$element->value] = [
                'applied' => $applied,
                'modifier' => $applied ? ($modifiers[$element->value] ?? null) : null,
                'reason' => $applied ? ($reasons[$element->value]['message'] ?? null) : null,
            ];
        }

        return $notes;
    }
}
