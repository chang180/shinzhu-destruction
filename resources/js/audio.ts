import type { BattleEvent } from './game';

export type FinaleSound = 'main' | 'advanced' | null;
type Track = 'effect' | 'music';
interface Voice { clip: HTMLAudioElement; track: Track; emphasis: boolean; cancelled: boolean }

/** Playback never controls turns, animation completion or outcomes. */
export class BattleAudio {
    private context: AudioContext | null = null;
    private active = new Set<Voice>();
    private ambience: Voice | null = null;
    private ambienceKey: string | null = null;
    private castTurns = new Set<number>();
    private timers = new Set<ReturnType<typeof setTimeout>>();
    private unlocked = false;
    muted = true;
    effectsVolume = 0.45;
    musicVolume = 0.2;

    async unlock(): Promise<void> {
        this.unlocked = true;
        try { this.context ??= new AudioContext(); await this.context.resume(); } catch { /* HTML audio remains optional. */ }
    }
    beginPresentation(): void { this.castTurns.clear(); }
    private allowed(): boolean { return this.unlocked && !this.muted && !document.hidden; }
    private volume(track: Track): number {
        const value = track === 'music' ? this.musicVolume : this.effectsVolume;
        return Number.isFinite(value) ? Math.max(0, Math.min(1, value)) : 0;
    }
    private finish(voice: Voice): void {
        voice.cancelled = true;
        voice.clip.pause();
        voice.clip.onended = null;
        voice.clip.onerror = null;
        this.active.delete(voice);
        if (this.ambience === voice) { this.ambience = null; this.ambienceKey = null; }
        if (this.allowed()) this.updateVolumes();
    }
    updateVolumes(): void {
        if (!this.allowed()) { this.stop(); return; }
        const duck = [...this.active].some(voice => voice.emphasis);
        for (const voice of this.active) voice.clip.volume = this.volume(voice.track);
        if (this.ambience) this.ambience.clip.volume = this.volume('music') * (duck ? 0.12 : 0.45);
    }
    private start(key: string, track: Track = 'effect', emphasis = false, loop = false, rate = 1): Voice | null {
        if (!this.allowed() || this.volume(track) === 0) return null;
        if (!loop && this.active.size >= 8) {
            const oldest = this.active.values().next().value;
            if (oldest) this.finish(oldest);
        }
        const clip = new Audio();
        const ogg = !!clip.canPlayType('audio/ogg; codecs="vorbis"');
        clip.src = `/assets/visual-v2/audio/${key}.${ogg ? 'ogg' : 'mp3'}`;
        clip.preload = 'auto'; clip.loop = loop; clip.playbackRate = rate;
        clip.volume = this.volume(track) * (loop ? 0.45 : 1);
        const voice: Voice = { clip, track, emphasis, cancelled: false };
        if (loop) { this.ambience = voice; this.ambienceKey = key; }
        else this.active.add(voice);
        let fallback = false;
        const fail = (attempt = 0): void => {
            if (voice.cancelled) return;
            if (attempt === 0 && fallback) return;
            if (ogg && !fallback && this.allowed()) {
                fallback = true;
                clip.src = `/assets/visual-v2/audio/${key}.mp3`;
                clip.onerror = () => fail(1);
                try { void clip.play().catch(() => fail(1)); } catch { fail(1); }
            } else this.finish(voice);
        };
        clip.onended = () => this.finish(voice);
        clip.onerror = () => fail();
        try { void clip.play().catch(() => fail()); } catch { fail(); }
        this.updateVolumes();
        return voice;
    }
    setAmbience(levelId: string | null): void {
        const key = levelId && ['noon-fold', 'meter-feast', 'mirror-shade', 'stored-night'].includes(levelId) ? `ambience-${levelId}` : null;
        if (this.ambienceKey === key && this.allowed() && this.volume('music') > 0) return;
        if (this.ambience) this.finish(this.ambience);
        if (key) this.start(key, 'music', false, true);
    }
    ui(key: 'confirm' | 'unable' | 'unlock' | 'toggle'): void { this.start(`ui-${key}`); }
    countdown(): void { this.start('overhaul', 'effect', false, false, 1.15); }
    stopEffects(): void {
        for (const timer of this.timers) clearTimeout(timer);
        this.timers.clear();
        for (const voice of [...this.active]) this.finish(voice);
        this.castTurns.clear();
    }
    stop(): void {
        this.stopEffects();
        if (this.ambience) this.finish(this.ambience);
    }
    play(event: BattleEvent, levelId = 'empty-cup', finale: FinaleSound = null): void {
        if (!this.allowed()) return;
        if (event.type === 'outcome') {
            if (this.ambience) this.finish(this.ambience);
            const key = event.after.outcome !== 'player_victory' ? 'defeat' : finale ?? 'victory';
            this.start(`ending-${key}`, 'music', true);
            return;
        }
        if (event.type === 'action_accepted') {
            if (this.castTurns.has(event.turn)) return;
            this.castTurns.add(event.turn);
            if (event.cue_id.includes('ultimate')) this.start('ultimate-charge', 'effect', true);
            else if (event.target) this.start(`cast-${event.target}`);
            else this.ui('confirm');
            return;
        }
        if (event.type === 'impact') {
            if (event.cue_id.includes('ultimate')) {
                this.start('ultimate-impact', 'effect', true);
                const timer = setTimeout(() => { this.timers.delete(timer); this.start('ultimate-tail', 'effect', true); }, 120);
                this.timers.add(timer);
            } else this.start(Number(event.delta.absorbed) > 0 ? 'shield' : 'impact');
            return;
        }
        if (event.type === 'phase_change') {
            if (event.reason_code === 'overhaul_started') this.start('overhaul', 'effect', true);
            else if (event.reason_code === 'overhaul_stopped') this.start('interrupt');
            return;
        }
        if (event.type === 'level_phase_change') {
            if (levelId === 'mirror-shade') this.start('mirror');
            else this.ui('toggle');
            return;
        }
        const keys: Record<string, string> = { breach_opened: 'breach', interrupt: 'interrupt', interrupt_failed: 'ui-unable', city_repair: 'repair', city_shield: 'shield', city_reinforce: 'shield', combo: 'combo', action_missed: 'ui-unable' };
        if (keys[event.type]) this.start(keys[event.type]);
    }
}
