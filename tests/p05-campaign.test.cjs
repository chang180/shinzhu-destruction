const test = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');
const ts = require('typescript');

// 和 P04 的顯示測試一樣：直接跑出貨的 TypeScript，不另寫第二份實作。
const source = ts.transpileModule(fs.readFileSync('resources/js/game.ts', 'utf8'), {
  compilerOptions: { module: ts.ModuleKind.CommonJS, target: ts.ScriptTarget.ES2022 },
}).outputText;
const context = { exports: {}, crypto: globalThis.crypto };
vm.runInNewContext(source, context);
const { canPlay, levelStatusText, nextPlayableLevel, endingFor, deckSummary, briefingLines } = context.exports;
// 這些值來自另一個 vm realm，原型不同；比對結構前先搬回這個 realm。
const plain = value => JSON.parse(JSON.stringify(value));

const level = (id, overrides = {}) => ({
  level_id: id, sequence: 1, tier: 'main', available: true, name: id, subtitle: '', mechanic: '', lesson: '',
  briefing: null, apostle: id, max_turns: 8, requires: null, unlocked: true, practice_unlocked: true,
  best: null, practice_best: null, deck_size: 15, initial_defenses: {}, reward: null, forecast: [], ...overrides,
});
const campaign = (overrides = {}) => ({
  milestones: [], titles: {}, deck: {}, deck_choices: {}, pending_reward: null,
  main_cleared: false, stood_down: false, advanced_cleared: false,
  main_finale: 'meter-feast', advanced_finale: 'stored-night', ...overrides,
});

test('未交付內容與未解鎖的關卡都開不了，而且說得出是哪一種', () => {
  const madeButLocked = level('noon-fold', { unlocked: false, practice_unlocked: false });
  const unfinished = level('mirror-shade', { available: false });

  assert.equal(canPlay(madeButLocked, 'challenge'), false);
  assert.equal(levelStatusText(madeButLocked, 'challenge'), '先通過前一關');
  assert.equal(canPlay(unfinished, 'challenge'), false);
  assert.equal(levelStatusText(unfinished, 'challenge'), '內容製作中');
});

test('練習軌用自己的解鎖狀態與自己的最佳成績', () => {
  const onlyPractice = level('noon-fold', { unlocked: false, practice_unlocked: true, practice_best: { turns: 7, run_id: 'r1' } });

  assert.equal(canPlay(onlyPractice, 'challenge'), false);
  assert.equal(canPlay(onlyPractice, 'practice'), true);
  assert.equal(levelStatusText(onlyPractice, 'practice'), '最佳 7 回合');
  assert.equal(levelStatusText(level('empty-cup'), 'challenge'), '8 回合 · 牌組 15 張');
});

test('「繼續計畫」指向第一個還沒通關的可玩關卡', () => {
  const levels = [
    level('empty-cup', { best: { turns: 6, run_id: 'a' } }),
    level('noon-fold', { sequence: 2 }),
    level('meter-feast', { sequence: 3, unlocked: false, practice_unlocked: false }),
  ];

  assert.equal(nextPlayableLevel(levels, 'challenge').level_id, 'noon-fold');

  // 全部通關後回最後一個可玩的關卡，不會變成沒有入口。
  const cleared = levels.map(l => ({ ...l, unlocked: true, best: { turns: 8, run_id: 'x' } }));
  assert.equal(nextPlayableLevel(cleared, 'challenge').level_id, 'meter-feast');
});

test('主線終幕只在第 3 關通關且還沒收手時出現，收手後不再重播', () => {
  const win = { outcome: 'player_victory', level_id: 'meter-feast' };

  assert.equal(endingFor(win, campaign({ main_cleared: true }), 'meter-feast', 'stored-night'), 'main');
  assert.equal(endingFor(win, campaign({ main_cleared: true, stood_down: true }), 'meter-feast', 'stored-night'), null);
  assert.equal(endingFor({ outcome: 'city_held', level_id: 'meter-feast' }, campaign({ main_cleared: true }), 'meter-feast', 'stored-night'), null);
});

test('進階終幕獨立於收手；收手過的玩家打完第 5 關照樣拿到終幕', () => {
  const win = { outcome: 'player_victory', level_id: 'stored-night' };
  const state = campaign({ main_cleared: true, stood_down: true, advanced_cleared: true });

  assert.equal(endingFor(win, state, 'meter-feast', 'stored-night'), 'advanced');
});

test('牌組清單只列還在牌組裡的牌，並顯示張數', () => {
  const cards = { 'long-flow': { name: '千戶長流', role: '水系試探' }, 'tide-siege': { name: '潮圍夜巷', role: '水系破陣' } };
  const summary = plain(deckSummary({ 'long-flow': 2, 'tide-siege': 1, 'open-chill': 0 }, cards));

  assert.deepEqual(summary, [
    { name: '千戶長流', count: 2, role: '水系試探' },
    { name: '潮圍夜巷', count: 1, role: '水系破陣' },
  ]);
});

test('簡報標題來自關卡設定，沒有設定就不硬寫第一關的文案', () => {
  assert.deepEqual(plain(briefingLines(level('x', { briefing: { headline: '讓城市\n喊渴。', quote: '', quote_note: '', lessons: [] } }))), ['讓城市', '喊渴。']);
  assert.deepEqual(plain(briefingLines(undefined)), []);
});
