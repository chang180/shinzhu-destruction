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
const { cueImage, cueDuration, reportFindings, PendingAction, PendingStorageError } = context.exports;
const event = (type, turn, delta = {}, after = {}) => ({ type, turn, delta, after, cue_id: '', actor: 'player', target: 'water' });
const run = (outcome, events, actions = []) => ({ outcome, history: events.map((e, i) => ({ input: { skill_id: actions[i] ?? 'probe.water' }, events: [e] })) });

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
  const report = reportFindings(run('player_victory', [event('interrupt', 3), event('city_repair', 6, { core_resilience: 20 }), event('impact', 8, { core_resilience: -11 }), event('impact', 9, { core_resilience: -32 })], ['disrupt.water', 'probe.heat', 'probe.land', 'ultimate']));
  assert.equal(report.length, 3);
  assert.match(report[0].text, /第 3 回合.*共 1 次/);
  assert.match(report[1].text, /實際修回 20 點/);
  assert.equal(report[2].turn, 9);
  assert.match(report[2].text, /萬川歸寂.*32 點/);
});

test('zero-damage loss does not fabricate lost repair points or attack highlights', () => {
  const report = reportFindings(run('city_held', [event('city_repair', 3, { core_resilience: 0 }), event('impact', 4, { core_resilience: 0 })], ['gather', 'gather']));
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
  const input = pending.prepare({ run_id: 'run-1', version: 4 }, { skill_id: 'probe.water', target: 'water' });
  const restored = new PendingAction(store);
  const retry = restored.prepare({ run_id: 'run-1', version: 5 }, { skill_id: 'gather', target: null });
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
