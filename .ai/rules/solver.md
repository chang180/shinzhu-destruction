---
paths:
  - 'app/Domain/Game/Solver/**'
---

# Solver

## Solver proves existence only; keep it out of player metrics
SolvabilitySolver (game:solve) may read the full deterministic state incl. draw order, so it is never a Strategy and never part of difficulty_index. Action model: reveal, every legal play x every legal keep subset, one swap per turn, timeout. Status meanings are strict: budget exhaustion is exhausted_unknown (NOT unsolvable); exhaustively_unsolved only when the unlimited round finished with zero budget cuts and zero discrepancy skips. Only fully explored failed subtrees may be memoized as dead. Every solved path must replay via SolvabilitySolver::replay() to player_victory.
