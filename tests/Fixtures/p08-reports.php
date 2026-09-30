<?php

use App\Domain\Game\ActionRequest;
use App\Domain\Game\ActionType;
use App\Domain\Game\BattleEngine;
use App\Domain\Game\BattleState;
use App\Domain\Game\Cards\CardCatalog;
use App\Domain\Game\LevelRepository;
use App\Domain\Game\Scenario\ScenarioModifiers;
use App\Domain\Game\Simulation\Strategies\PlannerStrategy;
use App\Domain\Game\SkillCatalog;
use Random\Engine\Xoshiro256StarStar;
use Random\Randomizer;

require __DIR__.'/../../vendor/autoload.php';

$config = require __DIR__.'/../../config/game.php';
$skills = new SkillCatalog($config['skills']);
$cards = new CardCatalog($config['cards'], $skills);
$engine = new BattleEngine($skills, $cards, $config);
$modifiers = ScenarioModifiers::neutral();
$planner = new PlannerStrategy($modifiers, $cards);
$reports = [];

foreach ((new LevelRepository($config['levels']))->all() as $level) {
    foreach (['planner', 'gather'] as $strategy) {
        $state = $engine->start($level, 1);
        $rng = new Randomizer(new Xoshiro256StarStar(hash('sha256', 'shinzhu:1', true)));
        $history = [];

        while (! $state->outcome->isFinished()) {
            $choice = $state->turnPhase === BattleState::PHASE_AWAITING_REVEAL
                ? ['type' => 'reveal']
                : ($strategy === 'planner' ? $planner->choose($state, $engine, $level, $rng) : ['type' => 'play', 'fixed' => 'gather']);
            $request = new ActionRequest(
                actionId: 'sample-'.count($history),
                expectedVersion: $state->version,
                type: ActionType::from($choice['type']),
                cardId: $choice['card_id'] ?? null,
                fixedSkillId: $choice['fixed'] ?? null,
                keep: $choice['keep'] ?? [],
            );
            $result = $engine->apply($state, $request, $level, $modifiers);
            $history[] = ['input' => $request->toArray(), 'events' => $result->eventsToArray()];
            $state = $result->state;
        }

        $reports[] = [
            'level_id' => $level->id,
            'strategy' => $strategy,
            'outcome' => $state->outcome->value,
            'state' => $state->toPublicArray(),
            'cards' => $cards->toArray(),
            'history' => $history,
        ];
    }
}

echo json_encode($reports, JSON_THROW_ON_ERROR);
