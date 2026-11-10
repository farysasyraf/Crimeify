"""Draws Line-Up's art, in public/modules/game:

- line-up-suspects.svg: the suspects, as symbols the pages put in (_line-up-room.blade.php): plain silhouettes from the
  chest up, told apart only by a cap, a hoodie, glasses, a bun, a jacket's collar or a beanie, never by anything that
  hints at who they might be (lu-cap, lu-hoodie, lu-glasses, lu-bun, lu-collar, lu-beanie); and lu-hand, a hand
  gripping the side of a board, its fingers over the front, for each side of each suspect's board;
- title-line-up.svg: LINE-UP in Oswald Bold, off-white with an amber hyphen, over a height chart's lines;
- tile-line-up.svg: the Games page's tile: three suspects holding boards with "?" against the height chart, under a
  strip light, the title along the foot;
- picture-line-up.svg: beside the title on the Games page: five suspects in the line-up room, numbered on the floor;
- oswald.woff2: Oswald, the game's lettering, as a web font.

Oswald is under the SIL Open Font License 1.1 (public/modules/game/Oswald-OFL.txt). Only needed again to change the
art; the pages use the files. Download the variable font from
https://github.com/google/fonts/raw/main/ofl/oswald/Oswald%5Bwght%5D.ttf, then, with fonttools, brotli and uharfbuzz
installed (pip install fonttools brotli uharfbuzz), from the project's folder:

    python Modules/Game/Resources/art/make_lineup_art.py Oswald[wght].ttf public/modules/game
"""
import io
import sys
from pathlib import Path

import uharfbuzz as hb
from fontTools.pens.boundsPen import BoundsPen
from fontTools.pens.svgPathPen import SVGPathPen
from fontTools.pens.transformPen import TransformPen
from fontTools.ttLib import TTFont
from fontTools.varLib.instancer import instantiateVariableFont

INK, AMBER, FIGURE, RIM = '#f1efe8', '#f5c26b', '#0e1014', '#cddaec'
WALL_TOP, WALL_FOOT, FLOOR = '#3c414a', '#2b2f36', '#17191e'

font_path, out = sys.argv[1], Path(sys.argv[2])


def weight(value):
    """Oswald at the weight given, its glyphs, its cap height and a HarfBuzz font to set words with."""
    data = io.BytesIO()
    instantiateVariableFont(TTFont(font_path), {'wght': value}).save(data)
    data = data.getvalue()
    font = TTFont(io.BytesIO(data))
    return font, font.getGlyphSet(), font['OS/2'].sCapHeight, hb.Font(hb.Face(data))


BOLD = weight(700)


def word(text, x, baseline, size, face=BOLD):
    """The word set in the face given, size tall in caps, its left ink edge at x and its baseline at y = baseline: each
    glyph's path and name, and the word's ink width."""
    font, glyphs, cap, hb_font = face
    scale = size / cap
    buf = hb.Buffer()
    buf.add_str(text)
    buf.guess_segment_properties()
    hb.shape(hb_font, buf, {'kern': True})
    placed, advance = [], 0
    for info, pos in zip(buf.glyph_infos, buf.glyph_positions):
        placed.append((font.getGlyphName(info.codepoint), advance + pos.x_offset))
        advance += pos.x_advance
    bounds = BoundsPen(glyphs)
    for name, at in placed:
        glyphs[name].draw(TransformPen(bounds, (1, 0, 0, 1, at, 0)))
    x_min, _, x_max, _ = bounds.bounds
    paths = []
    for name, at in placed:
        pen = SVGPathPen(glyphs)
        glyphs[name].draw(TransformPen(pen, (scale, 0, 0, -scale, x + (at - x_min) * scale, baseline)))
        paths.append((name, pen.getCommands()))
    return paths, (x_max - x_min) * scale


# The suspects: one body, chest up, and what tells each apart. On a 120 by 260 box, the board held at the chest.
TORSO = 'M6 260C8 196 12 150 22 132C30 118 44 112 52 110L68 110C76 112 90 118 98 132C108 150 112 196 114 260Z'
NECK = 'M49 90L71 90L73 116L47 116Z'
HEAD = 'M60 39C72 39 82 51 82 66C82 81 72 93 60 93C48 93 38 81 38 66C38 51 48 39 60 39Z'
LOOKS = {
    'cap': {'body': ['M37 60C37 34 83 34 83 60Z', 'M33 58L95 55L96 62L34 64Z']},
    'hoodie': {'behind': '<path d="M28 124C22 72 32 30 60 30C88 30 98 72 92 124Z" fill="#262a32"/>',
               'over': '<path d="M50 118L48 168M70 118L72 168" stroke="#4a505a" stroke-width="2.4" stroke-linecap="round" fill="none"/>'},
    'glasses': {'over': '<g fill="none" stroke="#9aa3b0" stroke-width="2.2"><rect x="39" y="59" width="17" height="11" rx="4"/>'
                        '<rect x="64" y="59" width="17" height="11" rx="4"/><path d="M56 63L64 63"/></g>'},
    'bun': {'body': ['M60 23C67 23 72 28 72 35C72 42 67 47 60 47C53 47 48 42 48 35C48 28 53 23 60 23Z']},
    'collar': {'body': ['M38 52C38 34 82 34 82 52L82 56L38 56Z'],
               'over': '<path d="M47 112L34 136L54 126ZM73 112L86 136L66 126Z" fill="#23262d"/>'},
    'beanie': {'body': ['M38 56C38 30 82 30 82 56Z', 'M35 52L85 52L85 61L35 61Z'],
               'over': '<path d="M37 56.5L83 56.5" stroke="#2a2e36" stroke-width="1.6"/>'},
}


def suspect(look):
    """A suspect's drawing, on its 120 by 260 box: what's behind the body, the body in the figure's colour (unless the
    page gives one), then what's over it."""
    parts = LOOKS[look]
    body = ''.join(f'<path d="{d}"/>' for d in [TORSO, NECK, HEAD] + parts.get('body', []))
    return f'{parts.get("behind", "")}<g class="lu-body">{body}</g>{parts.get("over", "")}'


# A left hand on a 30 by 40 box, the board's left edge at x = 12: the back of the hand outside it, a wrist below, and
# four fingers over the board's front, each outlined in the rim light so they read against the board.
HAND = ('<path d="M7 2C11 2 14 5 14 9L14 31C14 35 11 38 7 38C3 38 0 35 0 31L0 9C0 5 3 2 7 2Z"/>'
        '<path d="M2 32L6 40L12 40L12 32Z"/>'
        '<g fill="#1a1d23" stroke="' + RIM + '" stroke-opacity="0.55" stroke-width="1">'
        + ''.join(f'<rect x="8" y="{y}" width="{w}" height="6.4" rx="3.2"/>' for y, w in ((5, 13), (12, 14.5), (19, 14), (26, 12)))
        + '</g>')

sprite = ['<svg xmlns="http://www.w3.org/2000/svg" class="lu-sprite" aria-hidden="true" focusable="false">',
          '<!-- Line-Up\'s suspects and their hands, drawn by Modules/Game/Resources/art/make_lineup_art.py. -->']
for look in LOOKS:
    sprite.append(f'<symbol id="lu-{look}" viewBox="0 0 120 260">{suspect(look)}</symbol>')
sprite.append(f'<symbol id="lu-hand" viewBox="0 0 30 40">{HAND}</symbol>')
sprite.append('</svg>')
(out / 'line-up-suspects.svg').write_text('\n'.join(sprite) + '\n', encoding='utf-8', newline='\n')


# The title: LINE-UP over the lines of a height chart, a shadow under the letters so the lines don't run into them.
CAP = 600
letters, ink = word('LINE-UP', 40, 40 + CAP, CAP)
title_w, title_h = ink + 80, CAP + 80
rules = ''.join(f'<rect x="0" y="{y:.0f}" width="{title_w:.0f}" height="{10 if i % 2 == 0 else 5}" fill="{INK}" fill-opacity="{0.55 if i % 2 == 0 else 0.3}"/>'
                for i, y in enumerate(40 + CAP * step / 6 for step in range(-0, 7)))
shadow = ''.join(f'<path d="{d}"/>' for _, d in letters)
lettering = ''.join(f'<path d="{d}" fill="{AMBER if name == "hyphen" else INK}"/>' for name, d in letters)
(out / 'title-line-up.svg').write_text(
    f'<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 {title_w:.0f} {title_h:.0f}" role="img" aria-label="Line-Up">\n'
    '<!-- Line-Up\'s title, in Oswald Bold (SIL Open Font License 1.1, Oswald-OFL.txt), drawn by Modules/Game/Resources/art/make_lineup_art.py. -->\n'
    f'<g>{rules}</g><g fill="#000" fill-opacity="0.6" transform="translate(0 26)">{shadow}</g><g>{lettering}</g>\n'
    '</svg>\n', encoding='utf-8', newline='\n')


def room(width, height, floor, places, title_band=0):
    """The line-up room's wall, height chart, strip light, cones of light over each place given (their x), a red and a
    blue glow at its sides, and its floor, floor high (none, 0)."""
    wall = height - floor
    minor, major = 30, 150
    lines = ''.join(f'<rect x="0" y="{wall - y - 1}" width="{width}" height="{3 if y % major == 0 else 1.5}" fill="{INK}" fill-opacity="{0.32 if y % major == 0 else 0.13}"/>'
                    for y in range(minor, wall, minor))
    cones = ''.join(f'<ellipse cx="{x}" cy="0" rx="{width * 0.075:.0f}" ry="{wall * 0.85:.0f}" fill="url(#cone)"/>' for x in places)
    floor_part = (f'<rect x="0" y="{wall}" width="{width}" height="{floor}" fill="url(#floor)"/>'
                  f'<rect x="0" y="{wall}" width="{width}" height="2" fill="{INK}" fill-opacity="0.22"/>') if floor else ''
    defs = ('<linearGradient id="wall" x1="0" y1="0" x2="0" y2="1">'
            f'<stop offset="0" stop-color="{WALL_TOP}"/><stop offset="1" stop-color="{WALL_FOOT}"/></linearGradient>'
            f'<linearGradient id="floor" x1="0" y1="0" x2="0" y2="1"><stop offset="0" stop-color="#202329"/><stop offset="1" stop-color="{FLOOR}"/></linearGradient>'
            '<radialGradient id="cone"><stop offset="0" stop-color="#fff9e8" stop-opacity="0.2"/><stop offset="1" stop-color="#fff9e8" stop-opacity="0"/></radialGradient>'
            '<radialGradient id="red" cx="0" cy="0.35" r="0.6"><stop offset="0" stop-color="#e0564d" stop-opacity="0.2"/><stop offset="1" stop-color="#e0564d" stop-opacity="0"/></radialGradient>'
            '<radialGradient id="blue" cx="1" cy="0.35" r="0.6"><stop offset="0" stop-color="#4d8fe0" stop-opacity="0.22"/><stop offset="1" stop-color="#4d8fe0" stop-opacity="0"/></radialGradient>'
            '<filter id="glow" x="-50%" y="-50%" width="200%" height="200%"><feGaussianBlur stdDeviation="9"/></filter>'
            '<linearGradient id="band" x1="0" y1="0" x2="0" y2="1"><stop offset="0" stop-color="#0c0d10" stop-opacity="0"/><stop offset="0.45" stop-color="#0c0d10"/></linearGradient>')
    light = (f'<rect x="{width * 0.08:.0f}" y="-4" width="{width * 0.84:.0f}" height="14" rx="6" fill="#fffbea" filter="url(#glow)" opacity="0.7"/>'
             f'<rect x="{width * 0.1:.0f}" y="-4" width="{width * 0.8:.0f}" height="11" rx="5" fill="#fffbea"/>')
    body = (f'<rect width="{width}" height="{wall}" fill="url(#wall)"/>{lines}'
            f'<rect width="{width}" height="{wall}" fill="url(#red)"/><rect width="{width}" height="{wall}" fill="url(#blue)"/>'
            f'{cones}{light}{floor_part}')
    return defs, body


def held(look, x, foot, scale, mark):
    """A suspect at x (its middle), its box's foot at y = foot, scaled: a soft glow behind, the silhouette with its rim
    light, its board with the mark given (a "?"), and a hand each side gripping it."""
    w, h = 120 * scale, 260 * scale
    left, top = x - w / 2, foot - h
    figure = suspect(look)
    board_w, board_h = 104 * scale, 56 * scale
    board_x, board_y = x - board_w / 2, top + 170 * scale
    question, q_w = word(mark, 0, 0, board_h * 0.42)
    q = ''.join(f'<path d="{d}"/>' for _, d in question)
    hand_scale = scale * 0.95
    hands = ''.join(
        f'<g transform="translate({board_x + (0 if side == 1 else board_w) - side * 12 * hand_scale:.1f} {board_y + board_h - 40 * hand_scale + 4 * scale:.1f}) scale({side * hand_scale:.3f} {hand_scale:.3f})">'
        f'<g fill="{FIGURE}" stroke="{RIM}" stroke-opacity="0.55" stroke-width="{1.2 / hand_scale:.2f}">{HAND}</g></g>'
        for side in (1, -1))
    strings = ''.join(f'<rect x="{board_x + board_w * at:.1f}" y="{board_y - 13 * scale:.1f}" width="{2 * scale:.1f}" height="{13 * scale:.1f}" fill="#6b707a"/>'
                      for at in (0.22, 0.76))
    return (f'<g transform="translate({left:.1f} {top:.1f}) scale({scale:.3f})">'
            f'<g fill="#7896c3" opacity="0.28" filter="url(#glow)">{figure}</g>'
            f'<g fill="{FIGURE}" stroke="{RIM}" stroke-opacity="0.45" stroke-width="1.4">{figure}</g></g>'
            f'{strings}<rect x="{board_x:.1f}" y="{board_y:.1f}" width="{board_w:.1f}" height="{board_h:.1f}" fill="#0a0b0e" stroke="#3d4149" stroke-width="{2 * scale:.1f}"/>'
            f'<g fill="{INK}" transform="translate({x - q_w / 2:.1f} {board_y + board_h * 0.7:.1f})">{q}</g>{hands}')


# The tile: three suspects against the chart, the title along the foot.
tile = 600
defs, body = room(tile, tile, 0, [150, 300, 450])
# Their boards clear of the title, which covers their waists.
suspects = ''.join(held(look, x, 468, 1.32, '?') for look, x in (('cap', 128), ('hoodie', 300), ('glasses', 472)))
title_scale = tile * 0.62 / title_w
(out / 'tile-line-up.svg').write_text(
    f'<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 {tile} {tile}" role="img" aria-label="Line-Up">\n'
    '<!-- Line-Up\'s tile, drawn by Modules/Game/Resources/art/make_lineup_art.py. Title: title-line-up.svg. -->\n'
    f'<defs>{defs}</defs>\n{body}\n{suspects}\n'
    f'<rect x="0" y="{tile - 168}" width="{tile}" height="168" fill="url(#band)"/>'
    f'<g transform="translate({(tile - title_w * title_scale) / 2:.1f} {tile - 36 - title_h * title_scale:.1f}) scale({title_scale:.5f})">'
    f'<g fill="#000" fill-opacity="0.6" transform="translate(0 26)">{shadow}</g>{lettering}</g>\n'
    '</svg>\n', encoding='utf-8', newline='\n')

# The picture beside the title on the Games page: five suspects in the room, their places numbered on the floor.
pw, ph, floor_h = 960, 560, 96
places = [120 + 180 * i for i in range(5)]
defs, body = room(pw, ph, floor_h, places)
looks = ['cap', 'hoodie', 'glasses', 'bun', 'collar']
suspects = ''.join(held(look, x, ph - floor_h, 1.42, '?') for look, x in zip(looks, places))
numbers = ''
for i, x in enumerate(places):
    glyph, glyph_w = word(str(i + 1), 0, 0, 34)
    numbers += f'<g fill="{INK}" fill-opacity="0.6" transform="translate({x - glyph_w / 2:.1f} {ph - floor_h + 58})">' + ''.join(f'<path d="{d}"/>' for _, d in glyph) + '</g>'
(out / 'picture-line-up.svg').write_text(
    f'<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 {pw} {ph}" role="img" aria-label="A line-up of five suspects">\n'
    '<!-- Line-Up\'s line-up room, drawn by Modules/Game/Resources/art/make_lineup_art.py. -->\n'
    f'<defs>{defs}</defs>\n{body}\n{suspects}\n{numbers}\n'
    '</svg>\n', encoding='utf-8', newline='\n')

# The lettering, as a web font: Oswald's every weight, compressed.
web = TTFont(font_path)
web.flavor = 'woff2'
web.save(out / 'oswald.woff2')

print(f'line-up-suspects.svg, title-line-up.svg ({title_w:.0f} by {title_h:.0f}), tile-line-up.svg, picture-line-up.svg and oswald.woff2')
