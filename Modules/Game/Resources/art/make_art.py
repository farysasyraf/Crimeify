"""Draws the game library's art for Higher or Lower, in public/modules/game:

- doodles.svg: a square of faint, outlined crime doodles (a police badge, a fingerprint, a magnifying glass, a lock,
  a motorbike…) that repeats seamlessly behind the library's games, in the style of a sticker-bombed backdrop;
- tile-higher-or-lower.svg: the game's square tile, its title (title.svg) over the doodles and a green and red glow.

The doodles are Material Icons Round, which the app serves from public/fonts (Apache License 2.0, see
material-icons-LICENSE.txt there), drawn as outlines. Only needed again to change the art; the pages use the SVG files.
With fonttools and brotli installed (pip install fonttools brotli), from the project's folder:

    python Modules/Game/Resources/art/make_art.py
"""
import random
import re
from pathlib import Path

from fontTools.pens.boundsPen import BoundsPen
from fontTools.pens.svgPathPen import SVGPathPen
from fontTools.pens.transformPen import TransformPen
from fontTools.ttLib import TTFont

PUBLIC = Path('public')
OUT = PUBLIC / 'modules/game'
GREEN, RED, BLACK = '#59ac51', '#c75555', '#0c0d10'

# The doodles, each Material icon by name, and the square they repeat in, as a grid of cells with one each.
ICONS = ['local_police', 'fingerprint', 'search', 'lock', 'two_wheeler', 'visibility', 'gavel', 'door_front',
         'key', 'directions_car', 'shield', 'pin_drop', 'local_shipping', 'policy', 'warning', 'emoji_events']
SIZE, CELLS = 600, 5

font = TTFont(PUBLIC / 'fonts/material-icons-round.woff2')
glyphs = font.getGlyphSet()


def doodle(name, x, y, size, angle):
    """An icon as an SVG path, its middle at x, y, size wide or tall, turned by angle degrees."""
    bounds = BoundsPen(glyphs)
    glyphs[name].draw(bounds)
    x_min, y_min, x_max, y_max = bounds.bounds
    scale = size / max(x_max - x_min, y_max - y_min)
    pen = SVGPathPen(glyphs)
    # The font's y goes up: flip it, about the icon's middle.
    glyphs[name].draw(TransformPen(pen, (scale, 0, 0, -scale, -(x_min + x_max) / 2 * scale, (y_min + y_max) / 2 * scale)))
    return f'<path transform="translate({x:.1f} {y:.1f}) rotate({angle:.0f})" d="{pen.getCommands()}"/>'


def doodles():
    """One doodle a cell, each off its middle, turned, and of its own size, the icons shuffled so no two the same are
    side by side, and the same every time."""
    pick = random.Random(7)
    cell = SIZE / CELLS
    names = (ICONS * 2)[:CELLS * CELLS]
    pick.shuffle(names)
    paths = []
    for row in range(CELLS):
        for column in range(CELLS):
            x = (column + 0.5) * cell + pick.uniform(-cell * 0.24, cell * 0.24)
            y = (row + 0.5) * cell + pick.uniform(-cell * 0.24, cell * 0.24)
            size = pick.uniform(cell * 0.34, cell * 0.5)
            paths.append(doodle(names[row * CELLS + column], x, y, size, pick.uniform(-30, 30)))
    # Outlines only, thin and faint, the same width however large they're shown.
    return ('<g fill="none" stroke="#ffffff" stroke-opacity="0.12" stroke-width="1.4" stroke-linejoin="round" '
            'vector-effect="non-scaling-stroke">' + ''.join(paths) + '</g>')


def title():
    """title.svg's width, height and drawing (its two coloured groups)."""
    svg = (OUT / 'title.svg').read_text(encoding='utf-8')
    width, height = (float(n) for n in re.search(r'viewBox="0 0 (\S+) (\S+)"', svg).groups())
    return width, height, ''.join(re.findall(r'<g fill="[^"]+">.*?</g>', svg, flags=re.S))


credit = 'Doodles: Material Icons Round (Apache License 2.0). Drawn by Modules/Game/Resources/art/make_art.py.'
pattern = doodles()

(OUT / 'doodles.svg').write_text(
    f'<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 {SIZE} {SIZE}" width="{SIZE}" height="{SIZE}">\n'
    f'<!-- {credit} -->\n{pattern}\n</svg>\n', encoding='utf-8', newline='\n')

# The tile: the doodles, smaller, the glow, and the title across the middle.
tile = 600
width, height, drawing = title()
scale = tile * 0.8 / width
(OUT / 'tile-higher-or-lower.svg').write_text(
    f'<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 {tile} {tile}" role="img" aria-label="Higher or Lower">\n'
    f'<!-- Higher or Lower\'s tile. {credit} Title: title.svg. -->\n'
    '<defs>\n'
    f'<pattern id="doodles" width="{SIZE}" height="{SIZE}" patternUnits="userSpaceOnUse" patternTransform="scale(0.6)">{pattern}</pattern>\n'
    f'<radialGradient id="green" cx="0.3" cy="0.3" r="0.6"><stop offset="0" stop-color="{GREEN}" stop-opacity="0.32"/><stop offset="1" stop-color="{GREEN}" stop-opacity="0"/></radialGradient>\n'
    f'<radialGradient id="red" cx="0.72" cy="0.74" r="0.55"><stop offset="0" stop-color="{RED}" stop-opacity="0.3"/><stop offset="1" stop-color="{RED}" stop-opacity="0"/></radialGradient>\n'
    '</defs>\n'
    f'<rect width="{tile}" height="{tile}" fill="{BLACK}"/>\n'
    f'<rect width="{tile}" height="{tile}" fill="url(#doodles)"/>\n'
    f'<rect width="{tile}" height="{tile}" fill="url(#green)"/>\n'
    f'<rect width="{tile}" height="{tile}" fill="url(#red)"/>\n'
    f'<g transform="translate({(tile - width * scale) / 2:.1f} {(tile - height * scale) / 2:.1f}) scale({scale:.5f})">{drawing}</g>\n'
    '</svg>\n', encoding='utf-8', newline='\n')

print('doodles.svg and tile-higher-or-lower.svg')
