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
export const kindNames: Record<string, string> = { probe: '試探', breach: '破陣', disrupt: '擾序', gather: '蓄勢', ultimate: '空杯・終式' };
export function skillName(id: string): string { const [kind, element] = id.split('.'); return (element ? `${elementNames[element as Element]}系・` : '') + (kindNames[kind] ?? kind); }
export function unavailableText(choice: Choice | undefined, state: BattleState, skill: Skill): string {
    if (!choice) return '目前無法施放';
    if (choice.reason === 'on_cooldown') return `第 ${state.cooldowns[skill.id]} 回合可用`;
    if (choice.reason === 'not_enough_malice') return `需 ${skill.malice_cost} 惡意`;
    if (choice.reason === 'sigils_not_ready') return `三系印記各需 ${skill.required_sigils} 枚`;
    return choice.reason ? '目前無法施放' : '';
}
const eventNames: Record<string, string> = { action_accepted: '禁術發動', impact: '核心命中', defense_shift: '防線削弱', sigil_gain: '印記累積', sigil_spent: '印記解放', resistance_change: '抗性變化', combo: '跨系連攜', breach_opened: '破綻開啟', breach_consumed: '破綻追擊', interrupt: '成功打斷修復', interrupt_failed: '未能打斷', malice_refund: '使徒返還惡意', malice_recovered: '蓄勢回復', city_repair: '城市修復', city_reinforce: '城市補強', city_shield: '城市架盾', shield_absorbed: '護盾吸收', shield_expired: '護盾到期', phase_change: '階段轉換', turn_advanced: '進入下一回合', outcome: '對局結算' };
export function eventText(event: BattleEvent): string {
    if (event.type === 'outcome') return event.after.outcome === 'player_victory' ? '毀滅成功・學院認可' : '城市守住・本次試煉結束';
    let detail = '';
    if (typeof event.delta.core_resilience === 'number') detail = `｜核心 ${event.delta.core_resilience > 0 ? '+' : ''}${event.delta.core_resilience} → ${event.after.core_resilience}`;
    else if (typeof event.delta.malice === 'number') detail = `｜惡意 ${event.delta.malice > 0 ? '+' : ''}${event.delta.malice}`;
    return `${eventNames[event.type] ?? '戰況更新'}${detail}`;
}
export function cueImage(event: BattleEvent): string {
    if (event.cue_id.includes('ultimate') || event.type === 'outcome') return 'apostle';
    if (event.actor === 'city') return 'city';
    return event.target ?? 'apostle';
}
export function cueDuration(event: BattleEvent): number {
    if (event.cue_id.includes('ultimate')) return 2600;
    if (event.type === 'outcome') return event.after.outcome === 'player_victory' ? 8000 : 3000;
    if (['interrupt', 'breach_opened', 'combo'].includes(event.type)) return 1200;
    return 700;
}
export function visibleEvents(events: BattleEvent[]): BattleEvent[] {
    return events.filter(e => ['action_accepted', 'impact', 'interrupt', 'interrupt_failed', 'breach_opened', 'combo', 'city_repair', 'city_shield', 'city_reinforce', 'outcome'].includes(e.type));
}
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
        try { this.value = JSON.parse(storage.getItem('academy.pending') ?? 'null'); } catch { this.value = null; }
    }
    prepare(run: Run, choice: Choice): ActionInput {
        if (this.value) {
            if (this.value.runId !== run.run_id) throw new Error('另一局仍有待確認行動');
            return this.value.input;
        }
        const input = { action_id: crypto.randomUUID(), expected_version: run.version, skill_id: choice.skill_id, target: choice.target };
        this.value = { runId: run.run_id, input };
        this.storage.setItem('academy.pending', JSON.stringify(this.value));
        return input;
    }
    clear(): void { this.value = null; this.storage.removeItem('academy.pending'); }
}
