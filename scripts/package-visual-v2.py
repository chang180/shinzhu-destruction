"""Copy original ImageGen outputs, encode delivery sizes, record provenance and preview.

This only converts delivery formats/sizes; it does not paint or edit illustrations.
"""
import hashlib
import json
from pathlib import Path
import shutil

from PIL import Image, ImageDraw

ROOT = Path(__file__).resolve().parents[1]
manifest_path = ROOT / 'assets/visual-v2-generation.json'
manifest = json.loads(manifest_path.read_text(encoding='utf-8'))
originals = ROOT / 'assets/generated/visual-v2'
delivery = ROOT / 'public/assets/visual-v2'
evidence = ROOT / 'docs/phase-reports/data/visual-v2'
for directory in [originals, delivery, evidence]:
    directory.mkdir(parents=True, exist_ok=True)
sheet = Image.new('RGB', (1280, 5 * 210), '#171321')
draw = ImageDraw.Draw(sheet)
for i, item in enumerate(sorted(manifest['assets'], key=lambda item: item['id'])):
    key = item['id']
    source = Path(item['source'])
    if not source.is_absolute():
        source = ROOT / source
    original = originals / (key + '.png')
    if source.resolve() != original.resolve():
        shutil.copy2(source, original)
    image = Image.open(original).convert('RGB')
    outputs = []
    for width, suffix in [(1600, ''), (1280, '-medium'), (768, '-small')]:
        height = round(width * image.height / image.width)
        target = delivery / f'{key}{suffix}.webp'
        image.resize((width, height), Image.Resampling.LANCZOS).save(target, 'WEBP', quality=80, method=6)
        outputs.append({'file': target.relative_to(ROOT).as_posix(), 'width': width, 'height': height, 'bytes': target.stat().st_size, 'sha256': hashlib.sha256(target.read_bytes()).hexdigest()})
    preview = image.copy()
    preview.thumbnail((320, 180))
    x, y = i % 4 * 320, i // 4 * 210
    sheet.paste(preview, (x, y))
    draw.text((x + 8, y + 184), key, fill='#eadac5')
    item.pop('hint', None)
    item.update(source=original.relative_to(ROOT).as_posix(), raw_dimensions=[image.width, image.height], raw_sha256=hashlib.sha256(original.read_bytes()).hexdigest(), outputs=outputs, alt=key.replace('-', ' '), review='pending-visual-inspection')
sheet.save(evidence / 'contact-sheet.jpg', quality=90)
manifest.update(status='packaged-review-pending', conversion='Pillow WebP quality 80 method 6, proportional widths 1600/1280/768', provenance='Original image_gen generation; existing Yan Chen image used only as identity reference. No text or third-party character references.')
manifest_path.write_text(json.dumps(manifest, ensure_ascii=False, indent=2) + '\n', encoding='utf-8')
print(f'Packaged {len(manifest["assets"])} originals and {len(manifest["assets"]) * 3} WebP delivery variants')
