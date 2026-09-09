<?php

namespace App\Domain\Game;

/**
 * 玩家可以送出的四種行動。
 *
 * 揭牌與換牌都會改變局面（因此推進 version），但都不推進城市回合；
 * 只有出牌與逾時會讓城市行動。
 */
enum ActionType: string
{
    /** 開始回合：揭示手牌並開啟決策窗口。 */
    case Reveal = 'reveal';

    /** 出牌或使用手牌旁的固定行動（蓄勢／終招）。 */
    case Play = 'play';

    /** 每回合一次的免費換牌。 */
    case Swap = 'swap';

    /** 決策窗口逾時：錯失行動，城市照預告行動。 */
    case Timeout = 'timeout';
}
