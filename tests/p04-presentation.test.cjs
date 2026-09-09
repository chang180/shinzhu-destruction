const test = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');
const ts = require('typescript');

// Exercise the shipped TypeScript helpers without a second implementation or browser dependency.
const source = ts.transpileModule(fs.readFileSync('resources/js/game.ts', 'utf8'), {
  compilerOptions: { module: ts.ModuleKind.CommonJS, target: ts.ScriptTarget.ES2022 },
}).outputText;
const context = { exports: {}, crypto: globalThis.crypto };
vm.runInNewContext(source, context);
const { cueImage, cueDuration, reportFindings, PendingAction, PendingStorageError, secondsLeft, serverOffset, dataNoteText, briefingTimingText, nextHandText, shouldAutoReveal, keptCardsForPlay, soundStatusText, playedName } = context.exports;
const event = (type, turn, delta = {}, after = {}) => ({ type, turn, delta, after, cue_id: '', reason_code: '', actor: 'player', target: 'water' });
// 牌組與卡面在真實回應裡一定存在；戰報要靠它們把 card_id 翻成玩家看到的卡名。
const deck = { 'c1': 'long-flow', 'c2': 'final-waste' };
const cards = {
  'long-flow': { card: 'long-flow', skill_id: 'probe.water', name: '千戶長流' },
  'final-waste': { card: 'final-waste', skill_id: 'ultimate', name: '揮霍無度・新竹歸寂' },
  'hold-spite': { card: 'hold-spite', skill_id: 'gather', name: '屏息蓄惡' },
};
const play = spec => (typeof spec === 'string' ? { type: 'play', card_id: spec, keep: [] } : spec);
const run = (outcome, events, actions = []) => ({
  outcome, cards, state: { deck },
  history: events.map((e, i) => ({ input: play(actions[i] ?? 'c1'), events: [e] })),
});

test('victory and city defense use distinct art and ending duration even after an ultimate', () => {
  const victory = { ...event('outcome', 9, {}, { outcome: 'player_victory' }), cue_id: 'cue.ultimate.victory' };
  const held = event('outcome', 10, {}, { outcome: 'city_held' });
  assert.equal(cueImage(victory), 'victory');
  assert.equal(cueDuration(victory), 8000);
  assert.equal(cueImage(held), 'city');
  assert.equal(cueDuration(held), 3000);
  assert.equal(cueImage({ ...victory, type: 'impact' }), 'apostle');
});

test('victory findings cite actual interrupted turns, repairs and strongest hit', () => {
  const report = reportFindings(run('player_victory', [event('interrupt', 3), event('city_repair', 6, { core_resilience: 20 }), event('impact', 8, { core_resilience: -11 }), event('impact', 9, { core_resilience: -32 })], ['c1', 'c1', 'c1', 'c2']));
  assert.equal(report.length, 3);
  assert.match(report[0].text, /第 3 回合.*共 1 次/);
  assert.match(report[1].text, /實際修回 20 點/);
  assert.equal(report[2].turn, 9);
  assert.match(report[2].text, /揮霍無度・新竹歸寂.*32 點/);
});

test('zero-damage loss does not fabricate lost repair points or attack highlights', () => {
  const gather = { type: 'play', card_id: null, fixed: 'gather', keep: [] };
  const report = reportFindings(run('city_held', [event('city_repair', 3, { core_resilience: 0 }), event('impact', 4, { core_resilience: 0 })], [gather, gather]));
  assert.equal(report.length, 1);
  assert.equal(report[0].turn, null);
  assert.match(report[0].text, /2 回合蓄勢/);
});

test('failed interrupts and a used breach are explained from their own recorded evidence', () => {
  const battle = run('city_held', [event('interrupt_failed', 2), event('impact', 7, { core_resilience: -30 }), event('breach_consumed', 7)]);
  const report = reportFindings(battle);
  assert.match(report[0].text, /第 2 回合.*未取消/);
  assert.match(report[1].text, /確實用到了破綻/);
});

function storage(initial = null) {
  let value = initial;
  return { getItem: () => value, setItem: (_key, next) => { value = next; }, removeItem: () => { value = null; } };
}
test('a restored uncertain action keeps the original id, version and skill until confirmed', () => {
  const store = storage();
  const pending = new PendingAction(store);
  const input = pending.prepare({ run_id: 'run-1', version: 4 }, { type: 'play', card_id: 'c1', fixed: null });
  const restored = new PendingAction(store);
  const retry = restored.prepare({ run_id: 'run-1', version: 5 }, { type: 'play', card_id: null, fixed: 'gather' });
  assert.equal(JSON.stringify(retry), JSON.stringify(input));
  assert.throws(() => restored.prepare({ run_id: 'run-2', version: 1 }, {}), /另一局/);
  restored.clear();
  assert.equal(new PendingAction(store).value, null);
});

test('malformed saved actions do not trap the player in pending recovery', () => {
  for (const bad of ['broken JSON', '{}', '{"runId":"../other"}', '{"runId":"run-1","input":{"expected_version":-1}}']) {
    assert.equal(new PendingAction(storage(bad)).value, null);
  }
});

test('unavailable storage refuses a new action before assigning any pending payload', () => {
  const pending = new PendingAction({ getItem: () => { throw Error('disabled'); }, setItem: () => { throw Error('quota'); }, removeItem() {} });
  assert.throws(() => pending.prepare({ run_id: 'run-1', version: 1 }, { skill_id: 'gather', target: null }), PendingStorageError);
  assert.equal(pending.value, null);
});

test('the countdown reads the server deadline through the clock offset, not the local clock', () => {
  // 本機時鐘快了一分鐘也不能讓玩家少拿決策時間：算式一律走伺服器時間。
  const offset = serverOffset('2026-09-09T12:00:00.000Z', Date.parse('2026-09-09T12:01:00.000Z'));
  assert.equal(offset, -60000);
  assert.equal(secondsLeft('2026-09-09T12:00:30.000Z', offset, Date.parse('2026-09-09T12:01:00.000Z')), 30);
  assert.equal(secondsLeft('2026-09-09T12:00:30.000Z', offset, Date.parse('2026-09-09T12:01:25.000Z')), 5);
  // 過了截止時間只會停在 0，不會變成負數倒著跑。
  assert.equal(secondsLeft('2026-09-09T12:00:30.000Z', offset, Date.parse('2026-09-09T12:02:00.000Z')), 0);
  assert.equal(secondsLeft(null, 0), null);
});

test('battle timing copy promises continuous hands without a start-turn gate', () => {
  assert.equal(briefingTimingText('practice'), '進入戰鬥就會自動發牌；練習模式不限時，出牌演出後直接接下一手。');
  assert.match(briefingTimingText('challenge'), /自動發牌.*30 秒.*直接接下一個 30 秒/);
  assert.doesNotMatch(briefingTimingText('challenge'), /開始回合/);
  assert.match(nextHandText('challenge'), /立即開始 30 秒/);
});

test('only compatible in-progress runs between hands are automatically revealed', () => {
  const runState = (outcome, compatible, turn_phase) => ({ outcome, compatible, state: { turn_phase } });
  assert.equal(shouldAutoReveal(runState('in_progress', true, 'awaiting_reveal')), true);
  assert.equal(shouldAutoReveal(runState('in_progress', true, 'decision')), false);
  assert.equal(shouldAutoReveal(runState('player_victory', true, 'awaiting_reveal')), false);
  assert.equal(shouldAutoReveal(runState('in_progress', false, 'awaiting_reveal')), false);
});

test('clicking a kept card plays it instead of trying to retain the same physical card', () => {
  assert.deepEqual(keptCardsForPlay(['card-1', 'card-2'], 'card-1'), ['card-2']);
});

test('the lobby sound label reflects the current preference instead of claiming a default', () => {
  assert.equal(soundStatusText(true), '目前靜音');
  assert.equal(soundStatusText(false), '目前有聲');
});

test('a card face only claims a data modifier when this level actually applies it', () => {
  assert.equal(dataNoteText({ applied: true, modifier: 0.062, reason: null }, 'water'), '水情修正 +6.2%（本局）');
  assert.equal(dataNoteText({ applied: true, modifier: -0.03, reason: null }, 'heat'), '熱情修正 -3.0%（本局）');
  // 沒被本關採用就明說，不做裝飾性的假加成。
  assert.equal(dataNoteText({ applied: false, modifier: null, reason: null }, 'land'), '本關未採用這一系的資料。');
  assert.equal(dataNoteText(undefined, 'water'), '本關未採用這一系的資料。');
});

test('the report names the physical card that was played, and the fixed actions by name', () => {
  const battle = run('player_victory', [event('impact', 1)], ['c1']);
  assert.equal(playedName(battle, { type: 'play', card_id: 'c1' }), '千戶長流');
  assert.equal(playedName(battle, { type: 'play', card_id: null, fixed: 'gather' }), '屏息蓄惡');
  assert.equal(playedName(battle, { type: 'timeout' }), '逾時錯失行動');
  assert.equal(playedName(battle, { type: 'swap', card_id: 'c1' }), '換牌');
});

test('the report states what keeping, swapping and timing out actually cost', () => {
  const battle = run('city_held', [event('action_missed', 3), event('impact', 4, { core_resilience: -8 }), event('impact', 5)], [
    { type: 'timeout', keep: [] },
    { type: 'play', card_id: 'c1', keep: ['c2'] },
    { type: 'swap', card_id: 'c1' },
  ]);
  const report = reportFindings(battle);
  assert.match(report.find(f => /逾時/.test(f.text)).text, /第 3 回合逾時，共 1 次/);
  assert.match(report.find(f => /^你留了/.test(f.text)).text, /你留了 1 張牌.*分佈在 1 個回合/);
  assert.match(report.find(f => /換牌/.test(f.text)).text, /用掉 1 次免費換牌/);
});

test('a wasted breach window is reported from its own recorded reason code', () => {
  const wasted = { ...event('breach_consumed', 4), reason_code: 'missed_action_wasted_breach' };
  const report = reportFindings(run('city_held', [event('action_missed', 4), wasted], [{ type: 'timeout', keep: [] }, { type: 'timeout', keep: [] }]));
  assert.match(report.find(f => /逾時/.test(f.text)).text, /破綻窗口過期/);
});

function audioHarness() {
  const voices = [], clips = [];
  class Context {
    currentTime = 0;
    async resume() {}
    createOscillator() {
      const voice = { frequency: { setValueAtTime(value) { voice.note = value; } }, connect(gain) { return gain; }, start() {}, stop() { voice.stopped = true; }, disconnect() {} };
      voices.push(voice); return voice;
    }
    createGain() { return { gain: { setValueAtTime() {}, linearRampToValueAtTime() {}, exponentialRampToValueAtTime() {} }, connect() {}, disconnect() {} }; }
  }
  class Clip {
    constructor() { clips.push(this); }
    play() { return Promise.reject(Error('OGG unsupported')); }
    pause() { this.paused = true; }
  }
  const scope = { exports: {}, AudioContext: Context, Audio: Clip, document: { hidden: false } };
  vm.runInNewContext(ts.transpileModule(fs.readFileSync('resources/js/audio.ts', 'utf8'), { compilerOptions: { module: ts.ModuleKind.CommonJS, target: ts.ScriptTarget.ES2022 } }).outputText, scope);
  return { audio: new scope.exports.BattleAudio(), scope, voices, clips };
}
test('muted and hidden playback creates no audio; zero volume creates no oscillator', async () => {
  const h = audioHarness(); await h.audio.unlock();
  h.audio.play(event('impact', 1)); assert.equal(h.voices.length, 0);
  h.audio.muted = false; h.scope.document.hidden = true;
  h.audio.play(event('impact', 1)); assert.equal(h.clips.length, 0);
  h.scope.document.hidden = false; h.audio.effectsVolume = 0;
  h.audio.play(event('impact', 1)); assert.equal(h.voices.length, 0); assert.equal(h.clips[0].volume, 0);
});
test('unsupported OGG still has synthesized ending and skip stops all voices and clips', async () => {
  const h = audioHarness(); await h.audio.unlock(); h.audio.muted = false;
  h.audio.play(event('outcome', 9, {}, { outcome: 'player_victory' }));
  assert.deepEqual(h.voices.map(voice => voice.note), [262, 330, 392, 523]);
  h.audio.stop();
  assert.ok(h.clips.every(clip => clip.paused));
  assert.ok(h.voices.every(voice => voice.stopped));
  await Promise.resolve();
});
