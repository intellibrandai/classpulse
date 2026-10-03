#!/usr/bin/env python3
"""Rebuild the ClassPulse waveform mark (public/brand/*) as vectors measured from the approved reference PNG.

Usage: python3 scripts/brand/build-logo.py <path-to-reference-280x144.png>
Only the 17 bars of the symbol are read (x, y-extent, colour); the reference's dark rectangle is discarded, so every
output has a transparent background and nothing is upscaled. Needs Pillow + numpy (dev machine only, not shipped).
"""
import sys
from pathlib import Path
import numpy as np
from PIL import Image, ImageDraw, ImageFilter

src = Path(sys.argv[1])
out = Path(__file__).resolve().parents[2] / 'public' / 'brand'
out.mkdir(parents=True, exist_ok=True)

a = np.array(Image.open(src).convert('RGB')).astype(int)
bg = np.median(a[5:25, 150:270].reshape(-1, 3), axis=0)
d = np.abs(a - bg).sum(axis=2)
X0, X1, Y0, Y1 = 36, 140, 30, 78
cols = [x for x in range(X0, X1) if d[Y0:Y1, x].max() > 45]
groups, cur = [], [cols[0]]
for x in cols[1:]:
    if x == cur[-1] + 1:
        cur.append(x)
    else:
        groups.append(cur)
        cur = [x]
groups.append(cur)

bars = []  # dicts: x (left), w, y (top), h, colours (top, mid, bottom) as hex, kind
for g in groups:
    sub = d[Y0:Y1, g[0]:g[-1] + 1]
    rows = [Y0 + i for i in range(sub.shape[0]) if sub[i].max() > 45]
    peak = max(g, key=lambda x: d[Y0:Y1, x].max())
    top, bot = rows[0], rows[-1]
    mid = (top + bot) // 2
    rgb = lambda y: tuple(int(v) for v in a[y, peak])
    kind = 'plain'
    x, w = g[0], len(g)
    if w >= 6:  # the bright white bar sits inside a glow group: keep only its 2px core
        core = [c for c in g if d[Y0:Y1, c].max() > 300]
        x, w, kind = core[0], len(core), 'white'
    if g[0] == 65:
        x, w, kind = 65, 2, 'gradient'
    if rgb(mid)[2] > 180 and rgb(mid)[0] < 100:
        kind = 'cyan'
    bars.append(dict(x=x, w=w, y=top, h=bot - top + 1, c=(rgb(top + 2), rgb(mid), rgb(bot - 2)), kind=kind))

hx = lambda t: '#%02x%02x%02x' % t
VB = (38, 30, 100, 44)  # x y w h in source pixels


def svg(variant='dark', bold=1.0, tile=False):
    light = variant == 'light'
    defs, body = [], []
    for i, b in enumerate(bars):
        x, y, w, h = b['x'], b['y'], b['w'] * bold, b['h']
        x = x - (w - b['w'] * 1.0) / 2
        top, mid, bot = b['c']
        if light:
            lum = lambda t: sum(t) / 3
            if b['kind'] in ('white', 'gradient'):
                stops = ['#1e1b4b', '#3730a3', '#4f46e5'] if b['kind'] == 'gradient' else ['#1e1b4b'] * 3
            elif b['kind'] == 'cyan':
                stops = ['#1d7fb8'] * 3
            else:
                al = max(0.4, min(1.0, (lum(mid) - 20) / 110))
                stops = [f'rgba(79,70,229,{al:.2f})'] * 3
        else:
            stops = [hx(top), hx(mid), hx(bot)]
        fill = f'url(#g{i})' if len(set(stops)) > 1 else stops[0]
        if len(set(stops)) > 1:
            gid = f'g{i}'
            defs.append(f'<linearGradient id="{gid}" x1="0" y1="0" x2="0" y2="1"><stop offset="0" stop-color="{stops[0]}"/><stop offset=".5" stop-color="{stops[1]}"/><stop offset="1" stop-color="{stops[2]}"/></linearGradient>')
        if b['kind'] in ('white', 'cyan') and not light:
            glow = '#ffffff' if b['kind'] == 'white' else '#2b9bd6'
            body.append(f'<rect x="{x - 1:.2f}" y="{y - 2}" width="{w + 2:.2f}" height="{h + 4}" rx="{(w + 2) / 2:.2f}" fill="{glow}" opacity="{0.55 if b["kind"] == "white" else 0.6}" filter="url(#glow)"/>')
        body.append(f'<rect x="{x:.2f}" y="{y}" width="{w:.2f}" height="{h}" rx="{min(w, 2) / 2:.2f}" fill="{fill}"/>')
    vb = ' '.join(str(v) for v in VB)
    bgtile = ''
    if tile:
        vb = '31 -3 114 114'
        bgtile = '<rect x="31" y="-3" width="114" height="114" rx="24" fill="#0b1326"/>'
    return (f'<svg xmlns="http://www.w3.org/2000/svg" viewBox="{vb}" role="img" aria-label="ClassPulse">'
            f'<defs><filter id="glow" x="-200%" y="-30%" width="500%" height="160%"><feGaussianBlur stdDeviation="3"/></filter>{"".join(defs)}</defs>'
            f'{bgtile}{"".join(body)}</svg>\n')


(out / 'classpulse-mark.svg').write_text(svg('dark'))
(out / 'classpulse-mark-light.svg').write_text(svg('light'))
(out / 'classpulse-favicon.svg').write_text(svg('dark', bold=2.3, tile=True))


def png(size, bold, tile, path):
    S = 8
    ratio = 116 / 56 if tile else 100 / 44
    W = size
    Hh = int(size / ratio) if not tile else size
    vbx, vby, vbw, vbh = (31, -3, 114, 114) if tile else VB
    sc = (W * S) / vbw
    H = int(vbh * sc)
    canvas = Image.new('RGBA', (W * S, H), (0, 0, 0, 0))
    glow = Image.new('RGBA', canvas.size, (0, 0, 0, 0))
    dr, gd = ImageDraw.Draw(canvas), ImageDraw.Draw(glow)
    if tile:
        dr.rounded_rectangle((0, 0, canvas.width - 1, canvas.height - 1), radius=int(24 * sc), fill=(11, 19, 38, 255))
    for b in bars:
        w = b['w'] * bold
        x = b['x'] - (w - b['w']) / 2
        x0, x1 = (x - vbx) * sc, (x + w - vbx) * sc
        y0, y1 = (b['y'] - vby) * sc, (b['y'] + b['h'] - vby) * sc
        if b['kind'] in ('white', 'cyan'):
            col = (255, 255, 255, 150) if b['kind'] == 'white' else (43, 155, 214, 160)
            gd.rounded_rectangle((x0 - sc, y0 - 2 * sc, x1 + sc, y1 + 2 * sc), radius=int(sc * 2), fill=col)
        top, mid, bot = b['c']
        for yy in range(int(y0), int(y1)):
            t = (yy - y0) / max(1, (y1 - y0))
            c = tuple(int(top[k] + (mid[k] - top[k]) * t * 2) if t < .5 else int(mid[k] + (bot[k] - mid[k]) * (t - .5) * 2) for k in range(3))
            dr.line((x0, yy, x1, yy), fill=c + (255,))
    glow = glow.filter(ImageFilter.GaussianBlur(3 * sc))
    if tile:
        res = Image.alpha_composite(Image.new('RGBA', canvas.size, (0, 0, 0, 0)), glow)
        res = Image.alpha_composite(res, canvas)
    else:
        res = Image.alpha_composite(glow, canvas)
    res = res.resize((W, max(1, int(H / S))), Image.LANCZOS)
    res.save(path)


png(512, 2.3, True, out / 'classpulse-icon-512.png')
png(180, 2.3, True, out / 'apple-touch-icon.png')
png(64, 2.3, True, out / 'favicon-64.png')
png(600, 1.0, False, out / 'classpulse-mark-600.png')
ico = Image.open(out / 'classpulse-icon-512.png').convert('RGBA')
ico.save(out.parent / 'favicon.ico', sizes=[(16, 16), (32, 32), (48, 48)])
print('bars', len(bars), 'written to', out)
