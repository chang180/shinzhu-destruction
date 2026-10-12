// Run explicitly against the local Laravel server; uses the bundled Playwright runtime.
// UI fixtures come from the actual PHP engine. Requests are intercepted to avoid changing saves.
const { chromium } = require('playwright');
const { execFileSync } = require('node:child_process');
const fs = require('node:fs');
const path = require('node:path');
const http = require('node:http');
const assert = require('node:assert/strict');

const base = process.env.VISUAL_QA_URL || 'http://127.0.0.1:8000';
const evidence = process.env.VISUAL_QA_EVIDENCE || 'docs/phase-reports/data/visual-v2';
const pagesFiles = ['index.html', 'app.js', 'game.js', 'style.css'];
const originalPages = Object.fromEntries(pagesFiles.map(file => [file, fs.readFileSync('docs/' + file)]));
fs.mkdirSync(evidence, { recursive: true });
const php = String.raw`
ob_start(); require 'tests/Fixtures/p08-reports.php'; ob_end_clean();
$output = [];
foreach ((new LevelRepository($config['levels']))->all() as $level) {
    $start = $engine->start($level, 1);
    $revealed = $engine->apply($start, new ActionRequest(actionId: 'qa-reveal', expectedVersion: $start->version, type: ActionType::Reveal), $level, $modifiers)->state;
    $base = ['run_id' => 'visual-qa-'.$level->id, 'level_id' => $level->id, 'mode' => 'practice', 'rules_version' => $config['rules_version'], 'compatible' => true, 'cards' => $cards->toArray(), 'snapshots' => [], 'scenario' => ['modifiers' => [], 'reasons' => []], 'data_notes' => [], 'server_time' => gmdate('c')];
    $output[$level->id]['opening'] = $base + ['outcome' => 'in_progress', 'version' => $revealed->version, 'state' => $revealed->toPublicArray(), 'available_actions' => $engine->availableActions($revealed), 'history' => []];
    foreach (['planner', 'gather'] as $strategy) {
        for ($seed = 1; $seed <= 30; $seed++) {
            $state = $engine->start($level, $seed); $history = [];
            $rng = new Randomizer(new Xoshiro256StarStar(hash('sha256', 'shinzhu:'.$seed, true)));
            while (!$state->outcome->isFinished()) {
                $choice = $state->turnPhase === BattleState::PHASE_AWAITING_REVEAL ? ['type' => 'reveal'] : ($strategy === 'planner' ? $planner->choose($state, $engine, $level, $rng) : ['type' => 'play', 'fixed' => 'gather']);
                $request = new ActionRequest(actionId: 'qa-'.count($history), expectedVersion: $state->version, type: ActionType::from($choice['type']), cardId: $choice['card_id'] ?? null, fixedSkillId: $choice['fixed'] ?? null);
                $result = $engine->apply($state, $request, $level, $modifiers);
                $history[] = ['sequence' => count($history) + 1, 'input' => $request->toArray(), 'events' => $result->eventsToArray()];
                $state = $result->state;
            }
            if ($strategy === 'gather' || $state->outcome->value === 'player_victory') break;
        }
        $output[$level->id][$strategy] = $base + ['outcome' => $state->outcome->value, 'version' => $state->version, 'state' => $state->toPublicArray(), 'available_actions' => [], 'history' => $history];
    }
}
echo json_encode($output, JSON_THROW_ON_ERROR);
`;
// Imports in a required PHP file do not propagate to this compilation unit.
const imports = ['ActionRequest','ActionType','BattleState','LevelRepository'].map(name => `use App\\Domain\\Game\\${name};`).join('\n').replaceAll('\\\\', '\\')
  + '\nuse Random\\Engine\\Xoshiro256StarStar;\nuse Random\\Randomizer;\n';
const fixtures = JSON.parse(execFileSync('php', ['-r', imports + php], { maxBuffer: 4 * 1024 * 1024 }).toString());

(async () => {
  const browser = await chromium.launch({ headless: true, ...(process.env.VISUAL_QA_BROWSER ? { executablePath: process.env.VISUAL_QA_BROWSER } : {}) });
  const context = await browser.newContext({ viewport: { width: 1280, height: 900 }, ignoreHTTPSErrors: base === 'https://shinzhu.test' });
  const catalogResponse = await context.request.get(base + '/api/v1/levels');
  assert.equal(catalogResponse.status(), 200);
  const original = await catalogResponse.json();
  assert.equal(original.rules_version, '8.0.0');
  let selected = 'noon-fold', view = 'opening', reward = false;
  const issues = [], requests = [], checks = [], briefingGeometry = [];
  let initialBytes = null;
  const page = await context.newPage();
  page.on('pageerror', error => issues.push(error.message));
  page.on('response', response => { if (response.status() >= 400) issues.push(response.status() + ' ' + new URL(response.url()).pathname); });
  page.on('request', request => { if (request.url().includes('/assets/visual-v2/')) requests.push(new URL(request.url()).pathname); });
  await page.route('**/api/v1/**', async route => {
    const url = new URL(route.request().url());
    if (url.pathname.endsWith('/levels')) {
      const catalog = structuredClone(original);
      catalog.levels.forEach(level => { level.unlocked = true; level.practice_unlocked = true; });
      catalog.campaign.main_cleared = selected === 'meter-feast';
      catalog.campaign.advanced_cleared = selected === 'stored-night';
      catalog.campaign.stood_down = false;
      if (reward) {
        const level = catalog.levels.find(level => level.level_id === selected);
        const added = level.reward.options.map(id => catalog.cards[id]);
        const removed = Object.values(catalog.cards).find(card => card.kind === 'probe');
        catalog.campaign.pending_reward = { level_id: selected, level_name: level.name, prompt: 'Fixture reward', chosen: null, options: added.map((add, index) => ({ key: level.reward.options[index], style: '策略選修', add, remove: removed, remove_remaining: 2 })) };
      } else catalog.campaign.pending_reward = null;
      return route.fulfill({ json: catalog });
    }
    if (url.pathname.endsWith('/runs') && route.request().method() === 'GET') return route.fulfill({ json: { data: [] } });
    if (url.pathname.endsWith('/runs') && route.request().method() === 'POST') {
      const data = structuredClone(fixtures[selected][view]);
      // Milestone screens are only shown in challenge mode; the fixtures have no running clock.
      if (view !== 'opening') data.mode = 'challenge';
      return route.fulfill({ json: { data } });
    }
    if (/\/runs\/visual-qa-/.test(url.pathname)) return route.fulfill({ json: { data: fixtures[selected][view] } });
    return route.abort();
  });
  async function inspect(label) {
    for (const image of await page.locator('main img').all()) { await image.scrollIntoViewIfNeeded(); await image.evaluate(img => img.decode()); }
    assert.equal(await page.evaluate(() => document.documentElement.scrollWidth > innerWidth + 1), false, label + ' horizontal overflow');
    const bad = await page.locator('main img').evaluateAll(images => images.filter(img => !img.complete || !img.naturalWidth).length);
    assert.equal(bad, 0, label + ' broken images');
    await page.screenshot({ path: `${evidence}/${label}.png`, fullPage: true });
    checks.push(label);
  }
  async function open() {
    await page.goto(base, { waitUntil: 'networkidle' });
    if (initialBytes === null) {
      initialBytes = await page.evaluate(() => performance.getEntriesByType('resource').reduce((sum, entry) => sum + entry.encodedBodySize, 0) + performance.getEntriesByType('navigation')[0].encodedBodySize);
      assert.ok(initialBytes < 3 * 1024 * 1024, 'initial screen exceeds 3 MiB');
    }
    const level = original.levels.find(level => level.level_id === selected);
    await page.locator('.route li').filter({ hasText: level.name }).getByRole('button').click();
    await page.locator(view === 'opening' ? '.briefing' : '.report').waitFor({ state: 'visible' });
  }
  try {
    for (const width of [2560, 1280, 390, 320]) {
      await page.setViewportSize({ width, height: 900 });
      for (const id of ['empty-cup', 'noon-fold', 'meter-feast', 'mirror-shade', 'stored-night']) {
        selected = id; view = 'opening'; reward = false;
        await open(); await inspect(`${width}-${id}-briefing`);
        const geometry = await page.locator('.briefing-art img').evaluate(img => {
          const bounds = img.getBoundingClientRect();
          return { width: bounds.width, height: bounds.height, naturalWidth: img.naturalWidth, naturalHeight: img.naturalHeight, fit: getComputedStyle(img).objectFit, source: new URL(img.currentSrc).pathname };
        });
        assert.equal(geometry.fit, 'contain', `${id}: illustration must not be cropped`);
        assert.ok(Math.abs(geometry.width / geometry.height - 16 / 9) < 0.01, `${id}: inconsistent frame`);
        assert.ok(Math.abs(geometry.naturalWidth / geometry.naturalHeight - 16 / 9) < 0.01, `${id}: portrait source in landscape frame`);
        assert.match(geometry.source, new RegExp(`/visual-v2/briefing-${id}`));
        const previous = briefingGeometry.find(frame => frame.viewport === width);
        if (previous) assert.ok(Math.abs(previous.width - geometry.width) < 1 && Math.abs(previous.height - geometry.height) < 1, 'all five briefing frames must have equal dimensions');
        briefingGeometry.push({ viewport: width, level: id, ...geometry });
        await page.getByRole('button', { name: '明白了，開始連續試煉' }).click();
        await page.locator('.battle').waitFor({ state: 'visible' });
        await inspect(`${width}-${id}-battle`);
        if (width === 1280) {
          await page.locator('.settings summary').click();
          await page.getByLabel('靜音', { exact: true }).uncheck();
          await page.waitForTimeout(250);
          await page.getByLabel('靜音', { exact: true }).check();
          await page.locator('.settings summary').click();
        }
        for (const outcome of ['planner', 'gather']) {
          view = outcome;
          assert.equal(fixtures[id][outcome].outcome, outcome === 'planner' ? 'player_victory' : 'city_held');
          await open(); await inspect(`${width}-${id}-${outcome}`);
          if (width === 1280 && id === 'noon-fold' && outcome === 'planner') {
            await page.getByText('展開完整戰報與演出', { exact: true }).click();
            await page.getByRole('button', { name: '重播本局演出' }).click();
            await page.getByRole('dialog', { name: '戰鬥演出' }).waitFor();
            await page.keyboard.press('Escape');
            await page.getByRole('dialog', { name: '戰鬥演出' }).waitFor({ state: 'hidden' });
          }
        }
      }
      selected = 'noon-fold'; view = 'planner'; reward = true;
      await open(); await page.getByRole('button', { name: '挑選新禁術' }).click();
      await page.locator('.reward-options').waitFor({ state: 'visible' });
      await inspect(`${width}-reward`);
    }
    // Asset manifest remains outside public; use its non-sensitive keys from the local test harness.
    const soundManifest = JSON.parse(fs.readFileSync('assets/visual-v2-audio.json', 'utf8'));
    const audio = await page.evaluate(async keys => {
      const context = new AudioContext(); const results = [];
      for (const key of keys) for (const ext of ['ogg', 'mp3']) {
        const response = await fetch(`/assets/visual-v2/audio/${key}.${ext}`);
        const decoded = await context.decodeAudioData(await response.arrayBuffer());
        results.push({ key, ext, duration: decoded.duration, channels: decoded.numberOfChannels });
      }
      await context.close(); return results;
    }, soundManifest.assets.map(asset => asset.key));
    assert.equal(audio.length, 52);
    const docsRoot = path.resolve('docs');
    const prototype = http.createServer((request, response) => {
      const file = path.resolve(docsRoot, '.' + decodeURIComponent(new URL(request.url, 'http://local').pathname === '/' ? '/index.html' : new URL(request.url, 'http://local').pathname));
      if (!file.startsWith(docsRoot + path.sep) || !fs.existsSync(file)) { response.writeHead(404).end(); return; }
      const type = { '.js': 'text/javascript', '.css': 'text/css', '.html': 'text/html' }[path.extname(file)] || 'application/octet-stream';
      response.setHeader('Content-Type', type); response.end(fs.readFileSync(file));
    });
    await new Promise(resolve => prototype.listen(8001, '127.0.0.1', resolve));
    const staticPage = await context.newPage();
    staticPage.on('pageerror', error => issues.push('Pages: ' + error.message));
    await staticPage.goto('http://127.0.0.1:8001', { waitUntil: 'networkidle' });
    await staticPage.screenshot({ path: `${evidence}/pages-prototype.png`, fullPage: true });
    await new Promise(resolve => prototype.close(resolve));
    for (const file of pagesFiles) {
      assert.deepEqual(fs.readFileSync('docs/' + file), originalPages[file]);
      const current = execFileSync('git', ['hash-object', 'docs/' + file]).toString().trim();
      const committed = execFileSync('git', ['rev-parse', 'abfbb3d:docs/' + file]).toString().trim();
      assert.equal(current, committed);
    }
    assert.equal(issues.length, 0, JSON.stringify(issues));
    fs.writeFileSync(`${evidence}/browser-results.json`, JSON.stringify({ base: base === 'https://shinzhu.test' ? 'local HTTPS shinzhu.test' : 'local Laravel shell', briefing_geometry: briefingGeometry, rules_version: original.rules_version, initial_screen_encoded_bytes: initialBytes, replay_escape: 'passed', fixture_note: 'Real PHP engine opening/win/loss histories; mocked campaign/API responses for UI coverage. No production or real-save changes.', viewports: [2560, 1280, 390, 320], checks, loaded_assets: [...new Set(requests)], decoded_audio: audio, issues, pages: 'browser loaded, no script errors; four entry files byte-identical throughout QA and Git-normalized content identical to abfbb3d (Windows CRLF checkout)' }, null, 2));
    console.log(JSON.stringify({ screens: checks.length, sounds_decoded: audio.length, issues, pages: 'passed' }));
  } finally { await browser.close(); }
})().catch(error => { console.error(error); process.exitCode = 1; });
