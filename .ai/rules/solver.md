---
paths:
  - 'app/Domain/Game/Solver/**'
  - 'app/Console/Commands/SolveLevelsCommand.php'
---

# Solver

## Solver proves existence only; keep it out of player metrics
SolvabilitySolver (game:solve) may read the full deterministic state incl. draw order, so it is never a Strategy and never part of difficulty_index. Action model: reveal, every legal play x every legal keep subset, one swap per turn, timeout. Status meanings are strict: budget exhaustion is exhausted_unknown (NOT unsolvable); exhaustively_unsolved only when the unlimited round finished with zero budget cuts and zero discrepancy skips. Only fully explored failed subtrees may be memoized as dead. Every solved path must replay via SolvabilitySolver::replay() to player_victory.

## Optional verified witness reuse for full matrices

`game:solve --reuse-paths` caches only within the same level/deck and keys by seed. Every candidate path must replay under the new scenario to player_victory before reuse; illegal or losing paths fall back to unchanged search. Output records solution_source and zero search nodes for reused paths; these are actual replayed witnesses, never inferred scenario equivalence. Default search and exhausted_unknown / exhaustively_unsolved meanings are unchanged. All published seeds still need replay_verified=true.