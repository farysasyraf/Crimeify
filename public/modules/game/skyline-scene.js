// Skyline's 3D scene (Three.js), for the solo game (skyline.js) and live games, on the phones (live.js) and the big
// screen (live-host.js): a state's outline as a dark slab, its police districts as lit towers where they are, each as
// tall as its count, and the one to guess as a glass outline that the page raises to the guess (guess()); at the answer,
// the real tower grows inside it (reveal()). On the big screen's answer, everyone's guesses ring the tower at their
// heights, the closest three named. Drag to turn it, pinch or scroll to zoom, and tap or point at a tower for its name
// and count. Where WebGL can't be had, supported() says so, and the page shows the towers as a list instead.
//
// The page loads it through its import map, which names three and this file with their versions.
import * as THREE from 'three';
import { OrbitControls } from 'three/addons/controls/OrbitControls.js';

// The state's longest side, the tallest tower's height and the land's thickness, in the scene's units.
const SIZE = 10;
const TALLEST = 5;
const SLAB = 0.22;

const colour = {
    sea: 0x0e1013,
    land: 0x1a2028,
    edge: 0x3aaaf2,
    low: 0x1d4f75,
    high: 0x5cc4ff,
    guess: 0xf5c26b,
    answer: 0x7fd8a4,
    ring: 0xeef2f6,
};

const number = new Intl.NumberFormat('en-MY');
const t = (text, replace = {}) => (window.t ? window.t(text, replace) : text);
const ease = (x) => 1 - (1 - x) ** 3;

// Whether this browser can draw the scene.
export const supported = () => {
    try {
        const canvas = document.createElement('canvas');

        return Boolean(canvas.getContext('webgl2') ?? canvas.getContext('webgl'));
    } catch {
        return false;
    }
};

// A tower's windows, some lit: one texture for every tower, repeated up each by its height.
let windowsTexture = null;
const windows = () => {
    if (windowsTexture) {
        return windowsTexture;
    }

    const canvas = document.createElement('canvas');
    canvas.width = canvas.height = 64;
    const paint = canvas.getContext('2d');
    paint.fillStyle = '#000';
    paint.fillRect(0, 0, 64, 64);

    let seed = 11;
    const random = () => (seed = (seed * 16807) % 2147483647) / 2147483647;
    for (let row = 0; row < 4; row++) {
        for (let column = 0; column < 4; column++) {
            const lit = random();
            paint.fillStyle = lit > 0.5 ? `rgba(255, 224, 160, ${0.5 + lit * 0.5})` : 'rgba(110, 160, 215, .16)';
            paint.fillRect(column * 16 + 4, row * 16 + 4, 8, 9);
        }
    }

    windowsTexture = new THREE.CanvasTexture(canvas);
    windowsTexture.wrapS = windowsTexture.wrapT = THREE.RepeatWrapping;
    windowsTexture.colorSpace = THREE.SRGBColorSpace;

    return windowsTexture;
};

export class SkylineScene {
    // The scene in the element given, drawn once the states' outlines (the boundaries file, or a promise of it) are in.
    // calm: no turning by itself and no growing, for those who've turned motion down.
    constructor(element, { outlines, calm = false } = {}) {
        this.element = element;
        this.outlines = Promise.resolve(outlines).catch(() => null);
        this.calm = calm;
        this.animations = [];
        this.tags = [];
        this.dirty = true;

        this.renderer = new THREE.WebGLRenderer({ antialias: true, powerPreference: 'high-performance' });
        this.renderer.setPixelRatio(Math.min(window.devicePixelRatio || 1, 2));
        this.renderer.domElement.className = 'skyline-canvas';
        this.labels = document.createElement('div');
        this.labels.className = 'skyline-labels';
        this.labels.setAttribute('aria-hidden', 'true');
        element.append(this.renderer.domElement, this.labels);

        this.scene = new THREE.Scene();
        this.scene.background = new THREE.Color(colour.sea);
        this.scene.fog = new THREE.Fog(colour.sea, 18, 40);
        this.camera = new THREE.PerspectiveCamera(40, 1, 0.1, 200);

        // Light from the sky and from one side, so each tower's faces differ; no shadows, which on the dark land look
        // like holes, and cost phones more.
        this.scene.add(new THREE.HemisphereLight(0xc4dcff, 0x0e1013, 1.1));
        const sun = new THREE.DirectionalLight(0xffffff, 1.8);
        sun.position.set(-7, 14, 9);
        this.scene.add(sun);

        const grid = new THREE.GridHelper(SIZE * 4, 40, 0x223040, 0x161d26);
        grid.position.y = -0.01;
        this.scene.add(grid);

        this.controls = new OrbitControls(this.camera, this.renderer.domElement);
        Object.assign(this.controls, {
            enableDamping: true,
            dampingFactor: 0.08,
            minPolarAngle: 0.2,
            maxPolarAngle: Math.PI / 2 - 0.08,
            rotateSpeed: 0.7,
            autoRotateSpeed: 0.5,
        });
        // Turning stops turning by itself, and keeps the view as it's left when the window changes size; panning stays
        // near the state.
        this.controls.addEventListener('start', () => {
            this.controls.autoRotate = false;
            this.touched = true;
        });
        this.controls.addEventListener('change', () => {
            this.controls.target.clamp(new THREE.Vector3(-SIZE / 2, 0, -SIZE / 2), new THREE.Vector3(SIZE / 2, TALLEST, SIZE / 2));
            this.dirty = true;
        });

        this.pointing();
        this.resize();
        new ResizeObserver(() => this.resize()).observe(element);
        this.loop = this.loop.bind(this);
        requestAnimationFrame(this.loop);
    }

    // The round given (Skyline::face()): its state's outline and its towers, the one to guess as a glass outline on
    // the ground. Resolves once it's drawn.
    async show(round) {
        const outlines = await this.outlines;
        this.clear();
        this.round = round;
        this.group = new THREE.Group();
        this.scene.add(this.group);

        const { project, polygons } = this.projection(round, outlines);
        this.land(polygons);

        const tallest = Math.max(...round.towers.map((tower) => tower.crimes ?? 0), 1);
        this.unit = TALLEST / tallest;
        const spots = round.towers.map((tower) => project(tower.lng, tower.lat));
        this.width = this.spread(spots);

        this.towers = round.towers.map((tower, index) => {
            const [x, z] = spots[index];
            const target = tower.crimes === null;
            const mesh = target ? null : this.tower(x, z, tower.crimes * this.unit, this.shade(tower.crimes / tallest));

            if (mesh) {
                mesh.userData = { name: tower.name, crimes: tower.crimes };
            }

            return { ...tower, x, z, mesh, target };
        });

        this.target = this.towers.find((tower) => tower.target);
        this.ghost(this.target);
        this.level();

        const top = this.towers.find((tower) => tower.name === round.tallest.name);
        this.tag(`${round.tallest.name} · ${number.format(round.tallest.crimes)}`, 'tallest', new THREE.Vector3(top.x, SLAB + tallest * this.unit, top.z));
        this.targetTag = this.tag(`${this.target.name} · ?`, 'target', new THREE.Vector3(this.target.x, SLAB, this.target.z));
        this.guessed = 0;

        this.touched = false;
        this.frame();
        this.controls.autoRotate = !this.calm;
        this.dirty = true;
    }

    // Raise the glass tower to the height given, as a count. While it moves, a faint level across the state shows which
    // towers it's above.
    guess(value) {
        if (!this.target) {
            return;
        }

        this.guessed = value;
        this.controls.autoRotate = false;
        const height = Math.max(value * this.unit, 0.002);
        this.glass.scale.y = height;
        this.glass.visible = value > 0;
        this.plane.position.y = SLAB + height;
        this.plane.material.opacity = 0.055;
        this.planeShownAt = performance.now();
        this.targetTag.anchor.y = SLAB + height;
        this.targetTag.element.textContent = `${this.target.name} · ${number.format(value)}`;
        this.dirty = true;
    }

    // The answer: the real tower grows to the count inside the glass one, which stays as the guess (none, for no guess).
    // ghosts, on the big screen: everyone's guesses, closest first, as rings at their heights, the closest three named.
    // Resolves once it's grown.
    async reveal({ crimes, guess = null, ghosts = [] }) {
        if (!this.target) {
            return;
        }

        const target = this.target;
        this.plane.material.opacity = 0;
        this.pulse.visible = false;
        this.controls.autoRotate = false;

        if (guess === null) {
            this.glass.visible = false;
            this.targetTag.element.hidden = true;
        } else {
            this.guess(guess);
            this.plane.material.opacity = 0;
            this.targetTag.element.textContent = t('Guess: :count', { count: number.format(guess) });
            this.targetTag.element.className = 'skyline-label is-guess';
        }

        await this.rings(ghosts);

        const height = crimes * this.unit;
        const tower = this.tower(target.x, target.z, 0.002, colour.answer, true);
        const answer = this.tag(`${target.name} · ${number.format(crimes)}`, 'answer', new THREE.Vector3(target.x, SLAB, target.z));
        answer.element.hidden = true;

        await this.animate(this.calm ? 0 : 1400, (done) => {
            const now = Math.max(height * ease(done), 0.002);
            this.stretch(tower, now);
            answer.anchor.y = SLAB + now;
            answer.element.hidden = done === 0;
            answer.element.textContent = `${target.name} · ${number.format(Math.round(crimes * ease(done)))}`;
        });

        // The closest three by name, at their rings.
        ghosts.slice(0, 3).forEach((ghost, index) => {
            this.tag(`${index + 1}. ${ghost.name} · ${number.format(ghost.value)}`, `ghost is-place-${index + 1}`,
                new THREE.Vector3(target.x + this.width * 0.9, SLAB + ghost.value * this.unit, target.z));
        });
        this.dirty = true;
    }

    // Everyone's guesses as rings round the tower to guess, rising into place one after another.
    async rings(ghosts) {
        if (ghosts.length === 0) {
            return;
        }

        const target = this.target;
        const geometry = new THREE.RingGeometry(this.width * 0.62, this.width * 0.8, 40);
        geometry.rotateX(-Math.PI / 2);
        const places = [0xf5c26b, 0xdfe6ee, 0xd59a6a];
        const rings = ghosts.map((ghost, index) => {
            const ring = new THREE.Mesh(geometry, new THREE.MeshBasicMaterial({
                color: places[index] ?? colour.ring,
                transparent: true,
                opacity: index < 3 ? 0.95 : 0.4,
                side: THREE.DoubleSide,
                depthWrite: false,
            }));
            ring.position.set(target.x, SLAB, target.z);
            ring.userData.height = SLAB + ghost.value * this.unit;
            this.group.add(ring);
            return ring;
        });

        await this.animate(this.calm ? 0 : 1000, (done) => {
            rings.forEach((ring, index) => {
                // Each starts a little after the one before, the furthest first, so the closest arrive last.
                const late = (rings.length - 1 - index) / Math.max(rings.length, 1) * 0.5;
                const own = Math.min(1, Math.max(0, (done - late) / 0.5));
                ring.position.y = SLAB + (ring.userData.height - SLAB) * ease(own);
                ring.scale.setScalar(0.4 + 0.6 * ease(own));
            });
        });
    }

    dispose() {
        cancelAnimationFrame(this.frameId);
        this.clear();
        this.controls.dispose();
        this.renderer.dispose();
        this.element.replaceChildren();
    }

    // Each frame: the camera's easing, anything growing, the base of the tower to guess pulsing, the level fading, and
    // drawing only when something changed.
    loop(now) {
        this.frameId = requestAnimationFrame(this.loop);
        const moved = this.controls.update();
        let changed = this.step(now);

        if (this.pulse?.visible && !this.calm) {
            const beat = (now % 1600) / 1600;
            this.pulse.scale.setScalar(1 + beat * 0.9);
            this.pulse.material.opacity = 0.85 * (1 - beat);
            changed = true;
        }

        if (this.plane && this.plane.material.opacity > 0 && now - this.planeShownAt > 1200) {
            this.plane.material.opacity = Math.max(0, this.plane.material.opacity - 0.003);
            changed = true;
        }

        if (moved || changed || this.dirty) {
            this.dirty = false;
            this.renderer.render(this.scene, this.camera);
            this.place();
        }
    }

    step(now) {
        if (this.animations.length === 0) {
            return false;
        }

        this.animations = this.animations.filter((animation) => {
            animation.start ??= now;
            const done = animation.duration === 0 ? 1 : Math.min(1, (now - animation.start) / animation.duration);
            animation.update(done);

            if (done >= 1) {
                animation.resolve();
                return false;
            }

            return true;
        });

        return true;
    }

    animate(duration, update) {
        return new Promise((resolve) => {
            this.animations.push({ duration, update, resolve, start: null });
            this.dirty = true;
        });
    }

    // Lay the state flat around the middle of its outline and towers: east to the right, north away from the camera,
    // its longest side SIZE across. project(lng, lat) gives [x, z]; polygons are its outline's, as [x, y] rings.
    projection(round, outlines) {
        const features = (outlines?.features ?? []).filter((feature) => round.regions.includes(feature.properties?.shapeISO));
        const polygons = features.flatMap((feature) => (feature.geometry.type === 'Polygon' ? [feature.geometry.coordinates]
            : feature.geometry.type === 'MultiPolygon' ? feature.geometry.coordinates : []));

        const points = [...polygons.flatMap((polygon) => polygon[0]), ...round.towers.map((tower) => [tower.lng, tower.lat])];
        const lngs = points.map((point) => point[0]);
        const lats = points.map((point) => point[1]);
        const [west, east, south, north] = [Math.min(...lngs), Math.max(...lngs), Math.min(...lats), Math.max(...lats)];
        const middle = [(west + east) / 2, (south + north) / 2];
        const squeeze = Math.cos((middle[1] * Math.PI) / 180);
        const scale = SIZE / Math.max((east - west) * squeeze, north - south, 0.01);
        const flat = (lng, lat) => [(lng - middle[0]) * squeeze * scale, (lat - middle[1]) * scale];

        this.extent = { x: (east - west) * squeeze * scale, z: (north - south) * scale };

        return {
            project: (lng, lat) => {
                const [x, y] = flat(lng, lat);
                return [x, -y];
            },
            polygons: polygons.map((polygon) => polygon.map((ring) => this.simplify(ring.map(([lng, lat]) => flat(lng, lat))))),
        };
    }

    // A ring with its points closer than a hair dropped, so the biggest states' coasts stay quick to draw.
    simplify(ring) {
        const kept = [];
        for (const point of ring) {
            const last = kept[kept.length - 1];
            if (!last || Math.hypot(point[0] - last[0], point[1] - last[1]) > 0.025) {
                kept.push(point);
            }
        }

        return kept;
    }

    // The state: a dark slab, without its specks of islands, and its coast and borders in blue on top.
    land(polygons) {
        const area = (ring) => Math.abs(ring.reduce((sum, point, index) => {
            const next = ring[(index + 1) % ring.length];
            return sum + point[0] * next[1] - next[0] * point[1];
        }, 0)) / 2;

        const shapes = [];
        const edges = [];
        for (const [outer, ...holes] of polygons) {
            if (outer.length < 3 || area(outer) < 0.004) {
                continue;
            }

            const shape = new THREE.Shape(outer.map(([x, y]) => new THREE.Vector2(x, y)));
            holes.filter((hole) => hole.length >= 3).forEach((hole) => shape.holes.push(new THREE.Path(hole.map(([x, y]) => new THREE.Vector2(x, y)))));
            shapes.push(shape);
            edges.push(outer, ...holes);
        }

        if (shapes.length === 0) {
            // No outline to be had: a plain square of ground under the towers.
            const ground = new THREE.Mesh(new THREE.BoxGeometry(SIZE * 1.1, SLAB, SIZE * 1.1), new THREE.MeshStandardMaterial({ color: colour.land }));
            ground.position.y = SLAB / 2;
            this.group.add(ground);
            return;
        }

        const slab = new THREE.Mesh(
            new THREE.ExtrudeGeometry(shapes, { depth: SLAB, bevelEnabled: false }),
            new THREE.MeshStandardMaterial({ color: colour.land, roughness: 0.95, metalness: 0.05 }),
        );
        slab.rotation.x = -Math.PI / 2;
        this.group.add(slab);

        const line = new THREE.LineBasicMaterial({ color: colour.edge, transparent: true, opacity: 0.75 });
        for (const ring of edges) {
            const geometry = new THREE.BufferGeometry().setFromPoints(ring.map(([x, y]) => new THREE.Vector3(x, SLAB + 0.003, -y)));
            this.group.add(new THREE.LineLoop(geometry, line));
        }
    }

    // How wide the towers are, from how many there are for the state's size; and those too close to tell apart moved
    // a little apart, as a city's map does with its labels.
    spread(spots) {
        const room = Math.sqrt(Math.max(this.extent.x * this.extent.z, 1) / Math.max(spots.length, 1));
        const width = Math.min(0.6, Math.max(0.24, room * 0.34));
        const gap = width * 1.3;

        for (let pass = 0; pass < 40; pass++) {
            let moved = false;
            for (let i = 0; i < spots.length; i++) {
                for (let j = i + 1; j < spots.length; j++) {
                    const dx = spots[j][0] - spots[i][0];
                    const dz = spots[j][1] - spots[i][1];
                    const apart = Math.hypot(dx, dz);

                    if (apart < gap) {
                        const push = (gap - apart) / 2;
                        const [ux, uz] = apart > 1e-6 ? [dx / apart, dz / apart] : [Math.cos(i + j), Math.sin(i + j)];
                        spots[i][0] -= ux * push;
                        spots[i][1] -= uz * push;
                        spots[j][0] += ux * push;
                        spots[j][1] += uz * push;
                        moved = true;
                    }
                }
            }
            if (!moved) {
                break;
            }
        }

        return width;
    }

    // A tower's colour, darker blue for the shortest to bright for the tallest.
    shade(share) {
        return new THREE.Color(colour.low).lerp(new THREE.Color(colour.high), Math.sqrt(Math.max(0, Math.min(1, share))));
    }

    // A lit tower at x, z, the height given, its windows repeated up it.
    tower(x, z, height, tint, answer = false) {
        const geometry = new THREE.BoxGeometry(this.width, 1, this.width);
        geometry.translate(0, 0.5, 0);
        const lights = windows().clone();
        lights.repeat.set(1, height / this.width);
        const side = new THREE.MeshStandardMaterial({
            color: tint, emissive: 0xffffff, emissiveMap: lights, emissiveIntensity: answer ? 0.8 : 0.65, roughness: 0.55, metalness: 0.15,
        });
        const top = new THREE.MeshStandardMaterial({ color: tint, emissive: tint, emissiveIntensity: answer ? 0.5 : 0.3, roughness: 0.4 });
        const mesh = new THREE.Mesh(geometry, [side, side, top, side, side, side]);
        mesh.position.set(x, SLAB, z);
        mesh.scale.y = height;
        mesh.userData.lights = lights;
        this.group.add(mesh);

        return mesh;
    }

    stretch(mesh, height) {
        mesh.scale.y = height;
        mesh.userData.lights.repeat.y = height / this.width;
    }

    // The tower to guess: a glass outline, raised by guess(), with a ring pulsing at its foot until the answer.
    ghost(target) {
        const box = new THREE.BoxGeometry(this.width * 1.04, 1, this.width * 1.04);
        box.translate(0, 0.5, 0);
        this.glass = new THREE.Group();
        this.glass.add(
            new THREE.Mesh(box, new THREE.MeshBasicMaterial({ color: colour.guess, transparent: true, opacity: 0.16, depthWrite: false })),
            new THREE.LineSegments(new THREE.EdgesGeometry(box), new THREE.LineBasicMaterial({ color: colour.guess })),
        );
        this.glass.position.set(target.x, SLAB, target.z);
        this.glass.scale.y = 0.002;
        this.glass.visible = false;
        this.group.add(this.glass);

        const ring = new THREE.RingGeometry(this.width * 0.55, this.width * 0.75, 40);
        ring.rotateX(-Math.PI / 2);
        this.pulse = new THREE.Mesh(ring, new THREE.MeshBasicMaterial({ color: colour.guess, transparent: true, opacity: 0.8, depthWrite: false, side: THREE.DoubleSide }));
        this.pulse.position.set(target.x, SLAB + 0.01, target.z);
        this.group.add(this.pulse);

        const base = new THREE.EdgesGeometry(new THREE.PlaneGeometry(this.width * 1.04, this.width * 1.04).rotateX(-Math.PI / 2));
        const foot = new THREE.LineSegments(base, new THREE.LineBasicMaterial({ color: colour.guess }));
        foot.position.set(target.x, SLAB + 0.005, target.z);
        this.group.add(foot);
    }

    // The faint level across the state at the guess's height.
    level() {
        const plane = new THREE.PlaneGeometry(this.extent.x + 0.8, this.extent.z + 0.8);
        plane.rotateX(-Math.PI / 2);
        this.plane = new THREE.Mesh(plane, new THREE.MeshBasicMaterial({ color: colour.guess, transparent: true, opacity: 0, depthWrite: false, side: THREE.DoubleSide }));
        this.planeShownAt = 0;
        this.group.add(this.plane);
    }

    // A label over the scene at a point in it, kept there as the camera moves (place()).
    tag(text, kind, anchor) {
        const element = document.createElement('span');
        element.className = `skyline-label is-${kind}`;
        element.textContent = text;
        this.labels.append(element);
        const tag = { element, anchor };
        this.tags.push(tag);
        this.dirty = true;

        return tag;
    }

    place() {
        const { clientWidth: width, clientHeight: height } = this.renderer.domElement;
        const point = new THREE.Vector3();

        for (const tag of this.tags) {
            point.copy(tag.anchor).project(this.camera);
            const behind = point.z > 1;
            tag.element.style.visibility = behind ? 'hidden' : '';
            tag.element.style.transform = `translate(${((point.x + 1) / 2) * width}px, ${((1 - point.y) / 2) * height}px) translate(-50%, calc(-100% - 6px))`;
        }
    }

    // The camera from the south and above, as close as it can be with the whole state and its tallest towers in view:
    // the corners of the box round them brought to just inside the edges, a few steps closer or further each time.
    frame() {
        const elevation = 0.66;
        const target = new THREE.Vector3(0, TALLEST * 0.3, 0);
        const corners = [];
        for (const x of [-this.extent.x / 2 - this.width, this.extent.x / 2 + this.width]) {
            for (const z of [-this.extent.z / 2 - this.width, this.extent.z / 2 + this.width]) {
                corners.push(new THREE.Vector3(x, 0, z), new THREE.Vector3(x, SLAB + TALLEST * 1.08, z));
            }
        }

        let distance = SIZE * 2;
        const place = () => {
            this.camera.position.set(0, target.y + distance * Math.sin(elevation), distance * Math.cos(elevation));
            this.camera.lookAt(target);
            this.camera.updateMatrixWorld();
        };
        for (let step = 0; step < 6; step++) {
            place();
            const reach = Math.max(...corners.map((corner) => {
                const seen = corner.clone().project(this.camera);
                return Math.max(Math.abs(seen.x), Math.abs(seen.y));
            }));
            distance *= reach / 0.94;
        }
        place();

        this.controls.target.copy(target);
        this.controls.minDistance = distance * 0.3;
        this.controls.maxDistance = distance * 1.8;
        this.scene.fog.near = distance * 0.9;
        this.scene.fog.far = distance * 2.6;
        this.controls.update();
        this.dirty = true;
    }

    resize() {
        const width = this.element.clientWidth;
        const height = this.element.clientHeight;

        if (width === 0 || height === 0) {
            return;
        }

        this.renderer.setSize(width, height, false);
        this.camera.aspect = width / height;
        this.camera.updateProjectionMatrix();

        // As when the scene was set out while hidden.
        if (this.round && !this.touched) {
            this.frame();
        }
        this.dirty = true;
    }

    // Pointing at a tower, or tapping it without turning the scene, names it and its count.
    pointing() {
        const canvas = this.renderer.domElement;
        const ray = new THREE.Raycaster();
        const at = new THREE.Vector2();
        let down = null;

        const pick = (event) => {
            if (!this.towers) {
                return;
            }

            const box = canvas.getBoundingClientRect();
            at.set(((event.clientX - box.left) / box.width) * 2 - 1, -((event.clientY - box.top) / box.height) * 2 + 1);
            ray.setFromCamera(at, this.camera);
            const meshes = this.towers.map((tower) => tower.mesh).filter(Boolean);
            const hit = ray.intersectObjects(meshes, false)[0]?.object;

            if (!hit) {
                this.hint?.element.remove();
                this.tags = this.tags.filter((tag) => tag !== this.hint);
                this.hint = null;
                this.dirty = true;
                return;
            }

            const anchor = new THREE.Vector3(hit.position.x, SLAB + hit.scale.y, hit.position.z);
            const text = `${hit.userData.name} · ${number.format(hit.userData.crimes)}`;

            if (this.hint) {
                this.hint.anchor = anchor;
                this.hint.element.textContent = text;
            } else {
                this.hint = this.tag(text, 'hint', anchor);
            }
            this.dirty = true;
        };

        canvas.addEventListener('pointermove', (event) => {
            if (event.pointerType === 'mouse' && event.buttons === 0) {
                pick(event);
            }
        });
        canvas.addEventListener('pointerdown', (event) => { down = { x: event.clientX, y: event.clientY, at: performance.now() }; });
        canvas.addEventListener('pointerup', (event) => {
            if (down && Math.hypot(event.clientX - down.x, event.clientY - down.y) < 6 && performance.now() - down.at < 500) {
                pick(event);
            }
            down = null;
        });
    }

    // Everything of the round before, gone, and its labels.
    clear() {
        this.animations.forEach((animation) => animation.resolve());
        this.animations = [];
        this.labels.replaceChildren();
        this.tags = [];
        this.hint = null;
        this.target = null;
        this.towers = null;

        if (this.group) {
            this.group.traverse((object) => {
                object.geometry?.dispose();
                [object.material].flat().filter(Boolean).forEach((material) => material.dispose());
                object.userData.lights?.dispose();
            });
            this.scene.remove(this.group);
            this.group = null;
        }
    }
}
