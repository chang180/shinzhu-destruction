<?php

namespace App\Domain\Game\Cards;

use App\Domain\Game\Element;
use App\Domain\Game\SkillKind;
use Random\Engine\Xoshiro256StarStar;
use Random\Randomizer;

/**
 * 實體牌的展開與洗牌。
 *
 * 每張實體牌有自己的 ID（`long-flow-1`、`long-flow-2`），所以「同招的兩張卡」
 * 是兩張不同的牌：留下其中一張不會連帶留下另一張，換掉一張也只有那一張離場。
 *
 * 洗牌完全由 (seed, 第幾次洗牌) 決定，不保存亂數器狀態。抽牌堆用完才把棄牌堆
 * 洗回來，`shuffleCount` 加一——同一個 seed 重跑一定得到同一串牌序，
 * 重整頁面也不會換手牌。
 */
final class Deck
{
    /**
     * 牌組設定展開成實體牌，順序固定（牌型設定順序 × 張數），洗牌前不含亂數。
     *
     * @param  array<string, int>  $composition  牌型 => 張數
     * @return array<string, string> 實體牌 ID => 牌型
     */
    public static function expand(array $composition): array
    {
        $cards = [];

        foreach ($composition as $cardId => $copies) {
            for ($copy = 1; $copy <= $copies; $copy++) {
                $cards[$cardId.'-'.$copy] = $cardId;
            }
        }

        return $cards;
    }

    /**
     * @param  list<string>  $cards
     * @return list<string>
     */
    public static function shuffle(array $cards, int $seed, int $shuffleCount): array
    {
        if ($cards === []) {
            return [];
        }

        $randomizer = new Randomizer(new Xoshiro256StarStar(
            hash('sha256', 'shinzhu:deck:'.$seed.':'.$shuffleCount, true),
        ));

        return array_values($randomizer->shuffleArray($cards));
    }

    /**
     * 開局牌序：洗完之後把三系試探各一張提到最前面。
     *
     * 首手保證看得到三系試探是教學需求（P04-REVISION-PLAN §4.5）——第一關要教
     * 「五張手牌」與「三個系別」，而不是讓玩家因為發牌極差在第一回合就沒得選。
     * 提前的是牌序，不是額外發牌，牌組張數與內容都沒有變。
     *
     * @param  array<string, string>  $deck  實體牌 ID => 牌型
     * @return list<string>
     */
    public static function openingOrder(array $deck, CardCatalog $catalog, int $seed): array
    {
        $pile = self::shuffle(array_keys($deck), $seed, 0);
        $front = [];

        foreach (Element::all() as $element) {
            foreach ($pile as $instanceId) {
                if (in_array($instanceId, $front, true)) {
                    continue;
                }

                $skill = $catalog->skillFor($deck[$instanceId]);

                if ($skill->kind === SkillKind::Probe && $skill->element === $element) {
                    $front[] = $instanceId;

                    break;
                }
            }
        }

        $rest = array_values(array_filter($pile, static fn (string $id): bool => ! in_array($id, $front, true)));

        return array_merge($front, $rest);
    }
}
