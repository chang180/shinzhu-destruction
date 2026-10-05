---
paths:
  - 'tests/**'
---

# Tests

## Baseline comparisons need a real vendor copy in the worktree
When comparing output of an older commit via `git worktree`, copy vendor (cp -R), never symlink it: Composer's autoloader resolves __DIR__ through the symlink and silently loads app/ classes from the main checkout, so "old vs new" compares new code with itself. Verify with ReflectionClass(...)->getFileName() inside the worktree. Also: full-suite assertion counts vary run to run because API tests use server-generated seeds; do not report a single assertion count as a stable baseline.
