<script setup lang="ts">
import { computed, nextTick, onMounted, onUnmounted, ref, watch } from 'vue';
import { api, ApiError, PendingAction, PendingStorageError, reportFindings, elements, elementNames, skillName, unavailableText, eventText, cueImage, cueDuration, visibleEvents } from './game';
import type { BattleEvent, Choice, Level, Run, SavedRun, Settlement, Skill } from './game';
import { BattleAudio } from './audio';

const page = ref<'lobby' | 'briefing' | 'battle' | 'report'>('lobby');
const level = ref<Level>();
const skills = ref<Record<string, Skill>>({});
const savedRuns = ref<SavedRun[]>([]);
const run = ref<Run>();
const busy = ref(false);
const playing = ref(false);
const error = ref('');
const notice = ref('');
const selected = ref('probe.water');
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
const state = computed(() => run.value?.state);
const locked = computed(() => busy.value || playing.value || uncertain.value);
const currentSkill = computed(() => skills.value[selected.value]);
const choices = computed(() => Object.values(skills.value));
const selectedChoice = computed(() => choiceFor(selected.value));
const allEvents = computed(() => run.value?.history.flatMap(h => h.events) ?? []);
const highlights = computed(() => run.value ? reportFindings(run.value) : []);
const sourceNames: Record<string, string> = { 'wra.reservoir_conditions': '水利署・水庫水情', 'nstc.science_park_water': '國科會・園區用水', 'moi.land_use': '內政部・國土利用' };
const qualityNames: Record<string, string> = { demo: '示範情境', fresh: '開局時有效資料', stale: '開局時已過期資料', unavailable: '資料不可得' };
const description: Record<string, string> = {
    probe: '低成本削弱防線，累積該系印記。', breach: '高衝擊削弱防線；留意惡意與再次可用回合。',
    disrupt: '只有在本回合預告可打斷、且系別相同時，才會取消城市行動。',
    gather: '回復惡意並緩解最高抗性；仍消耗一回合，城市照常行動。', ultimate: '消耗所有印記，對核心施放終式；先抓住破綻再出手。',
};
const hint = computed(() => {
    if (!state.value) return '';
    if (state.value.breach_available) return '防線已裂開！下一個行動會用掉破綻，連蓄勢也算。把窗口留給值得的一擊。';
    if (state.value.intent?.interruptible) return '城市正準備修復。查看水系擾序是否可用；第 3 回合用了，要到第 7 回合才恢復，不能連擋第 6 回合。';
    if (state.value.turn === 1) return '先讀城市預告，再選禁術。試探省惡意；破陣傷害較高但有冷卻。每次有效施招都會推進回合。';
    return '換系能緩解原系抗性，連續三種不同系進攻會連攜。第 3、6、9 回合的修復，值得提前留招。';
});
function choiceFor(id: string): Choice | undefined {
    const skill = skills.value[id];
    return run.value?.available_actions.find(a => a.skill_id === id && a.target === skill?.element);
}
function reasonFor(skill: Skill): string { return state.value ? unavailableText(choiceFor(skill.id), state.value, skill) : ''; }
function asset(name: string): string { return `/assets/p04/${name}.webp`; }
function focusHeading(): void { void nextTick(() => document.querySelector<HTMLElement>('main h1, main h2')?.focus()); }
function showRun(value: Run, briefing = false): void {
    run.value = value;
    page.value = value.outcome !== 'in_progress' ? 'report' : briefing ? 'briefing' : 'battle';
    if (!value.compatible) notice.value = '這一局使用舊版規則。可以查看紀錄，請回學院另開新局。';
    focusHeading();
}
async function loadLobby(): Promise<void> {
    if (busy.value || playing.value) return;
    busy.value = true; error.value = ''; notice.value = '';
    try {
        const [catalog, saves] = await Promise.all([
            api<{ levels: Level[]; skills: Record<string, Skill> }>('/levels'), api<{ data: SavedRun[] }>('/runs'),
        ]);
        level.value = catalog.levels.find(l => l.level_id === 'empty-cup'); skills.value = catalog.skills;
        savedRuns.value = saves.data.filter(s => s.level_id === 'empty-cup'); page.value = 'lobby';
        if (pending.value) {
            try {
                const result = await api<{ data: Run }>(`/runs/${pending.value.runId}`);
                showRun(result.data);
                notice.value = '有一筆施招尚待確認。請重試原行動，以取回結果。';
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
        if (e.status === 419) error.value = '工作階段驗證已更新，請重新整理；已送出的施招會保留原編號供確認。';
        else if (e.status === 429) { retryAt.value = Date.now() + e.retryAfter * 1000; error.value = `請稍候 ${e.retryAfter} 秒再重試原行動。`; }
        else error.value = e.message;
    } else error.value = '連線中斷或回應逾時。施招請重試原行動；開局請回學院查看最近對局，避免重複開局。';
}
async function openRun(id: string): Promise<void> {
    if (locked.value) return;
    busy.value = true; error.value = ''; notice.value = '';
    try { showRun((await api<{ data: Run }>(`/runs/${id}`)).data); }
    catch (e) { handleError(e); } finally { busy.value = false; }
}
async function start(retry = false): Promise<void> {
    if (locked.value) return;
    busy.value = true; error.value = ''; notice.value = '';
    await audio.unlock();
    try {
        const result = await api<{ data: Run }>(retry ? `/runs/${run.value!.run_id}/retry` : '/runs', retry ? {} : { level_id: 'empty-cup' });
        showRun(result.data, true);
        for (const name of ['city', 'apostle', 'yan-chen', 'victory', 'water', 'heat', 'land']) { const image = new Image(); image.src = asset(name); }
    } catch (e) { handleError(e); } finally { busy.value = false; }
}
async function enterBattle(): Promise<void> { await audio.unlock(); page.value = 'battle'; focusHeading(); }
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
async function submit(retry = false): Promise<void> {
    if (!run.value || busy.value || playing.value || (!retry && uncertain.value)) return;
    if (Date.now() < retryAt.value) { error.value = '仍在等待可重試時間，請稍候。'; return; }
    const choice = selectedChoice.value;
    if (!retry && (!choice || choice.reason)) return;
    busy.value = true; error.value = ''; notice.value = '';
    await audio.unlock();
    try {
        const input = retry ? pending.value!.input : pending.prepare(run.value, choice!);
        uncertain.value = true;
        const response = await api<{ data: Settlement }>(`/runs/${run.value.run_id}/actions`, input);
        const latest = (await api<{ data: Run }>(`/runs/${run.value.run_id}`)).data;
        pending.clear(); uncertain.value = false;
        await present(response.data.events);
        showRun(latest);
        if (latest.version > response.data.version) notice.value = '另一分頁已繼續操作，現在顯示最新局面。';
    } catch (e) {
        if (e instanceof ApiError && [404, 409, 422].includes(e.status)) {
            pending.clear(); uncertain.value = false;
            try { showRun((await api<{ data: Run }>(`/runs/${run.value.run_id}`)).data); } catch { /* Original error remains visible. */ }
        }
        handleError(e);
    } finally { busy.value = false; }
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
onMounted(() => {
    try { const saved = JSON.parse(localStorage.getItem('academy.settings') ?? 'null'); if (saved) { muted.value = saved.muted !== false; reduced.value = saved.reduced === true || reduced.value; speed.value = saved.speed === 2 ? 2 : 1; effectsVolume.value = saved.effects ?? .45; musicVolume.value = saved.music ?? .2; } } catch { /* Use defaults. */ }
    document.addEventListener('visibilitychange', onVisibility); document.addEventListener('keydown', onKey); void loadLobby();
});
onUnmounted(() => { skip(); document.removeEventListener('visibilitychange', onVisibility); document.removeEventListener('keydown', onKey); });
</script>

<template>
    <div class="app-shell" :class="{ 'low-motion': reduced }">
        <div :inert="playing">
        <header class="topbar">
            <button class="brand" :disabled="locked" @click="loadLobby"><span class="brand-seal">惡</span><span>我的反派學院<small>VILLAIN ACADEMY</small></span></button>
            <span class="edition">入學試煉 <b>01</b> / 13</span>
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
        <div v-if="uncertain" class="message" role="status">施招結果待確認。<button :disabled="busy || playing" @click="run ? submit(true) : loadLobby()">{{ run ? '重試原行動' : '重新連線取回對局' }}</button></div>
        <p v-if="busy && !playing" class="loading" role="status">正在確認學院紀錄…</p>
        <main>
            <template v-if="page === 'lobby'">
                <section class="hero">
                    <img class="hero-art" :src="asset('hero')" alt="學員帶著巨型畢業計畫，望向懸浮的陶片學院與虛構城市" fetchpriority="high">
                    <div class="hero-copy"><p class="eyebrow">十三禁術 · 從第一堂課開始</p><h1 tabindex="-1">超認真<br>毀滅新竹<span>計畫。</span></h1><p class="lead">城市有它的修復計畫。<br>而你，是計畫之外的那一筆。</p><button class="primary" :disabled="locked || !level" @click="start()">簽下入學計畫 <span>↗</span></button><p class="small">單人策略試煉 · 每局 {{ level?.max_turns ?? 10 }} 回合 · 預設靜音</p></div>
                    <span class="hero-caption">虛構城市演習 / 非真實災害預測</span>
                </section>
                <section class="lobby-bottom"><div><p class="eyebrow">CHAPTER 01</p><h2 tabindex="-1">第一禁術・枯潮</h2><p>枯潮教授・晏沉｜讓城市喊渴。<br>把城市核心韌性降至零，就是你的勝利。</p><p class="small">第 2～13 關仍在製作中。此版本開放完整第 1 關。</p></div><div class="save-list"><p class="eyebrow">你的試煉紀錄 · 本瀏覽器</p><p v-if="!savedRuns.length">還沒有紀錄。從第一次大膽的決策開始。</p><button v-for="saved in savedRuns" :key="saved.run_id" :disabled="locked" @click="openRun(saved.run_id)"><span>{{ saved.outcome === 'in_progress' ? '繼續試煉' : saved.outcome === 'player_victory' ? '毀滅成功・查看戰報' : '城市守住・查看戰報' }}</span><small>第 {{ saved.turn }} 回合 ↗</small></button><p class="small">匿名紀錄依賴本瀏覽器 Cookie；清除後無法找回。</p></div></section>
            </template>
            <template v-else-if="run && state">
                <section v-if="page === 'briefing'" class="briefing content-width">
                    <div class="briefing-art"><img :src="asset('yan-chen')" alt="枯潮教授晏沉，身穿深紫學院長袍，平靜地端著一只空杯"><span>枯潮教授 / 晏沉</span></div>
                    <div><p class="eyebrow">作戰簡報 · {{ level?.name }}</p><h1 tabindex="-1">讓城市<br>喊渴。</h1><blockquote class="professor-quote">「杯子空了，補水就好。城市空了呢？」<small>晏沉將空杯推到你面前。「這就是你今天的作業。」</small></blockquote><p class="lead">你有 {{ state.max_turns }} 回合。核心歸零便通關；回合用盡而城市仍站著，這次試煉就結束。</p><ol class="lesson"><li><b>讀預告。</b>每次施招後城市回應。第 3、6、9 回合會修復核心。</li><li><b>留擾序。</b>同系擾序可取消可打斷的預告。第 3 回合使用後，第 7 回合才能再用。</li><li><b>抓破綻。</b>破陣削弱防線，換系累積連攜。首次打斷還會獲得枯潮禁術返還的 2 點惡意。</li></ol><button class="primary" :disabled="locked" @click="enterBattle">明白了，開始試煉 ↗</button><p class="small">本局情境已凍結。回學院後可繼續，不用一次打完。</p></div>
                </section>
                <section v-if="page === 'battle'" class="battle content-width">
                    <div class="battle-heading"><div><p class="eyebrow">CHAPTER 01 / {{ level?.name }}</p><h1 tabindex="-1">讓城市喊渴</h1></div><div class="turn"><strong>{{ state.turn.toString().padStart(2, '0') }}</strong><span>/ {{ state.max_turns }} 回合</span></div></div>
                    <div class="battle-layout"><div class="battle-field">
                        <div class="city-stage"><img :src="asset('city')" alt="虚構丘陵城市的水系防守光網"><div class="core-panel"><span>城市核心韌性</span><strong>{{ state.core_resilience }}</strong><progress aria-label="城市核心韌性" :value="state.core_resilience" max="100"></progress></div><div class="intent"><small>施招後，城市將會…</small><p>{{ state.intent?.description ?? '對局已結束' }}</p></div></div>
                        <div class="defenses"><div v-for="element in elements" :key="element" :class="element"><b>{{ elementNames[element] }}系防線 <strong>{{ state.defenses[element] }}</strong></b><progress :aria-label="`${elementNames[element]}系防線`" :value="state.defenses[element]" max="100"></progress><small>抗性 {{ state.resistance[element] }} 層 · 印記 {{ state.sigils[element] }}/{{ state.sigil_cap }}</small></div></div>
                        <div v-if="state.shields.length || state.breach_available" class="window"><span v-if="state.breach_available">破綻已開啟・下一行動消耗</span><span v-for="(shield, index) in state.shields" :key="index">護盾 {{ shield.amount }}</span></div>
                        <div class="professor"><img :src="asset('yan-chen')" alt="端著空杯的枯潮教授晏沉"><div><small>晏沉的旁註</small><p>{{ hint }}</p></div></div>
                    </div><section class="command-panel" aria-label="禁術選擇"><div class="malice"><span>你的惡意</span><b>{{ state.malice }}<small> / {{ state.malice_cap }}</small></b></div><p class="small">選擇禁術，再確認施放。每招消耗一回合。</p>
                        <div class="skills"><button v-for="skill in choices" :key="skill.id" :class="[skill.element, { selected: selected === skill.id, unavailable: !!reasonFor(skill) }]" :aria-pressed="selected === skill.id" :disabled="locked || !run.compatible" @click="selected = skill.id"><span>{{ skillName(skill.id) }}</span><small>{{ reasonFor(skill) || `惡意 ${skill.malice_cost} · 冷卻 ${skill.cooldown}` }}</small></button></div>
                        <div v-if="currentSkill" class="skill-detail"><b>{{ skillName(currentSkill.id) }}</b><p>{{ description[currentSkill.kind] }}</p><small>基礎衝擊 {{ currentSkill.base_impact }} · 防線 {{ currentSkill.defense_delta }}<br>實際戰果受防線、抗性、護盾與情境影響。</small></div>
                        <button class="primary cast" :disabled="locked || !selectedChoice || !!selectedChoice.reason || !run.compatible" @click="submit()">{{ currentSkill && reasonFor(currentSkill) ? reasonFor(currentSkill) : `施放 ${skillName(selected)}` }} ↗</button>
                    </section></div>
                    <details class="forecast"><summary>查看完整城市預告與冷卻規則</summary><p>冷卻 {{ currentSkill?.cooldown }} 表示使用後的 {{ currentSkill?.cooldown }} 個決策回合不能再用該招。</p><ol><li v-for="intent in level?.forecast" :key="intent.scheduled_turn">{{ intent.description }}</li></ol></details>
                </section>
                <section v-if="page === 'report'" class="report content-width" :class="{ victory: run.outcome === 'player_victory' }"><img :src="asset(run.outcome === 'player_victory' ? 'victory' : 'city')" :alt="run.outcome === 'player_victory' ? '虛構城市化為懸浮陶片，金色光幕宣告枯潮試煉通關' : '守住的虛構城市'">
                    <div><p class="eyebrow">ACADEMY FIELD REPORT / 第一關戰報</p><h1 tabindex="-1">{{ run.outcome === 'player_victory' ? '毀滅成功。' : '城市守住了。' }}</h1><p class="lead">{{ run.outcome === 'player_victory' ? '第一禁術・枯潮，修習通過。晏沉抬杯，向你致意。' : '本次試煉結束。把城市的回應，變成下一次的計畫。' }}</p><p>第 {{ state.turn }} 回合 · 核心剩餘 {{ state.core_resilience }} · 共 {{ run.history.length }} 次行動</p><p>{{ run.outcome === 'player_victory' ? '晏沉的結語：「一座城市若把每次撐過去，都當成不必改變的理由，最後就會連下一次也沒有。」以下列出你如何使這一局走到終點。' : '晏沉收回空杯：「你讓它喘過氣了。看看是哪一回合。」以下依你的實際行動複盤，再挑一個決策重試。' }}</p><div class="report-actions"><button class="primary" :disabled="locked || !run.compatible" @click="start(true)">同情境再試一次 ↗</button><button :disabled="locked" @click="replay">重播本局演出</button><button :disabled="locked" @click="loadLobby">回學院</button></div></div>
                </section>
                <section v-if="page === 'report'" class="content-width"><h2>關鍵回合 · 依實際紀錄</h2><ol class="highlights"><li v-for="(event, index) in highlights" :key="index"><b>{{ event.turn === null ? '全局' : `第 ${event.turn} 回合` }}</b><span>{{ event.text }}</span></li></ol></section>
                <section class="content-width data-panel"><details :open="page === 'briefing'"><summary>本局情境情報 · 開局後不變</summary><p class="small">以下是遊戲情境修正，不是災害預測。正值有利進攻；缺值採中性修正。只有本關採用的系別會生效。</p><div class="data-grid"><div v-for="element in elements" :key="element"><b>{{ elementNames[element] }}系 {{ (run.scenario.modifiers[element] * 100).toFixed(1) }}%</b><p>{{ run.scenario.reasons[element]?.message }}</p></div></div><div v-if="!Object.keys(run.snapshots).length" class="message">舊局未保存來源品質。保留原情境數值，不以今日資料冒充。</div><div v-for="(snapshot, source) in run.snapshots" :key="source" class="source-row"><b>{{ sourceNames[source] ?? source }}</b><span>{{ qualityNames[snapshot.quality] ?? snapshot.quality }}</span><small>觀測：{{ snapshot.observed_at ?? (snapshot.period ? Object.values(snapshot.period).join(' / ') : '無觀測日期') }}</small><p v-for="warning in snapshot.warnings" :key="warning" class="small">{{ warning }}</p></div></details></section>
                <section v-if="run.history.length" class="content-width history"><details><summary>完整行動紀錄 · {{ run.history.length }} 次</summary><article v-for="entry in run.history" :key="entry.sequence"><h3>第 {{ entry.events[0]?.turn }} 回合 / {{ skillName(entry.input.skill_id) }}</h3><ul><li v-for="event in entry.events" :key="event.sequence">{{ eventText(event) }}</li></ul></article></details></section>
            </template>
        </main>
        <footer><span>世外高人 / 智慧沙盒創新計畫</span><span>虛構策略遊戲。城市被毀滅，是玩家勝利。</span><a href="/docs/ASSET-SOURCES.md" @click.prevent="notice = '圖片：OpenAI image_gen 原創生成。音效：Kenney Impact Sounds（CC0）。完整來源與提示詞見專案 assets/p04-generation.json、assets/third-party/manifest.json。'">素材來源</a></footer>
        </div>
        <div v-if="currentEvent" class="cutscene" :class="{ ultimate: currentEvent.cue_id.includes('ultimate'), triumph: currentEvent.after.outcome === 'player_victory' }" role="dialog" aria-modal="true" aria-label="戰鬥演出"><img :src="asset(cueImage(currentEvent))" :alt="eventText(currentEvent)"><div class="cutscene-copy"><p class="eyebrow">第 {{ currentEvent.turn }} 回合 / {{ currentEvent.target ? elementNames[currentEvent.target] + '系' : '第一禁術・枯潮' }}</p><h2>{{ currentEvent.cue_id.includes('ultimate') && currentEvent.type !== 'outcome' ? '萬川歸寂' : eventText(currentEvent) }}</h2><p v-if="currentEvent.type === 'outcome'">{{ currentEvent.after.outcome === 'player_victory' ? '枯潮修習通過。這一局，城市已無下一次。' : '城市尚存韌性。回到課堂，檢查這次的決策。' }}</p><p v-if="currentEvent.type === 'impact'">護盾吸收 {{ currentEvent.delta.absorbed }} · 命中前有效防線 {{ currentEvent.delta.effective_defense }}</p></div><button @click="skip">跳過演出 · Esc</button></div>
    </div>
</template>
