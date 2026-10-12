"""Reproducible original sound design; authoring tools are not production dependencies.

Usage: python scripts/build-visual-v2-audio.py --encoder-dir /local/authoring/tools
No downloaded recordings, melodies, voices or project dependency changes.
"""
import argparse
import hashlib
import json
from pathlib import Path
import sys
import wave

import numpy as np

ROOT = Path(__file__).resolve().parents[1]
RATE = 44100


def tone(duration, frequency, material="ceramic"):
    t = np.arange(round(duration * RATE)) / RATE
    ratios = {"ceramic": [1, 2.37, 3.91, 5.42], "brass": [1, 2, 3, 4], "glass": [1, 2.76, 5.4, 8.93], "soft": [1, 2, 3]}[material]
    out = np.zeros(len(t))
    for index, ratio in enumerate(ratios):
        out += np.sin(2 * np.pi * frequency * ratio * t) * np.exp(-t * (2.2 + index * 3)) / (index + 1) ** 1.5
    return out * np.minimum(t / 0.008, 1)


def noise(duration, rng, low, high):
    n = round(duration * RATE)
    bins = np.fft.rfftfreq(n, 1 / RATE)
    spectrum = np.fft.rfft(rng.normal(size=n))
    band = np.minimum(bins / max(low, 1), 1) ** 4 * np.minimum(high / np.maximum(bins, 1), 1) ** 5
    out = np.fft.irfft(spectrum * band, n=n)
    return out / max(np.max(np.abs(out)), 1e-8)


def sweep(duration, first, last):
    t = np.arange(round(duration * RATE)) / RATE
    phase = first * t + (last - first) * t * t / (2 * duration)
    return np.sin(2 * np.pi * phase) * np.sin(np.pi * t / duration) ** 2


def place(out, sample, when=0, volume=1):
    start = round(when * RATE)
    count = min(len(sample), len(out) - start)
    if count > 0:
        out[start:start + count] += sample[:count] * volume


SPECS = {
    "ui-confirm": (0.32, "兩次短陶片敲擊"), "ui-unable": (0.26, "低沉紙面擦響"),
    "ui-unlock": (1.15, "銅片上行三音"), "ui-toggle": (0.16, "乾淨短開關"),
    "cast-water": (0.65, "陶腔水滴與下滑流聲"), "cast-heat": (0.55, "乾裂脈衝與上揚熱氣"),
    "cast-land": (0.68, "低頻土塊與顆粒崩裂"), "impact": (0.55, "短促低頻衝擊"),
    "shield": (0.65, "有阻力的悶金屬共鳴"), "breach": (1.0, "高頻盾紋裂開"),
    "interrupt": (0.65, "斷弦般下降截斷"), "repair": (1.35, "溫暖陶鐘逐層重組"),
    "combo": (0.95, "三種材質交接形成和弦"), "ultimate-charge": (1.05, "顆粒與低頻逐步聚集"),
    "ultimate-impact": (0.9, "低頻重擊與玻璃碎屑"), "ultimate-tail": (1.6, "稀疏碎片與消散餘韻"),
    "mirror": (0.85, "對稱反向玻璃回聲"), "overhaul": (1.2, "雙重低音警報與機械張力"),
    "ambience-noon-fold": (24, "長晝乾燥風聲與遠處金屬鳴響"),
    "ambience-meter-feast": (24, "低調機械脈動與園區供應聲"),
    "ambience-mirror-shade": (24, "稀疏玻璃回聲與丘陵風聲"),
    "ambience-stored-night": (24, "夜間低頻張力與慢速脈衝"),
    "ending-defeat": (3.6, "平靜惋惜的三段落句"), "ending-victory": (4.4, "銅器與紙面節奏的單關慶祝"),
    "ending-main": (5.8, "完整結業樂句、溫暖收束"), "ending-advanced": (7.2, "五門禁術畢業慶典、明確終止"),
}


def compose(key, duration):
    rng = np.random.default_rng(int(hashlib.sha256(key.encode()).hexdigest()[:8], 16))
    t = np.arange(round(duration * RATE)) / RATE
    out = np.zeros(len(t))
    if key.startswith("ambience-"):
        mode = list(k for k in SPECS if k.startswith("ambience-")).index(key)
        # Integer cycles and circular filtering produce a continuous loop boundary.
        fundamental = [98, 73, 110, 55][mode]
        for harmonic, level in [(1, .13), (1.5, .055), (2, .025)]:
            freq = round(fundamental * harmonic * duration) / duration
            out += level * np.sin(2 * np.pi * freq * t) * (0.7 + 0.3 * np.cos(2 * np.pi * (mode + 1) * t / duration))
        out += noise(duration, rng, 150 + mode * 60, [950, 550, 1800, 350][mode]) * .12 * (0.6 + .4 * np.sin(2 * np.pi * t / duration) ** 2)
        for at in [2.5, 8.5, 14.5, 20.5]:
            place(out, tone(2.8, [587, 147, 784, 82][mode], ["ceramic", "brass", "glass", "soft"][mode]), at, .045)
    elif key.startswith("ending-"):
        melody = {
            "ending-defeat": [(0, 293.66), (.65, 261.63), (1.4, 220)],
            "ending-victory": [(0, 196), (.42, 293.66), (.84, 329.63), (1.5, 392), (2.3, 293.66)],
            "ending-main": [(0, 196), (.5, 246.94), (1, 293.66), (1.65, 392), (2.5, 329.63), (3.3, 293.66)],
            "ending-advanced": [(0, 146.83), (.36, 196), (.72, 220), (1.08, 293.66), (1.8, 392), (2.6, 440), (3.4, 392), (4.5, 293.66)],
        }[key]
        for at, freq in melody:
            place(out, tone(2.4, freq, "brass"), at, .3)
            place(out, tone(2.5, freq / 2, "soft"), at, .22)
        for at in [0, .8, 1.6]:
            place(out, noise(.2, rng, 700, 4000) * np.exp(-np.arange(round(.2 * RATE)) / RATE * 22), at, .07)
        if key != "ending-defeat":
            at = melody[-1][0]
            for freq in [146.83, 220, 293.66]:
                place(out, tone(duration - at, freq, "soft"), at, .22)
    elif key in ["cast-water", "cast-heat", "cast-land"]:
        if key == "cast-water":
            place(out, sweep(.55, 680, 170), 0, .4)
            for at, freq in [(0, 890), (.12, 640), (.27, 430)]:
                place(out, tone(.35, freq), at, .22)
            out += noise(duration, rng, 400, 1900) * np.sin(np.pi * t / duration) ** 2 * .17
        elif key == "cast-heat":
            out += noise(duration, rng, 1500, 8000) * np.exp(-t * 9) * .6
            place(out, sweep(.48, 110, 650), 0, .28)
            for at in [0, .08, .17]:
                place(out, tone(.15, 1350, "glass"), at, .15)
        else:
            out += noise(duration, rng, 70, 650) * np.exp(-t * 5) * .65
            place(out, sweep(.55, 155, 45), 0, .5)
            for at in [0, .13, .22]:
                place(out, tone(.3, 137), at, .25)
    else:
        palettes = {
            "ui-confirm": [(0, 750), (.095, 1000)], "ui-unable": [(0, 115)], "ui-unlock": [(0, 392), (.16, 494), (.32, 587)],
            "ui-toggle": [(0, 530)], "impact": [(0, 82)], "shield": [(0, 180)], "breach": [(0, 1200), (.08, 1750)],
            "interrupt": [(0, 640), (.07, 320)], "repair": [(0, 330), (.18, 440), (.36, 554), (.54, 660)],
            "combo": [(0, 294), (.13, 440), (.26, 587)], "ultimate-impact": [(0, 49), (.02, 196)],
            "ultimate-tail": [(0, 392), (.25, 587), (.58, 784)], "mirror": [(0, 784), (.28, 784)],
            "overhaul": [(0, 73), (.4, 78), (.8, 73)],
        }
        for at, freq in palettes.get(key, []):
            material = "glass" if key in ["mirror", "breach", "ultimate-tail"] else "ceramic"
            place(out, tone(duration - at, freq, material), at, .45)
        if key == "ultimate-charge":
            out += sweep(duration, 38, 190) * .65 + noise(duration, rng, 150, 5000) * (t / duration) ** 2 * .4
        if key in ["impact", "shield", "breach", "ultimate-impact", "ui-unable", "ui-toggle"]:
            high = 700 if key == "shield" else 6000
            out += noise(duration, rng, 80, high) * np.exp(-t * (18 if key.startswith("ui") else 7)) * .5
        if key == "mirror":
            bell = tone(.28, 784, "glass")
            place(out, bell[::-1], 0, .3)
        if key == "interrupt":
            place(out, sweep(.32, 900, 100), 0, .4)
    if not key.startswith("ambience-"):
        out *= np.minimum(t / .005, 1) * np.minimum((duration - t) / .06, 1)
    peak = .28 if key.startswith("ambience-") else .38 if key.startswith("ui-") else .65
    out *= peak / max(np.max(np.abs(out)), 1e-8)
    return out


def main():
    parser = argparse.ArgumentParser()
    parser.add_argument("--encoder-dir", required=True)
    args = parser.parse_args()
    sys.path.insert(0, args.encoder_dir)
    import soundfile as sf
    import lameenc
    source = ROOT / "assets/generated/visual-v2/audio"
    target = ROOT / "public/assets/visual-v2/audio"
    source.mkdir(parents=True, exist_ok=True)
    target.mkdir(parents=True, exist_ok=True)
    manifest = {"date": "2026-10-12", "tool": "Original deterministic numpy synthesis; soundfile 0.13.1 Vorbis / lameenc 1.8.1 MP3", "source_script": "scripts/build-visual-v2-audio.py", "provenance": "Original procedural recordings and original short compositions; no third-party samples", "assets": []}
    for key, (duration, description) in SPECS.items():
        sample = compose(key, duration)
        original = source / (key + ".wav")
        with wave.open(str(original), "wb") as handle:
            handle.setparams((1, 2, RATE, len(sample), "NONE", "not compressed"))
            handle.writeframes((sample * 32767).astype("<i2").tobytes())
        outputs = []
        for ext in ["ogg", "mp3"]:
            output = target / f"{key}.{ext}"
            if ext == "ogg":
                with sf.SoundFile(str(output), mode="w", samplerate=RATE, channels=1, format="OGG", subtype="VORBIS") as encoded:
                    for start in range(0, len(sample), RATE):
                        encoded.write(sample[start:start + RATE])
            else:
                encoder = lameenc.Encoder()
                encoder.set_bit_rate(96)
                encoder.set_in_sample_rate(RATE)
                encoder.set_channels(1)
                encoder.set_quality(2)
                pcm = (sample * 32767).astype("<i2").tobytes()
                output.write_bytes(encoder.encode(pcm) + encoder.flush())
            outputs.append({"file": output.relative_to(ROOT).as_posix(), "bytes": output.stat().st_size, "sha256": hashlib.sha256(output.read_bytes()).hexdigest()})
        manifest["assets"].append({"key": key, "description": description, "duration_seconds": duration, "loop": key.startswith("ambience-"), "sample_rate": RATE, "channels": 1, "peak_dbfs": round(float(20 * np.log10(np.max(np.abs(sample)))), 2), "rms_dbfs": round(float(20 * np.log10(np.sqrt(np.mean(sample ** 2)))), 2), "source": original.relative_to(ROOT).as_posix(), "source_sha256": hashlib.sha256(original.read_bytes()).hexdigest(), "outputs": outputs, "review": "pending-browser-playback"})
    (ROOT / "assets/visual-v2-audio.json").write_text(json.dumps(manifest, ensure_ascii=False, indent=2) + "\n", encoding="utf-8")
    print(f"Generated {len(SPECS)} original WAV masters and {len(SPECS) * 2} browser audio files")


if __name__ == "__main__":
    main()
