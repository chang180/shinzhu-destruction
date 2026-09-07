# Shinzhu project constraints

- Read README.md, docs/DEVELOPMENT-STATUS.md, docs/AI-HANDOFF.md and the assigned phase report before making changes. Complete only the assigned phase and update the root README and handoff evidence.
- The production application uses Laravel, Vue with TypeScript and Vite, SQLite, file cache/session and synchronous queues. Target Hostinger PHP shared hosting; no required Node daemon, Redis, Docker, SSR or queue worker in production.
- Keep docs/index.html, docs/app.js, docs/game.js and docs/style.css working as the independent GitHub Pages review prototype. Never send Vite output into docs, replace the Pages entry point, or make Pages depend on Laravel. Run the legacy and Pages browser checks when changing build or routing configuration.
- Laravel is the only authority for future battle rules. There are thirteen levels, not thirteen total turns. The player is a villain student; destroying the fictional city is the player's victory.
- Follow the README's verified central-government data sources. Freeze data snapshots per run, label missing/stale/demo data, and never present game modifiers as real disaster predictions.
- Preserve original artwork requirements and asset provenance. Downloaded candidate assets are not automatically approved or integrated production assets.
- Install Boost's available features, agents, package guidelines and integrations non-interactively. Do not modify vendor. Keep MCP configuration project-scoped, portable and free of secrets. Production must omit dev dependencies and disable Boost.
- Use PHPUnit for backend behavior, TypeScript checking, ESLint/Prettier for frontend code, Laravel Pint for PHP, and browser checks for the Laravel shell and the static Pages prototype. Never claim a check passed without running it.
- Public docs may be served by Pages: no credentials, private host configuration, raw environment dumps or personal information in reports.
