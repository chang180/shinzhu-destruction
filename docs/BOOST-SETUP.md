# Laravel Boost 安裝紀錄

更新：2026-09-08｜安裝版本：`laravel/boost v2.7.1`

## 執行方式

Phase 1 先以 `composer require laravel/boost --dev --no-interaction --prefer-dist` 安裝，再由 `scripts/configure-boost.php` 啟動 Laravel kernel，核對版本與路徑，寫入專案根目錄的 `boost.json`，最後執行：

```sh
php artisan boost:install --guidelines --skills --mcp --no-interaction
```

腳本會拒絕不在專案範圍內的輸出路徑、Boost 版本不符、渲染失敗、缺少 skill 或 MCP 設定。完整去秘密化輸出會寫到本機 `output/boost-install.log` 與 `output/boost-install.json`；`output/` 不提交，報告只記錄必要摘要。

## 已選功能

| 類別 | 結果 | 證據 |
|---|---|---|
| Guidelines | 已選且成功 | 9 份 guideline 已由 Boost 寫入各 agent；根 `AGENTS.md`／`CLAUDE.md` 已重新讀取 |
| Skills | 已選且成功 | `infer-conventions`、`laravel-best-practices`、`testing-best-practices`、`deploying-laravel-cloud` |
| MCP | 已選且成功 | 12 個具檔案策略的 agent 產生設定；Pi 本版沒有 MCP 能力 |
| Laravel Cloud | 已選且成功 | 因 skills 開啟而可選，產物為 `.ai/skills/deploying-laravel-cloud/`；不代表正式改用 Cloud |
| Nightwatch | 本版／本環境不可選 | Boost 偵測到未安裝 Nightwatch 套件 |
| Sail | 本版／本環境不可選 | Boost 偵測到未安裝 Sail 環境；Hostinger 也不依賴 Docker |
| 第三方套件 guidelines | 本版／本環境無可選項 | `ThirdPartyPackage::discover()` 回傳空集合，未為了全選額外安裝套件 |

## Agent 產物矩陣

所有列出的 agent 都寫入 guidelines；支援 skills 的 agent 同步四份 skill。MCP 設定為專案範圍且無秘密，未安裝的客戶端只代表「已產生設定、尚未連線實測」。

| Agent | Guidelines | Skills | MCP 檔案 | 結果 |
|---|---|---|---|---|
| Amp | `AGENTS.md` | `.agents/skills` | `.amp/settings.json` | 已選且成功 |
| Antigravity | `AGENTS.md` | `.agents/skills` | `.agents/mcp_config.json` | 已選且成功 |
| Claude Code | `CLAUDE.md` | `.claude/skills` | `.mcp.json` | 已選且成功 |
| Codex | `AGENTS.md` | `.agents/skills` | `.codex/config.toml` | 已選且成功 |
| GitHub Copilot | `AGENTS.md` | `.github/skills` | `.vscode/mcp.json` | 已選且成功 |
| Cursor | `AGENTS.md` | `.cursor/skills` | `.cursor/mcp.json` | 已選且成功 |
| Factory Droid | `AGENTS.md` | `.factory/skills` | `.factory/mcp.json` | 已選且成功 |
| Grok Build | `AGENTS.md` | `.grok/skills` | `.grok/config.toml` | 已選且成功 |
| Junie | `AGENTS.md` | `.junie/skills` | `.junie/mcp/mcp.json` | 已選且成功 |
| Kiro | `AGENTS.md` | `.kiro/skills` | `.kiro/settings/mcp.json` | 已選且成功 |
| OpenCode | `AGENTS.md` | `.agents/skills` | `opencode.json` | 已選且成功 |
| Pi | `AGENTS.md` | `.pi/skills` | 不適用 | 已選且成功 |
| Zed | `AGENTS.md` | `.agents/skills` | `.zed/settings.json` | 已選且成功 |

## 使用規範

- 進入每個階段前先讀根 `AGENTS.md`、`CLAUDE.md` 與專案規則；使用 Laravel API 前先查閱版本相符文件。
- PHP 變更執行 `vendor/bin/pint --dirty --format agent`；行為變更補 PHPUnit feature test；前端變更執行 TypeScript check 與 Vite build。
- MCP 設定只服務開發；正式部署使用 `composer install --no-dev` 並設定 `BOOST_ENABLED=false`，不公開開發工具端點。
- 重新安裝或升版後必須重新執行腳本，檢查 agent 矩陣、路徑及 skill/MCP 內容，不可只看 Artisan exit code。
