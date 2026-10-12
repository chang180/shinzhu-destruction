const test = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');
const ts = require('typescript');
const { execFileSync } = require('node:child_process');

// Exercise the shipped TypeScript helpers without a second implementation or browser dependency.
const source = ts.transpileModule(fs.readFileSync('resources/js/game.ts', 'utf8'), {
  compilerOptions: { module: ts.ModuleKind.CommonJS, target: ts.ScriptTarget.ES2022 },
}).outputText;
const context = { exports: {}, crypto: globalThis.crypto };
vm.runInNewContext(source, context);
const { cueImage, cueDuration, reportAsset, sceneAsset, apostleAsset, briefingAsset, counterfactualText, reportFindings, reportSummary, cardGuide, PendingAction, PendingStorageError, secondsLeft, serverOffset, dataNoteText, briefingTimingText, nextHandText, shouldAutoReveal, soundStatusText, playedName } = context.exports;
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
  assert.equal(cueImage(held), 'defense-success-1');
  assert.equal(cueDuration(held), 3000);
  assert.equal(cueImage({ ...victory, type: 'impact' }), 'skill-ultimate');
});

test('P06 selects the generated scene, apostle, cut-in and ending art by stable level and event IDs', () => {
  const victory = { ...event('outcome', 12, {}, { outcome: 'player_victory' }), cue_id: 'cue.ultimate.victory' };
  const cityEvent = { ...event('city_repair', 3), actor: 'city', target: 'land' };
  assert.equal(sceneAsset('mirror-shade'), 'scene-mirror-shade');
  assert.equal(apostleAsset('stored-night'), 'apostle-stored-night');
  assert.equal(briefingAsset('stored-night'), 'briefing-stored-night');
  assert.equal(reportAsset('city_held', 'stored-night'), 'defense-success-2');
  assert.equal(reportAsset('player_victory', 'meter-feast'), 'victory-meter-feast');
  assert.equal(cueImage(cityEvent, 'mirror-shade'), 'scene-mirror-shade');
  assert.equal(cueImage({ ...victory, type: 'impact' }, 'meter-feast'), 'ultimate-meter-feast');
  assert.equal(cueImage(event('impact', 4), 'meter-feast'), 'skill-water-2');
  assert.equal(cueImage(victory, 'stored-night'), 'victory-stored-night');
});

test('P07 counterfactual copy names the actual strategy and states whether the outcome actually diverged', () => {
  const same = { sequence: 3, strategy: 'planner', actual: { outcome: 'player_victory', turns: 7, core_remaining: 0 }, counterfactual: { outcome: 'player_victory', turns: 6, core_remaining: 0, diverged: false } };
  const changed = { sequence: 3, strategy: 'planner', actual: { outcome: 'player_victory', turns: 7, core_remaining: 0 }, counterfactual: { outcome: 'city_held', turns: 8, core_remaining: 40, diverged: true } };
  assert.match(counterfactualText(same), /仍然是毀滅成功/);
  assert.match(counterfactualText(same), /planner/);
  assert.match(counterfactualText(changed), /會走向城市守住/);
  assert.match(counterfactualText(changed), /和實際的毀滅成功不一樣/);
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
  assert.match(briefingTimingText('practice'), /不限時.*直接補牌/);
  assert.match(briefingTimingText('challenge'), /自動發牌.*30 秒.*補牌.*下一個 30 秒/);
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

test('playing a retained physical card sends its exact identity without manual keep', () => {
  const pending = new PendingAction({ getItem: () => null, setItem() {}, removeItem() {} });
  const input = pending.prepare({ run_id: 'run-1', version: 4 }, { type: 'play', card_id: 'card-1', fixed: null });
  assert.equal(input.card_id, 'card-1');
  assert.equal(Object.hasOwn(input, 'keep'), false);
});

test('swapping a retained card keeps the current server version and sends no keep choice', () => {
  const pending = new PendingAction({ getItem: () => null, setItem() {}, removeItem() {} });
  const input = pending.prepare({ run_id: 'run-1', version: 9 }, { type: 'swap', card_id: 'card-2', fixed: null });
  assert.equal(input.type, 'swap');
  assert.equal(input.expected_version, 9);
  assert.equal(Object.hasOwn(input, 'keep'), false);
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
  assert.equal(report.some(f => /^你留了/.test(f.text)), false);
  assert.match(report.find(f => /換牌/.test(f.text)).text, /用掉 1 次免費換牌/);
});

test('a wasted breach window is reported from its own recorded reason code', () => {
  const wasted = { ...event('breach_consumed', 4), reason_code: 'missed_action_wasted_breach' };
  const report = reportFindings(run('city_held', [event('action_missed', 4), wasted], [{ type: 'timeout', keep: [] }, { type: 'timeout', keep: [] }]));
  assert.match(report.find(f => /逾時/.test(f.text)).text, /破綻窗口過期/);
});

test('card guidance explains automatic targets and the timing of all five actions', () => {
  const guide = kind => cardGuide({ kind, element: ['gather', 'ultimate'].includes(kind) ? null : 'land' });
  assert.equal(guide('probe').target, '土地系防線 → 城市核心');
  assert.match(guide('probe').timing, /低成本.*印記/);
  assert.match(guide('breach').example, /土地系防線.*首次歸零/);
  assert.match(guide('disrupt').timing, /可打斷.*土地系/);
  assert.match(guide('disrupt').example, /不同系.*仍會執行/);
  assert.match(guide('gather').target, /不攻擊城市/);
  assert.match(guide('gather').example, /城市仍會照預告/);
  assert.match(guide('ultimate').target, /三系合擊/);
  assert.match(guide('ultimate').example, /任何護盾/);
});

test('the result summary identifies the recorded final blow rather than the strongest hit', () => {
  const first = { ...event('impact', 2, { core_resilience: -70 }, { core_resilience: 30 }), before: { core_resilience: 100 } };
  const last = { ...event('impact', 4, { core_resilience: -30 }, { core_resilience: 0 }), before: { core_resilience: 30 } };
  const finish = { ...event('outcome', 4, {}, { outcome: 'player_victory' }), before: {} };
  const battle = run('player_victory', [first, last, finish], ['c2', 'c1', 'c1']);
  battle.state = { ...battle.state, turn: 5, max_turns: 8, core_resilience: 0 };

  const summary = reportSummary(battle);

  assert.equal(summary.turn, 4);
  assert.equal(summary.initialCore, 100);
  assert.match(summary.condition, /核心已歸零/);
  assert.match(summary.finalMove, /第 4 回合.*千戶長流.*30 打到 0/);
  assert.match(summary.findings[0].text, /揮霍無度.*70.*最大一擊/);
});

test('a loss summary cites exhausted turns and limits its causes to three recorded findings', () => {
  const finish = { ...event('outcome', 8, {}, { outcome: 'city_held' }), reason_code: 'turns_exhausted', before: {} };
  const battle = run('city_held', [
    { ...event('impact', 1, { core_resilience: -10 }, { core_resilience: 90 }), before: { core_resilience: 100 } },
    { ...event('interrupt', 3), before: {} },
    { ...event('city_repair', 6, { core_resilience: 12 }), before: { core_resilience: 50 } },
    { ...event('action_missed', 7), before: {} }, finish,
  ]);
  battle.state = { ...battle.state, turn: 9, max_turns: 8, core_resilience: 62 };

  const summary = reportSummary(battle);

  assert.equal(summary.turn, 8);
  assert.match(summary.condition, /8 回合已用完/);
  assert.match(summary.finalMove, /還差 62 點/);
  assert.equal(summary.findings.length, 3);
  assert.match(summary.findings[1].text, /取消 1 次/);
  assert.match(summary.findings[2].text, /補回 12 點/);
  assert.equal(summary.findings[2].turn, null);
  assert.match(summary.findings[2].text, /第 6 回合/);
});

test('incomplete old reports do not invent an initial core or a winning move', () => {
  const battle = run('player_victory', []);
  battle.state = { ...battle.state, turn: 6, core_resilience: 0 };

  const summary = reportSummary(battle);

  assert.equal(summary.initialCore, null);
  assert.match(summary.finalMove, /沒有保留最後一擊/);
  assert.match(summary.findings[0].text, /沒有足夠/);
});

test('all five levels produce truthful victory and defeat summaries from the real PHP engine', () => {
  const samples = JSON.parse(execFileSync('php', ['tests/Fixtures/p08-reports.php'], { maxBuffer: 2 * 1024 * 1024 }).toString());

  assert.equal(samples.length, 10);
  for (const sample of samples) {
    const summary = reportSummary(sample);
    const events = sample.history.flatMap(entry => entry.events);
    const outcome = events.findLast(event => event.type === 'outcome');
    assert.equal(summary.initialCore, 100, sample.level_id);
    assert.equal(summary.turn, outcome.turn, sample.level_id);
    assert.ok(summary.findings.length <= 3);
    if (sample.outcome === 'player_victory') {
      assert.equal(sample.outcome, 'player_victory', sample.level_id);
      const hit = events.findLast(event => event.type === 'impact');
      assert.match(summary.finalMove, new RegExp(`第 ${hit.turn} 回合.*${hit.before.core_resilience} 打到 0`));
      assert.equal(hit.after.core_resilience, 0);
    } else {
      assert.equal(sample.outcome, 'city_held', sample.level_id);
      assert.match(summary.condition, new RegExp(`${sample.state.max_turns} 回合已用完`));
      assert.match(summary.finalMove, new RegExp(`還差 ${sample.state.core_resilience} 點`));
      if (sample.strategy === 'gather') {
        assert.match(summary.findings[0].text, new RegExp(`用了 ${sample.state.max_turns} 回合蓄勢`));
        assert.doesNotMatch(summary.findings.map(finding => finding.text).join(''), /最大一擊|成功取消/);
      }
    }
  }
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
    canPlayType() { return 'probably'; }
    play() { this.paused = false; return scope.failOGG && this.src.endsWith('.ogg') ? Promise.reject(Error('OGG unsupported')) : Promise.resolve(); }
    pause() { this.paused = true; }
  }
  const scope = { exports: {}, AudioContext: Context, Audio: Clip, document: { hidden: false }, setTimeout, clearTimeout, failOGG: false };
  vm.runInNewContext(ts.transpileModule(fs.readFileSync('resources/js/audio.ts', 'utf8'), { compilerOptions: { module: ts.ModuleKind.CommonJS, target: ts.ScriptTarget.ES2022 } }).outputText, scope);
  return { audio: new scope.exports.BattleAudio(), scope, voices, clips };
}
test('muted and hidden playback creates no audio; zero volume creates no oscillator', async () => {
  const h = audioHarness(); await h.audio.unlock();
  h.audio.play(event('impact', 1)); assert.equal(h.voices.length, 0);
  h.audio.muted = false; h.scope.document.hidden = true;
  h.audio.play(event('impact', 1)); assert.equal(h.clips.length, 0);
  h.scope.document.hidden = false; h.audio.effectsVolume = 0;
  h.audio.play(event('impact', 1)); assert.equal(h.voices.length, 0); assert.equal(h.clips.length, 0);
});
test('unsupported OGG falls back to MP3 and skip stops every clip', async () => {
  const h = audioHarness(); await h.audio.unlock(); h.audio.muted = false;
  h.scope.failOGG = true;
  h.audio.play(event('outcome', 9, {}, { outcome: 'player_victory' }));
  await Promise.resolve();
  assert.match(h.clips[0].src, /ending-victory\.mp3$/);
  h.audio.stop();
  assert.ok(h.clips.every(clip => clip.paused));
  assert.ok(h.voices.every(voice => voice.stopped));
  await Promise.resolve();
});

test('an OGG error plus its rejected play promise does not cancel the MP3 fallback', async () => {
  const h = audioHarness(); await h.audio.unlock(); h.audio.muted = false;
  h.scope.failOGG = true; h.audio.ui('confirm');
  const clip = h.clips[0]; clip.onerror();
  await Promise.resolve(); await Promise.resolve();
  assert.match(clip.src, /\.mp3$/); assert.equal(clip.paused, false);
  clip.onerror(); assert.equal(clip.paused, true);
});

test('one action casts once while impact and repair retain distinct sounds and natural tails', async () => {
  const h = audioHarness(); await h.audio.unlock(); h.audio.muted = false;
  const cast = { ...event('action_accepted', 2), cue_id: 'cue.action.probe' };
  h.audio.play(cast); h.audio.play(cast); h.audio.play(event('impact', 2)); h.audio.play(event('city_repair', 2));
  assert.equal(h.clips.length, 3);
  assert.match(h.clips[0].src, /cast-water/);
  assert.match(h.clips[1].src, /impact/);
  assert.match(h.clips[2].src, /repair/);
  assert.ok(h.clips.every(clip => !clip.paused));
  h.audio.beginPresentation(); h.audio.play(cast);
  assert.equal(h.clips.length, 4);
  h.audio.stop();
});

test('ambience survives skip, ducks under an ultimate and stops on leaving battle', async () => {
  const h = audioHarness(); await h.audio.unlock(); h.audio.muted = false;
  h.audio.setAmbience('noon-fold');
  const loop = h.clips[0]; const normal = loop.volume;
  h.audio.play({ ...event('action_accepted', 2), cue_id: 'cue.action.ultimate' });
  assert.ok(loop.volume < normal);
  h.audio.stopEffects();
  assert.equal(loop.paused, false);
  assert.equal(loop.volume, normal);
  h.audio.setAmbience(null);
  assert.equal(loop.paused, true);
});

test('muting stops every track and all runtime volume values stay bounded', async () => {
  const h = audioHarness(); await h.audio.unlock(); h.audio.muted = false;
  h.audio.effectsVolume = 3; h.audio.musicVolume = NaN;
  h.audio.play(event('impact', 1)); h.audio.setAmbience('stored-night');
  assert.equal(h.clips.length, 1); assert.equal(h.clips[0].volume, 1);
  h.audio.muted = true; h.audio.updateVolumes();
  assert.equal(h.clips[0].paused, true);
});

test('voice limit prevents unbounded overlap and a cancelled fallback never resurrects audio', async () => {
  const h = audioHarness(); await h.audio.unlock(); h.audio.muted = false;
  for (let i = 0; i < 12; i++) h.audio.play(event('impact', i));
  assert.equal(h.clips.filter(clip => !clip.paused).length, 8);
  h.audio.stop(); h.scope.failOGG = true;
  h.audio.play(event('impact', 99)); const clip = h.clips.at(-1);
  h.audio.stop(); await Promise.resolve();
  assert.match(clip.src, /\.ogg$/); assert.equal(clip.paused, true);
});

test('both finale cues are independent of ordinary victory and defeat', async () => {
  const h = audioHarness(); await h.audio.unlock(); h.audio.muted = false;
  const victory = event('outcome', 9, {}, { outcome: 'player_victory' });
  h.audio.play(victory, 'meter-feast', 'main');
  h.audio.play(victory, 'stored-night', 'advanced');
  h.audio.play(event('outcome', 9, {}, { outcome: 'city_held' }), 'stored-night', 'advanced');
  assert.match(h.clips[0].src, /ending-main/); assert.match(h.clips[1].src, /ending-advanced/);
  assert.match(h.clips[2].src, /ending-defeat/); h.audio.stop();
});

test('all 21 visual assets and 26 sound units have readable hashed delivery files', () => {
  const crypto = require('node:crypto');
  const images = JSON.parse(fs.readFileSync('assets/visual-v2-generation.json', 'utf8')).assets;
  const sounds = JSON.parse(fs.readFileSync('assets/visual-v2-audio.json', 'utf8')).assets;
  assert.equal(images.length, 21); assert.equal(sounds.length, 26);
  for (const item of [...images, ...sounds]) {
    for (const output of item.outputs) {
      const bytes = fs.readFileSync(output.file);
      assert.equal(bytes.length, output.bytes);
      assert.equal(crypto.createHash('sha256').update(bytes).digest('hex'), output.sha256);
    }
  }
});

test('all four level presentations resolve distinct assets without changing card rules', () => {
  const { presentationFor, assetUrl, assetSrcSet, phaseLine } = context.exports;
  for (const id of ['noon-fold', 'meter-feast', 'mirror-shade', 'stored-night']) {
    assert.match(assetUrl(briefingAsset(id)), new RegExp(`/visual-v2/briefing-${id}`));
    assert.match(assetUrl(reportAsset('player_victory', id)), new RegExp(`victory-${id}`));
    assert.match(assetSrcSet(sceneAsset(id)), /768w.*1280w.*1600w/);
    assert.ok(presentationFor(id).quote);
  }
  assert.match(phaseLine('noon-fold', 'shift-guard'), /換班/);
  assert.equal(phaseLine('stored-night', 'not-entered'), '');
  assert.equal(assetSrcSet('city'), undefined);
});

test('the first briefing uses a native landscape image with the same delivery sizes as later levels', () => {
  const { assetUrl, assetSrcSet } = context.exports;
  assert.equal(briefingAsset('empty-cup'), 'briefing-empty-cup');
  assert.equal(assetUrl(briefingAsset('empty-cup')), '/assets/visual-v2/briefing-empty-cup.webp');
  assert.match(assetSrcSet(briefingAsset('empty-cup')), /768w.*1280w.*1600w/);
  const images = JSON.parse(fs.readFileSync('assets/visual-v2-generation.json', 'utf8')).assets;
  for (const id of ['empty-cup', 'noon-fold', 'meter-feast', 'mirror-shade', 'stored-night']) {
    const frame = images.find(image => image.id === `briefing-${id}`);
    assert.ok(Math.abs(frame.raw_dimensions[0] / frame.raw_dimensions[1] - 16 / 9) < 0.01);
  }
});

test('final professor reviews use recorded overhaul outcomes, including neutral old saves', () => {
  const { professorReview } = context.exports;
  const review = (outcome, reasons = []) => professorReview({ level_id: 'stored-night', outcome, history: [{ events: reasons.map(reason_code => ({ reason_code })) }] });
  assert.match(review('player_victory', ['overhaul_started', 'overhaul_stopped']), /拆掉/);
  assert.match(review('player_victory', ['overhaul_started', 'overhaul_completed']), /重整過/);
  assert.match(review('player_victory', ['overhaul_started']), /交卷/);
  assert.doesNotMatch(review('player_victory'), /拆掉|交卷|重整過/);
  assert.doesNotMatch(review('city_held', ['overhaul_stopped']), /不必補考/);
});
