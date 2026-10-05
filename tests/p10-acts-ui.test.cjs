const test = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');
const ts = require('typescript');

// 直接跑出貨的 TypeScript，不另寫第二份實作（同 p10-acts 與 P04／P05 的顯示測試）。
const source = ts.transpileModule(fs.readFileSync('resources/js/game.ts', 'utf8'), {
  compilerOptions: { module: ts.ModuleKind.CommonJS, target: ts.ScriptTarget.ES2022 },
}).outputText;
const context = { exports: {}, crypto: globalThis.crypto };
vm.runInNewContext(source, context);
const { actSteps, currentAct, nextActText, actBriefingLines, isConditionalAct, reportActs, visibleEvents, cueDuration } = context.exports;
// vm 裡建立的陣列與外層不是 reference-equal，比較前先轉成純資料（同 p10-acts）。
const plain = value => JSON.parse(JSON.stringify(value));

const act = (id, label, startsWhen, objective = `${label}的目標`, next = null) => ({
  id, order: 1, label, objective, starts_when: startsWhen, starts_when_summary: `${label}的條件`, next_phase_summary: next,
});
const turnAct = (id, label, turn) => act(id, label, { type: 'turn_gte', value: turn });
const flagAct = (id, label, flag) => act(id, label, { type: 'flag_true', flag });

// 第 3 關那種純回合幕，與第 5 關那種條件幕。
const threeTurnActs = [turnAct('a', '第一幕', 1), turnAct('b', '第二幕', 4), turnAct('c', '第三幕', 7)];
const storedNight = [
  turnAct('county-alert', '第一幕・全縣戒備', 1),
  act('overhaul-warning', '第二幕・重整警報', { type: 'any_of', of: [{ type: 'turn_gte', value: 4 }, { type: 'flag_true', flag: 'overhaul_started' }] }),
  act('last-night', '第三幕・最後一夜', { type: 'any_of', of: [{ type: 'flag_true', flag: 'overhaul_stopped' }, { type: 'flag_true', flag: 'overhaul_completed' }] }),
];

test('the current act comes from the saved level_phase_id', () => {
  const level = { phases: threeTurnActs };
  assert.equal(currentAct(level, { level_phase_id: 'b' }).id, 'b');
});

test('a pre-P10-1 save with no act id reads as the first act', () => {
  const level = { phases: threeTurnActs };
  assert.equal(currentAct(level, { level_phase_id: null }).id, 'a');
  assert.equal(actSteps(level, { level_phase_id: null })[0].status, 'current');
});

test('the progress strip marks earlier acts done and later acts upcoming', () => {
  const steps = actSteps({ phases: threeTurnActs }, { level_phase_id: 'b' });
  assert.deepEqual(plain(steps.map(step => step.status)), ['done', 'current', 'upcoming']);
});

test('a single-act level shows no progress strip', () => {
  assert.deepEqual(plain(actSteps({ phases: [turnAct('only', '全關', 1)] }, { level_phase_id: 'only' })), []);
  assert.deepEqual(plain(actSteps(undefined, { level_phase_id: null })), []);
});

test('only acts without any turn threshold count as conditional', () => {
  assert.equal(isConditionalAct(turnAct('a', '第一幕', 1)), false);
  // any_of 裡只要有一項帶回合，排程就成立，不算條件幕。
  assert.equal(isConditionalAct(storedNight[1]), false);
  assert.equal(isConditionalAct(storedNight[2]), true);
  assert.equal(isConditionalAct(flagAct('x', '旗標幕', 'overhaul_stopped')), true);
});

test('the conditional act is flagged in the progress strip', () => {
  const steps = actSteps({ phases: storedNight }, { level_phase_id: 'county-alert' });
  assert.deepEqual(plain(steps.map(step => step.conditional)), [false, false, true]);
});

test('a conditional next act says it has no fixed turn', () => {
  const text = nextActText({ phases: storedNight }, { level_phase_id: 'overhaul-warning' });
  assert.match(text, /第三幕・最後一夜/);
  assert.match(text, /沒有固定回合/);
});

test('an ordinary next act just states its condition', () => {
  const text = nextActText({ phases: threeTurnActs }, { level_phase_id: 'a' });
  assert.match(text, /第二幕/);
  assert.doesNotMatch(text, /沒有固定回合/);
});

test('the last act has no next-act hint', () => {
  assert.equal(nextActText({ phases: threeTurnActs }, { level_phase_id: 'c' }), null);
});

test('briefing lists one line per act and marks the conditional one', () => {
  const lines = actBriefingLines({ phases: storedNight });
  assert.equal(lines.length, 3);
  assert.match(lines[2], /條件幕，沒有固定回合/);
  assert.doesNotMatch(lines[0], /條件幕/);
  assert.deepEqual(plain(actBriefingLines({ phases: [turnAct('only', '全關', 1)] })), []);
});

const event = (turn, type, extra = {}) => ({ sequence: 0, turn, type, actor: 'city', target: null, reason_code: '', cue_id: '', before: {}, delta: {}, after: {}, ...extra });
const run = events => ({ history: [{ sequence: 1, input: {}, events }], state: { turn: events[events.length - 1].turn } });

test('the report splits turns by the acts the run actually entered', () => {
  const events = [
    event(1, 'impact', { delta: { core_resilience: -10 } }),
    event(3, 'level_phase_change', { after: { level_phase_id: 'b', from_turn: 4 } }),
    event(5, 'city_repair', { delta: { core_resilience: 12 } }),
    event(6, 'outcome', { after: { outcome: 'city_held' } }),
  ];
  const sections = reportActs(run(events), { phases: threeTurnActs });

  assert.deepEqual(plain(sections.map(s => s.turns)), [[1, 2, 3], [4, 5, 6], []]);
  assert.deepEqual(plain(sections.map(s => s.reached)), [true, true, false]);
  assert.match(sections[0].lines[0], /10 點損失/);
  assert.match(sections[1].lines[0], /修回 12 點/);
});

test('an act the run never entered says so instead of inventing content', () => {
  const sections = reportActs(run([event(2, 'outcome', { after: { outcome: 'city_held' } })]), { phases: threeTurnActs });
  assert.equal(sections[1].reached, false);
  assert.match(sections[1].lines[0], /沒有進入這一幕/);
});

test('an act with no notable record says that rather than padding', () => {
  const sections = reportActs(run([event(1, 'hand_revealed'), event(2, 'outcome', { after: { outcome: 'city_held' } })]), { phases: threeTurnActs });
  assert.match(sections[0].lines[0], /沒有命中、修復或打斷的紀錄/);
});

test('a single-act level gets no per-act report', () => {
  assert.deepEqual(plain(reportActs(run([event(1, 'outcome', { after: { outcome: 'city_held' } })]), { phases: [turnAct('only', '全關', 1)] })), []);
});

test('entering an act is a short skippable cue, well under the decision window', () => {
  const change = event(3, 'level_phase_change', { after: { level_phase_id: 'b', label: '第二幕' } });
  assert.ok(visibleEvents([change]).includes(change), '幕次切換要進演出佇列');
  assert.equal(cueDuration(change), 900);
  assert.ok(cueDuration(change) < 3000, '演出要遠短於 30 秒決策窗口');
});
