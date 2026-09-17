<script setup lang="ts">
import { computed, nextTick, onMounted, onUnmounted, ref, watch } from 'vue';
import { api, ApiError, PendingAction, PendingStorageError, reportFindings, dataNoteText, briefingTimingText, briefingLines, nextHandText, shouldAutoReveal, keptCardsForPlay, soundStatusText, secondsLeft, serverOffset, playedName, elements, elementNames, unavailableText, eventText, cueImage, cueDuration, visibleEvents, canPlay, levelStatusText, nextPlayableLevel, endingFor, deckSummary, apostleAsset, briefingAsset, reportAsset, sceneAsset, fetchCounterfactual, counterfactualText } from './game';
import type { BattleEvent, Campaign, Card, Choice, CounterfactualComparison, Level, Run, RunMode, SavedRun, Settlement } from './game';
import { BattleAudio } from './audio';

const page = ref<'lobby' | 'briefing' | 'battle' | 'report' | 'reward'>('lobby');
const levels = ref<Level[]>([]);
const campaign = ref<Campaign>();
const cards = ref<Record<string, Card>>({});
const decisionSeconds = ref(30);
const savedRuns = ref<SavedRun[]>([]);
const run = ref<Run>();
const busy = ref(false);
const playing = ref(false);
const error = ref('');
const notice = ref('');
const mode = ref<RunMode>('challenge');
const previewedCard = ref<Card>();
const keep = ref<string[]>([]);
const currentEvent = ref<BattleEvent>();
const pending = new PendingAction({
    getItem: key => sessionStorage.getItem(key),
    setItem: (key, value) => sessionStorage.setItem(key, value),
    removeItem: key => sessionStorage.removeItem(key),
});
const uncertain = ref(!!pending.value);
const retryAt = ref(0);
const audio = new BattleAudio();
const muted = ref(true);
const reduced = ref(matchMedia('(prefers-reduced-motion: reduce)').matches);
const speed = ref(1);
const effectsVolume = ref(0.45);
const musicVolume = ref(0.2);
let stopWait: (() => void) | undefined;
let presentation = 0;
// P07 反事實比較：依行動序號記結果，換一局就清空，不讓上一局的比較留在畫面上。
const counterfactuals = ref<Record<number, CounterfactualComparison | 'loading' | 'error'>>({});
async function tryCounterfactual(sequence: number, alternative: { type: 'play' | 'timeout'; fixed?: string }): Promise<void> {
    if (!run.value) return;
    counterfactuals.value[sequence] = 'loading';
    try {
        counterfactuals.value[sequence] = await fetchCounterfactual(run.value.run_id, sequence, alternative);
    } catch { counterfactuals.value[sequence] = 'error'; }
}
/*
 * 倒數只是顯示。判定逾時的是伺服器截止時間，所以這裡保存的是「伺服器時間減本機
 * 時間」，畫面用它換算剩餘秒數；本機時鐘不準也不會讓玩家多拿或少拿決策時間。
 */
const clockOffset = ref(0);
const tick = ref(Date.now());
let ticker: number | undefined;
let timingOut = false;

const state = computed(() => run.value?.state);
const level = computed(() => levels.value.find(l => l.level_id === run.value?.level_id) ?? levels.value[0]);
const mainLevels = computed(() => levels.value.filter(l => l.tier === 'main'));
const advancedLevels = computed(() => levels.value.filter(l => l.tier === 'advanced'));
const nextLevel = computed(() => nextPlayableLevel(levels.value, mode.value));
const ending = computed(() => run.value ? endingFor(run.value, campaign.value, campaign.value?.main_finale ?? '', campaign.value?.advanced_finale ?? '') : null);
const reward = computed(() => campaign.value?.pending_reward ?? null);
const currentDeck = computed(() => deckSummary(campaign.value?.deck ?? {}, cards.value));
const advancedFinaleFrames = ['advanced-finale-1', 'advanced-finale-2', 'advanced-finale-3'];
const advancedEntry = computed(() => levels.value.find(l => l.tier === 'advanced' && canPlay(l, mode.value)));
const locked = computed(() => busy.value || playing.value || uncertain.value);
const revealed = computed(() => state.value?.turn_phase === 'decision');
const remaining = computed(() => secondsLeft(state.value?.deadline_at ?? null, clockOffset.value, tick.value));
const urgent = computed(() => remaining.value !== null && remaining.value <= 10);
const allEvents = computed(() => run.value?.history.flatMap(h => h.events) ?? []);
const highlights = computed(() => run.value ? reportFindings(run.value) : []);
const hand = computed(() => (state.value?.hand ?? []).map(id => ({ id, card: cardFor(id) })).filter(entry => !!entry.card));
const fixedCards = computed(() => Object.values(run.value?.cards ?? {}).filter(card => ['gather', 'ultimate'].includes(card.skill_id)));
const canKeepMore = computed(() => keep.value.length < (state.value?.max_keep ?? 2));
const sourceNames: Record<string, string> = { 'wra.reservoir_conditions': '水利署・水庫水情', 'nstc.science_park_water': '國科會・園區用水', 'moi.land_use': '內政部・國土利用' };
const qualityNames: Record<string, string> = { demo: '示範情境', fresh: '開局時有效資料', stale: '開局時已過期資料', unavailable: '資料不可得' };
const hint = computed(() => {
    if (!state.value) return '';
    if (!revealed.value) return nextHandText(mode.value);
    if (state.value.breach_available) return '防線已裂開！下一個行動會用掉破綻，連逾時也算。把窗口留給值得的一擊。';
    if (state.value.intent?.interruptible) return '城市正準備修復。看看手上有沒有同系的擾序牌；打斷成功會直接劃掉那條預告。';
    if (state.value.turn === 1) return '先勾選最多 2 張留牌，再點要打出的牌；點牌會立即施放。每回合另有一次免費換牌。';
    return '點牌會立即施放。換系能緩解原系抗性，連續三種不同系進攻會連攜；手上沒有想要的牌時，換牌與蓄勢都是退路。';
});

function cardFor(instanceId: string): Card | undefined {
    const type = state.value?.deck[instanceId];
    return type ? run.value?.cards[type] : undefined;
}
function choiceForCard(instanceId: string): Choice | undefined {
    return run.value?.available_actions.find(a => a.type === 'play' && a.card_id === instanceId);
}
function choiceForFixed(skillId: string | null): Choice | undefined {
    return skillId ? run.value?.available_actions.find(a => a.type === 'play' && a.fixed === skillId) : undefined;
}
function swapChoice(instanceId: string): Choice | undefined {
    return run.value?.available_actions.find(a => a.type === 'swap' && a.card_id === instanceId);
}
function reasonFor(instanceId: string): string {
    const card = cardFor(instanceId);
    return state.value && card ? unavailableText(choiceForCard(instanceId), state.value, card) : '';
}
function fixedReason(card: Card): string {
    return state.value ? unavailableText(choiceForFixed(card.skill_id), state.value, card) : '';
}
function noteFor(card: Card | undefined): string {
    return card ? dataNoteText(run.value?.data_notes[card.element as never], card.element) : '';
}
function toggleKeep(instanceId: string): void {
    if (locked.value || !revealed.value) return;
    keep.value = keep.value.includes(instanceId)
        ? keep.value.filter(id => id !== instanceId)
        : canKeepMore.value ? [...keep.value, instanceId] : keep.value;
}
const p06Assets = new Set(['yan-chen-stern', 'yan-chen-pleased', 'student-silhouette', 'apostle-empty-cup', 'apostle-noon-fold', 'apostle-meter-feast', 'apostle-mirror-shade', 'apostle-stored-night', 'scene-noon-fold', 'scene-meter-feast', 'scene-mirror-shade', 'scene-stored-night', 'skill-water-2', 'skill-heat-2', 'skill-land-2', 'skill-ultimate', 'defense-success-1', 'defense-success-2', 'victory-2', 'victory-3', 'advanced-finale-1', 'advanced-finale-2', 'advanced-finale-3']);
function asset(name: string): string { return `/assets/${p06Assets.has(name) ? 'p06' : 'p04'}/${name}.webp`; }
function focusHeading(): void { void nextTick(() => document.querySelector<HTMLElement>('main h1, main h2')?.focus()); }
function showRun(value: Run, briefing = false): void {
    run.value = value;
    mode.value = value.mode;
    clockOffset.value = serverOffset(value.server_time);
    previewedCard.value = undefined; keep.value = []; counterfactuals.value = {};
    page.value = value.outcome !== 'in_progress' ? 'report' : briefing ? 'briefing' : 'battle';
    if (!value.compatible) notice.value = '這一局使用舊版規則。可以查看紀錄，請回學院另開新局。';
    focusHeading();
}
async function loadLobby(): Promise<void> {
    if (busy.value || playing.value) return;
    busy.value = true; error.value = ''; notice.value = '';
    try {
        const [catalog, saves] = await Promise.all([
            api<{ levels: Level[]; decision_seconds: number; campaign: Campaign; cards: Record<string, Card> }>('/levels'),
            api<{ data: SavedRun[] }>('/runs'),
        ]);
        levels.value = catalog.levels; decisionSeconds.value = catalog.decision_seconds;
        campaign.value = catalog.campaign; cards.value = catalog.cards;
        savedRuns.value = saves.data; page.value = 'lobby';
        if (pending.value) {
            try {
                const result = await api<{ data: Run }>(`/runs/${pending.value.runId}`);
                showRun(result.data);
                notice.value = '有一筆行動尚待確認。請重試原行動，以取回結果。';
            } catch (e) {
                if (!(e instanceof ApiError) || e.status !== 404) throw e;
                pending.clear(); uncertain.value = false;
                notice.value = '原對局已無法存取，可能是工作階段已過期。已解除待確認狀態，可另開新局。';
            }
        }
    } catch (e) { handleError(e); } finally { busy.value = false; }
}
function handleError(e: unknown): void {
    if (e instanceof PendingStorageError) error.value = e.message;
    else if (e instanceof ApiError) {
        if (e.status === 419) error.value = '工作階段驗證已更新，請重新整理；已送出的行動會保留原編號供確認。';
        else if (e.status === 429) { retryAt.value = Date.now() + e.retryAfter * 1000; error.value = `請稍候 ${e.retryAfter} 秒再重試原行動。`; }
        else error.value = e.message;
    } else error.value = '連線中斷或回應逾時。行動請重試原編號；開局請回學院查看最近對局，避免重複開局。';
}
async function openRun(id: string): Promise<void> {
    if (locked.value) return;
    let opened: Run | undefined;
    busy.value = true; error.value = ''; notice.value = '';
    try { opened = (await api<{ data: Run }>(`/runs/${id}`)).data; showRun(opened); }
    catch (e) { handleError(e); } finally { busy.value = false; }
    if (opened && shouldAutoReveal(opened)) await reveal();
}
async function start(retry = false, levelId?: string): Promise<void> {
    if (locked.value) return;
    const target = levelId ?? nextLevel.value?.level_id;
    if (!retry && !target) return;
    busy.value = true; error.value = ''; notice.value = '';
    await audio.unlock();
    try {
        const result = await api<{ data: Run }>(
            retry ? `/runs/${run.value!.run_id}/retry` : '/runs',
            retry ? {} : { level_id: target, mode: mode.value },
        );
        showRun(result.data, true);
        for (const name of [sceneAsset(result.data.level_id), apostleAsset(result.data.level_id), briefingAsset(result.data.level_id), reportAsset('player_victory', result.data.level_id), reportAsset('city_held', result.data.level_id), 'skill-water-2', 'skill-heat-2', 'skill-land-2', 'skill-ultimate']) { const image = new Image(); image.src = asset(name); }
    } catch (e) { handleError(e); } finally { busy.value = false; }
}
/** 挑一張獎勵牌。它只影響**下一局**：已經開始的那一局在開局時就凍結了牌組。 */
async function chooseReward(levelId: string, option: string): Promise<void> {
    if (locked.value) return;
    busy.value = true; error.value = '';
    try {
        campaign.value = (await api<{ data: Campaign }>('/campaign/reward', { level_id: levelId, option })).data;
        notice.value = '新牌已換進牌組，下一局生效。';
        page.value = 'lobby'; focusHeading();
    } catch (e) { handleError(e); } finally { busy.value = false; }
}
/** 收下戰果，結束本次毀滅計畫。進階畢業考仍然留著，日後可以回來挑戰。 */
async function standDown(): Promise<void> {
    if (locked.value) return;
    busy.value = true; error.value = '';
    try {
        campaign.value = (await api<{ data: Campaign }>('/campaign/stand-down', {})).data;
        notice.value = '毀滅計畫已結案。進階畢業考隨時可以回來挑戰。';
        page.value = 'lobby'; focusHeading();
    } catch (e) { handleError(e); } finally { busy.value = false; }
}
async function openReward(): Promise<void> {
    if (locked.value || !reward.value) return;
    page.value = 'reward'; focusHeading();
}
async function enterBattle(): Promise<void> {
    await audio.unlock(); page.value = 'battle'; focusHeading();
    if (run.value && shouldAutoReveal(run.value)) await reveal();
}
function skip(): void { presentation++; stopWait?.(); stopWait = undefined; currentEvent.value = undefined; audio.stop(); }
async function present(events: BattleEvent[]): Promise<void> {
    const previousFocus = document.activeElement instanceof HTMLElement ? document.activeElement : null;
    playing.value = true;
    const token = ++presentation;
    try {
        for (const event of visibleEvents(events)) {
            if (token !== presentation) break;
            currentEvent.value = event;
            await nextTick();
            document.querySelector<HTMLButtonElement>('.cutscene > button')?.focus();
            audio.play(event);
            await new Promise<void>(resolve => { const timer = setTimeout(resolve, reduced.value ? 180 : cueDuration(event) / speed.value); stopWait = () => { clearTimeout(timer); resolve(); }; });
        }
    } finally {
        currentEvent.value = undefined; playing.value = false; stopWait = undefined; audio.stop();
        await nextTick(); previousFocus?.focus();
    }
}
/**
 * 送出一次行動。retry 走待確認流程，重送**原本的** action_id 與 payload。
 */
async function send(choice: Pick<Choice, 'type' | 'card_id' | 'fixed'> | null, keepCards: string[] = [], retry = false): Promise<void> {
    if (!run.value || busy.value || playing.value || (!retry && uncertain.value)) return;
    if (Date.now() < retryAt.value) { error.value = '仍在等待可重試時間，請稍候。'; return; }
    if (!retry && !choice) return;
    busy.value = true; error.value = ''; notice.value = '';
    await audio.unlock();
    let automaticallyReveal = false;
    try {
        const input = retry ? pending.value!.input : pending.prepare(run.value, choice!, keepCards);
        uncertain.value = true;
        const response = await api<{ data: Settlement }>(`/runs/${run.value.run_id}/actions`, input);
        const latest = (await api<{ data: Run }>(`/runs/${run.value.run_id}`)).data;
        pending.clear(); uncertain.value = false;
        if (input.type === 'play' || input.type === 'timeout') await present(response.data.events);
        if (latest.outcome !== 'in_progress') {
            // 通關才會有新的里程碑與牌組獎勵；戰報要顯示它們，所以這裡重新取一次戰役狀態。
            try { campaign.value = (await api<{ data: Campaign }>('/campaign')).data; } catch { /* 戰報照樣顯示。 */ }
        }
        showRun(latest);
        automaticallyReveal = (input.type === 'play' || input.type === 'timeout') && shouldAutoReveal(latest);
        if (latest.version > response.data.version) notice.value = '另一分頁已繼續操作，現在顯示最新局面。';
    } catch (e) {
        if (e instanceof ApiError && [404, 409, 422].includes(e.status)) {
            pending.clear(); uncertain.value = false;
            try { showRun((await api<{ data: Run }>(`/runs/${run.value.run_id}`)).data); } catch { /* Original error remains visible. */ }
        }
        handleError(e);
    } finally { busy.value = false; }
    if (automaticallyReveal) await reveal();
}
async function reveal(): Promise<void> { await send({ type: 'reveal', card_id: null, fixed: null }); }
async function castCard(instanceId: string): Promise<void> {
    const choice = choiceForCard(instanceId);
    if (!choice || choice.reason || !run.value?.compatible) return;
    await send(choice, keptCardsForPlay(keep.value, instanceId));
}
async function castFixed(skillId: string): Promise<void> {
    const choice = choiceForFixed(skillId);
    if (!choice || choice.reason || !run.value?.compatible) return;
    await send(choice, keep.value);
}
const swap = (instanceId: string) => send({ type: 'swap', card_id: instanceId, fixed: null });
/**
 * 倒數歸零時請伺服器收斂這一回合。Hostinger 沒有常駐 worker，逾時要靠客戶端
 * 請求或下一次有效寫入來結算；判定仍然只看伺服器的截止時間。
 */
async function settleTimeout(): Promise<void> {
    if (timingOut || locked.value || !run.value) return;
    timingOut = true;
    try { await send({ type: 'timeout', card_id: null, fixed: null }); } finally { timingOut = false; }
}
async function replay(): Promise<void> {
    if (locked.value || !run.value) return;
    await audio.unlock();
    await present(allEvents.value);
}
function onVisibility(): void { if (document.hidden) { skip(); audio.stop(); } }
function onKey(event: KeyboardEvent): void {
    if (!playing.value) return;
    if (event.key === 'Escape') skip();
    if (event.key === 'Tab') { event.preventDefault(); document.querySelector<HTMLButtonElement>('.cutscene > button')?.focus(); }
}
watch([muted, reduced, speed, effectsVolume, musicVolume], () => {
    audio.muted = muted.value; audio.effectsVolume = effectsVolume.value; audio.musicVolume = musicVolume.value;
    if (muted.value) audio.stop();
    try { localStorage.setItem('academy.settings', JSON.stringify({ muted: muted.value, reduced: reduced.value, speed: speed.value, effects: effectsVolume.value, music: musicVolume.value })); } catch { /* Storage is optional for preferences. */ }
});
watch(remaining, value => { if (value === 0 && revealed.value && !locked.value) void settleTimeout(); });
onMounted(() => {
    try { const saved = JSON.parse(localStorage.getItem('academy.settings') ?? 'null'); if (saved) { muted.value = saved.muted !== false; reduced.value = saved.reduced === true || reduced.value; speed.value = saved.speed === 2 ? 2 : 1; effectsVolume.value = saved.effects ?? .45; musicVolume.value = saved.music ?? .2; } } catch { /* Use defaults. */ }
    ticker = window.setInterval(() => { tick.value = Date.now(); }, 250);
    document.addEventListener('visibilitychange', onVisibility); document.addEventListener('keydown', onKey); void loadLobby();
});
onUnmounted(() => { skip(); if (ticker) clearInterval(ticker); document.removeEventListener('visibilitychange', onVisibility); document.removeEventListener('keydown', onKey); });
</script>

<template>
    <div class="app-shell" :class="{ 'low-motion': reduced }">
        <div :inert="playing">
        <header class="topbar">
            <button class="brand" :disabled="locked" @click="loadLobby"><span class="brand-seal">惡</span><span>我的反派學院<small>VILLAIN ACADEMY</small></span></button>
            <span class="edition">主線 <b>3</b> 關 / 進階 <b>2</b> 關</span>
            <details class="settings"><summary>聲音與演出</summary><div class="settings-panel">
                <label><input v-model="muted" type="checkbox" @change="audio.unlock"> 靜音</label>
                <label>音效 <input v-model.number="effectsVolume" aria-label="音效音量" type="range" min="0" max="1" step=".05"></label>
                <label>結局樂句 <input v-model.number="musicVolume" aria-label="結局音量" type="range" min="0" max="1" step=".05"></label>
                <label><input v-model="reduced" type="checkbox"> 減少動態</label>
                <label>演出速度 <select v-model.number="speed"><option :value="1">正常</option><option :value="2">兩倍速</option></select></label>
            </div></details>
        </header>
        <div v-if="error" class="message error" role="alert">{{ error }} <button v-if="!uncertain" :disabled="busy" @click="loadLobby">重新載入學院</button></div>
        <div v-if="notice" class="message" role="status">{{ notice }}</div>
        <div v-if="uncertain" class="message" role="status">行動結果待確認。<button :disabled="busy || playing" @click="run ? send(null, [], true) : loadLobby()">{{ run ? '重試原行動' : '重新連線取回對局' }}</button></div>
        <p v-if="busy && !playing" class="loading" role="status">正在確認學院紀錄…</p>
        <main>
            <template v-if="page === 'lobby'">
                <section class="hero">
                    <img class="hero-art" :src="asset('hero')" alt="學員帶著巨型畢業計畫，望向懸浮的陶片學院與虛構城市" fetchpriority="high">
                    <img class="hero-student" :src="asset('student-silhouette')" alt="" aria-hidden="true">
                    <div class="hero-copy"><p class="eyebrow">五門禁術 · 從第一堂課開始</p><h1 tabindex="-1">超認真<br>毀滅新竹<span>計畫。</span></h1><p class="lead">城市有它的修復計畫。<br>而你，是計畫之外的那一筆。</p>
                        <fieldset class="mode-picker"><legend>選擇模式</legend>
                            <label :class="{ on: mode === 'challenge' }"><input v-model="mode" type="radio" value="challenge"><b>限時挑戰</b><small>每回合 {{ decisionSeconds }} 秒決策；逾時只執行城市回應。</small></label>
                            <label :class="{ on: mode === 'practice' }"><input v-model="mode" type="radio" value="practice"><b>不限時練習</b><small>同一套牌組與城市規則，只關掉截止時間。成績分開記錄。</small></label>
                        </fieldset>
                        <button class="primary" :disabled="locked || !nextLevel" @click="start()">{{ nextLevel && nextLevel.sequence > 1 ? `繼續計畫 · ${nextLevel.name}` : '簽下入學計畫' }} <span>↗</span></button><p class="small">單人牌組策略 · 手牌 5 張 · {{ soundStatusText(muted) }}（可於右上角調整）</p></div>
                    <span class="hero-caption">虛構城市演習 / 非真實災害預測</span>
                </section>
                <section class="lobby-bottom"><div><p class="eyebrow">CAMPAIGN · 主線 3 關 / 進階 2 關</p><h2 tabindex="-1">五門禁術</h2>
                    <ol class="route">
                        <li v-for="l in mainLevels" :key="l.level_id" :class="{ ready: canPlay(l, mode) }"><b>{{ l.sequence }}. {{ l.name }}</b><span>{{ l.mechanic }}</span><small>{{ levelStatusText(l, mode) }}</small><button v-if="canPlay(l, mode)" :disabled="locked" @click="start(false, l.level_id)">{{ (mode === 'practice' ? l.practice_best : l.best) ? '再挑戰一次' : '開始這一關' }} ↗</button></li>
                    </ol>
                    <p class="eyebrow">進階畢業考 · 主線通關後解鎖</p>
                    <ol class="route advanced">
                        <li v-for="l in advancedLevels" :key="l.level_id" :class="{ ready: canPlay(l, mode) }"><b>{{ l.sequence }}. {{ l.name }}</b><span>{{ l.mechanic }}</span><small>{{ levelStatusText(l, mode) }}</small><button v-if="canPlay(l, mode)" :disabled="locked" @click="start(false, l.level_id)">{{ (mode === 'practice' ? l.practice_best : l.best) ? '再挑戰一次' : '開始這一關' }} ↗</button></li>
                    </ol>
                    <p class="small">第 3 關完成主線目標即可收下戰果結束；進階是選修，失敗不會撤銷主線通關。</p>
                    <div v-if="campaign" class="campaign-state">
                        <p v-if="campaign.milestones.length" class="titles"><span v-for="(title, key) in campaign.titles" :key="key" class="tag">{{ title }}</span><em v-if="campaign.stood_down">已收下戰果結案</em></p>
                        <button v-if="reward" class="primary" :disabled="locked" @click="openReward">有一張新禁術等你挑選 · {{ reward.level_name }} ↗</button>
                        <details><summary>目前牌組 · {{ Object.values(campaign.deck).reduce((sum, count) => sum + count, 0) }} 張</summary><ul class="deck-list"><li v-for="entry in currentDeck" :key="entry.name"><b>{{ entry.name }} ×{{ entry.count }}</b><small>{{ entry.role }}</small></li></ul><p class="small">牌組獎勵是替換不是增牌；每一局在開局時凍結牌組，之後挑的牌從下一局生效。</p></details>
                    </div>
                </div><div class="save-list"><p class="eyebrow">你的試煉紀錄 · 本瀏覽器</p><p v-if="!savedRuns.length">還沒有紀錄。從第一次大膽的決策開始。</p><button v-for="saved in savedRuns" :key="saved.run_id" :disabled="locked" @click="openRun(saved.run_id)"><span>{{ saved.outcome === 'in_progress' ? '繼續試煉' : saved.outcome === 'player_victory' ? '毀滅成功・查看戰報' : '城市守住・查看戰報' }}<small>{{ saved.mode === 'practice' ? '不限時練習' : '限時挑戰' }}</small></span><small>第 {{ saved.turn }} 回合 ↗</small></button><p class="small">匿名紀錄依賴本瀏覽器 Cookie；清除後無法找回。</p></div></section>
            </template>
            <template v-else-if="page === 'reward' && reward">
                <section class="content-width reward">
                    <p class="eyebrow">DECK REWARD / {{ reward!.level_name }}</p>
                    <h1 tabindex="-1">挑一張，換掉一張。</h1>
                    <p class="lead">{{ reward!.prompt }}</p>
                    <ul class="reward-options">
                        <li v-for="option in reward!.options" :key="option.key">
                            <b>{{ option.add.name }}<em>{{ option.style }}</em></b>
                            <p>{{ option.add.text }}</p>
                            <small>{{ option.add.role }}｜惡意 {{ option.add.malice_cost }}<template v-if="option.add.cooldown"> · 冷卻 {{ option.add.cooldown }}</template></small>
                            <p class="cost">代價：換掉一張「{{ option.remove.name }}」，牌組裡還剩 {{ option.remove_remaining }} 張。</p>
                            <button class="primary" :disabled="locked" @click="chooseReward(reward!.level_id, option.key)">選這一張</button>
                        </li>
                    </ul>
                    <p class="small">牌組維持 15 張。挑過就不能重挑，新牌從下一局開始生效——已經開始的對局在開局時就凍結了牌組。</p>
                    <div class="report-actions"><button :disabled="locked" @click="loadLobby">晚點再決定</button></div>
                </section>
            </template>
            <template v-else-if="run && state">
                <section v-if="page === 'briefing'" class="briefing content-width">
                    <div class="briefing-art"><img :src="asset(briefingAsset(run.level_id))" alt="枯潮教授晏沉，身穿深紫學院長袍，平靜地端著一只空杯"><span>枯潮教授 / 晏沉</span></div>
                    <div><p class="eyebrow">作戰簡報 · 第 {{ level?.sequence }} 關 {{ level?.name }}｜{{ mode === 'practice' ? '不限時練習' : '限時挑戰' }}</p>
                        <h1 tabindex="-1"><template v-for="(line, index) in briefingLines(level)" :key="index">{{ line }}<br v-if="index < briefingLines(level).length - 1"></template></h1>
                        <blockquote class="professor-quote">{{ level?.briefing?.quote }}<small>{{ level?.briefing?.quote_note }}</small></blockquote>
                        <p class="lead">你有 {{ state.max_turns }} 回合、一副 {{ level?.deck_size }} 張的牌組。核心歸零便通關；回合用盡而城市仍站著，這次試煉就結束。</p>
                        <ol class="lesson">
                            <li><b>每回合五張手牌，點一張立即施放。</b>{{ briefingTimingText(mode) }}</li>
                            <li><b>最多留 2 張。</b>先標記要留下的牌，再點另一張施放；留下的牌會佔住下一手的位置。另有每回合一次的免費換牌。</li>
                            <li v-for="(lesson, index) in level?.briefing?.lessons ?? []" :key="index">{{ lesson }}</li>
                            <li><b>蓄勢與終招不是牌。</b>它們固定在手牌旁邊，點擊同樣立即執行。</li>
                        </ol>
                        <button class="primary" :disabled="locked" @click="enterBattle">明白了，開始連續試煉 ↗</button><p class="small">本局情境與牌序已凍結。回學院後可繼續，不用一次打完。</p></div>
                </section>
                <section v-if="page === 'battle'" class="battle content-width">
                    <div class="battle-heading"><div><p class="eyebrow">CHAPTER {{ String(level?.sequence ?? 1).padStart(2, '0') }} / {{ level?.name }}｜{{ mode === 'practice' ? '不限時練習' : '限時挑戰' }}</p><h1 tabindex="-1">{{ level?.subtitle }}</h1></div>
                        <div class="clock"><div class="turn"><strong>{{ state.turn.toString().padStart(2, '0') }}</strong><span>/ {{ state.max_turns }} 回合</span></div>
                            <div v-if="revealed && remaining !== null" class="countdown" :class="{ urgent }" role="timer" :aria-label="`本回合剩餘 ${remaining} 秒`"><strong>{{ remaining }}</strong><span>秒</span></div>
                            <div v-else-if="revealed" class="countdown practice"><strong>∞</strong><span>不限時</span></div>
                        </div>
                    </div>
                    <div class="city-strip">
                        <div class="city-core"><img :src="asset(sceneAsset(run.level_id))" :alt="`${level?.name}的虛構城市防線`"><img class="apostle-mark" :src="asset(apostleAsset(run.level_id))" :alt="`${level?.name}的禁術使徒`"><div class="core-panel"><span>城市核心韌性</span><strong>{{ state.core_resilience }}</strong><progress aria-label="城市核心韌性" :value="state.core_resilience" max="100"></progress></div></div>
                        <div class="city-read">
                            <div class="intent-card" :class="{ interruptible: state.intent?.interruptible }"><small>下一步 · 城市預告</small><p>{{ state.intent?.description ?? '對局已結束' }}</p><span v-if="state.intent?.interruptible" class="tag">可用{{ elementNames[state.intent.element] }}系擾序打斷</span></div>
                            <div class="defenses"><div v-for="element in elements" :key="element" :class="element"><b>{{ elementNames[element] }}系防線 <strong>{{ state.defenses[element] }}</strong></b><progress :aria-label="`${elementNames[element]}系防線`" :value="state.defenses[element]" max="100"></progress><small>抗性 {{ state.resistance[element] }} 層 · 印記 {{ state.sigils[element] }}/{{ state.sigil_cap }}</small></div></div>
                            <div v-if="state.shields.length || state.breach_available" class="window"><span v-if="state.breach_available">破綻已開啟・下一個行動消耗</span><span v-for="(shield, index) in state.shields" :key="index">護盾 {{ shield.amount }}</span></div>
                        </div>
                    </div>
                    <div class="table-bar"><div class="malice"><span>你的惡意</span><b>{{ state.malice }}<small> / {{ state.malice_cap }}</small></b></div>
                        <p class="counts">抽牌堆 {{ state.draw_pile_count }} · 棄牌堆 {{ state.discard_pile.length }} · 留牌 {{ keep.length }}/{{ state.max_keep }}</p>
                        <p v-if="revealed" class="professor-note">{{ hint }}</p>
                    </div>

                    <div v-if="!revealed" class="reveal-gate">
                        <p>{{ nextHandText(mode) }}</p>
                    </div>
                    <template v-else>
                        <ul class="hand" aria-label="手牌">
                            <li v-for="entry in hand" :key="entry.id" :class="[entry.card!.element, { previewed: previewedCard === entry.card, kept: keep.includes(entry.id), unavailable: !!reasonFor(entry.id) }]">
                                <button class="card-face" :disabled="locked || !!reasonFor(entry.id) || !run.compatible" :title="reasonFor(entry.id) || `點擊立即施放 ${entry.card!.name}`" @mouseenter="previewedCard = entry.card" @mouseleave="previewedCard = undefined" @focus="previewedCard = entry.card" @blur="previewedCard = undefined" @click="castCard(entry.id)">
                                    <span class="card-top"><b>{{ entry.card!.name }}</b><em>{{ entry.card!.element ? elementNames[entry.card!.element] + '系' : '無系' }}</em></span>
                                    <span class="card-cost">惡意 {{ entry.card!.malice_cost }}<template v-if="entry.card!.cooldown"> · 冷卻 {{ entry.card!.cooldown }}</template></span>
                                    <span class="card-text">{{ entry.card!.text }}</span>
                                    <span class="card-role">{{ entry.card!.role }}</span>
                                    <span class="card-data">{{ noteFor(entry.card!) }}</span>
                                    <span v-if="reasonFor(entry.id)" class="card-block">{{ reasonFor(entry.id) }}</span>
                                </button>
                                <div class="card-tools">
                                    <button :disabled="locked || (!keep.includes(entry.id) && !canKeepMore)" :aria-pressed="keep.includes(entry.id)" @click="toggleKeep(entry.id)">{{ keep.includes(entry.id) ? '已留牌' : '留到下回合' }}</button>
                                    <button :disabled="locked || !!swapChoice(entry.id)?.reason" @click="swap(entry.id)">{{ state.swap_used ? '已換過' : '換這張' }}</button>
                                </div>
                            </li>
                        </ul>
                        <div class="preview-bar" aria-live="polite">
                            <template v-if="previewedCard"><b>{{ previewedCard.name }}</b><p>{{ previewedCard.role }}</p><small>基礎衝擊 {{ previewedCard.base_impact }} · 防線 {{ previewedCard.defense_delta }}｜{{ noteFor(previewedCard) }}<br>點擊立即施放；實際戰果由伺服器依防線、抗性、護盾與情境結算。</small></template>
                            <template v-else><b>先留牌，再出手</b><p>先勾選最多 2 張要留到下一手的牌；移到卡牌上可預覽，點擊卡牌立即施放。</p></template>
                        </div>
                        <div class="fixed-actions">
                            <p class="eyebrow">手牌旁的固定行動 · 不佔手牌</p>
                            <button v-for="card in fixedCards" :key="card.skill_id" :class="{ unavailable: !!fixedReason(card) }" :disabled="locked || !!fixedReason(card) || !run.compatible" :title="fixedReason(card) || `點擊立即執行 ${card.name}`" @mouseenter="previewedCard = card" @mouseleave="previewedCard = undefined" @focus="previewedCard = card" @blur="previewedCard = undefined" @click="castFixed(card.skill_id)"><b>{{ card.name }}</b><small>{{ fixedReason(card) || `${card.role}，點擊立即執行` }}</small></button>
                        </div>
                    </template>
                    <details class="forecast"><summary>查看完整城市預告與規則細節</summary><p>留牌上限 {{ state.max_keep }} 張，換牌每回合 1 次且不推進回合、不重設倒數。逾時不會自動施放選中的牌，也不扣未出的牌費。</p><ol><li v-for="intent in level?.forecast" :key="intent.scheduled_turn">{{ intent.description }}</li></ol></details>
                </section>
                <section v-if="page === 'report'" class="report content-width" :class="{ victory: run.outcome === 'player_victory' }"><img :src="asset(reportAsset(run.outcome, run.level_id))" :alt="run.outcome === 'player_victory' ? '虛構城市化為懸浮陶片，金色光幕宣告禁術修習通關' : '重新穩定的虛構城市防線'">
                    <div><p class="eyebrow">ACADEMY FIELD REPORT / 第 {{ level?.sequence }} 關戰報 · {{ level?.name }}｜{{ run.mode === 'practice' ? '不限時練習' : '限時挑戰' }}</p><h1 tabindex="-1">{{ run.outcome === 'player_victory' ? '毀滅成功。' : '城市守住了。' }}</h1><p class="lead">{{ run.outcome === 'player_victory' ? `第 ${level?.sequence} 門禁術・${level?.name}，修習通過。晏沉抬杯，向你致意。` : '本次試煉結束。把城市的回應，變成下一次的計畫。' }}</p><p>第 {{ state.turn }} 回合 · 核心剩餘 {{ state.core_resilience }} · 逾時 {{ state.timeouts }} 次</p><p>{{ run.outcome === 'player_victory' ? '晏沉的結語：「一座城市若把每次撐過去，都當成不必改變的理由，最後就會連下一次也沒有。」以下列出你如何使這一局走到終點。' : '晏沉收回空杯：「你讓它喘過氣了。看看是哪一回合。」以下依你的實際行動複盤，再挑一個決策重試。' }}</p><p v-if="run.mode === 'practice'" class="small">練習成績單獨記錄，不會登記為限時挑戰通關。</p><div class="report-actions"><button v-if="run.outcome === 'player_victory' && nextLevel && nextLevel.level_id !== run.level_id" class="primary" :disabled="locked" @click="start(false, nextLevel.level_id)">前往第 {{ nextLevel.sequence }} 關 · {{ nextLevel.name }} ↗</button><button :class="{ primary: run.outcome !== 'player_victory' }" :disabled="locked || !run.compatible" @click="start(true)">同情境再試一次 ↗</button><button :disabled="locked" @click="replay">重播本局演出</button><button :disabled="locked" @click="loadLobby">回學院</button></div></div>
                </section>
                <section v-if="page === 'report' && ending === 'main'" class="content-width ending main"><img class="ending-art" :src="asset('victory-2')" alt="浮空陶片與學院的銅色焰火">
                    <p class="eyebrow">CAMPAIGN ENDING / 主線目標達成</p>
                    <h2 tabindex="-1">新竹縣模擬防線，失守。</h2>
                    <p class="lead">晏沉在名冊上蓋下「{{ campaign?.titles?.main_cleared ?? '毀滅計畫通過' }}」。三門禁術修習完畢，這份計畫已經可以結案。</p>
                    <p>接下來由你決定：收下戰果結束本次計畫，或接受進階畢業考——那是兩關更強的城市應變方案，失敗不會撤銷你剛剛拿到的通過。</p>
                    <div class="report-actions">
                        <button class="primary" :disabled="locked" @click="standDown">收下戰果，結束本次計畫</button>
                        <button :disabled="locked || !advancedEntry" @click="advancedEntry && start(false, advancedEntry.level_id)">接受進階畢業考 · {{ advancedEntry?.name }} ↗</button>
                    </div>
                    <p class="small">收手之後仍可從同一份存檔回來挑戰進階；這不是失敗，也不是少拿一個結局。</p>
                </section>
                <section v-if="page === 'report' && ending === 'advanced'" class="content-width ending advanced"><img v-for="(frame, index) in advancedFinaleFrames" :key="frame" class="ending-art" :src="asset(frame)" :alt="`進階終幕第 ${index + 1} 幕`">
                    <p class="eyebrow">CAMPAIGN ENDING / 進階終幕</p>
                    <h2 tabindex="-1">蓄夜之後，沒有下一個夜晚。</h2>
                    <p class="lead">五門禁術全數修習完畢。晏沉把空杯倒扣在桌上：「{{ campaign?.titles?.advanced_cleared ?? '首席反派' }}。這個稱號，學院只發給把最後一次重整也打斷的人。」</p>
                    <p class="small">虛構城市演習；真實的新竹縣不是這場推演的結論。</p>
                </section>
                <section v-if="page === 'report' && reward" class="content-width ending reward-hint">
                    <p class="eyebrow">DECK REWARD / 牌組獎勵</p>
                    <h2 tabindex="-1">有一張新禁術可以換進牌組。</h2>
                    <p class="lead">{{ reward?.prompt }}</p>
                    <div class="report-actions"><button class="primary" :disabled="locked" @click="openReward">去挑一張 ↗</button></div>
                </section>
                <section v-if="page === 'report'" class="content-width"><h2>關鍵回合 · 依實際紀錄</h2><ol class="highlights"><li v-for="(event, index) in highlights" :key="index"><b>{{ event.turn === null ? '全局' : `第 ${event.turn} 回合` }}</b><span>{{ event.text }}</span></li></ol></section>
                <section class="content-width data-panel"><details :open="page === 'briefing'"><summary>本局情境情報 · 開局後不變</summary><p class="small">以下是遊戲情境修正，不是災害預測。正值有利進攻；缺值採中性修正。標示「本關未採用」的系別不會在這一局生效。</p><div class="data-grid"><div v-for="element in elements" :key="element"><b>{{ elementNames[element] }}系 {{ run.data_notes[element]?.applied ? ((run.data_notes[element].modifier ?? 0) * 100).toFixed(1) + '%' : '本關未採用' }}</b><p>{{ run.data_notes[element]?.reason ?? run.scenario.reasons[element]?.message }}</p></div></div><div v-if="!Object.keys(run.snapshots).length" class="message">舊局未保存來源品質。保留原情境數值，不以今日資料冒充。</div><div v-for="(snapshot, source) in run.snapshots" :key="source" class="source-row"><b>{{ sourceNames[source] ?? source }}</b><span>{{ qualityNames[snapshot.quality] ?? snapshot.quality }}</span><small>觀測：{{ snapshot.observed_at ?? (snapshot.period ? Object.values(snapshot.period).join(' / ') : '無觀測日期') }}</small><p v-for="warning in snapshot.warnings" :key="warning" class="small">{{ warning }}</p></div></details></section>
                <section v-if="run.history.length" class="content-width history"><details><summary>完整行動紀錄 · {{ run.history.length }} 次</summary><article v-for="entry in run.history" :key="entry.sequence"><h3>第 {{ entry.events[0]?.turn }} 回合 / {{ playedName(run, entry.input) }}</h3><ul><li v-for="event in entry.events" :key="event.sequence">{{ eventText(event) }}</li></ul>
                    <div v-if="entry.input.type === 'play'" class="counterfactual">
                        <button :disabled="counterfactuals[entry.sequence] === 'loading'" @click="tryCounterfactual(entry.sequence, { type: 'play', fixed: 'gather' })">如果這回合改蓄勢，結果會怎樣？</button>
                        <p v-if="counterfactuals[entry.sequence] === 'loading'" class="small">模擬中…</p>
                        <p v-else-if="counterfactuals[entry.sequence] === 'error'" class="small">這個比較目前算不出來，可以再試一次。</p>
                        <p v-else-if="counterfactuals[entry.sequence]" class="small">{{ counterfactualText(counterfactuals[entry.sequence] as CounterfactualComparison) }}</p>
                    </div>
                </article></details></section>
            </template>
        </main>
        <footer><span>世外高人 / 智慧沙盒創新計畫</span><span>虛構策略遊戲。城市被毀滅，是玩家勝利。</span><a href="/docs/ASSET-SOURCES.md" @click.prevent="notice = '圖片：OpenAI image_gen 原創生成。音效：Kenney Impact Sounds（CC0）。完整來源與提示詞見 assets/p06-generation.json、assets/p04-generation.json、assets/third-party/manifest.json。'">素材來源</a></footer>
        </div>
        <div v-if="currentEvent" class="cutscene" :class="{ ultimate: currentEvent.cue_id.includes('ultimate'), triumph: currentEvent.after.outcome === 'player_victory' }" role="dialog" aria-modal="true" aria-label="戰鬥演出"><img :src="asset(cueImage(currentEvent, run?.level_id))" :alt="eventText(currentEvent)"><div class="cutscene-copy"><p class="eyebrow">第 {{ currentEvent.turn }} 回合 / {{ currentEvent.target ? elementNames[currentEvent.target] + '系' : (level?.name ?? '') }}</p><h2>{{ currentEvent.cue_id.includes('ultimate') && currentEvent.type !== 'outcome' ? '揮霍無度・新竹歸寂' : eventText(currentEvent) }}</h2><p v-if="currentEvent.type === 'outcome'">{{ currentEvent.after.outcome === 'player_victory' ? '枯潮修習通過。這一局，城市已無下一次。' : '城市尚存韌性。回到課堂，檢查這次的決策。' }}</p><p v-if="currentEvent.type === 'action_missed'">決策時間用完了。這一回合你沒有出手，城市照預告行動。</p><p v-if="currentEvent.type === 'impact'">護盾吸收 {{ currentEvent.delta.absorbed }} · 命中前有效防線 {{ currentEvent.delta.effective_defense }}</p></div><button @click="skip">跳過演出 · Esc</button></div>
    </div>
</template>
