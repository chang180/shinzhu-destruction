export type Element = 'water' | 'heat' | 'land';
export type Outcome = 'in_progress' | 'player_victory' | 'city_held';
export interface Intent { type: string; element: Element; magnitude: number; interruptible: boolean; scheduled_turn: number; description: string }
export interface BattleState {
    turn: number; max_turns: number; core_resilience: number; malice: number; malice_cap: number; sigil_cap: number;
    defenses: Record<Element, number>; resistance: Record<Element, number>; sigils: Record<Element, number>;
    cooldowns: Record<string, number>; shields: { element: string; amount: number }[];
    combo_chain: Element[]; breach_available: boolean; intent: Intent | null; outcome: Outcome;
}
export interface BattleEvent { sequence: number; turn: number; type: string; actor: string; target: Element | null; reason_code: string; cue_id: string; before: Record<string, unknown>; delta: Record<string, unknown>; after: Record<string, unknown> }
export interface Choice { skill_id: string; target: Element | null; reason: string | null }
export interface ActionInput { action_id: string; expected_version: number; skill_id: string; target: Element | null }
export interface History { sequence: number; input: ActionInput; events: BattleEvent[] }
export interface Snapshot { quality: string; observed_at: string | null; period: Record<string, unknown> | null; warnings?: string[] }
export interface Run { run_id: string; level_id: string; rules_version: string; compatible: boolean; version: number; outcome: Outcome; state: BattleState; available_actions: Choice[]; snapshots: Record<string, Snapshot>; scenario: { modifiers: Record<Element, number>; reasons: Record<Element, { message: string }> }; history: History[] }
export interface Skill { id: string; kind: string; element: Element | null; malice_cost: number; cooldown: number; base_impact: number; defense_delta: number; required_sigils: number }
export interface Level { level_id: string; name: string; apostle: string; max_turns: number; unlocked: boolean; initial_defenses: Record<Element, number>; forecast: Intent[] }
export interface SavedRun { run_id: string; level_id: string; turn: number; outcome: Outcome }
export interface Settlement { events: BattleEvent[]; version: number; state: BattleState; replayed: boolean }
export const elements: Element[] = ['water', 'heat', 'land'];
export const elementNames = { water: '水', heat: '熱', land: '土地' };
export const kindNames: Record<string, string> = { probe: '試探', breach: '破陣', disrupt: '擾序', gather: '蓄勢', ultimate: '萬川歸寂' };
export function skillName(id: string): string { const [kind, element] = id.split('.'); return (element ? `${elementNames[element as Element]}系・` : '') + (kindNames[kind] ?? kind); }
export function unavailableText(choice: Choice | undefined, state: BattleState, skill: Skill): string {
    if (!choice) return '目前無法施放';
    if (choice.reason === 'on_cooldown') return `第 ${state.cooldowns[skill.id]} 回合可用`;
    if (choice.reason === 'not_enough_malice') return `需 ${skill.malice_cost} 惡意`;
    if (choice.reason === 'sigils_not_ready') return `三系印記各需 ${skill.required_sigils} 枚`;
    return choice.reason ? '目前無法施放' : '';
}
const eventNames: Record<string, string> = { action_accepted: '禁術發動', impact: '核心命中', defense_shift: '防線削弱', sigil_gain: '印記累積', sigil_spent: '印記解放', resistance_change: '抗性變化', combo: '跨系連攜', breach_opened: '破綻開啟', breach_consumed: '破綻追擊', interrupt: '成功打斷修復', interrupt_failed: '未能打斷', malice_refund: '枯潮返還惡意', malice_recovered: '蓄勢回復', city_repair: '城市修復', city_reinforce: '城市補強', city_shield: '城市架盾', shield_absorbed: '護盾吸收', shield_expired: '護盾到期', phase_change: '階段轉換', turn_advanced: '進入下一回合', outcome: '對局結算' };
export function eventText(event: BattleEvent): string {
    if (event.type === 'outcome') return event.after.outcome === 'player_victory' ? '毀滅成功・學院認可' : '城市守住・本次試煉結束';
    let detail = '';
    if (typeof event.delta.core_resilience === 'number') detail = `｜核心 ${event.delta.core_resilience > 0 ? '+' : ''}${event.delta.core_resilience} → ${event.after.core_resilience}`;
    else if (typeof event.delta.malice === 'number') detail = `｜惡意 ${event.delta.malice > 0 ? '+' : ''}${event.delta.malice}`;
    return `${eventNames[event.type] ?? '戰況更新'}${detail}`;
}
export function cueImage(event: BattleEvent): string {
    if (event.type === 'outcome') return event.after.outcome === 'player_victory' ? 'victory' : 'city';
    if (event.cue_id.includes('ultimate')) return 'apostle';
    if (event.actor === 'city') return 'city';
    return event.target ?? 'apostle';
}
export function cueDuration(event: BattleEvent): number {
    if (event.type === 'outcome') return event.after.outcome === 'player_victory' ? 8000 : 3000;
    if (event.cue_id.includes('ultimate')) return 2600;
    if (['interrupt', 'breach_opened', 'combo'].includes(event.type)) return 1200;
    return 700;
}
export function visibleEvents(events: BattleEvent[]): BattleEvent[] {
    return events.filter(e => ['action_accepted', 'impact', 'interrupt', 'interrupt_failed', 'breach_opened', 'combo', 'city_repair', 'city_shield', 'city_reinforce', 'outcome'].includes(e.type));
}
export interface ReportFinding { turn: number | null; text: string }
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
        findings.push({ turn: strongest.turn, text: `${skillName(action?.input.skill_id ?? '')}造成本局最大單次核心損失 ${-Number(strongest.delta.core_resilience)} 點。${events.some(event => event.turn === strongest.turn && event.type === 'breach_consumed') ? '這一擊確實用到了破綻窗口。' : '完整紀錄保留了當時的護盾與防線。'}` });
    }
    const gathered = run.history.filter(entry => entry.input.skill_id === 'gather').length;
    if (gathered) findings.push({ turn: null, text: `你用了 ${gathered} 回合蓄勢。它回復資源但不直接衝擊核心，城市仍照預告行動。${run.outcome === 'city_held' ? '下一次，把蓄起的惡意換成攻勢。' : ''}` });
    return findings;
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
                && typeof input.skill_id === 'string' && input.skill_id.length > 0
                && (input.target === null || elements.includes(input.target))) this.value = value;
        } catch { this.value = null; }
    }
    prepare(run: Run, choice: Choice): ActionInput {
        if (this.value) {
            if (this.value.runId !== run.run_id) throw new Error('另一局仍有待確認行動');
            return this.value.input;
        }
        const input = { action_id: crypto.randomUUID(), expected_version: run.version, skill_id: choice.skill_id, target: choice.target };
        const value = { runId: run.run_id, input };
        try { this.storage.setItem('academy.pending', JSON.stringify(value)); }
        catch { throw new PendingStorageError('瀏覽器無法保存待確認行動，本次尚未送出。請允許此網站使用工作階段儲存後再試。'); }
        this.value = value;
        return input;
    }
    clear(): void { this.storage.removeItem('academy.pending'); this.value = null; }
}
