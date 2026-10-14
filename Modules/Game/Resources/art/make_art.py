"""Draws the Games page's art, in public/modules/game:

- doodles.svg: a square of faint, outlined crime doodles (a police badge, a fingerprint, a magnifying glass, a lock,
  a motorbike…) that repeats seamlessly behind the library's games, in the style of a sticker-bombed backdrop;
- tile-higher-or-lower.svg: Higher or Lower's square tile, its title (title.svg) over the doodles and a green and red
  glow;
- map-districtguessr.svg: Malaysia's states (the Map module's malaysia-states.geojson, simplified), with a guess and
  its answer, beside DistrictGuessr's title;
- tile-districtguessr.svg: DistrictGuessr's tile, its title (title-districtguessr.svg) over that map, the doodles and a
  blue glow.

The doodles are Material Icons Round, which the app serves from public/fonts (Apache License 2.0, see
material-icons-LICENSE.txt there), drawn as outlines. Only needed again to change the art; the pages use the SVG files.
With fonttools and brotli installed (pip install fonttools brotli), from the project's folder:

    python Modules/Game/Resources/art/make_art.py
"""
import json
import math
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


def title(name='title.svg'):
    """A drawn title's width, height and drawing (its coloured groups), from make_title.py's SVG files."""
    svg = (OUT / name).read_text(encoding='utf-8')
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



def simplify(points, tolerance):
    """A line's points with those it barely bends at left out (Ramer–Douglas–Peucker), so the outline stays small."""
    if len(points) < 3:
        return points
    keep = [False] * len(points)
    keep[0] = keep[-1] = True
    stack = [(0, len(points) - 1)]
    while stack:
        first, last = stack.pop()
        (x1, y1), (x2, y2) = points[first], points[last]
        length = math.hypot(x2 - x1, y2 - y1)
        furthest, index = 0.0, None
        for i in range(first + 1, last):
            x, y = points[i]
            # From the line between the ends, or for a closed outline, whose ends are the same point, from that point.
            distance = abs((x2 - x1) * (y1 - y) - (x1 - x) * (y2 - y1)) / length if length else math.hypot(x - x1, y - y1)
            if distance > furthest:
                furthest, index = distance, i
        if index is not None and furthest > tolerance:
            keep[index] = True
            stack += [(first, index), (index, last)]
    return [point for point, kept in zip(points, keep) if kept]


def malaysia(width):
    """Malaysia's states as one SVG path, width wide, from the Map module's boundaries; its height; and a function from
    a latitude and longitude to the drawing's x and y. Tiny islands are left out."""
    states = json.loads((PUBLIC / 'modules/map/malaysia-states.geojson').read_text(encoding='utf-8'))['features']
    rings = []
    for state in states:
        shape = state['geometry']
        for polygon in shape['coordinates'] if shape['type'] == 'MultiPolygon' else [shape['coordinates']]:
            rings.append(polygon[0])
    lngs = [lng for ring in rings for lng, _ in ring]
    lats = [lat for ring in rings for _, lat in ring]
    scale = width / (max(lngs) - min(lngs))

    def place(lat, lng):
        return (lng - min(lngs)) * scale, (max(lats) - lat) * scale

    path = []
    for ring in rings:
        points = simplify([place(lat, lng) for lng, lat in ring], tolerance=width / 1100)
        xs, ys = [x for x, _ in points], [y for _, y in points]
        if len(points) < 4 or (max(xs) - min(xs)) * (max(ys) - min(ys)) < (width / 300) ** 2:
            continue
        path.append('M' + 'L'.join(f'{x:.1f} {y:.1f}' for x, y in points) + 'Z')
    return ''.join(path), (max(lats) - min(lats)) * scale, place


def pin(x, y, size, colour):
    """A map pin (Material's location_on), its point at x, y."""
    bounds = BoundsPen(glyphs)
    glyphs['location_on'].draw(bounds)
    x_min, y_min, x_max, y_max = bounds.bounds
    scale = size / (y_max - y_min)
    pen = SVGPathPen(glyphs)
    glyphs['location_on'].draw(TransformPen(pen, (scale, 0, 0, -scale, x - (x_min + x_max) / 2 * scale, y + y_min * scale)))
    return f'<path fill="{colour}" d="{pen.getCommands()}"/>'


def guessed(place, size):
    """A guess and the answer, as DistrictGuessr shows them: a blue pin some way off Batu Pahat's green one, joined by a
    dashed line."""
    answer = place(1.85748, 102.93981)
    guess = place(2.72, 101.94)
    return (f'<line x1="{guess[0]:.1f}" y1="{guess[1]:.1f}" x2="{answer[0]:.1f}" y2="{answer[1]:.1f}" stroke="#eef2f6" '
            f'stroke-width="{size / 14:.1f}" stroke-dasharray="{size / 6:.1f} {size / 8:.1f}" stroke-linecap="round"/>'
            + pin(*guess, size, '#3aaaf2') + pin(*answer, size, GREEN))


BLUE = '#3aaaf2'

# DistrictGuessr's picture beside its title on the Games page: Malaysia with a guess and its answer.
picture = 900
outline, outline_height, place = malaysia(picture)
(OUT / 'map-districtguessr.svg').write_text(
    f'<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 -40 {picture} {outline_height + 40:.0f}" role="img" aria-label="Malaysia">\n'
    f'<!-- Malaysia\'s states, from public/modules/map/malaysia-states.geojson. {credit} -->\n'
    f'<path d="{outline}" fill="#1a2028" fill-opacity="0.9" stroke="{BLUE}" stroke-opacity="0.55" stroke-width="1.4" '
    'stroke-linejoin="round" vector-effect="non-scaling-stroke"/>\n'
    f'{guessed(place, 54)}\n</svg>\n', encoding='utf-8', newline='\n')

# DistrictGuessr's tile: its title over Malaysia, a guess and its answer, the doodles and a blue glow.
width, height, drawing = title('title-districtguessr.svg')
scale = tile * 0.76 / width
outline, outline_height, place = malaysia(tile * 0.9)
(OUT / 'tile-districtguessr.svg').write_text(
    f'<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 {tile} {tile}" role="img" aria-label="DistrictGuessr">\n'
    f'<!-- DistrictGuessr\'s tile. {credit} Title: title-districtguessr.svg. Map: malaysia-states.geojson. -->\n'
    '<defs>\n'
    f'<pattern id="doodles" width="{SIZE}" height="{SIZE}" patternUnits="userSpaceOnUse" patternTransform="scale(0.6)">{pattern}</pattern>\n'
    f'<radialGradient id="blue" cx="0.5" cy="0.62" r="0.62"><stop offset="0" stop-color="{BLUE}" stop-opacity="0.3"/><stop offset="1" stop-color="{BLUE}" stop-opacity="0"/></radialGradient>\n'
    '</defs>\n'
    f'<rect width="{tile}" height="{tile}" fill="{BLACK}"/>\n'
    f'<rect width="{tile}" height="{tile}" fill="url(#doodles)"/>\n'
    f'<rect width="{tile}" height="{tile}" fill="url(#blue)"/>\n'
    f'<g transform="translate({(tile - width * scale) / 2:.1f} {tile * 0.17:.1f}) scale({scale:.5f})">{drawing}</g>\n'
    f'<g transform="translate({tile * 0.05:.1f} {tile * 0.6 - outline_height / 2:.1f})">'
    f'<path d="{outline}" fill="#1a2028" stroke="{BLUE}" stroke-opacity="0.6" stroke-width="1.2" stroke-linejoin="round" vector-effect="non-scaling-stroke"/>'
    f'{guessed(place, 40)}</g>\n'
    '</svg>\n', encoding='utf-8', newline='\n')

print('doodles.svg, tile-higher-or-lower.svg, map-districtguessr.svg and tile-districtguessr.svg')
