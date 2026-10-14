"""Draws the games' titles in public/modules/game, shown in both languages:

- title.svg, Higher or Lower's: two words stacked in Montserrat Black, the top one green with its first letter's stem
  rising into an up arrow, the bottom one red with its last letter's stem, or for an R its leg, dropping into a down
  arrow;
- title-districtguessr.svg, DistrictGuessr's: DISTRICT over GUESSR in blue, beside a map pin as tall as both (Material
  Icons Round, which the app serves from public/fonts, Apache License 2.0).

Only needed again to change the words, the colours or the arrows; the pages use the SVG files. Montserrat is under the
SIL Open Font License 1.1 (public/modules/game/Montserrat-OFL.txt): download the variable font from
https://github.com/google/fonts/raw/main/ofl/montserrat/Montserrat%5Bwght%5D.ttf, then, with fonttools, brotli and
uharfbuzz installed (pip install fonttools brotli uharfbuzz), from the project's folder:

    python Modules/Game/Resources/title/make_title.py Montserrat[wght].ttf public/modules/game
"""
import io
import sys

import uharfbuzz as hb
from fontTools.pens.boundsPen import BoundsPen
from fontTools.pens.recordingPen import RecordingPen
from fontTools.pens.svgPathPen import SVGPathPen
from fontTools.pens.transformPen import TransformPen
from fontTools.ttLib import TTFont
from fontTools.varLib.instancer import instantiateVariableFont

GREEN, RED, LIGHT, BLUE = '#59ac51', '#c75555', '#eef2f6', '#3aaaf2'

font_path, out_dir = sys.argv[1], sys.argv[2]

# Montserrat at its heaviest, Black (900).
data = io.BytesIO()
instantiateVariableFont(TTFont(font_path), {'wght': 900}).save(data)
data = data.getvalue()
font = TTFont(io.BytesIO(data))
glyphs = font.getGlyphSet()
cap = font['OS/2'].sCapHeight
hb_font = hb.Font(hb.Face(data))


def straight_edges(name):
    """The glyph's straight edges, as ((x1, y1), (x2, y2)), in font units with y up."""
    rec = RecordingPen()
    glyphs[name].draw(rec)
    lines, start, cur = [], None, None
    for op, args in rec.value:
        if op == 'moveTo':
            start = cur = args[0]
        elif op == 'lineTo':
            lines.append((cur, args[0]))
            cur = args[0]
        elif op in ('qCurveTo', 'curveTo'):
            cur = args[-1]
        elif op in ('closePath', 'endPath'):
            if cur != start:
                lines.append((cur, start))
            cur = start
    return lines


def stem_edges(name):
    """The x of each vertical edge at least half the cap height long, left to right."""
    return sorted({round(a[0]) for a, b in straight_edges(name) if abs(a[0] - b[0]) < 1 and abs(a[1] - b[1]) > cap * 0.5})


# The width of a stem, from H's left one. The arrows' shafts are as wide, and their heads and lengths in proportion.
STEM = stem_edges('H')[1] - stem_edges('H')[0]
SHAFT = cap * 0.2
HEAD_WIDTH = STEM * 2.3
HEAD_HEIGHT = STEM * 1.25
# How far a shaft starts inside its letter, so no seam shows between them.
OVERLAP = 24


def stroke(name, which):
    """The stroke of a glyph an arrow grows from, as (left x, right x): its left stem (H), its right one (H), its only
    one (T), or its leg (R, drawn as a P with a straight leg under the bowl's right side)."""
    edges = stem_edges(name)
    if which == 'left' or which == 'only':
        return edges[0], edges[1]
    if which == 'right':
        return edges[-2], edges[-1]
    if which == 'leg':
        bounds = BoundsPen(glyphs)
        glyphs['P'].draw(bounds)
        return bounds.bounds[2] - STEM, bounds.bounds[2]
    raise ValueError(which)


def set_word(text, dx, baseline, last_as=None):
    """The word as one SVG path, kerned, with its left ink edge at dx and its baseline at y = baseline (SVG's y grows
    downwards), its last glyph drawn as last_as if given. Returns the path, each glyph's name and x, its ink width, and
    where its glyphs' x is measured from."""
    buf = hb.Buffer()
    buf.add_str(text)
    buf.guess_segment_properties()
    hb.shape(hb_font, buf, {'kern': True})
    x, placed = 0, []
    for info, pos in zip(buf.glyph_infos, buf.glyph_positions):
        placed.append((font.getGlyphName(info.codepoint), x + pos.x_offset))
        x += pos.x_advance
    if last_as:
        placed[-1] = (last_as, placed[-1][1])

    bounds = BoundsPen(glyphs)
    for name, x in placed:
        glyphs[name].draw(TransformPen(bounds, (1, 0, 0, 1, x, 0)))
    x_min, _, x_max, _ = bounds.bounds
    origin = dx - x_min

    pen = SVGPathPen(glyphs)
    for name, x in placed:
        glyphs[name].draw(TransformPen(pen, (1, 0, 0, -1, origin + x, baseline)))
    return pen.getCommands(), placed, x_max - x_min, origin


def arrow(left, right, from_y, up):
    """A shaft between left and right from from_y, then a head, pointing up or down."""
    sign = -1 if up else 1
    mid = (left + right) / 2
    start, shaft_end = from_y - sign * OVERLAP, from_y + sign * SHAFT
    tip = shaft_end + sign * HEAD_HEIGHT
    return (f'M{left:.0f} {start:.0f}H{right:.0f}V{shaft_end:.0f}H{mid + HEAD_WIDTH / 2:.0f}L{mid:.0f} {tip:.0f}'
            f'L{mid - HEAD_WIDTH / 2:.0f} {shaft_end:.0f}H{left:.0f}Z')


def title(top, bottom, top_stroke, bottom_stroke, label, out):
    rise = SHAFT + HEAD_HEIGHT
    top_base = rise + cap
    bottom_base = top_base + cap * 1.14
    # Room on the left for the up arrow's head, which is wider than the stem it grows from.
    margin = HEAD_WIDTH / 2
    leg = bottom_stroke == 'leg'

    top_path, top_placed, top_width, top_origin = set_word(top, margin, top_base)
    bottom_path, bottom_placed, bottom_width, bottom_origin = set_word(bottom, margin, bottom_base, 'P' if leg else None)

    name, x = top_placed[0]
    left, right = stroke(name, top_stroke)
    up = arrow(top_origin + x + left, top_origin + x + right, top_base - cap, up=True)

    name, x = bottom_placed[-1]
    left, right = (bottom_origin + x + edge for edge in stroke(name, bottom_stroke))
    down = arrow(left, right, bottom_base, up=False)
    if leg:
        # The leg, from the baseline up to where the bowl is furthest out: of the points on that line, the middle one
        # is on the curve, with its control points above and below it.
        rec = RecordingPen()
        glyphs['P'].draw(rec)
        out_x = right - bottom_origin - x
        on_line = sorted(pt[1] for _, args in rec.value for pt in args if isinstance(pt, tuple) and abs(pt[0] - out_x) < 1)
        down += f'M{left:.0f} {bottom_base - on_line[len(on_line) // 2]:.0f}H{right:.0f}V{bottom_base:.0f}H{left:.0f}Z'

    width = max(margin + top_width, margin + bottom_width, (left + right) / 2 + HEAD_WIDTH / 2)
    height = bottom_base + rise
    svg = (
        f'<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 {width:.0f} {height:.0f}" role="img" aria-label="{label}">\n'
        f'<!-- {label}, drawn by Modules/Game/Resources/title/make_title.py: lettering in Montserrat Black, SIL Open '
        'Font License 1.1 (Montserrat-OFL.txt). -->\n'
        # The arrows are paths of their own: in the same path as the letters, where they overlap would cancel out.
        f'<g fill="{GREEN}"><path d="{top_path}"/><path d="{up}"/></g>\n'
        f'<g fill="{RED}"><path d="{bottom_path}"/><path d="{down}"/></g>\n'
        '</svg>\n'
    )
    with open(f'{out_dir}/{out}', 'w', encoding='utf-8', newline='\n') as file:
        file.write(svg)
    print(f'{out}: {width:.0f} x {height:.0f}')


def wordmark(top, bottom, label, out):
    """Two words stacked in Montserrat Black, the top one light and the bottom one blue, after a blue map pin as tall as
    both."""
    icons = TTFont('public/fonts/material-icons-round.woff2').getGlyphSet()
    bounds = BoundsPen(icons)
    icons['location_on'].draw(bounds)
    x_min, y_min, x_max, y_max = bounds.bounds
    gap = cap * 0.14
    height = cap * 2 + gap
    scale = height / (y_max - y_min)
    pin = SVGPathPen(icons)
    icons['location_on'].draw(TransformPen(pin, (scale, 0, 0, -scale, -x_min * scale, y_max * scale)))

    left = (x_max - x_min) * scale + cap * 0.22
    top_path, _, top_width, _ = set_word(top, left, cap)
    bottom_path, _, bottom_width, _ = set_word(bottom, left, height)
    width = left + max(top_width, bottom_width)

    svg = (
        f'<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 {width:.0f} {height:.0f}" role="img" aria-label="{label}">\n'
        f'<!-- {label}, drawn by Modules/Game/Resources/title/make_title.py: lettering in Montserrat Black, SIL Open '
        'Font License 1.1 (Montserrat-OFL.txt); the pin, Material Icons Round (Apache License 2.0). -->\n'
        f'<g fill="{BLUE}"><path d="{pin.getCommands()}"/><path d="{bottom_path}"/></g>\n'
        f'<g fill="{LIGHT}"><path d="{top_path}"/></g>\n'
        '</svg>\n'
    )
    with open(f'{out_dir}/{out}', 'w', encoding='utf-8', newline='\n') as file:
        file.write(svg)
    print(f'{out}: {width:.0f} x {height:.0f}')


title('HIGHER', 'LOWER', 'left', 'leg', 'Higher or Lower', 'title.svg')
wordmark('DISTRICT', 'GUESSR', 'DistrictGuessr', 'title-districtguessr.svg')
