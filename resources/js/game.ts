export type Element = 'water' | 'heat' | 'land';
export type Outcome = 'in_progress' | 'player_victory' | 'city_held';
export type ActionType = 'reveal' | 'play' | 'swap' | 'timeout';
export type RunMode = 'challenge' | 'practice';
export type TurnPhase = 'awaiting_reveal' | 'decision';
export interface Intent { type: string; element: Element; magnitude: number; interruptible: boolean; scheduled_turn: number; description: string; level_phase_id?: string; conditional?: boolean }
export interface PhaseTriggerShape { type: string; value?: number; flag?: string; of?: PhaseTriggerShape[] }
export interface LevelPhase { id: string; order: number; label: string; objective: string; starts_when: PhaseTriggerShape; starts_when_summary: string; next_phase_summary: string | null }
export interface BattleState {
    turn: number; max_turns: number; core_resilience: number; malice: number; malice_cap: number; sigil_cap: number;
    defenses: Record<Element, number>; resistance: Record<Element, number>; sigils: Record<Element, number>;
    cooldowns: Record<string, number>; shields: { element: string; amount: number }[];
    combo_chain: Element[]; breach_available: boolean; intent: Intent | null; outcome: Outcome;
    // 牌桌狀態。抽牌堆只送剩餘張數，未抽牌序留在伺服器。
    deck: Record<string, string>; hand: string[]; kept_last_turn: string[]; discard_pile: string[]; draw_pile_count: number;
    hand_size: number; max_keep: number; turn_phase: TurnPhase; deadline_at: string | null;
    swap_used: boolean; timeouts: number;
    // 關卡幕次（P10-1 起）。舊存檔沒有這個欄位時為 null，視為第一幕。
    level_phase_id: string | null;
    // 機制狀態（stored-night 的 standby／overhaul，其餘一律 standard）。和幕次是兩回事。
    phase: string; flags: Record<string, unknown>;
}
export interface BattleEvent { sequence: number; turn: number; type: string; actor: string; target: Element | null; reason_code: string; cue_id: string; before: Record<string, unknown>; delta: Record<string, unknown>; after: Record<string, unknown> }
export interface Choice { type: ActionType; card_id: string | null; fixed: string | null; skill_id: string | null; target: Element | null; reason: string | null }
export interface ActionInput { action_id: string; expected_version: number; type: ActionType; card_id?: string | null; fixed?: string | null; keep?: string[] }
export interface History { sequence: number; input: ActionInput; events: BattleEvent[] }
export interface Snapshot { quality: string; observed_at: string | null; period: Record<string, unknown> | null; warnings?: string[] }
// 卡面。成本、冷卻與衝擊都由伺服器從技能表算好，前端不另存一份數值。
export interface Card { card: string; skill_id: string; name: string; text: string; role: string; kind: string; element: Element | null; malice_cost: number; cooldown: number; base_impact: number; defense_delta: number; required_sigils: number }
export interface DataNote { applied: boolean; modifier: number | null; reason: string | null }
export interface Run {
    run_id: string; level_id: string; mode: RunMode; rules_version: string; compatible: boolean; version: number;
    outcome: Outcome; state: BattleState; available_actions: Choice[]; cards: Record<string, Card>;
    snapshots: Record<string, Snapshot>; scenario: { modifiers: Record<Element, number>; reasons: Record<Element, { message: string }> };
    data_notes: Record<Element, DataNote>; history: History[]; server_time: string;
}
export interface Skill { id: string; kind: string; element: Element | null; malice_cost: number; cooldown: number; base_impact: number; defense_delta: number; required_sigils: number }
export interface Briefing { headline: string; quote: string; quote_note: string; lessons: string[] }
export interface Level { level_id: string; sequence: number; tier: 'main' | 'advanced'; available: boolean; name: string; subtitle: string; mechanic: string; lesson: string; briefing: Briefing | null; apostle: string; max_turns: number; requires: string | null; unlocked: boolean; practice_unlocked: boolean; best: { turns: number; run_id: string } | null; practice_best: { turns: number; run_id: string } | null; deck_size: number; initial_defenses: Record<Element, number>; reward: { options: string[] } | null; phases: LevelPhase[]; forecast: Intent[] }
export interface RewardOption { key: string; style: string; add: Card; remove: Card; remove_remaining: number }
export interface RewardOffer { level_id: string; level_name: string; prompt: string; options: RewardOption[]; chosen: string | null }
export interface Campaign {
    milestones: string[]; titles: Record<string, string>; deck: Record<string, number>; deck_choices: Record<string, string>;
    pending_reward: RewardOffer | null; main_cleared: boolean; stood_down: boolean; advanced_cleared: boolean;
    main_finale: string; advanced_finale: string;
}
/**
 * 這個模式下這一關能不能開。available 是「內容做完了沒有」，unlocked 是玩家進度，
 * 兩者都成立才可以開局；練習軌有自己的解鎖狀態。
 */
export function canPlay(level: Level, mode: RunMode): boolean {
    return level.available && (mode === 'practice' ? level.practice_unlocked : level.unlocked);
}
export function levelStatusText(level: Level, mode: RunMode): string {
    if (!level.available) return '內容製作中';
    if (!canPlay(level, mode)) return '先通過前一關';
    const best = mode === 'practice' ? level.practice_best : level.best;
    return best ? `最佳 ${best.turns} 回合` : `${level.max_turns} 回合 · 牌組 ${level.deck_size} 張`;
}
/** 下一關：還沒通關的第一個可玩關卡；全通了就回最後一個可玩的關卡。 */
export function nextPlayableLevel(levels: Level[], mode: RunMode): Level | undefined {
    const playable = levels.filter(level => canPlay(level, mode));
    return playable.find(level => !(mode === 'practice' ? level.practice_best : level.best)) ?? playable[playable.length - 1];
}
export type EndingKind = 'main' | 'advanced' | null;
/**
 * 這一局的勝利要不要接戰役終幕。主線最後一關通關＝完成主線目標，玩家可以收手
 * 結束；進階最後一關通關＝進階終幕。收手之後再打主線關卡不會重播終幕。
 */
export function endingFor(run: Pick<Run, 'outcome' | 'level_id'>, campaign: Campaign | undefined, mainFinale: string, advancedFinale: string): EndingKind {
    if (!campaign || run.outcome !== 'player_victory') return null;
    if (run.level_id === advancedFinale && campaign.advanced_cleared) return 'advanced';
    if (run.level_id === mainFinale && campaign.main_cleared && !campaign.stood_down) return 'main';
    return null;
}
export function deckSummary(deck: Record<string, number>, cards: Record<string, Card>): { name: string; count: number; role: string }[] {
    return Object.entries(deck)
        .filter(([, count]) => count > 0)
        .map(([type, count]) => ({ name: cards[type]?.name ?? type, count, role: cards[type]?.role ?? '' }));
}
export interface SavedRun { run_id: string; level_id: string; mode: RunMode; turn: number; outcome: Outcome }
export interface Settlement { events: BattleEvent[]; version: number; state: BattleState; replayed: boolean; server_time: string }
export const elements: Element[] = ['water', 'heat', 'land'];
export const elementNames = { water: '水', heat: '熱', land: '土地' };
export const kindNames: Record<string, string> = { probe: '試探', breach: '破陣', disrupt: '擾序', gather: '蓄勢', ultimate: '萬川歸寂' };
export function cardGuide(card: Card): { target: string; timing: string; example: string } {
    const element = card.element ? `${elementNames[card.element]}系` : '';
    const target = card.kind === 'gather' ? '回復你的惡意，不攻擊城市' : card.element ? `${element}防線 → 城市核心` : '三系合擊 → 城市核心';
    const guides: Record<string, { timing: string; example: string }> = {
        probe: { timing: '惡意不多時，低成本進攻並累積印記。', example: `例如：想累積${element}印記，就用這張試探；防線越強，核心傷害越少。` },
        breach: { timing: '想削弱防線，為後續攻擊開路時使用。', example: `例如：${element}防線還很厚，先用破陣削弱它；防線首次歸零會開啟破綻，下一個行動就會消耗它。` },
        disrupt: { timing: `城市預告「可打斷」且是${element}時使用。`, example: `例如：城市準備${element}修復，用同系擾序取消它；不同系或不可打斷的預告仍會執行。` },
        gather: { timing: '惡意不足或想緩解抗性時，花一回合準備。', example: '例如：手上的強牌付不起費用，先蓄勢回復惡意；這回合城市仍會照預告行動。' },
        ultimate: { timing: '三系印記都足夠時，找護盾少或破綻開啟的窗口。', example: '例如：三系印記已滿足需求，施放終招消耗印記攻擊核心；城市的任何護盾都能吸收它。' },
    };
    return { target, ...(guides[card.kind] ?? { timing: card.role, example: card.text }) };
}
export function briefingTimingText(mode: RunMode): string {
    return mode === 'practice'
        ? '進入戰鬥就會自動發牌；練習模式不限時，出牌演出後直接接下一手。'
        : '進入戰鬥就會自動發牌並開始 30 秒倒數；出牌演出後直接接下一個 30 秒。';
}
export function nextHandText(mode: RunMode): string {
    return mode === 'practice' ? '正在發下一手；練習模式不限時。' : '正在發下一手，接著立即開始 30 秒。';
}
export function shouldAutoReveal(run: Pick<Run, 'outcome' | 'compatible' | 'state'>): boolean {
    return run.compatible && run.outcome === 'in_progress' && run.state.turn_phase === 'awaiting_reveal';
}
export function keptCardsForPlay(keptCards: string[], playedCardId: string): string[] {
    return keptCards.filter(cardId => cardId !== playedCardId);
}
export function canKeepCard(state: Pick<BattleState, 'kept_last_turn'>, cardId: string): boolean {
    return !state.kept_last_turn.includes(cardId);
}
/** 簡報標題是設定檔裡的兩行字；不在前端硬寫任何一關的文案。 */
export function briefingLines(level: Level | undefined): string[] {
    return (level?.briefing?.headline ?? '').split('\n').filter(line => line.length > 0);
}
/** 完整預告：多幕關卡在每一幕的第一個回合前標出幕名（P10-2）；單一幕的關卡維持原樣。 */
export function forecastLines(level: Level | undefined): { turn: number; text: string }[] {
    const phases = level?.phases ?? [];
    const labels: Record<string, string> = Object.fromEntries(phases.map(phase => [phase.id, phase.label]));
    return (level?.forecast ?? []).map((intent, index, all) => {
        const phaseId = intent.level_phase_id;
        const startsAct = phases.length > 1 && phaseId !== undefined && phaseId !== all[index - 1]?.level_phase_id;
        return { turn: intent.scheduled_turn, text: startsAct ? `【${labels[phaseId] ?? phaseId}】${intent.description}` : intent.description };
    });
}
/**
 * P10-6 三幕顯示。這些函式只讀伺服器已經公開的欄位（state.level_phase_id、level.phases），
 * 不在前端推測幕次何時切換——條件幕要等引擎寫進局面才算進入。
 */

/** 這一局現在在哪一幕。舊存檔沒有 level_phase_id，依契約視為第一幕。 */
export function currentAct(level: Level | undefined, state: Pick<BattleState, 'level_phase_id'>): LevelPhase | undefined {
    const phases = level?.phases ?? [];
    return phases.find(phase => phase.id === state.level_phase_id) ?? phases[0];
}

/**
 * 條件幕：進入條件沒有任何回合門檻，所以開局前無法排程，必須標示出來。
 * 第 5 關的重整警報與最後一夜是目前唯二的例子（any_of 裡只要有一項帶回合就仍可排程）。
 */
export function isConditionalAct(phase: LevelPhase): boolean {
    const hasTurn = (trigger: PhaseTriggerShape): boolean =>
        trigger.type === 'turn_gte' || (trigger.of ?? []).some(hasTurn);
    return !hasTurn(phase.starts_when);
}

export type ActStatus = 'done' | 'current' | 'upcoming';
export interface ActStep { phase: LevelPhase; status: ActStatus; conditional: boolean }

/** 三幕進度條。current 之前的都算走過，之後的算未到；不猜條件幕會不會到。 */
export function actSteps(level: Level | undefined, state: Pick<BattleState, 'level_phase_id'>): ActStep[] {
    const phases = level?.phases ?? [];
    if (phases.length < 2) return [];
    const active = currentAct(level, state);
    const index = phases.findIndex(phase => phase.id === active?.id);
    return phases.map((phase, order) => ({
        phase,
        status: order < index ? 'done' : order === index ? 'current' : 'upcoming',
        conditional: isConditionalAct(phase),
    }));
}

/** 下一幕的進入條件；最後一幕回 null。條件幕明說它取決於這一局怎麼打。 */
export function nextActText(level: Level | undefined, state: Pick<BattleState, 'level_phase_id'>): string | null {
    const phases = level?.phases ?? [];
    const index = phases.findIndex(phase => phase.id === currentAct(level, state)?.id);
    const next = index < 0 ? undefined : phases[index + 1];
    if (!next) return null;
    const summary = next.starts_when_summary || '條件未公開';
    return isConditionalAct(next)
        ? `下一幕「${next.label}」：${summary}——沒有固定回合，取決於這一局的局面。`
        : `下一幕「${next.label}」：${summary}。`;
}

/** 簡報用的三行幕次說明：只給幕名與目標，完整預告仍然折疊在下方。 */
export function actBriefingLines(level: Level | undefined): string[] {
    const phases = level?.phases ?? [];
    if (phases.length < 2) return [];
    return phases.map(phase => {
        const conditional = isConditionalAct(phase) ? '（條件幕，沒有固定回合）' : '';
        return `${phase.label}${conditional}：${phase.objective}`;
    });
}

export interface ActReportSection { id: string; label: string; turns: number[]; reached: boolean; lines: string[] }

/**
 * 戰報分幕：用本局實際的 level_phase_change 事件切，沒有到的幕明說沒有到。
 * 只敘述紀錄到的後果，不推論玩家意圖。
 */
export function reportActs(run: Run, level: Level | undefined): ActReportSection[] {
    const phases = level?.phases ?? [];
    if (phases.length < 2) return [];
    const events = run.history.flatMap(entry => entry.events);
    const starts = new Map<string, number>([[phases[0]!.id, 1]]);
    for (const event of events) {
        if (event.type === 'level_phase_change' && typeof event.after.level_phase_id === 'string') {
            const from = event.after.from_turn;
            starts.set(event.after.level_phase_id, typeof from === 'number' ? from : event.turn + 1);
        }
    }
    const lastTurn = events.findLast(event => event.type === 'outcome')?.turn ?? run.state.turn;
    const ordered = phases.filter(phase => starts.has(phase.id));
    return phases.map(phase => {
        const from = starts.get(phase.id);
        if (from === undefined) {
            return { id: phase.id, label: phase.label, turns: [], reached: false, lines: [`本局沒有進入這一幕（${phase.objective}）。`] };
        }
        const position = ordered.findIndex(candidate => candidate.id === phase.id);
        const nextFrom = starts.get(ordered[position + 1]?.id ?? '') ?? lastTurn + 1;
        const turns: number[] = [];
        for (let turn = from; turn < nextFrom && turn <= lastTurn; turn++) turns.push(turn);
        return { id: phase.id, label: phase.label, turns, reached: true, lines: actLines(events, turns) };
    });
}

/** 某一幕裡實際發生的事；沒有可敘述的紀錄就說沒有，不補空話。 */
function actLines(events: BattleEvent[], turns: number[]): string[] {
    const within = events.filter(event => turns.includes(event.turn));
    const lines: string[] = [];
    const damage = within.filter(event => event.type === 'impact' && Number(event.delta.core_resilience) < 0)
        .reduce((sum, event) => sum - Number(event.delta.core_resilience), 0);
    if (damage) lines.push(`這一幕你對核心造成 ${damage} 點損失。`);
    const repaired = within.filter(event => event.type === 'city_repair' && Number(event.delta.core_resilience) > 0)
        .reduce((sum, event) => sum + Number(event.delta.core_resilience), 0);
    if (repaired) lines.push(`城市在這一幕修回 ${repaired} 點核心。`);
    const interrupts = within.filter(event => event.type === 'interrupt');
    if (interrupts.length) lines.push(`你在第 ${interrupts.map(event => event.turn).join('、')} 回合打斷城市行動，共 ${interrupts.length} 次。`);
    const failed = within.filter(event => event.type === 'interrupt_failed');
    if (failed.length) lines.push(`第 ${failed.map(event => event.turn).join('、')} 回合的擾序沒有取消城市行動：需同系且預告可打斷。`);
    const missed = within.filter(event => event.type === 'action_missed');
    if (missed.length) lines.push(`第 ${missed.map(event => event.turn).join('、')} 回合逾時，共 ${missed.length} 次沒有出手。`);
    if (!lines.length) lines.push('這一幕沒有命中、修復或打斷的紀錄。');
    return lines;
}

export function soundStatusText(muted: boolean): string {
    return muted ? '目前靜音' : '目前有聲';
}
/** P06 資產只依既有穩定的關卡與事件 ID 選擇；圖片不參與戰鬥結算。 */
export function sceneAsset(levelId: string): string {
    return levelId === 'empty-cup' ? 'city' : `scene-${levelId}`;
}
export function apostleAsset(levelId: string): string {
    return `apostle-${levelId}`;
}
export function briefingAsset(levelId: string): string {
    return levelId === 'stored-night' ? 'yan-chen-pleased' : 'yan-chen-stern';
}
export function reportAsset(outcome: Outcome, levelId: string): string {
    if (outcome === 'city_held') return levelId === 'stored-night' ? 'defense-success-2' : 'defense-success-1';
    if (levelId === 'stored-night') return 'victory-3';
    return levelId === 'empty-cup' ? 'victory' : 'victory-2';
}
export function skillName(id: string): string { const [kind, element] = id.split('.'); return (element ? `${elementNames[element as Element]}系・` : '') + (kindNames[kind] ?? kind); }
export function unavailableText(choice: Choice | undefined, state: BattleState, card: Card): string {
    if (!choice) return '目前無法施放';
    if (choice.reason === 'on_cooldown') return `第 ${state.cooldowns[card.skill_id]} 回合可用`;
    if (choice.reason === 'not_enough_malice') return `需 ${card.malice_cost} 惡意`;
    if (choice.reason === 'sigils_not_ready') return `三系印記各需 ${card.required_sigils} 枚`;
    if (choice.reason === 'swap_already_used') return '這一回合已經換過牌';
    if (choice.reason === 'nothing_to_swap') return '沒有牌可以換';
    return choice.reason ? '目前無法施放' : '';
}
// 這一局實際生效的資料修正。沒被本關採用的系別明說「本關未採用」，不做裝飾性的假加成。
export function dataNoteText(note: DataNote | undefined, element: Element | null): string {
    if (!element) return '本局結算依實際事件紀錄。';
    if (!note || !note.applied || note.modifier === null) return '本關未採用這一系的資料。';
    const percent = (note.modifier * 100).toFixed(1);
    return `${elementNames[element]}情修正 ${note.modifier >= 0 ? '+' : ''}${percent}%（本局）`;
}
// 伺服器截止時間減去伺服器現在時間，就是還剩幾秒；客戶端的本機時鐘不參與判定。
export function secondsLeft(deadlineAt: string | null, serverOffsetMs: number, now = Date.now()): number | null {
    if (!deadlineAt) return null;
    return Math.max(0, Math.ceil((Date.parse(deadlineAt) - (now + serverOffsetMs)) / 1000));
}
export function serverOffset(serverTime: string, now = Date.now()): number {
    return Date.parse(serverTime) - now;
}
const eventNames: Record<string, string> = { hand_revealed: '揭示手牌', card_swapped: '換牌', hand_settled: '整理手牌', action_missed: '錯失行動', action_accepted: '禁術發動', impact: '核心命中', defense_shift: '防線削弱', sigil_gain: '印記累積', sigil_spent: '印記解放', resistance_change: '抗性變化', combo: '跨系連攜', breach_opened: '破綻開啟', breach_consumed: '破綻追擊', interrupt: '成功打斷修復', interrupt_failed: '未能打斷', malice_refund: '枯潮返還惡意', malice_recovered: '蓄勢回復', city_repair: '城市修復', city_reinforce: '城市補強', city_shield: '城市架盾', shield_absorbed: '護盾吸收', shield_expired: '護盾到期', phase_change: '階段轉換', level_phase_change: '進入下一幕', turn_advanced: '進入下一回合', outcome: '對局結算' };
export function eventText(event: BattleEvent): string {
    if (event.type === 'outcome') return event.after.outcome === 'player_victory' ? '毀滅成功・學院認可' : '城市守住・本次試煉結束';
    let detail = '';
    if (event.type === 'level_phase_change' && typeof event.after.label === 'string') detail = `｜${event.after.label}`;
    else if (typeof event.delta.core_resilience === 'number') detail = `｜核心 ${event.delta.core_resilience > 0 ? '+' : ''}${event.delta.core_resilience} → ${event.after.core_resilience}`;
    else if (typeof event.delta.malice === 'number') detail = `｜惡意 ${event.delta.malice > 0 ? '+' : ''}${event.delta.malice}`;
    return `${eventNames[event.type] ?? '戰況更新'}${detail}`;
}
export function cueImage(event: BattleEvent, levelId = 'empty-cup'): string {
    if (event.type === 'outcome') return reportAsset(event.after.outcome as Outcome, levelId);
    if (event.cue_id.includes('ultimate')) return 'skill-ultimate';
    if (event.actor === 'city') return sceneAsset(levelId);
    if (event.target && levelId !== 'empty-cup') return `skill-${event.target}-2`;
    return event.target ?? apostleAsset(levelId);
}
export function cueDuration(event: BattleEvent): number {
    if (event.type === 'outcome') return event.after.outcome === 'player_victory' ? 8000 : 3000;
    // 進入下一幕只要一個短提示；它在結算階段播，揭牌與 30 秒倒數都在它之後才開始。
    if (event.type === 'level_phase_change') return 900;
    if (event.cue_id.includes('ultimate')) return 2600;
    if (['interrupt', 'breach_opened', 'combo'].includes(event.type)) return 1200;
    return 700;
}
export function visibleEvents(events: BattleEvent[]): BattleEvent[] {
    return events.filter(e => ['action_accepted', 'action_missed', 'impact', 'interrupt', 'interrupt_failed', 'breach_opened', 'combo', 'city_repair', 'city_shield', 'city_reinforce', 'level_phase_change', 'outcome'].includes(e.type));
}
export interface ReportFinding { turn: number | null; text: string }
export interface ReportSummary { turn: number; initialCore: number | null; condition: string; finalMove: string; findings: ReportFinding[] }
export function reportSummary(run: Run): ReportSummary {
    const events = run.history.flatMap(entry => entry.events);
    const outcome = events.findLast(event => event.type === 'outcome');
    const turn = outcome?.turn ?? run.state.turn;
    const initialCore = events.find(event => typeof event.before.core_resilience === 'number')?.before.core_resilience;
    const finalHit = events.findLast(event => event.type === 'impact' && event.after.core_resilience === 0 && Number(event.delta.core_resilience) < 0);
    const entry = finalHit ? run.history.find(history => history.events.includes(finalHit)) : undefined;
    const condition = run.outcome === 'player_victory'
        ? '城市核心已歸零，達成通關目標。'
        : outcome?.reason_code === 'turns_exhausted' ? `${run.state.max_turns} 回合已用完，城市核心仍未歸零。` : '城市守住；結束條件以完整紀錄為準。';
    const finalMove = run.outcome === 'player_victory'
        ? finalHit ? `第 ${finalHit.turn} 回合，你用「${playedName(run, entry?.input)}」把核心從 ${finalHit.before.core_resilience} 打到 0。` : '紀錄沒有保留最後一擊的細節。'
        : `結束時還差 ${run.state.core_resilience} 點核心；可從下方關鍵回合找出重試的時機。`;
    const findings: ReportFinding[] = [];
    const strongest = events.filter(event => event.type === 'impact' && Number(event.delta.core_resilience) < 0).sort((a, b) => Number(a.delta.core_resilience) - Number(b.delta.core_resilience))[0];
    if (strongest) {
        const action = run.history.find(history => history.events.includes(strongest));
        findings.push({ turn: strongest.turn, text: `「${playedName(run, action?.input)}」命中核心，削減 ${-Number(strongest.delta.core_resilience)} 點，是本局最大一擊。${events.some(event => event.turn === strongest.turn && event.type === 'breach_consumed' && event.reason_code !== 'missed_action_wasted_breach') ? '這一擊用到了破綻。' : ''}` });
    }
    const interrupts = events.filter(event => event.type === 'interrupt');
    if (interrupts.length) findings.push({ turn: null, text: `第 ${interrupts.map(event => event.turn).join('、')} 回合，同系擾序成功取消 ${interrupts.length} 次城市行動，這些預告沒有執行。` });
    const repairs = events.filter(event => event.type === 'city_repair' && Number(event.delta.core_resilience) > 0);
    if (repairs.length) findings.push({ turn: null, text: `第 ${repairs.map(event => event.turn).join('、')} 回合，城市修復共補回 ${repairs.reduce((sum, event) => sum + Number(event.delta.core_resilience), 0)} 點核心，抵銷了部分進攻。` });
    const missed = events.filter(event => event.type === 'action_missed');
    if (missed.length) findings.push({ turn: null, text: `第 ${missed.map(event => event.turn).join('、')} 回合逾時，共 ${missed.length} 次沒有出手，城市仍照預告行動。` });
    const gathered = run.history.filter(entry => entry.input.type === 'play' && entry.input.fixed === 'gather');
    if (gathered.length) findings.push({ turn: null, text: `你用了 ${gathered.length} 回合蓄勢，這些回合沒有攻擊核心；城市仍照預告行動。` });
    if (!findings.length) findings.push({ turn: null, text: '沒有足夠的命中、修復或打斷紀錄可整理原因；請查看完整行動紀錄。' });
    return { turn, initialCore: typeof initialCore === 'number' ? initialCore : null, condition, finalMove, findings: findings.slice(0, 3) };
}
// 一次行動打出的是哪一張牌。牌名優先，找不到牌就退回招式代碼的名稱。
export function playedName(run: Run, input: ActionInput | undefined): string {
    if (!input) return '未知行動';
    if (input.type === 'timeout') return '逾時錯失行動';
    if (input.type === 'reveal') return '揭示手牌';
    if (input.type === 'swap') return '換牌';
    const cardType = input.card_id ? run.state.deck[input.card_id] : null;
    if (cardType && run.cards[cardType]) return run.cards[cardType]!.name;
    const fixed = Object.values(run.cards).find(card => card.skill_id === input.fixed);
    return fixed ? fixed.name : skillName(input.fixed ?? '');
}
// Describe recorded consequences only; combat calculations remain on the server.
export function reportFindings(run: Run): ReportFinding[] {
    const events = run.history.flatMap(entry => entry.events);
    const findings: ReportFinding[] = [];
    const interrupts = events.filter(event => event.type === 'interrupt');
    const repairs = events.filter(event => event.type === 'city_repair' && Number(event.delta.core_resilience) > 0);
    if (interrupts.length) findings.push({ turn: interrupts[0]!.turn, text: `你在第 ${interrupts.map(event => event.turn).join('、')} 回合取消城市行動，共 ${interrupts.length} 次。${run.outcome === 'player_victory' ? '城市把回復寄望於固定節奏，而你讀懂了它。' : '這些打斷已生效；其餘回合的進攻仍要跟上。'}` });
    if (repairs.length) findings.push({ turn: repairs[0]!.turn, text: `第 ${repairs.map(event => event.turn).join('、')} 回合，城市實際修回 ${repairs.reduce((sum, event) => sum + Number(event.delta.core_resilience), 0)} 點核心。${run.outcome === 'player_victory' ? '補回數字，沒有補上被你持續施壓的缺口。' : '這些回復抵銷了你的部分攻勢；下次可比較擾序的時機。'}` });
    const failed = events.filter(event => event.type === 'interrupt_failed');
    if (failed.length) findings.push({ turn: failed[0]!.turn, text: `第 ${failed.map(event => event.turn).join('、')} 回合的擾序未取消城市行動：需同系且預告可打斷。命中傷害仍以事件紀錄為準。` });
    const strongest = events.filter(event => event.type === 'impact').sort((a, b) => Number(a.delta.core_resilience) - Number(b.delta.core_resilience))[0];
    if (strongest && Number(strongest.delta.core_resilience) < 0) {
        const action = run.history.find(entry => entry.events.includes(strongest));
        findings.push({ turn: strongest.turn, text: `${playedName(run, action?.input)}造成本局最大單次核心損失 ${-Number(strongest.delta.core_resilience)} 點。${events.some(event => event.turn === strongest.turn && event.type === 'breach_consumed') ? '這一擊確實用到了破綻窗口。' : '完整紀錄保留了當時的護盾與防線。'}` });
    }
    const gathered = run.history.filter(entry => entry.input.fixed === 'gather').length;
    if (gathered) findings.push({ turn: null, text: `你用了 ${gathered} 回合蓄勢。它回復資源但不直接衝擊核心，城市仍照預告行動。${run.outcome === 'city_held' ? '下一次，把蓄起的惡意換成攻勢。' : ''}` });
    const missed = events.filter(event => event.type === 'action_missed');
    if (missed.length) findings.push({ turn: missed[0]!.turn, text: `第 ${missed.map(event => event.turn).join('、')} 回合逾時，共 ${missed.length} 次。這些回合你沒有出手，城市照預告行動；${events.some(event => event.reason_code === 'missed_action_wasted_breach') ? '其中至少一次還讓已經開啟的破綻窗口過期。' : '手牌全部進了棄牌堆。'}` });
    const kept = run.history.filter(entry => (entry.input.keep ?? []).length > 0);
    if (kept.length) findings.push({ turn: kept[0]!.events[0]?.turn ?? null, text: `你留了 ${kept.reduce((sum, entry) => sum + (entry.input.keep ?? []).length, 0)} 張牌到下一回合，分佈在 ${kept.length} 個回合。留牌的代價是那幾回合少看到新牌。` });
    const swaps = run.history.filter(entry => entry.input.type === 'swap');
    if (swaps.length) findings.push({ turn: swaps[0]!.events[0]?.turn ?? null, text: `你用掉 ${swaps.length} 次免費換牌。換掉的牌當回合離開可抽集合，所以換走的那一張不會立刻回到手上。` });
    return findings;
}
export interface CounterfactualComparison {
    sequence: number; strategy: string;
    actual: { outcome: Outcome; turns: number; core_remaining: number };
    counterfactual: { outcome: Outcome; turns: number; core_remaining: number; diverged: boolean };
    continuation: { turn: number; type: string; skill_id: string | null; target: Element | null }[];
}
/** P07 反事實比較：換掉一次已結算的決策，交給模擬策略打完剩下的回合。 */
export function fetchCounterfactual(runId: string, sequence: number, alternative: { type: 'play' | 'timeout'; card_id?: string; fixed?: string }): Promise<CounterfactualComparison> {
    return api<CounterfactualComparison>(`/runs/${runId}/counterfactual`, { sequence, ...alternative });
}
export function counterfactualText(result: CounterfactualComparison): string {
    const label = (outcome: Outcome) => outcome === 'player_victory' ? '毀滅成功' : '城市守住';
    const detail = `第 ${result.counterfactual.turns} 回合、核心剩餘 ${result.counterfactual.core_remaining}`;
    return result.counterfactual.diverged
        ? `換成這個選擇，模擬（${result.strategy}策略接手）會走向${label(result.counterfactual.outcome)}（${detail}），和實際的${label(result.actual.outcome)}不一樣。`
        : `換成這個選擇，模擬（${result.strategy}策略接手）仍然是${label(result.counterfactual.outcome)}（${detail}），實際結果沒有被這一手決定。`;
}
export class PendingStorageError extends Error {}
export class ApiError extends Error {
    constructor(public status: number, message: string, public reason: string, public retryAfter: number) { super(message); }
}
export async function api<T>(path: string, body?: unknown): Promise<T> {
    const controller = new AbortController();
    const timer = setTimeout(() => controller.abort(), 15000);
    try {
        const response = await fetch(`/api/v1${path}`, {
            method: body === undefined ? 'GET' : 'POST', credentials: 'same-origin', signal: controller.signal,
            headers: { Accept: 'application/json', 'Content-Type': 'application/json', 'X-CSRF-TOKEN': document.querySelector<HTMLMetaElement>('meta[name="csrf-token"]')?.content ?? '' },
            ...(body === undefined ? {} : { body: JSON.stringify(body) }),
        });
        const data = await response.json().catch(() => ({}));
        if (!response.ok) throw new ApiError(response.status, data.message ?? '請求未完成', data.reason_code ?? '', Number(response.headers.get('Retry-After') ?? 0));
        return data as T;
    } finally { clearTimeout(timer); }
}
// Only uncertain submissions persist; the exact identifier and payload are reused after a lost response.
export class PendingAction {
    value: { runId: string; input: ActionInput } | null = null;
    constructor(private storage: Pick<Storage, 'getItem' | 'setItem' | 'removeItem'>) {
        try {
            const value = JSON.parse(storage.getItem('academy.pending') ?? 'null');
            const input = value?.input;
            if (typeof value?.runId === 'string' && /^[a-zA-Z0-9-]+$/.test(value.runId)
                && typeof input?.action_id === 'string' && input.action_id.length > 0
                && Number.isInteger(input.expected_version) && input.expected_version > 0
                && ['reveal', 'play', 'swap', 'timeout'].includes(input.type)
                && (input.card_id == null || typeof input.card_id === 'string')
                && (input.fixed == null || typeof input.fixed === 'string')
                && (input.keep === undefined || Array.isArray(input.keep))) this.value = value;
        } catch { this.value = null; }
    }
    prepare(run: Run, choice: Pick<Choice, 'type' | 'card_id' | 'fixed'>, keep: string[] = []): ActionInput {
        if (this.value) {
            if (this.value.runId !== run.run_id) throw new Error('另一局仍有待確認行動');
            return this.value.input;
        }
        const input: ActionInput = { action_id: crypto.randomUUID(), expected_version: run.version, type: choice.type, card_id: choice.card_id, fixed: choice.fixed, keep };
        const value = { runId: run.run_id, input };
        try { this.storage.setItem('academy.pending', JSON.stringify(value)); }
        catch { throw new PendingStorageError('瀏覽器無法保存待確認行動，本次尚未送出。請允許此網站使用工作階段儲存後再試。'); }
        this.value = value;
        return input;
    }
    clear(): void { this.storage.removeItem('academy.pending'); this.value = null; }
}
