import type { BattleEvent } from './game';

export class BattleAudio {
    private context: AudioContext | null = null;
    private active: HTMLAudioElement[] = [];
    private voices: OscillatorNode[] = [];
    muted = true;
    effectsVolume = 0.45;
    musicVolume = 0.2;

    async unlock(): Promise<void> {
        try { this.context ??= new AudioContext(); await this.context.resume(); } catch { /* Sound is optional. */ }
    }
    stop(): void {
        for (const audio of this.active) audio.pause();
        for (const voice of this.voices) { try { voice.stop(); } catch { /* Already ended. */ } }
        this.active = []; this.voices = [];
    }
    play(event: BattleEvent): void {
        this.stop();
        if (this.muted || document.hidden || !this.context) return;
        const ending = event.type === 'outcome';
        const frequency = event.target === 'water' ? 294 : event.target === 'heat' ? 440 : 196;
        const notes = ending ? (event.after.outcome === 'player_victory' ? [262, 330, 392, 523] : [262, 233, 196]) : [frequency];
        notes.forEach((note, index) => {
            const oscillator = this.context!.createOscillator();
            const gain = this.context!.createGain();
            const time = this.context!.currentTime + index * 0.18;
            oscillator.type = ending ? 'sine' : 'triangle';
            oscillator.frequency.setValueAtTime(note, time);
            gain.gain.setValueAtTime(0, time);
            gain.gain.linearRampToValueAtTime((ending ? this.musicVolume : this.effectsVolume) * 0.18, time + 0.02);
            gain.gain.exponentialRampToValueAtTime(0.001, time + 0.35);
            oscillator.connect(gain).connect(this.context!.destination);
            oscillator.start(time); oscillator.stop(time + 0.4); this.voices.push(oscillator);
        });
        const file = event.cue_id.includes('ultimate') || event.type === 'breach_opened' ? 'impactGlass_heavy_000'
            : event.type === 'interrupt' || event.type === 'city_repair' ? 'impactBell_heavy_000'
            : event.type === 'shield_absorbed' || event.cue_id.includes('breach') ? 'impactMetal_heavy_000' : 'impactGeneric_light_000';
        const sound = new Audio(`/assets/p04/${file}.ogg`);
        sound.volume = this.effectsVolume;
        this.active.push(sound);
        void sound.play().catch(() => undefined);
    }
}
