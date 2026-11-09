"""Draws Case File's pixel art, in public/modules/game:

- case-room.png: a detective's office at dusk, shown at a few times its size with its pixels kept square: blinds half
  up over a window onto the city, a bookshelf, a clock, a coat stand with a hat, a corkboard of pinned notes and red
  string, and the desk, its green banker's lamp lighting it, the phone and a mug, the app's name on a brass plate on
  its front; 320 x 240, with a ceiling and more floor than the office itself (office(), 320 x 180), so it fills a
  phone's taller screen;
- case-clues.png: the clues on the desk and the board, each closed and open, side by side in a row of its own (the
  folder, the clipboard, the ledger, the phone note and the photo), which the page lays over the office where they sit
  (CLUES below, in the office's pixels, ROOM_TOP lower in the room's, which _case-screen.blade.php's positions follow);
- picture-case-file.png: the office at twice its size, its clues closed, beside the title on the Games page;
- tile-case-file.png: the game's tile, the desk and lamp close up under its title;
- title-case-file.svg: CASE over FILE in Silkscreen Bold, a pixel typeface (SIL Open Font License 1.1, Silkscreen-OFL.txt),
  after a manila folder;
- silkscreen.woff2: Silkscreen Regular for the game's labels, and pixelify.woff2: Pixelify Sans (SIL Open Font License 1.1,
  PixelifySans-OFL.txt), a pixel typeface with small letters, for its words;
- case-wood.png and case-cork.png: the desk's top and the corkboard close up, repeating, behind the clues' close-ups
  and the suspects; case-mugshot.png, the rap sheet's mugshot; and case-phone.png, the phone the tip-off comes on.

Everything is drawn here, pixel by pixel, in a small palette, with ordered dithering for light rather than blending, so
it stays crisp. Only needed again to change the art; the pages use the files. Silkscreen and Pixelify Sans are from
https://github.com/google/fonts/tree/main/ofl/silkscreen and .../ofl/pixelifysans. With Pillow, fonttools and brotli
installed (pip install pillow fonttools brotli), from the project's folder:

    python Modules/Game/Resources/art/make_case_art.py Silkscreen-Regular.ttf Silkscreen-Bold.ttf "PixelifySans[wght].ttf"
"""
import random
import sys
from pathlib import Path

from fontTools.pens.boundsPen import BoundsPen
from fontTools.pens.svgPathPen import SVGPathPen
from fontTools.pens.transformPen import TransformPen
from fontTools.ttLib import TTFont
from PIL import Image

OUT = Path('public/modules/game')
W, H = 320, 180

# The palette: the night's darks, the wall, the wood, the lamp's green and brass, paper, the dusk outside, and the
# accents (the app's blue, amber, and the string's red).
C = {
    'ink': (12, 13, 17), 'shade': (20, 22, 28),
    'wall0': (30, 33, 42), 'wall1': (38, 42, 53), 'wall2': (47, 52, 65), 'trim': (24, 26, 33),
    'floor0': (34, 25, 23), 'floor1': (46, 34, 30), 'floor2': (58, 43, 36), 'floorlit': (112, 80, 56),
    'wood0': (54, 32, 23), 'wood1': (82, 49, 32), 'wood2': (110, 68, 42), 'wood3': (142, 92, 57), 'wood4': (176, 120, 74),
    'green0': (22, 54, 37), 'green1': (36, 86, 56), 'green2': (64, 128, 86), 'green3': (120, 184, 130),
    'brass0': (110, 82, 38), 'brass1': (170, 130, 60), 'brass2': (222, 184, 98),
    'paper0': (156, 144, 122), 'paper1': (202, 190, 164), 'paper2': (236, 228, 206), 'line': (120, 112, 98),
    'manila0': (150, 108, 52), 'manila1': (196, 150, 78), 'manila2': (226, 184, 112),
    'sky0': (52, 46, 84), 'sky1': (98, 62, 98), 'sky2': (170, 84, 92), 'sky3': (226, 128, 86), 'sky4': (246, 182, 104),
    'city0': (24, 22, 36), 'city1': (34, 30, 48), 'lit': (246, 204, 112),
    'cork0': (112, 78, 44), 'cork1': (140, 100, 58), 'cork2': (166, 124, 74),
    'red0': (128, 32, 36), 'red1': (190, 56, 56), 'red2': (232, 98, 88),
    'blue0': (24, 72, 112), 'blue1': (58, 170, 242), 'amber': (245, 194, 107), 'yellow0': (200, 170, 60), 'yellow1': (240, 214, 96),
    'grey0': (70, 74, 84), 'grey1': (110, 114, 124), 'grey2': (170, 174, 182), 'white': (240, 238, 230),
    'coat0': (110, 92, 64), 'coat1': (146, 124, 86), 'coat2': (178, 156, 112),
    'glow': (255, 214, 140),
}

# Where each clue sits in the office, as x, y, width, height: case.css lays them over it there.
CLUES = {
    'folder': (90, 98, 42, 20),
    'clipboard': (138, 92, 26, 22),
    'ledger': (170, 102, 30, 16),
    'note': (254, 103, 16, 13),
    'photo': (264, 19, 30, 34),
}

BAYER = [[0, 8, 2, 10], [12, 4, 14, 6], [3, 11, 1, 9], [15, 7, 13, 5]]


class Canvas:
    def __init__(self, width, height, fill=None):
        self.image = Image.new('RGBA', (width, height), (0, 0, 0, 0) if fill is None else C[fill] + (255,))
        self.px = self.image.load()
        self.w, self.h = width, height

    def dot(self, x, y, colour):
        if 0 <= x < self.w and 0 <= y < self.h:
            self.px[x, y] = (C[colour] if isinstance(colour, str) else colour) + (255,)

    def rect(self, x0, y0, x1, y1, colour):
        """Fills x0 to x1 and y0 to y1, both ends in."""
        for y in range(y0, y1 + 1):
            for x in range(x0, x1 + 1):
                self.dot(x, y, colour)

    def frame(self, x0, y0, x1, y1, colour):
        self.rect(x0, y0, x1, y0, colour)
        self.rect(x0, y1, x1, y1, colour)
        self.rect(x0, y0, x0, y1, colour)
        self.rect(x1, y0, x1, y1, colour)

    def line(self, x0, y0, x1, y1, colour):
        """A straight line, pixel by pixel (Bresenham)."""
        dx, dy = abs(x1 - x0), -abs(y1 - y0)
        sx, sy = (1 if x0 < x1 else -1), (1 if y0 < y1 else -1)
        err = dx + dy
        while True:
            self.dot(x0, y0, colour)
            if x0 == x1 and y0 == y1:
                return
            e2 = 2 * err
            if e2 >= dy:
                err += dy
                x0 += sx
            if e2 <= dx:
                err += dx
                y0 += sy

    def polygon(self, points, colour):
        """Fills a polygon: each row between its edges."""
        ys = [y for _, y in points]
        for y in range(min(ys), max(ys) + 1):
            crossings = []
            for (xa, ya), (xb, yb) in zip(points, points[1:] + points[:1]):
                if (ya <= y < yb) or (yb <= y < ya):
                    crossings.append(xa + (y - ya) * (xb - xa) / (yb - ya))
            crossings.sort()
            for left, right in zip(crossings[::2], crossings[1::2]):
                for x in range(round(left), round(right)):
                    self.dot(x, y, colour)

    def tint(self, x, y, colour, amount):
        """The pixel moved towards a colour, by a share."""
        if 0 <= x < self.w and 0 <= y < self.h:
            r, g, b, a = self.px[x, y]
            if a == 0:
                return
            t = C[colour]
            self.px[x, y] = (round(r + (t[0] - r) * amount), round(g + (t[1] - g) * amount), round(b + (t[2] - b) * amount), a)

    def glow(self, cx, cy, rx, ry, colour, strength, inside=None, step=0.12):
        """Light falling off from a middle, as ordered dithering: where it's brighter, more of the 4 x 4 pattern's
        pixels are lit, each by the same small step, up to four, so it stays a few colours rather than a smooth wash.
        Only where inside(x, y) says, if it's given, as on the desk's top and not the wall beside it."""
        for y in range(max(0, cy - ry), min(self.h, cy + ry + 1)):
            for x in range(max(0, cx - rx), min(self.w, cx + rx + 1)):
                d = ((x - cx) / rx) ** 2 + ((y - cy) / ry) ** 2
                if d >= 1 or (inside and not inside(x, y)):
                    continue
                level = strength * (1 - d) ** 1.4
                for k in range(4):
                    if BAYER[y % 4][x % 4] / 16 < min(level - k, 1):
                        self.tint(x, y, colour, step)

    def paste(self, other, x, y):
        self.image.alpha_composite(other.image, (x, y))


def office(regular, hands=True):
    pick = random.Random(21)
    c = Canvas(W, H, 'wall1')

    # The wall: faint wallpaper stripes, a picture rail, and the skirting.
    for x in range(0, W, 9):
        c.rect(x, 4, x, 113, 'wall0')
    c.rect(0, 0, W - 1, 3, 'trim')
    c.rect(0, 4, W - 1, 4, 'wall2')
    c.rect(0, 113, W - 1, 118, 'wood0')
    c.rect(0, 113, W - 1, 113, 'wood1')

    # The floor: planks, each a little lighter or darker, with their joints.
    for row, y in enumerate(range(119, H, 7)):
        tone = 'floor1' if row % 2 else 'floor0'
        c.rect(0, y, W - 1, min(H - 1, y + 6), tone)
        c.rect(0, y, W - 1, y, 'floor0' if tone == 'floor1' else 'shade')
        offset = pick.randrange(0, 40)
        for x in range(offset, W, 47):
            c.rect(x, y, x, min(H - 1, y + 6), 'shade')

    # The window: dusk over the city, the blinds half up, the light it throws on the floor.
    gx0, gy0, gx1, gy1 = 124, 16, 196, 78
    bands = ['sky0', 'sky1', 'sky2', 'sky3', 'sky4']
    for y in range(gy0, gy1 + 1):
        share = (y - gy0) / (gy1 - gy0) * (len(bands) - 1)
        low, mix = int(share), share - int(share)
        for x in range(gx0, gx1 + 1):
            band = bands[min(low + (1 if BAYER[y % 4][x % 4] / 16 < mix else 0), len(bands) - 1)]
            c.dot(x, y, band)
    # The sun going down behind the towers.
    for y in range(58, 79):
        for x in range(150, 178):
            if (x - 164) ** 2 + (y - 70) ** 2 <= 100:
                c.dot(x, y, 'amber')
    # The city: towers of different heights, some windows lit.
    x = gx0
    while x <= gx1:
        width = pick.randrange(5, 11)
        top = pick.randrange(48, 66)
        tone = 'city0' if pick.random() < 0.6 else 'city1'
        c.rect(x, top, min(gx1, x + width - 1), gy1, tone)
        for wy in range(top + 2, gy1 - 1, 3):
            for wx in range(x + 1, min(gx1, x + width - 1), 2):
                if pick.random() < 0.22:
                    c.dot(wx, wy, 'lit')
        x += width + pick.randrange(0, 2)
    # The frame, its bars, the blinds over the top, their cord, and the sill.
    c.frame(gx0 - 1, gy0 - 1, gx1 + 1, gy1 + 1, 'ink')
    c.rect(gx0 - 5, gy0 - 5, gx1 + 5, gy0 - 2, 'wood1')
    c.rect(gx0 - 5, gy0 - 2, gx0 - 2, gy1 + 2, 'wood1')
    c.rect(gx1 + 2, gy0 - 2, gx1 + 5, gy1 + 2, 'wood1')
    c.rect(gx0 - 5, gy0 - 5, gx1 + 5, gy0 - 5, 'wood2')
    c.rect(160, gy0, 161, gy1, 'wood1')
    c.rect(gx0, 47, gx1, 48, 'wood1')
    for y in range(gy0, 38, 4):
        c.rect(gx0, y, gx1, y + 2, 'paper0')
        c.rect(gx0, y, gx1, y, 'paper1')
        c.rect(gx0, y + 2, gx1, y + 2, 'line')
    c.rect(gx0, 38, gx1, 39, 'paper1')
    c.rect(193, 40, 193, 60, 'paper1')
    c.rect(192, 61, 194, 63, 'paper0')
    c.rect(gx0 - 8, gy1 + 2, gx1 + 8, gy1 + 5, 'wood2')
    c.rect(gx0 - 8, gy1 + 2, gx1 + 8, gy1 + 2, 'wood3')
    c.rect(gx0 - 8, gy1 + 6, gx1 + 8, gy1 + 6, 'wood0')
    # A plant on the sill.
    c.rect(130, gy1 - 3, 137, gy1 + 1, 'red0')
    c.rect(130, gy1 - 3, 137, gy1 - 3, 'red1')
    for lx, ly in [(131, gy1 - 6), (133, gy1 - 9), (135, gy1 - 7), (136, gy1 - 10), (132, gy1 - 11), (134, gy1 - 5)]:
        c.line(133, gy1 - 4, lx, ly, 'green2')
    # The dusk's light on the wall below the window. (The desk hides the floor it would fall on.)
    c.glow(160, 92, 50, 22, 'sky4', 0.8, lambda x, y: gy1 + 7 <= y <= 112)

    # The bookshelf.
    bx0, by0, bx1, by1 = 14, 18, 94, 98
    c.rect(bx0, by0, bx1, by1, 'wood0')
    c.rect(bx0 + 3, by0 + 3, bx1 - 3, by1 - 3, 'shade')
    for shelf in (by0 + 26, by0 + 52, by1 - 3):
        c.rect(bx0 + 3, shelf, bx1 - 3, shelf + 2, 'wood1')
        c.rect(bx0 + 3, shelf, bx1 - 3, shelf, 'wood2')
    c.rect(bx0, by0, bx1, by0, 'wood2')
    spines = ['red0', 'blue0', 'green0', 'brass0', 'grey0', 'wood2', 'red1', 'coat0', 'sky1']
    for top, bottom in ((by0 + 3, by0 + 25), (by0 + 29, by0 + 51), (by0 + 55, by1 - 4)):
        x = bx0 + 4
        while x < bx1 - 6:
            if pick.random() < 0.1:
                # A gap, or a box.
                if pick.random() < 0.5:
                    c.rect(x, bottom - 6, x + 8, bottom, 'manila0')
                    c.rect(x, bottom - 6, x + 8, bottom - 6, 'manila1')
                    x += 11
                else:
                    x += 4
                continue
            width = pick.randrange(3, 6)
            height = pick.randrange(12, bottom - top)
            colour = pick.choice(spines)
            c.rect(x, bottom - height, x + width - 1, bottom, colour)
            c.rect(x, bottom - height, x, bottom, 'ink')
            if height > 14:
                c.rect(x + 1, bottom - height + 3, x + width - 1, bottom - height + 3, 'brass1')
            x += width
        # A leaning book at the end of the row.
        c.line(bx1 - 9, bottom, bx1 - 5, bottom - 14, 'red1')
        c.line(bx1 - 8, bottom, bx1 - 4, bottom - 14, 'red0')
        c.line(bx1 - 7, bottom, bx1 - 3, bottom - 14, 'red0')

    # The clock.
    for y in range(18, 35):
        for x in range(98, 115):
            d = (x - 106) ** 2 + (y - 26) ** 2
            if d <= 64:
                c.dot(x, y, 'wood1' if d > 42 else 'paper2')
    # Its hands, except in the game, where its one hand counts down the time left (case-screen.js).
    if hands:
        c.line(106, 26, 106, 21, 'ink')
        c.line(106, 26, 110, 28, 'ink')
        c.dot(106, 26, 'red1')

    # The coat stand, with a trench coat and a fedora.
    c.rect(9, 36, 10, 116, 'wood0')
    c.rect(4, 116, 15, 117, 'wood0')
    c.rect(4, 115, 15, 115, 'wood1')
    c.rect(5, 38, 14, 38, 'wood1')
    c.polygon([(4, 42), (15, 42), (18, 94), (1, 94)], 'coat1')
    c.polygon([(4, 42), (7, 42), (4, 94), (1, 94)], 'coat0')
    c.polygon([(6, 42), (13, 42), (11, 52), (8, 52)], 'coat2')
    c.rect(2, 66, 17, 67, 'coat0')
    c.rect(9, 65, 10, 68, 'brass1')
    c.rect(15, 74, 15, 92, 'coat0')
    c.rect(2, 34, 17, 35, 'ink')
    c.rect(5, 27, 14, 33, 'shade')
    c.rect(6, 26, 13, 26, 'shade')
    c.rect(5, 31, 14, 32, 'red0')
    c.rect(6, 28, 13, 28, 'grey0')

    # The corkboard: pinned papers, a map, index cards, and red string between the pins. The photo clue is pinned at
    # its top right (CLUES['photo']).
    kx0, ky0, kx1, ky1 = 212, 12, 308, 86
    c.rect(kx0, ky0, kx1, ky1, 'wood1')
    c.rect(kx0, ky0, kx1, ky0, 'wood2')
    c.rect(kx0 + 3, ky0 + 3, kx1 - 3, ky1 - 3, 'cork1')
    for _ in range(420):
        c.dot(pick.randrange(kx0 + 3, kx1 - 2), pick.randrange(ky0 + 3, ky1 - 2), pick.choice(['cork0', 'cork2']))
    c.rect(kx0 + 3, ky1 - 3, kx1 - 3, ky1 - 3, 'cork0')
    # A newspaper cutting.
    c.rect(219, 19, 246, 42, 'paper1')
    c.rect(221, 21, 244, 24, 'grey0')
    for y in range(27, 41, 2):
        c.rect(221, y, 232, y, 'line')
        c.rect(235, y, 244, y, 'line')
    c.rect(235, 27, 244, 33, 'grey1')
    # A map, with a route marked.
    c.rect(222, 50, 254, 77, (176, 196, 168))
    for y in range(52, 77, 5):
        c.rect(222, y, 254, y, (150, 172, 146))
    c.line(226, 74, 236, 60, 'blue0')
    c.line(236, 60, 250, 56, 'blue0')
    c.rect(225, 66, 232, 72, (120, 160, 190))
    # Index cards.
    c.rect(258, 60, 280, 76, 'paper2')
    c.rect(258, 62, 280, 62, 'red2')
    for y in range(65, 75, 3):
        c.rect(260, y, 278, y, 'line')
    c.rect(284, 58, 302, 72, 'paper1')
    for y in range(61, 71, 3):
        c.rect(286, y, 300, y, 'line')
    # The red string, pin to pin, and the pins.
    pins = [(232, 19), (238, 50), (269, 60), (279, 19), (293, 58)]
    for (xa, ya), (xb, yb) in [(pins[0], pins[1]), (pins[1], pins[2]), (pins[2], pins[3]), (pins[3], pins[4]), (pins[0], pins[3])]:
        c.line(xa, ya, xb, yb, 'red1')
    for x, y in pins:
        c.rect(x - 1, y - 1, x + 1, y + 1, 'red2')
        c.dot(x - 1, y - 1, 'white')

    # The desk: its top, seen from in front and a little above, then its front, with drawers either side.
    top = [(52, 99), (268, 99), (278, 123), (42, 123)]
    c.polygon(top, 'wood2')
    for y in range(101, 123, 4):
        x0 = round(52 - (y - 99) * 10 / 24)
        x1 = round(268 + (y - 99) * 10 / 24)
        for x in range(x0, x1):
            if pick.random() < 0.18:
                c.dot(x, y, 'wood1')
    c.line(52, 99, 268, 99, 'wood1')
    c.rect(42, 123, 278, 125, 'wood3')
    c.rect(42, 126, 278, 168, 'wood1')
    c.rect(110, 128, 210, 168, 'wood0')
    c.rect(110, 128, 210, 129, 'shade')
    for x0, x1 in ((46, 106), (214, 274)):
        for y0 in (129, 142, 155):
            c.rect(x0, y0, x1, y0 + 11, 'wood2')
            c.rect(x0, y0, x1, y0, 'wood3')
            c.rect(x0, y0 + 11, x1, y0 + 11, 'wood0')
            mid = (x0 + x1) // 2
            c.rect(mid - 4, y0 + 5, mid + 4, y0 + 6, 'brass1')
            c.rect(mid - 4, y0 + 5, mid + 4, y0 + 5, 'brass2')
    c.rect(42, 169, 278, 172, 'wood0')
    c.rect(40, 173, 280, 174, 'shade')

    # The app's name on a brass plate at the middle of the desk's front, in Silkscreen at its own pixel size.
    from PIL import ImageDraw, ImageFont

    draw = ImageDraw.Draw(c.image)
    draw.fontmode = '1'
    font = ImageFont.truetype(regular, 8)
    name = 'CRIMEIFY'
    left, top, right, bottom = draw.textbbox((0, 0), name, font=font)
    width, height = right - left, bottom - top
    px0 = 160 - (width + 8) // 2
    c.rect(px0, 134, px0 + width + 7, 134 + height + 5, 'brass1')
    c.rect(px0, 134, px0 + width + 7, 134, 'brass2')
    c.rect(px0, 134 + height + 5, px0 + width + 7, 134 + height + 5, 'brass0')
    c.rect(px0 + 1, 136, px0 + 1, 136 + height + 1, 'brass2')
    c.dot(px0 + 2, 135 + (height + 5) // 2, 'brass0')
    c.dot(px0 + width + 5, 135 + (height + 5) // 2, 'brass0')
    draw.text((px0 + 4 - left, 137 - top), name, font=font, fill=C['ink'])
    c.rect(px0 + 2, 135 + height + 6, px0 + width + 6, 135 + height + 6, 'shade')

    # The lamp: a green banker's lamp, its light pooling on the desk and on the wall behind.
    on_desk = lambda x, y: (99 <= y <= 125 and 52 - (y - 99) * 10 / 24 <= x <= 268 + (y - 99) * 10 / 24)
    c.glow(70, 86, 52, 30, 'glow', 0.55, lambda x, y: y < 99, step=0.1)
    c.glow(80, 110, 78, 18, 'glow', 2.4, on_desk)
    c.rect(60, 96, 78, 101, 'brass0')
    c.rect(62, 95, 76, 95, 'brass1')
    c.rect(68, 80, 69, 95, 'brass1')
    c.rect(70, 80, 70, 95, 'brass0')
    c.polygon([(54, 72), (84, 72), (88, 81), (50, 81)], 'green1')
    c.rect(56, 72, 82, 73, 'green3')
    c.rect(52, 76, 86, 77, 'green2')
    c.rect(50, 81, 88, 81, 'green0')
    c.rect(52, 82, 86, 82, 'glow')
    c.rect(80, 82, 80, 88, 'brass1')
    c.rect(79, 89, 81, 90, 'brass2')

    # The phone, black, beside the note clue (CLUES['note']).
    c.polygon([(222, 104), (250, 104), (253, 114), (219, 114)], 'ink')
    c.rect(224, 102, 248, 104, 'shade')
    c.rect(221, 97, 251, 101, 'shade')
    c.rect(219, 96, 225, 101, 'ink')
    c.rect(247, 96, 253, 101, 'ink')
    c.rect(222, 96, 250, 96, 'grey0')
    for y in range(105, 114):
        for x in range(229, 244):
            d = (x - 236) ** 2 + ((y - 109) * 1.6) ** 2
            if d <= 30:
                c.dot(x, y, 'grey1' if d > 14 else 'paper1')
    c.dot(236, 109, 'ink')
    # A mug, steaming, and a pen.
    c.rect(204, 102, 212, 112, 'paper1')
    c.rect(204, 102, 212, 103, 'paper2')
    c.rect(213, 105, 214, 109, 'paper1')
    c.rect(205, 104, 211, 104, 'wood0')
    for x, y in [(206, 99), (207, 97), (209, 98), (210, 96), (208, 95)]:
        c.dot(x, y, 'grey1')
    c.line(136, 120, 152, 116, 'ink')
    c.line(136, 121, 152, 117, 'brass1')
    return c


def folder():
    """The rap sheet: a manila folder, closed with a red stamp, and open on its sheet, a mugshot and lines."""
    w, h = CLUES['folder'][2:]
    sheet = Canvas(w * 2, h)
    for frame in (0, 1):
        o = frame * w
        if frame == 0:
            sheet.polygon([(o + 4, 3), (o + w - 2, 3), (o + w - 1, h - 1), (o + 1, h - 1)], 'manila1')
            sheet.rect(o + 4, 1, o + 14, 3, 'manila1')
            sheet.rect(o + 4, 1, o + 14, 1, 'manila2')
            sheet.rect(o + 3, 3, o + w - 2, 3, 'manila2')
            sheet.rect(o + 1, h - 1, o + w - 1, h - 1, 'manila0')
            sheet.rect(o + 20, 8, o + 34, 12, 'red1')
            sheet.rect(o + 21, 9, o + 33, 11, 'manila1')
            sheet.rect(o + 22, 10, o + 32, 10, 'red1')
            sheet.rect(o + 6, 8, o + 15, 8, 'manila0')
            sheet.rect(o + 6, 11, o + 13, 11, 'manila0')
        else:
            sheet.polygon([(o + 2, 4), (o + w - 1, 4), (o + w - 1, h - 1), (o, h - 1)], 'manila0')
            sheet.polygon([(o + 5, 2), (o + w - 4, 2), (o + w - 3, h - 2), (o + 4, h - 2)], 'paper2')
            sheet.rect(o + 7, 5, o + 15, 14, 'grey1')
            sheet.rect(o + 9, 6, o + 13, 10, 'grey2')
            sheet.rect(o + 8, 11, o + 14, 14, 'grey0')
            for y in range(5, 16, 2):
                sheet.rect(o + 18, y, o + w - 8, y, 'line')
            sheet.rect(o + 18, 5, o + 28, 5, 'red1')
    return sheet


def clipboard():
    """The trend report: a clipboard, its sheet blank, then with the year's line going down or up."""
    w, h = CLUES['clipboard'][2:]
    sheet = Canvas(w * 2, h)
    for frame in (0, 1):
        o = frame * w
        sheet.rect(o + 1, 2, o + w - 2, h - 1, 'wood3')
        sheet.rect(o + 1, h - 1, o + w - 2, h - 1, 'wood1')
        sheet.rect(o + 4, 4, o + w - 5, h - 3, 'paper2')
        sheet.rect(o + 8, 0, o + w - 9, 4, 'grey2')
        sheet.rect(o + 8, 4, o + w - 9, 4, 'grey0')
        if frame == 0:
            for y in range(8, h - 4, 3):
                sheet.rect(o + 6, y, o + w - 8, y, 'paper1')
        else:
            sheet.rect(o + 6, 7, o + 6, h - 5, 'line')
            sheet.rect(o + 6, h - 5, o + w - 6, h - 5, 'line')
            for (xa, ya), (xb, yb) in [((8, 9), (11, 11)), ((11, 11), (14, 10)), ((14, 10), (17, 14)), ((17, 14), (19, 15))]:
                sheet.line(o + xa, ya, o + xb, yb, 'red1')
            sheet.rect(o + 18, 7, o + 19, 8, 'amber')
    return sheet


def ledger():
    """The ranking: a ledger, closed in red with a gold band, then open on a list of bars, one marked."""
    w, h = CLUES['ledger'][2:]
    sheet = Canvas(w * 2, h)
    for frame in (0, 1):
        o = frame * w
        if frame == 0:
            sheet.polygon([(o + 3, 2), (o + w - 2, 2), (o + w - 1, h - 1), (o + 1, h - 1)], 'red0')
            sheet.rect(o + 3, 2, o + w - 2, 2, 'red1')
            sheet.rect(o + 8, 3, o + 9, h - 2, 'brass1')
            sheet.rect(o + 1, h - 1, o + w - 1, h - 1, 'paper1')
            sheet.rect(o + 14, 6, o + 24, 9, 'brass1')
            sheet.rect(o + 15, 7, o + 23, 8, 'red0')
        else:
            sheet.polygon([(o + 2, 2), (o + w - 2, 2), (o + w - 1, h - 1), (o + 1, h - 1)], 'red0')
            sheet.rect(o + 3, 3, o + w // 2 - 1, h - 2, 'paper2')
            sheet.rect(o + w // 2 + 1, 3, o + w - 3, h - 2, 'paper1')
            sheet.rect(o + w // 2, 3, o + w // 2, h - 2, 'paper0')
            for row, length in enumerate([9, 7, 6, 4]):
                y = 5 + row * 3
                sheet.rect(o + 5, y, o + 5 + length, y + 1, 'amber' if row == 2 else 'line')
                sheet.rect(o + w // 2 + 3, y, o + w // 2 + 3 + length, y + 1, 'line')
    return sheet


def note():
    """The phone note: a yellow sticky note folded over, then flat with a number scribbled on it."""
    w, h = CLUES['note'][2:]
    sheet = Canvas(w * 2, h)
    for frame in (0, 1):
        o = frame * w
        sheet.rect(o + 1, 1, o + w - 2, h - 2, 'yellow1')
        sheet.rect(o + 1, h - 2, o + w - 2, h - 2, 'yellow0')
        if frame == 0:
            sheet.polygon([(o + w - 7, 1), (o + w - 1, 1), (o + w - 1, 7)], (0, 0, 0))
            sheet.polygon([(o + w - 7, 1), (o + w - 1, 7), (o + w - 7, 7)], 'yellow0')
            sheet.image.putpixel((o + w - 1, 1), (0, 0, 0, 0))
        else:
            sheet.rect(o + 3, 4, o + 6, 4, 'blue0')
            sheet.rect(o + 8, 4, o + 11, 4, 'blue0')
            sheet.rect(o + 3, 7, o + 11, 7, 'blue0')
            sheet.rect(o + 3, 9, o + 8, 9, 'blue0')
    # The folded corner's cut-out, see-through.
    pixels = sheet.image.load()
    for y in range(h):
        for x in range(w * 2):
            if pixels[x, y][:3] == (0, 0, 0) and pixels[x, y][3] == 255:
                pixels[x, y] = (0, 0, 0, 0)
    return sheet


def photo():
    """The photo pinned on the board: its back, with a pencilled note, then its front, a white frame round a dark
    picture, where the page draws the state with a blur where the district is (case-file.js)."""
    w, h = CLUES['photo'][2:]
    sheet = Canvas(w * 2, h)
    for frame in (0, 1):
        o = frame * w
        sheet.rect(o + 1, 2, o + w - 2, h - 1, 'white' if frame else 'paper1')
        sheet.rect(o + 1, h - 1, o + w - 2, h - 1, 'paper0')
        sheet.rect(o + 3, 4, o + w - 4, h - 9, 'ink' if frame else 'grey0')
        if frame == 0:
            # A question mark over the hidden picture, and a pencilled line under it.
            mark = ['.###.', '#...#', '....#', '...#.', '..#..', '.....', '..#..']
            for row, cells in enumerate(mark):
                for col, cell in enumerate(cells):
                    if cell == '#':
                        sheet.rect(o + 11 + col * 2 - 1, 6 + row * 2, o + 11 + col * 2, 7 + row * 2, 'paper1')
            sheet.rect(o + 6, h - 5, o + w - 9, h - 5, 'grey1')
        # The pin.
        sheet.rect(o + w // 2 - 1, 0, o + w // 2 + 1, 2, 'red2')
        sheet.dot(o + w // 2 - 1, 0, 'white')
    return sheet


def scaled(canvas, factor):
    return canvas.image.resize((canvas.w * factor, canvas.h * factor), Image.NEAREST)


def title(bold):
    """CASE over FILE in Silkscreen Bold, CASE in paper and FILE in amber, after a manila folder as tall as both, as
    an SVG of the letters' squares."""
    font = TTFont(bold)
    glyphs = font.getGlyphSet()
    cmap = font.getBestCmap()
    cap = font['OS/2'].sCapHeight
    gap = cap * 0.3
    height = cap * 2 + gap
    # The folder: a block as tall as both words, its tab and a stamp, in the font's own grid of squares.
    unit = cap / 5
    # Lying wide, its tab at the top left, a sheet showing over its edge, and a red stamp on its front.
    across = unit * 15
    folder = f'M0 0H{unit * 5:.0f}V{unit * 2:.0f}H{across:.0f}V{height:.0f}H0Z'
    paper = f'M{unit * 6:.0f} {unit:.0f}H{across - unit:.0f}V{unit * 2:.0f}H{unit * 6:.0f}Z'
    stamp = f'M{unit * 4:.0f} {unit * 6:.0f}H{across - unit * 4:.0f}V{unit * 9:.0f}H{unit * 4:.0f}Z'
    left = across + unit * 2

    def word(text, baseline):
        pen = SVGPathPen(glyphs)
        x = 0
        for char in text:
            name = cmap[ord(char)]
            glyphs[name].draw(TransformPen(pen, (1, 0, 0, -1, left + x, baseline)))
            x += glyphs[name].width
        return pen.getCommands(), x

    case, case_width = word('CASE', cap)
    file, file_width = word('FILE', height)
    width = left + max(case_width, file_width)
    svg = (
        f'<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 {width:.0f} {height:.0f}" role="img" aria-label="Case File" shape-rendering="crispEdges">\n'
        '<!-- Case File, drawn by Modules/Game/Resources/art/make_case_art.py: lettering in Silkscreen Bold, SIL Open Font '
        'License 1.1 (Silkscreen-OFL.txt). -->\n'
        f'<g fill="#{"%02x%02x%02x" % C["manila1"]}"><path d="{folder}"/></g>\n'
        f'<g fill="#{"%02x%02x%02x" % C["paper2"]}"><path d="{paper}"/></g>\n'
        f'<g fill="#{"%02x%02x%02x" % C["red1"]}"><path d="{stamp}"/></g>\n'
        f'<g fill="#{"%02x%02x%02x" % C["paper2"]}"><path d="{case}"/></g>\n'
        f'<g fill="#{"%02x%02x%02x" % C["amber"]}"><path d="{file}"/></g>\n'
        '</svg>\n'
    )
    (OUT / 'title-case-file.svg').write_text(svg, encoding='utf-8', newline='\n')
    return width, height


def tile(scene, bold):
    """The tile: the desk and its lamp close up, under CASE FILE in Silkscreen Bold at its own pixel size, all four
    times the size."""
    from PIL import ImageDraw, ImageFont

    square = Image.new('RGBA', (150, 150), C['ink'] + (255,))
    square.paste(scene.image.crop((40, 62, 190, 180)), (0, 32))
    draw = ImageDraw.Draw(square)
    draw.fontmode = '1'
    font = ImageFont.truetype(bold, 16)
    case, file = 'CASE ', 'FILE'
    total = draw.textlength(case + file, font=font)
    x = (150 - total) / 2
    draw.text((x, 8), case, font=font, fill=C['paper2'])
    draw.text((x + draw.textlength(case, font=font), 8), file, font=font, fill=C['amber'])
    return square.resize((600, 600), Image.NEAREST)


ROOM_TOP = 30


def room(regular):
    """The office as the game shows it, 320 x 240: a ceiling with a fan above it (ROOM_TOP rows) and more floor below,
    with a rug and a waste-paper basket, so it fills a phone's taller screen; a wide one shows its middle 180 rows.
    Without the clock's hands, which the game draws."""
    pick = random.Random(5)
    c = Canvas(W, 240, 'floor0')

    # The floor below the desk, its planks going on as above, a rug, and the basket.
    for row, y in enumerate(range(119 + ROOM_TOP, 240, 7)):
        tone = 'floor1' if row % 2 else 'floor0'
        c.rect(0, y, W - 1, min(239, y + 6), tone)
        c.rect(0, y, W - 1, y, 'floor0' if tone == 'floor1' else 'shade')
        for x in range(pick.randrange(0, 40), W, 47):
            c.rect(x, y, x, min(239, y + 6), 'shade')
    c.paste(office(regular, hands=False), 0, ROOM_TOP)
    c.rect(66, 212, 254, 239, 'red0')
    c.rect(70, 215, 250, 239, 'sky1')
    c.rect(74, 218, 246, 239, 'red0')
    for x in range(78, 244, 8):
        c.rect(x, 221, x + 3, 224, 'amber')
        c.rect(x + 4, 228, x + 7, 231, 'brass0')
    c.rect(66, 212, 254, 212, 'red1')
    c.polygon([(278, 196), (298, 196), (295, 214), (281, 214)], 'grey0')
    c.rect(278, 196, 298, 197, 'grey1')
    for x in range(282, 296, 3):
        c.rect(x, 199, x, 212, 'shade')
    c.rect(283, 192, 289, 196, 'paper1')
    c.rect(288, 190, 293, 195, 'paper2')
    c.dot(285, 193, 'paper0')

    # The ceiling, its cornice, and a fan with its light.
    c.rect(0, 0, W - 1, ROOM_TOP - 1, 'shade')
    for x in range(0, W, 16):
        c.rect(x, 0, x, ROOM_TOP - 4, 'trim')
    c.rect(0, ROOM_TOP - 3, W - 1, ROOM_TOP - 2, 'wood0')
    c.rect(0, ROOM_TOP - 1, W - 1, ROOM_TOP - 1, 'wood1')
    c.rect(159, 0, 160, 9, 'brass0')
    c.rect(150, 10, 169, 14, 'wood0')
    c.rect(150, 10, 169, 10, 'wood1')
    c.rect(116, 12, 149, 13, 'wood1')
    c.rect(170, 12, 203, 13, 'wood1')
    c.rect(116, 14, 149, 14, 'wood0')
    c.rect(170, 14, 203, 14, 'wood0')
    c.rect(154, 15, 165, 18, 'paper1')
    c.rect(155, 19, 164, 19, 'glow')
    c.glow(160, 20, 36, 10, 'glow', 0.5, lambda x, y: y < ROOM_TOP - 3, step=0.1)
    return c


def wood():
    """The desk's top seen from above, for the close-ups: 64 x 64, its boards' grain running across, repeating
    seamlessly."""
    pick = random.Random(9)
    c = Canvas(64, 64, 'wood2')
    for board in range(4):
        y0 = board * 16
        c.rect(0, y0, 63, y0, 'wood0')
        for _ in range(3):
            y = y0 + pick.randrange(3, 15)
            x = pick.randrange(0, 64)
            for step in range(pick.randrange(20, 48)):
                c.dot((x + step) % 64, y, 'wood1')
        for _ in range(26):
            c.dot(pick.randrange(0, 64), y0 + pick.randrange(2, 16), 'wood3')
    # A knot.
    for y in range(37, 42):
        for x in range(20, 27):
            if ((x - 23) / 3.4) ** 2 + ((y - 39) / 2.2) ** 2 <= 1:
                c.dot(x, y, 'wood1' if (x + y) % 2 else 'wood0')
    return c


def cork():
    """The corkboard close up, for the suspects: 64 x 64 of speckled cork, repeating seamlessly."""
    pick = random.Random(13)
    c = Canvas(64, 64, 'cork1')
    for _ in range(520):
        c.dot(pick.randrange(0, 64), pick.randrange(0, 64), pick.choice(['cork0', 'cork2', 'cork0']))
    return c


def mugshot():
    """The rap sheet's mugshot: no face yet, only a shape against the height lines, and a question mark."""
    c = Canvas(28, 32, 'grey1')
    for y in range(3, 32, 4):
        c.rect(0, y, 27, y, 'grey0')
    c.rect(9, 6, 18, 16, 'shade')
    c.rect(10, 5, 17, 5, 'shade')
    c.polygon([(3, 32), (6, 21), (21, 21), (25, 32)], 'shade')
    c.rect(11, 17, 16, 21, 'shade')
    for (x, y) in [(12, 8), (13, 8), (14, 8), (15, 8), (16, 9), (16, 10), (15, 11), (14, 12), (14, 14)]:
        c.dot(x, y, 'amber')
    c.frame(0, 0, 27, 31, 'ink')
    return c


def phone_big():
    """The phone close up, for the tip-off: a black rotary phone, its handset off, as a call comes in."""
    c = Canvas(72, 48)
    c.polygon([(12, 22), (60, 22), (68, 46), (4, 46)], 'ink')
    c.polygon([(14, 22), (58, 22), (60, 27), (12, 27)], 'grey0')
    c.rect(4, 46, 68, 47, 'shade')
    for y in range(28, 45):
        for x in range(22, 51):
            d = ((x - 36) / 14) ** 2 + ((y - 36) / 8.5) ** 2
            if d <= 1:
                c.dot(x, y, 'grey1' if d > 0.55 else 'paper1')
    for angle in range(0, 300, 30):
        import math
        x = round(36 + 10.5 * math.cos(math.radians(angle + 120)))
        y = round(36 + 6.2 * math.sin(math.radians(angle + 120)))
        c.rect(x - 1, y - 1, x, y, 'ink')
    c.rect(35, 35, 37, 37, 'red1')
    # The handset, lifted.
    c.polygon([(6, 8), (64, 4), (66, 10), (8, 15)], 'ink')
    c.rect(2, 6, 14, 16, 'ink')
    c.rect(58, 1, 70, 11, 'ink')
    c.line(10, 8, 62, 4, 'grey0')
    # Its cord.
    for x in range(0, 9, 2):
        c.dot(4 + x // 3, 18 + x, 'grey0')
    return c


def main():
    regular, bold = sys.argv[1], sys.argv[2]
    scene = office(regular)
    room(regular).image.save(OUT / 'case-room.png', optimize=True)
    wood().image.save(OUT / 'case-wood.png', optimize=True)
    cork().image.save(OUT / 'case-cork.png', optimize=True)
    mugshot().image.save(OUT / 'case-mugshot.png', optimize=True)
    phone_big().image.save(OUT / 'case-phone.png', optimize=True)

    sheets = [folder(), clipboard(), ledger(), note(), photo()]
    width = max(s.w for s in sheets)
    sprite = Image.new('RGBA', (width, sum(s.h for s in sheets)), (0, 0, 0, 0))
    y = 0
    for sheet in sheets:
        sprite.alpha_composite(sheet.image, (0, y))
        y += sheet.h
    sprite.save(OUT / 'case-clues.png', optimize=True)

    # The office with every clue open, to see how they look in it.
    preview = Canvas(W, H)
    preview.paste(scene, 0, 0)
    for (name, (x, cy, w, h)), sheet in zip(CLUES.items(), sheets):
        preview.image.alpha_composite(sheet.image.crop((w, 0, w * 2, h)), (x, cy))
    preview.image.save(Path(sys.argv[4]) / 'case-preview-open.png') if len(sys.argv) > 4 else None
    closed = Canvas(W, H)
    closed.paste(scene, 0, 0)
    for (name, (x, cy, w, h)), sheet in zip(CLUES.items(), sheets):
        closed.image.alpha_composite(sheet.image.crop((0, 0, w, h)), (x, cy))
    closed.image.save(Path(sys.argv[4]) / 'case-preview-closed.png') if len(sys.argv) > 4 else None
    scaled(closed, 2).save(OUT / 'picture-case-file.png', optimize=True)

    width, height = title(bold)
    tile(closed, bold).save(OUT / "tile-case-file.png", optimize=True)

    # Silkscreen Regular for the labels, as WOFF2.
    font = TTFont(regular)
    font.flavor = 'woff2'
    font.save(OUT / 'silkscreen.woff2')
    # And Pixelify Sans, which has small letters, for what the clues say.
    font = TTFont(sys.argv[3])
    font.flavor = 'woff2'
    font.save(OUT / 'pixelify.woff2')
    print(f'case-room.png, case-wood.png, case-cork.png, case-mugshot.png, case-phone.png, case-clues.png, picture-case-file.png, tile-case-file.png, title-case-file.svg ({width:.0f} x {height:.0f}), silkscreen.woff2, pixelify.woff2')


main()
