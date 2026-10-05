const test = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');
const ts = require('typescript');

// 直接跑出貨的 TypeScript，不另寫第二份實作（同 P04／P05 的顯示測試）。
const source = ts.transpileModule(fs.readFileSync('resources/js/game.ts', 'utf8'), {
  compilerOptions: { module: ts.ModuleKind.CommonJS, target: ts.ScriptTarget.ES2022 },
}).outputText;
const context = { exports: {}, crypto: globalThis.crypto };
vm.runInNewContext(source, context);
const { forecastLines, eventText } = context.exports;
const plain = value => JSON.parse(JSON.stringify(value));

const intent = (turn, phase, description) => ({ type: 'reinforce', element: 'water', magnitude: 4, interruptible: false, scheduled_turn: turn, description, level_phase_id: phase });
const phase = (id, label) => ({ id, order: 1, label, objective: '', starts_when_summary: '', next_phase_summary: null });

test('multi-act forecast labels only the first turn of each act', () => {
  const level = {
    phases: [phase('a', '第一幕・試杯'), phase('b', '第二幕・斷補')],
    forecast: [intent(1, 'a', '補強'), intent(2, 'a', '補強'), intent(3, 'b', '修復'), intent(4, 'b', '補強')],
  };

  assert.deepEqual(plain(forecastLines(level)), [
    { turn: 1, text: '【第一幕・試杯】補強' },
    { turn: 2, text: '補強' },
    { turn: 3, text: '【第二幕・斷補】修復' },
    { turn: 4, text: '補強' },
  ]);
});

test('single-act forecast stays unlabelled', () => {
  const level = { phases: [phase('main', '全關')], forecast: [intent(1, 'main', '補強'), intent(2, 'main', '修復')] };

  assert.deepEqual(plain(forecastLines(level)).map(line => line.text), ['補強', '修復']);
  assert.deepEqual(plain(forecastLines(undefined)), []);
});

test('an act change reads as entering the named act in the event log', () => {
  const event = { sequence: 9, turn: 2, type: 'level_phase_change', actor: 'city', target: null, reason_code: 'level_phase_turn_gte', cue_id: '', before: { level_phase_id: 'a' }, delta: {}, after: { level_phase_id: 'b', label: '第二幕・斷補', objective: '', from_turn: 3 } };

  assert.equal(eventText(event), '進入下一幕｜第二幕・斷補');
});
