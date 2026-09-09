<script setup lang="ts">
import { computed, nextTick, onMounted, onUnmounted, ref, watch } from 'vue';
import { api, ApiError, PendingAction, PendingStorageError, reportFindings, dataNoteText, briefingTimingText, nextHandText, shouldAutoReveal, keptCardsForPlay, soundStatusText, secondsLeft, serverOffset, playedName, elements, elementNames, unavailableText, eventText, cueImage, cueDuration, visibleEvents } from './game';
import type { BattleEvent, Card, Choice, Level, Run, RunMode, SavedRun, Settlement } from './game';
import { BattleAudio } from './audio';

const page = ref<'lobby' | 'briefing' | 'battle' | 'report'>('lobby');
const levels = ref<Level[]>([]);
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
const firstLevel = computed(() => levels.value.find(l => l.available));
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
function asset(name: string): string { return `/assets/p04/${name}.webp`; }
function focusHeading(): void { void nextTick(() => document.querySelector<HTMLElement>('main h1, main h2')?.focus()); }
function showRun(value: Run, briefing = false): void {
    run.value = value;
    mode.value = value.mode;
    clockOffset.value = serverOffset(value.server_time);
    previewedCard.value = undefined; keep.value = [];
    page.value = value.outcome !== 'in_progress' ? 'report' : briefing ? 'briefing' : 'battle';
    if (!value.compatible) notice.value = '這一局使用舊版規則。可以查看紀錄，請回學院另開新局。';
    focusHeading();
}
async function loadLobby(): Promise<void> {
    if (busy.value || playing.value) return;
    busy.value = true; error.value = ''; notice.value = '';
    try {
        const [catalog, saves] = await Promise.all([
            api<{ levels: Level[]; decision_seconds: number }>('/levels'), api<{ data: SavedRun[] }>('/runs'),
        ]);
        levels.value = catalog.levels; decisionSeconds.value = catalog.decision_seconds;
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
async function start(retry = false): Promise<void> {
    if (locked.value) return;
    busy.value = true; error.value = ''; notice.value = '';
    await audio.unlock();
    try {
        const result = await api<{ data: Run }>(
            retry ? `/runs/${run.value!.run_id}/retry` : '/runs',
            retry ? {} : { level_id: firstLevel.value?.level_id, mode: mode.value },
        );
        showRun(result.data, true);
        for (const name of ['city', 'apostle', 'yan-chen', 'victory', 'water', 'heat', 'land']) { const image = new Image(); image.src = asset(name); }
    } catch (e) { handleError(e); } finally { busy.value = false; }
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
                    <div class="hero-copy"><p class="eyebrow">五門禁術 · 從第一堂課開始</p><h1 tabindex="-1">超認真<br>毀滅新竹<span>計畫。</span></h1><p class="lead">城市有它的修復計畫。<br>而你，是計畫之外的那一筆。</p>
                        <fieldset class="mode-picker"><legend>選擇模式</legend>
                            <label :class="{ on: mode === 'challenge' }"><input v-model="mode" type="radio" value="challenge"><b>限時挑戰</b><small>每回合 {{ decisionSeconds }} 秒決策；逾時只執行城市回應。</small></label>
                            <label :class="{ on: mode === 'practice' }"><input v-model="mode" type="radio" value="practice"><b>不限時練習</b><small>同一套牌組與城市規則，只關掉截止時間。成績分開記錄。</small></label>
                        </fieldset>
                        <button class="primary" :disabled="locked || !firstLevel" @click="start()">簽下入學計畫 <span>↗</span></button><p class="small">單人牌組策略 · 手牌 5 張 · {{ soundStatusText(muted) }}（可於右上角調整）</p></div>
                    <span class="hero-caption">虛構城市演習 / 非真實災害預測</span>
                </section>
                <section class="lobby-bottom"><div><p class="eyebrow">CAMPAIGN · 主線 3 關 / 進階 2 關</p><h2 tabindex="-1">五門禁術</h2>
                    <ol class="route">
                        <li v-for="l in mainLevels" :key="l.level_id" :class="{ ready: l.available }"><b>{{ l.sequence }}. {{ l.name }}</b><span>{{ l.mechanic }}</span><small>{{ l.available ? `${l.max_turns} 回合 · 牌組 ${l.deck_size} 張` : '內容製作中（P05）' }}</small></li>
                    </ol>
                    <p class="eyebrow">進階畢業考 · 主線通關後解鎖</p>
                    <ol class="route advanced">
                        <li v-for="l in advancedLevels" :key="l.level_id"><b>{{ l.sequence }}. {{ l.name }}</b><span>{{ l.mechanic }}</span><small>內容製作中（P05）</small></li>
                    </ol>
                    <p class="small">第 3 關完成主線目標即可收下戰果結束；進階是選修，失敗不會撤銷主線通關。</p>
                </div><div class="save-list"><p class="eyebrow">你的試煉紀錄 · 本瀏覽器</p><p v-if="!savedRuns.length">還沒有紀錄。從第一次大膽的決策開始。</p><button v-for="saved in savedRuns" :key="saved.run_id" :disabled="locked" @click="openRun(saved.run_id)"><span>{{ saved.outcome === 'in_progress' ? '繼續試煉' : saved.outcome === 'player_victory' ? '毀滅成功・查看戰報' : '城市守住・查看戰報' }}<small>{{ saved.mode === 'practice' ? '不限時練習' : '限時挑戰' }}</small></span><small>第 {{ saved.turn }} 回合 ↗</small></button><p class="small">匿名紀錄依賴本瀏覽器 Cookie；清除後無法找回。</p></div></section>
            </template>
            <template v-else-if="run && state">
                <section v-if="page === 'briefing'" class="briefing content-width">
                    <div class="briefing-art"><img :src="asset('yan-chen')" alt="枯潮教授晏沉，身穿深紫學院長袍，平靜地端著一只空杯"><span>枯潮教授 / 晏沉</span></div>
                    <div><p class="eyebrow">作戰簡報 · {{ level?.name }}｜{{ mode === 'practice' ? '不限時練習' : '限時挑戰' }}</p><h1 tabindex="-1">讓城市<br>喊渴。</h1><blockquote class="professor-quote">「杯子空了，補水就好。城市空了呢？」<small>晏沉將空杯推到你面前。「這就是你今天的作業。」</small></blockquote><p class="lead">你有 {{ state.max_turns }} 回合、一副 {{ level?.deck_size }} 張的牌組。核心歸零便通關；回合用盡而城市仍站著，這次試煉就結束。</p><ol class="lesson"><li><b>每回合五張手牌，點一張立即施放。</b>{{ briefingTimingText(mode) }}</li><li><b>最多留 2 張。</b>先標記要留下的牌，再點另一張施放；留下的牌會佔住下一手的位置。另有每回合一次的免費換牌。</li><li><b>讀預告。</b>第 3、6 回合城市會修復核心；同系擾序牌可以取消它，首次打斷還會返還 2 點惡意。</li><li><b>蓄勢與終招不是牌。</b>它們固定在手牌旁邊，點擊同樣立即執行。</li></ol><button class="primary" :disabled="locked" @click="enterBattle">明白了，開始連續試煉 ↗</button><p class="small">本局情境與牌序已凍結。回學院後可繼續，不用一次打完。</p></div>
                </section>
                <section v-if="page === 'battle'" class="battle content-width">
                    <div class="battle-heading"><div><p class="eyebrow">CHAPTER 01 / {{ level?.name }}｜{{ mode === 'practice' ? '不限時練習' : '限時挑戰' }}</p><h1 tabindex="-1">{{ level?.subtitle }}</h1></div>
                        <div class="clock"><div class="turn"><strong>{{ state.turn.toString().padStart(2, '0') }}</strong><span>/ {{ state.max_turns }} 回合</span></div>
                            <div v-if="revealed && remaining !== null" class="countdown" :class="{ urgent }" role="timer" :aria-label="`本回合剩餘 ${remaining} 秒`"><strong>{{ remaining }}</strong><span>秒</span></div>
                            <div v-else-if="revealed" class="countdown practice"><strong>∞</strong><span>不限時</span></div>
                        </div>
                    </div>
                    <div class="city-strip">
                        <div class="city-core"><img :src="asset('city')" alt="虚構丘陵城市的水系防守光網"><div class="core-panel"><span>城市核心韌性</span><strong>{{ state.core_resilience }}</strong><progress aria-label="城市核心韌性" :value="state.core_resilience" max="100"></progress></div></div>
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
                <section v-if="page === 'report'" class="report content-width" :class="{ victory: run.outcome === 'player_victory' }"><img :src="asset(run.outcome === 'player_victory' ? 'victory' : 'city')" :alt="run.outcome === 'player_victory' ? '虛構城市化為懸浮陶片，金色光幕宣告枯潮試煉通關' : '守住的虛構城市'">
                    <div><p class="eyebrow">ACADEMY FIELD REPORT / 第一關戰報 · {{ run.mode === 'practice' ? '不限時練習' : '限時挑戰' }}</p><h1 tabindex="-1">{{ run.outcome === 'player_victory' ? '毀滅成功。' : '城市守住了。' }}</h1><p class="lead">{{ run.outcome === 'player_victory' ? '第一門禁術・枯潮，修習通過。晏沉抬杯，向你致意。' : '本次試煉結束。把城市的回應，變成下一次的計畫。' }}</p><p>第 {{ state.turn }} 回合 · 核心剩餘 {{ state.core_resilience }} · 逾時 {{ state.timeouts }} 次</p><p>{{ run.outcome === 'player_victory' ? '晏沉的結語：「一座城市若把每次撐過去，都當成不必改變的理由，最後就會連下一次也沒有。」以下列出你如何使這一局走到終點。' : '晏沉收回空杯：「你讓它喘過氣了。看看是哪一回合。」以下依你的實際行動複盤，再挑一個決策重試。' }}</p><p v-if="run.mode === 'practice'" class="small">練習成績單獨記錄，不會登記為限時挑戰通關。</p><div class="report-actions"><button class="primary" :disabled="locked || !run.compatible" @click="start(true)">同情境再試一次 ↗</button><button :disabled="locked" @click="replay">重播本局演出</button><button :disabled="locked" @click="loadLobby">回學院</button></div></div>
                </section>
                <section v-if="page === 'report'" class="content-width"><h2>關鍵回合 · 依實際紀錄</h2><ol class="highlights"><li v-for="(event, index) in highlights" :key="index"><b>{{ event.turn === null ? '全局' : `第 ${event.turn} 回合` }}</b><span>{{ event.text }}</span></li></ol></section>
                <section class="content-width data-panel"><details :open="page === 'briefing'"><summary>本局情境情報 · 開局後不變</summary><p class="small">以下是遊戲情境修正，不是災害預測。正值有利進攻；缺值採中性修正。標示「本關未採用」的系別不會在這一局生效。</p><div class="data-grid"><div v-for="element in elements" :key="element"><b>{{ elementNames[element] }}系 {{ run.data_notes[element]?.applied ? ((run.data_notes[element].modifier ?? 0) * 100).toFixed(1) + '%' : '本關未採用' }}</b><p>{{ run.data_notes[element]?.reason ?? run.scenario.reasons[element]?.message }}</p></div></div><div v-if="!Object.keys(run.snapshots).length" class="message">舊局未保存來源品質。保留原情境數值，不以今日資料冒充。</div><div v-for="(snapshot, source) in run.snapshots" :key="source" class="source-row"><b>{{ sourceNames[source] ?? source }}</b><span>{{ qualityNames[snapshot.quality] ?? snapshot.quality }}</span><small>觀測：{{ snapshot.observed_at ?? (snapshot.period ? Object.values(snapshot.period).join(' / ') : '無觀測日期') }}</small><p v-for="warning in snapshot.warnings" :key="warning" class="small">{{ warning }}</p></div></details></section>
                <section v-if="run.history.length" class="content-width history"><details><summary>完整行動紀錄 · {{ run.history.length }} 次</summary><article v-for="entry in run.history" :key="entry.sequence"><h3>第 {{ entry.events[0]?.turn }} 回合 / {{ playedName(run, entry.input) }}</h3><ul><li v-for="event in entry.events" :key="event.sequence">{{ eventText(event) }}</li></ul></article></details></section>
            </template>
        </main>
        <footer><span>世外高人 / 智慧沙盒創新計畫</span><span>虛構策略遊戲。城市被毀滅，是玩家勝利。</span><a href="/docs/ASSET-SOURCES.md" @click.prevent="notice = '圖片：OpenAI image_gen 原創生成。音效：Kenney Impact Sounds（CC0）。完整來源與提示詞見專案 assets/p04-generation.json、assets/third-party/manifest.json。'">素材來源</a></footer>
        </div>
        <div v-if="currentEvent" class="cutscene" :class="{ ultimate: currentEvent.cue_id.includes('ultimate'), triumph: currentEvent.after.outcome === 'player_victory' }" role="dialog" aria-modal="true" aria-label="戰鬥演出"><img :src="asset(cueImage(currentEvent))" :alt="eventText(currentEvent)"><div class="cutscene-copy"><p class="eyebrow">第 {{ currentEvent.turn }} 回合 / {{ currentEvent.target ? elementNames[currentEvent.target] + '系' : '枯潮・寶山空杯' }}</p><h2>{{ currentEvent.cue_id.includes('ultimate') && currentEvent.type !== 'outcome' ? '揮霍無度・新竹歸寂' : eventText(currentEvent) }}</h2><p v-if="currentEvent.type === 'outcome'">{{ currentEvent.after.outcome === 'player_victory' ? '枯潮修習通過。這一局，城市已無下一次。' : '城市尚存韌性。回到課堂，檢查這次的決策。' }}</p><p v-if="currentEvent.type === 'action_missed'">決策時間用完了。這一回合你沒有出手，城市照預告行動。</p><p v-if="currentEvent.type === 'impact'">護盾吸收 {{ currentEvent.delta.absorbed }} · 命中前有效防線 {{ currentEvent.delta.effective_defense }}</p></div><button @click="skip">跳過演出 · Esc</button></div>
    </div>
</template>
