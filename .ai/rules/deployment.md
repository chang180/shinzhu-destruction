---
paths:
  - 'package.json'
  - 'vite.config.mts'
---

# 部署建置：共享主機的 Rayon 執行緒陷阱

Hostinger 共享主機的 `nproc` 會回報實體機核心數（本機測到 64），但帳號實際可用的執行緒配額遠低於此。Vite 用的 Rolldown（Rust）啟動 rayon 執行緒池時會照 `nproc` 申請執行緒，超過配額會直接 panic：

```
Rolldown panicked... rayon-core/src/registry.rs:171
ThreadPoolBuildError { kind: IOError(Os { code: 11, kind: WouldBlock, message: "Resource temporarily unavailable" }) }
```

在共享主機上跑 `npm run build`（或任何會呼叫 Vite/Rolldown 的指令）一律要限制執行緒數，否則會直接建置失敗：

```
RAYON_NUM_THREADS=1 npm run build
```

`composer.json` 的 `setup` script 也會呼叫 `npm run build`，但目前沒有帶這個環境變數——在共享主機上跑 `composer setup`／`composer install`（含 npm build 這段）前，記得先 `export RAYON_NUM_THREADS=1`，或改成 `RAYON_NUM_THREADS=1 composer setup` 執行，否則同樣會炸。

# 部署後驗證：`config:cache` 會讓 `php artisan test` 假性全滅

正式環境部署流程通常是「跑 migration → build 前端 → `config:cache`／`route:cache`／`view:cache`」。如果部署驗證直接在這之後跑 `php artisan test`，PHPUnit 會載入已快取的 production 設定（而非 `phpunit.xml` 裡定義的 testing 環境變數），導致大量測試以非預期狀態失敗——例如 session/CSRF 相關設定跑到 production 值，造成原本應該 2xx 的請求全部回 419。

驗證步驟務必照這個順序：

```
php artisan config:clear   # 先清掉正式設定快取
php artisan test --compact
php artisan config:cache   # 測完再還原正式快取
```

`composer test` script 本身已經內建 `config:clear` 再 `artisan test`，走那條路不會中這個陷阱；只有直接手動呼叫 `php artisan test` 才需要自己記得清快取。

# Laravel Boost 在正式環境不註冊指令是預期行為

`vendor/laravel/boost/src/BoostServiceProvider.php` 只在 `app()->environment('local')` 或 `config('app.debug') === true` 時才註冊 Boost 的 artisan 指令與 MCP server。正式環境（`APP_ENV=production`、`APP_DEBUG=false`）下呼叫 `php artisan boost:mcp` 或任何 `boost:*` 指令會得到 `NamespaceNotFoundException: There are no commands defined in the "boost" namespace.`，MCP client 連線也會直接被拒（`CONNECTION_CLOSED`）。

這正是 CLAUDE.md「Production 必須停用 Boost」的規則生效中，不是故障，部署後看到這個錯誤或連線失敗不需要排查或修復。
