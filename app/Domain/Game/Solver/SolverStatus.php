<?php

namespace App\Domain\Game\Solver;

/**
 * 可解性搜尋的結論。
 *
 * - Solved：找到一條完整、可重播的通關路徑。
 * - ExhaustedUnknown：搜尋預算用完仍未找到；**不代表無解**。
 * - ExhaustivelyUnsolved：整棵行動樹都展開過（沒有任何分支因預算被截斷）仍無法通關。
 */
enum SolverStatus: string
{
    case Solved = 'solved';
    case ExhaustedUnknown = 'exhausted_unknown';
    case ExhaustivelyUnsolved = 'exhaustively_unsolved';
}
