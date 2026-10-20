/*
 * The bouquet builder on php/customize.php.
 *
 * The only ES module in the project, and the only file that imports anything: everything
 * else is a classic script loaded with defer. Three.js is vendored in js/vendor/ and pulled
 * in by relative path, so there is no import map and no build step -- see js/vendor/README.md.
 *
 * Enhancement, twice over. The page is already correct without this file: the picker is a
 * real GET form, the total is printed by php/config/customizer.php, and the chosen parts are
 * listed under the stage in words. What this adds is that the bouquet appears, turns, and
 * re-prices without a round trip. If the module fails to load, if JSON.parse chokes on a
 * stale cached payload, or if the browser has no WebGL, the page stays exactly as the server
 * rendered it -- the canvas starts hidden and only this file unhides it.
 *
 * The arithmetic in totalCentavos() mirrors designTotal() in php/config/customizer.php.
 * That duplication is deliberate and one-directional: the server's figure is what the page
 * ships with and what any later basket will trust, and this copy exists only so the number
 * moves while somebody clicks. checkAgainstServer() shouts in the console if the two ever
 * disagree, so a drift shows up while somebody is working on it rather than at the till.
 */

import * as THREE from './vendor/three.module.min.js';

/* ====================================================================
                        Reading the design off the page
==================================================================== */

/*
 * The whole parts list arrives as JSON in a data- attribute, put there by designPayload().
 * An attribute rather than an inline <script> because escape() -- htmlspecialchars with
 * ENT_QUOTES -- makes an attribute safe with no further thought, and a part named
 * "</script>" cannot end the document from inside one.
 *
 * Anything unexpected returns null and the caller gives up quietly. A visitor on a cached
 * page from before a deploy should see the server-rendered bouquet, not a console full of
 * exceptions.
 */
function readDesign() {
    const root = document.querySelector('[data-customizer]');

    if (!root) {
        return null;
    }

    let payload;

    try {
        payload = JSON.parse(root.dataset.customizer);
    } catch (error) {
        return null;
    }

    if (!payload || typeof payload !== 'object' || !payload.parts) {
        return null;
    }

    return { root: root, payload: payload };
}

/** The part a kind is currently set to, straight off the checked radio. */
function chosenPart(payload, form, kind) {
    const offered = payload.parts[kind] || [];

    if (offered.length === 0) {
        return null;
    }

    const checked = form.querySelector('input[name="' + kind + '"]:checked');
    const wanted = checked ? Number(checked.value) : null;

    for (const part of offered) {
        if (part.id === wanted) {
            return part;
        }
    }

    // The same fallback readDesignSelection() makes on the server: the first of the kind.
    return offered[0];
}

/* The kinds a bouquet has exactly one of, in the order PHP lists them after 'bloom'. */
const SINGLE_KINDS = ['wire', 'wrap', 'accent', 'foliage'];

/**
 * The three single parts, keyed by kind, with null for a kind that has nothing.
 *
 * Blooms are not in here. A bouquet may hold several kinds of flower head at once, so they
 * are a list with quantities rather than one choice -- see currentMix().
 */
function currentSelection(payload, form) {
    const selection = {};

    for (const kind of SINGLE_KINDS) {
        selection[kind] = chosenPart(payload, form, kind);
    }

    return selection;
}

/**
 * The flower heads and how many of each, read off the quantity boxes.
 *
 * The mirror of readBloomMix(), and it has to clamp the same way or the total under the
 * bouquet stops matching the one the server would print for the same URL: at most
 * payload.mixMax kinds, quantities of at least one, and payload.max stems in total, trimmed
 * from the end rather than scaled down.
 *
 * Offered order, which is the order the boxes are in on screen -- and the order readBloomMix()
 * puts its mix in, for this exact reason. Reading the boxes top to bottom is the only order
 * available here, so it is the order the server was made to use.
 *
 * An empty result falls back to payload.mix -- the design the server resolved -- rather than
 * to a bouquet of nothing. That is the one case the boxes cannot reproduce on their own:
 * readBloomMix() answers a request with no usable mix in it with the ?bloom= single-bloom
 * design, and the browser has no way to know what that was unless it is handed it.
 */
function currentMix(payload, form) {
    const offered = payload.parts.bloom || [];
    const mix = [];
    let used = 0;

    for (const part of offered) {
        if (mix.length >= payload.mixMax || used >= payload.max) {
            break;
        }

        const box = form.querySelector('[name="mix[' + part.id + ']"]');
        const wanted = box ? Number.parseInt(box.value, 10) : Number.NaN;

        if (!Number.isFinite(wanted) || wanted < 1) {
            continue;
        }

        const quantity = Math.min(wanted, payload.max - used);
        used += quantity;
        mix.push({ part: part, quantity: quantity });
    }

    if (mix.length > 0) {
        return mix;
    }

    return resolvedMix(payload);
}

/** payload.mix -- what the server built -- turned back into parts. */
function resolvedMix(payload) {
    const offered = payload.parts.bloom || [];
    const mix = [];

    for (const entry of payload.mix || []) {
        for (const part of offered) {
            if (part.id === entry.id) {
                mix.push({ part: part, quantity: entry.quantity });
                break;
            }
        }
    }

    return mix;
}

/** How many flowers are in the bouquet: the sum of the mix, as designCount() has it. */
function mixCount(mix) {
    let count = 0;

    for (const entry of mix) {
        count += entry.quantity;
    }

    return count;
}

/** Which arrangement is chosen, falling back to the one the page was rendered with. */
function currentArrangement(payload, form) {
    const checked = form.querySelector('input[name="arrangement"]:checked');

    return checked ? checked.value : payload.arrangement;
}

/* ====================================================================
                        The price, mirrored
==================================================================== */

/*
 * In whole centavos, never in pesos, for the same reason designTotal() is: '38.00' * 6 in
 * binary floating point is how a total prints as 227.99999999999997. Math.round before the
 * addition because 38.00 * 100 is 3799.9999999999995 here just as it is in PHP.
 */
function priceCentavos(part) {
    return Math.round(Number.parseFloat(part.price) * 100);
}

function totalCentavos(mix, selection) {
    const count = mixCount(mix);
    let centavos = 0;

    for (const entry of mix) {
        centavos += priceCentavos(entry.part) * entry.quantity;
    }

    for (const kind of SINGLE_KINDS) {
        const part = selection[kind];

        if (!part) {
            continue;
        }

        centavos += priceCentavos(part) * (part.perStem ? count : 1);
    }

    return centavos;
}

/*
 * Formatted the way formatPrice() formats: the peso sign, grouped thousands, always two
 * decimals. en-US rather than the visitor's locale on purpose -- a browser set to de-DE
 * would render 1.212,00 beside a server-rendered 1,212.00 on the same page, and two
 * spellings of one number reads as two numbers.
 *
 * The symbol comes from the payload, which got it from CURRENCY_SYMBOL, so there is one
 * definition of it in the project rather than a second one hard-coded here. The fallback is
 * only for the impossible case of a payload without it.
 */
let currency = '₱';

function formatPesos(centavos) {
    return currency + (centavos / 100).toLocaleString('en-US', {
        minimumFractionDigits: 2,
        maximumFractionDigits: 2,
    });
}

/*
 * The server printed a total before this file ran. If our arithmetic disagrees with it on
 * the very first render, the mirror has drifted -- so say so loudly here, where somebody is
 * looking, rather than letting a wrong figure sit quietly next to an Add to basket button.
 */
function checkAgainstServer(payload, mix, selection) {
    const ours = totalCentavos(mix, selection);
    const theirs = Math.round(Number.parseFloat(payload.total) * 100);

    if (ours !== theirs) {
        console.error(
            'customizer: the browser total (' + ours + ' centavos) disagrees with the server '
            + 'total (' + theirs + ' centavos). designTotal() is the one that is right; '
            + 'totalCentavos() in js/customizer.js needs fixing.'
        );
    }
}

/* ====================================================================
                        Geometry: one flower head per shape
==================================================================== */

/*
 * Every part is drawn from Three.js primitives in its own colour_hex. No textures and no
 * model files: GD is not enabled, nothing here needs an image, and chenille is fuzzy and
 * unreflective -- so a matte MeshStandardMaterial with roughness near 1 is the honest
 * material anyway, not a compromise.
 */
function matte(hex) {
    return new THREE.MeshStandardMaterial({
        color: new THREE.Color(hex),
        roughness: 0.92,
        metalness: 0.0,
    });
}

/* A lathe profile is spun around the Y axis, so these are half-outlines read bottom to top. */
const ROSE_PROFILE = [
    [0.02, 0.00], [0.10, 0.04], [0.19, 0.12], [0.24, 0.24],
    [0.23, 0.36], [0.17, 0.45], [0.09, 0.50], [0.02, 0.52],
];

const TULIP_PROFILE = [
    [0.02, 0.00], [0.08, 0.03], [0.14, 0.14], [0.17, 0.30],
    [0.19, 0.44], [0.17, 0.52], [0.10, 0.50], [0.02, 0.46],
];

function lathe(profile) {
    return new THREE.LatheGeometry(
        profile.map(function (point) {
            return new THREE.Vector2(point[0], point[1]);
        }),
        18
    );
}

/*
 * A flower with petals round a centre: the sunflower and the daisy. Same construction,
 * different proportions, which is exactly the distinction the shape column was added to
 * record -- a sunflower is wide with a big dark middle, a daisy is small with a pale one.
 *
 * The petals are flattened spheres rather than boxes so nothing reads as a cardboard cutout
 * at the size the stage draws them.
 */
function petalled(hex, options) {
    const head = new THREE.Group();
    const petalGeometry = new THREE.SphereGeometry(1, 8, 6);
    const petalMaterial = matte(hex);

    for (let i = 0; i < options.petals; i += 1) {
        const petal = new THREE.Mesh(petalGeometry, petalMaterial);
        const angle = (i / options.petals) * Math.PI * 2;

        petal.scale.set(options.petalLength, 0.035, options.petalWidth);
        petal.position.set(
            Math.cos(angle) * options.petalLength * 0.85,
            0,
            Math.sin(angle) * options.petalLength * 0.85
        );
        petal.rotation.y = -angle;
        // Lifted at the outer end, so the head is a shallow bowl rather than a flat wheel.
        petal.rotation.z = options.lift;

        head.add(petal);
    }

    const centre = new THREE.Mesh(
        new THREE.CylinderGeometry(options.centre, options.centre, 0.05, 16),
        matte(options.centreHex)
    );
    centre.position.y = 0.02;
    head.add(centre);

    return head;
}

/*
 * A cluster of little buds up a spike: lavender, and whatever else the shop calls a sprig.
 * Placed on alternating sides going up so it reads as a stalk of florets and not a kebab.
 */
function sprig(hex) {
    const head = new THREE.Group();
    const budGeometry = new THREE.SphereGeometry(0.055, 7, 6);
    const budMaterial = matte(hex);

    for (let i = 0; i < 14; i += 1) {
        const bud = new THREE.Mesh(budGeometry, budMaterial);
        const up = i / 13;
        const angle = i * 2.4;

        // Tapering towards the tip, which is what makes it a spike rather than a column.
        const spread = 0.075 * (1 - up * 0.7);

        bud.position.set(Math.cos(angle) * spread, up * 0.55, Math.sin(angle) * spread);
        head.add(bud);
    }

    return head;
}

/*
 * A rose bud: the shape a bouquet of fuzzy wire actually gets made into most often, and the one
 * shape here that is built from many copies of one part rather than from a silhouette.
 *
 * ONE PETAL IS A WEDGE CUT FROM THE POLE OF A SPHERE, not a band around its equator, and that
 * distinction is the whole shape. A band is a rectangle with hard straight ends, and a few of
 * them side by side tile into a smooth dome -- the first version of this rendered a mushroom
 * cap. A wedge taken from thetaStart = 0 converges to a POINT at the pole, so it is narrow at
 * one end and broad and cupped at the other, which is a petal. It is still a single core
 * primitive: no model file, nothing to download, and it takes colour_hex like everything else.
 */
function petalWedge(radius, phiLength, thetaLength) {
    return new THREE.SphereGeometry(
        radius, 12, 10,
        -phiLength / 2, phiLength,
        0, thetaLength
    );
}

/*
 * Petals are open shells, so both faces have to be lit: from inside a cupped petal you are
 * looking at its back, and a single-sided material draws that as a hole.
 */
function sheet(hex) {
    const material = matte(hex);
    material.side = THREE.DoubleSide;
    return material;
}

const BUD_PETALS = 24;
const BUD_CALYX_HEX = '#5f7d4f';

/* Where every petal's point meets. A rose's petals converge where it leaves the calyx. */
const BUD_APEX = 0.50;

/* How far above the origin that convergence sits, so the petals start just clear of the hip. */
const BUD_BASE = 0.06;

/*
 * A bud is taller than it is wide and a sphere patch is not, so the patch is stretched. Doing it
 * this way rather than with a bigger radius keeps the flower narrow instead of just making it
 * fatter. It has to be accounted for when the petal is placed: scale runs before translation, so
 * the wedge's pole ends up at radius * BUD_STRETCH rather than at radius.
 */
const BUD_STRETCH = 1.45;

/*
 * How far open the bud is drawn, 0 being shut and 1 being a blown rose. 0.5 was chosen by
 * rendering 0, 0.5 and 1 side by side and looking at them: shut reads as a bean, fully open
 * reads as a parasol from below, and the half-open one is the only one that reads as a rose from
 * both the front and a low angle. One constant rather than a column, because the shop picks a
 * shape per part and not a stage of flowering.
 */
const BUD_OPENNESS = 0.5;

/*
 * GOLDEN_ANGLE is declared further down, where the stem placement uses it, and this shape reuses
 * it rather than declaring a second copy. It was a second copy for one revision, and the cost was
 * worth recording: a duplicate top-level `const` is a SyntaxError for the WHOLE module, so the
 * file never evaluated and EVERY shape fell back to the written summary -- not just this one. The
 * page is built to survive that silently, which is why it looked like a missing GPU.
 */

/**
 * A rose bud, a little way open.
 *
 * Each petal's point is pinned at the apex and its body wraps DOWN and around the core, which is
 * how a rose is actually built -- and it is why no flipping is needed, since the wedge already
 * points the right way. Petals sit on a golden-angle spiral, each on a slightly larger sphere
 * than the last so they overlap outwards.
 *
 * Every petal hangs off a hinge placed AT the apex, so leaning it swings the skirt outwards while
 * the point stays where it belongs. Rotating the mesh itself instead drags the point off the apex
 * and opens a hole in the top of the flower.
 *
 * One material for the whole head and one geometry per petal, which is the same bargain
 * petalled() strikes: the geometries are small, and disposeGroup() frees every one of them when
 * the bouquet is rebuilt. They are deliberately NOT cached across heads -- disposeGroup()
 * disposes everything it finds in the bouquet, so a module-level cache would be handing back
 * destroyed buffers the second time the flower count changed.
 */
function budHead(hex) {
    const head = new THREE.Group();
    const petalMaterial = sheet(hex);

    /*
     * THE CROWN IS BUILT UPSIDE DOWN AND THEN TURNED OVER, and that is worth explaining because
     * the code below reads as though it were building the flower the wrong way up.
     *
     * The natural way to write a pole wedge is point-up: the wedge converges at the sphere's pole
     * and its body sweeps down and out. Built that way the petals converge at the TOP and flare
     * downwards, so the widest part of the head is its bottom hem -- an inverted teardrop that
     * reads as a hanging bell. A real bud is the other way round: narrow where it leaves the
     * calyx, widening through the shoulder, tapering to a point going up.
     *
     * So the crown is assembled point-up and the whole group is rotated PI about X. The
     * convergence then sits at the BASE, just above the hip, and every petal sweeps upwards from
     * it. Flipping one group is both cheaper and far easier to reason about than negating a
     * position, a lean and a stretch independently and hoping they agree.
     */
    const crown = new THREE.Group();

    for (let i = 0; i < BUD_PETALS; i += 1) {
        const t = i / (BUD_PETALS - 1);

        const radius = 0.085 + t * 0.063;
        const phi = 1.00 - t * 0.20;

        /*
         * How far along the side the petal wraps. Past PI/2 it curves back over the shoulder,
         * which is what closes the head.
         *
         * The wobble matters more than it looks. A wedge's hem is an arc at constant theta, so
         * one shared thetaLength puts every petal's edge at exactly the same height and the head
         * renders with a flat, sliced-off end -- a bell, not a flower. Breaking it per petal
         * is what fixes that, and it is deterministic rather than Math.random() because the
         * design lives in the query string: the same link has to draw the same bouquet twice.
         */
        const wobble = Math.sin(i * 1.7) * 0.11;
        const thetaLength = 1.70 + t * 0.60 + wobble;

        const spin = new THREE.Group();
        spin.rotation.y = i * GOLDEN_ANGLE;

        const hinge = new THREE.Group();
        hinge.position.y = BUD_APEX;
        // Squared, so the inner petals stay wrapped and only the outer ones fall away.
        hinge.rotation.x = t * t * (0.10 + BUD_OPENNESS * 1.00);

        const petal = new THREE.Mesh(petalWedge(radius, phi, thetaLength), petalMaterial);
        petal.scale.set(1, BUD_STRETCH, 1);
        // Drop it so the wedge's pole lands on the hinge -- after the stretch, not before.
        petal.position.y = -radius * BUD_STRETCH;

        hinge.add(petal);
        spin.add(hinge);
        crown.add(spin);
    }

    /*
     * Turn the crown over, then lift it so the point the petals converge on sits just above the
     * hip rather than at the origin. BUD_APEX is how far that convergence is from the crown's own
     * origin, so adding it back is what cancels the flip's offset exactly.
     */
    crown.rotation.x = Math.PI;
    crown.position.y = BUD_APEX + BUD_BASE;
    head.add(crown);

    /*
     * The receptacle: the green hip the petals spring from. Its job is to close the hole
     * underneath rather than to be seen, which is why it is small and low.
     */
    const hip = new THREE.Mesh(
        new THREE.SphereGeometry(0.070, 12, 10, 0, Math.PI * 2, Math.PI * 0.36, Math.PI * 0.64),
        matte(BUD_CALYX_HEX)
    );
    hip.position.y = 0.075;
    head.add(hip);

    /*
     * Five sepals: the same wedge in green, hinged at the BASE rather than the apex and thrown
     * right over so they point down and out. These are what make the silhouette read as a bud
     * instead of as a ball of petals, and they are the cheapest part of the whole shape.
     */
    const sepalMaterial = sheet(BUD_CALYX_HEX);
    const sepalGeometry = petalWedge(0.160, 0.28, 1.20);

    for (let i = 0; i < 5; i += 1) {
        const spin = new THREE.Group();
        spin.rotation.y = (i / 5) * Math.PI * 2 + 0.4;

        const hinge = new THREE.Group();
        hinge.position.y = 0.105;
        hinge.rotation.x = 2.30 + BUD_OPENNESS * 0.40;

        const sepal = new THREE.Mesh(sepalGeometry, sepalMaterial);
        sepal.position.y = -0.160;

        hinge.add(sepal);
        spin.add(hinge);
        head.add(spin);
    }

    return head;
}

/**
 * One flower head, as an Object3D sitting at the origin and growing upwards.
 *
 * An unknown shape falls back to the rose rather than drawing nothing. validateComponentInput()
 * will not store a bloom without a real shape, so this can only be reached by a row edited
 * outside the form -- and a rose is a better answer than an invisible flower.
 */
function bloomHead(part) {
    const hex = part.colour;

    switch (part.shape) {
        case 'tulip':
            return new THREE.Mesh(lathe(TULIP_PROFILE), matte(hex));

        case 'sunflower':
            return petalled(hex, {
                petals: 14,
                petalLength: 0.30,
                petalWidth: 0.075,
                lift: 0.22,
                centre: 0.13,
                centreHex: '#4a3524',
            });

        case 'daisy':
            return petalled(hex, {
                petals: 10,
                petalLength: 0.21,
                petalWidth: 0.055,
                lift: 0.16,
                centre: 0.06,
                centreHex: '#e8c65c',
            });

        case 'sprig':
            return sprig(hex);

        case 'bud':
            return budHead(hex);

        case 'rose':
        default:
            return new THREE.Mesh(lathe(ROSE_PROFILE), matte(hex));
    }
}

/* ====================================================================
                        Geometry: the bouquet
==================================================================== */

/*
 * All in the same made-up units, all measured from the tie point at y = 0 -- the place a
 * hand would hold it. Every stem pivots there, so leaning them outwards fans the heads apart
 * while the bottoms stay gathered, which is what a hand-tied bouquet actually does.
 */
const STEM_TOP = 2.9;
const STEM_BOTTOM = -0.95;
const WRAP_RADIUS = 1.15;
const WRAP_HEIGHT = 2.2;
const WRAP_APEX_Y = -1.7;
const MAX_LEAN = 0.42;

/*
 * Where the tie sits, as a fraction of the way up the cone rather than a height in units.
 *
 * It has to be a fraction because the wrap now grows with the bouquet: pinned to a fixed y,
 * the band would ride up towards the mouth of a small sleeve and end up a collar round the
 * flowers. At full size 0.72 works out at y = -0.116, which is where it was when the number
 * was written by eye.
 */
const ACCENT_ALONG = 0.72;

/*
 * The golden angle, 137.5 degrees in radians. Placing each stem this far round from the last
 * is what a real flower head does with its own seeds, and it is why six stems read as a dome
 * rather than a row: no two of them line up, at any count.
 */
const GOLDEN_ANGLE = 2.39996;

/**
 * The mix flattened into one part per stem, taken in turn rather than in blocks.
 *
 * Four roses and two sunflowers comes out rose, sunflower, rose, sunflower, rose, rose --
 * which matters because the stems are then placed on the spiral in this order. Laid out in
 * blocks instead, every sunflower would land on consecutive turns of the spiral and the
 * bouquet would be a rose bouquet with a yellow patch on one side.
 */
function stemsOf(mix) {
    const pools = mix.map(function (entry) {
        return { part: entry.part, left: entry.quantity };
    });

    const stems = [];
    let remaining = mixCount(mix);

    while (remaining > 0) {
        for (const pool of pools) {
            if (pool.left > 0) {
                stems.push(pool.part);
                pool.left -= 1;
                remaining -= 1;
            }
        }
    }

    return stems;
}

/**
 * A repeatable number between 0 and 1 for stem i: the fractional part of a big sine.
 *
 * Not Math.random(). "Loose and airy" has to look the same every time the bouquet is
 * rebuilt, and a real random would make the flowers jump on every keystroke in a quantity
 * box -- which reads as a bug, not as informality.
 */
function wobble(i) {
    const x = Math.sin((i + 1) * 12.9898) * 43758.5453;

    return x - Math.floor(x);
}

/**
 * How far stem i leans out and how long it is, for the chosen arrangement.
 *
 * Three arrangements out of two numbers, because that is genuinely all the difference there
 * is between them. The spiral placement, the wrap and the tie do not change:
 *
 *   dome   the phyllotaxis fan the builder shipped with -- taller in the middle, falling
 *          away at the edges, because a leaning stem's head is lower than an upright one's.
 *   round  the same fan pulled in, with every stem lengthened by exactly the amount its lean
 *          would have lowered it: 1/cos(lean). The heads then sit on a flat disc, which is
 *          what a posy is.
 *   loose  leaning further and at uneven lengths. The lean starts high rather than at zero,
 *          so even the middle stems are not vertical and there is room between them.
 */
function placement(arrangement, i, count) {
    const spread = count === 1 ? 0 : Math.sqrt(i / (count - 1));

    if (arrangement === 'round') {
        const lean = MAX_LEAN * 0.55 * spread;

        return { lean: lean, top: STEM_TOP / Math.cos(lean) };
    }

    if (arrangement === 'loose') {
        return {
            lean: MAX_LEAN * (0.75 + 0.55 * spread),
            top: STEM_TOP * (0.80 + 0.42 * wobble(i)),
        };
    }

    return { lean: MAX_LEAN * spread, top: STEM_TOP };
}

/**
 * How big the paper is, given how many flowers are going in it.
 *
 * The wrap used to be one fixed cone whatever the bouquet, which was wrong at both ends: a
 * single flower stood in a sleeve wide enough for two dozen, so the cone stopped reading as
 * paper round a bouquet and started reading as a plant pot with something growing out of it.
 *
 * The radius grows faster than the height, so a small bouquet gets a proportionally taller,
 * narrower sleeve -- which is what a rolled paper cone is -- and a big one gets the wide
 * shallow wrap it needs. sqrt rather than a straight fraction because the flowers themselves
 * spread as the square root of their count: they sit on a spiral, and a spiral fills area.
 */
function wrapSize(count, max) {
    // A payload without a limit in it should still draw something. 24 is CUSTOM_BLOOM_MAX,
    // and this is the only number in the file that is a copy of a PHP one -- it decides how
    // big the paper looks and nothing else, so a stale copy is a slightly wrong cone rather
    // than a wrong price.
    const limit = Number.isFinite(max) && max > 0 ? max : 24;
    const t = Math.sqrt(Math.min(count, limit) / limit);

    return {
        radius: WRAP_RADIUS * (0.42 + 0.58 * t),
        height: WRAP_HEIGHT * (0.72 + 0.28 * t),
        // How far down the stems are cut. Tied to the same number, so the cut ends stay
        // inside the paper at every size instead of poking through the point of a small one.
        bottom: STEM_BOTTOM * (0.55 + 0.45 * t),
    };
}

function foliageLeaves(hex) {
    const foliage = new THREE.Group();
    const leafShape = new THREE.Shape();
    leafShape.moveTo(0, 0);
    leafShape.quadraticCurveTo(0.18, 0.15, 0.46, 0.02);
    leafShape.quadraticCurveTo(0.23, -0.12, 0, 0);

    const leafGeometry = new THREE.ShapeGeometry(leafShape, 8);
    const leafMaterial = matte(hex);
    leafMaterial.side = THREE.DoubleSide;

    const twigGeometry = new THREE.CylinderGeometry(0.012, 0.02, 1.35, 6);
    const twigMaterial = new THREE.MeshStandardMaterial({
        color: new THREE.Color(hex).multiplyScalar(0.68),
        roughness: 0.95,
        metalness: 0,
    });

    for (let sprayIndex = 0; sprayIndex < 3; sprayIndex += 1) {
        const angle = (sprayIndex / 3) * Math.PI * 2 + Math.PI / 6;
        const spray = new THREE.Group();
        spray.position.set(Math.cos(angle) * 0.24, 0.08, Math.sin(angle) * 0.24);
        spray.rotation.y = angle;

        const twig = new THREE.Mesh(twigGeometry, twigMaterial);
        twig.position.y = 0.66;
        twig.rotation.z = -0.16;
        spray.add(twig);

        for (let leafIndex = 0; leafIndex < 4; leafIndex += 1) {
            const leaf = new THREE.Mesh(leafGeometry, leafMaterial);
            const leftSide = leafIndex % 2 === 0;

            leaf.position.set(0, 0.16 + leafIndex * 0.29, 0);
            leaf.rotation.z = leftSide ? Math.PI - 0.55 : 0.55;
            leaf.rotation.x = leftSide ? -0.18 : 0.18;
            leaf.scale.setScalar(0.82 + (leafIndex % 2) * 0.12);
            spray.add(leaf);
        }

        foliage.add(spray);
    }

    foliage.scale.y = 1.3;

    return foliage;
}

/**
 * The whole bouquet as one Group, rebuilt from scratch on every change.
 *
 * Rebuilding rather than editing in place: the count changes the number of objects, the
 * shape changes their geometry, and a builder that tries to reconcile all that against what
 * is already on screen is where the bugs would live. A few dozen primitives is nothing to
 * throw away and remake.
 *
 * A missing kind is simply not drawn. An admin may withdraw every accent, and a bouquet with
 * no ribbon is a bouquet, not an error -- the same choice designTotal() makes when it prices
 * one.
 */
function buildBouquet(mix, selection, arrangement, max) {
    const bouquet = new THREE.Group();
    const stems = stemsOf(mix);
    const size = wrapSize(stems.length, max);

    // Built once and shared by every stem. Three.js is happy for many meshes to point at one
    // geometry and one material, and 24 copies of each would be 24 uploads to the GPU. The
    // cylinder is one unit tall and each stem scales it, which is what lets them differ in
    // length without differing in geometry.
    const stemMaterial = matte(selection.wire ? selection.wire.colour : '#7d8a72');
    const stemGeometry = new THREE.CylinderGeometry(0.032, 0.026, 1, 7);

    for (let i = 0; i < stems.length; i += 1) {
        const stem = new THREE.Group();
        const place = placement(arrangement, i, stems.length);

        /*
         * A leaning stem is cut short at the bottom. Every stem pivots at the tie point, so
         * the further the head leans out one way, the further the cut end swings out the
         * other -- and the wrap is a cone, which is at its narrowest exactly where those ends
         * are. Left at full length, two dozen of them burst through the point of it like a
         * beard. Shortening in proportion to the lean keeps every end inside the paper.
         */
        const bottom = size.bottom * (1 - Math.min(1, place.lean / MAX_LEAN) * 0.65);

        const shaft = new THREE.Mesh(stemGeometry, stemMaterial);
        shaft.scale.y = place.top - bottom;
        shaft.position.y = (place.top + bottom) / 2;
        stem.add(shaft);

        const head = bloomHead(stems[i]);
        head.position.y = place.top;
        stem.add(head);

        /*
         * YXZ rather than the default XYZ, and it matters. In XYZ the X tilt is applied in
         * world space for every stem, so they would all lean the same way and the spin would
         * do nothing. In YXZ the tilt happens first and the spin carries it round, so each
         * stem leans outward along its own bearing.
         */
        stem.rotation.order = 'YXZ';
        stem.rotation.y = i * GOLDEN_ANGLE;
        stem.rotation.x = place.lean;

        bouquet.add(stem);
    }

    if (selection.foliage) {
        bouquet.add(foliageLeaves(selection.foliage.colour));
    }

    if (selection.wrap) {
        /*
         * An open cone, turned upside down so it is a funnel: apex at the bottom where the
         * stems gather, mouth at the top where the flowers come out. DoubleSide because an
         * open cone has no thickness, and from above you are looking at the inside of it --
         * with the default FrontSide the near wall would simply not be there.
         */
        const cone = new THREE.Mesh(
            new THREE.ConeGeometry(size.radius, size.height, 24, 1, true),
            new THREE.MeshStandardMaterial({
                color: new THREE.Color(selection.wrap.colour),
                roughness: 0.95,
                metalness: 0.0,
                side: THREE.DoubleSide,
            })
        );

        cone.rotation.x = Math.PI;
        cone.position.y = WRAP_APEX_Y + size.height / 2;
        bouquet.add(cone);
    }

    if (selection.accent) {
        /*
         * One band at the tie point, whatever the accent is. Twine, a satin bow, pearl pins
         * and a gift tag all draw the same and differ only in colour -- a decision recorded
         * in the plan: blooms got a shape column because a sunflower and a daisy are
         * unmistakably different objects, and four things tied round a stem are not.
         *
         * Sized and placed from the cone rather than from numbers picked by eye, so the band
         * stays on the paper at every bouquet size instead of floating off a small one.
         */
        const band = new THREE.Mesh(
            new THREE.TorusGeometry(size.radius * ACCENT_ALONG + 0.02, 0.07, 8, 26),
            matte(selection.accent.colour)
        );

        band.rotation.x = Math.PI / 2;
        band.position.y = WRAP_APEX_Y + size.height * ACCENT_ALONG;
        bouquet.add(band);
    }

    return bouquet;
}

/**
 * Every geometry and material in a group, handed back to the GPU.
 *
 * Three.js does not do this itself: dropping the last reference to a Mesh frees the
 * JavaScript object but leaves its buffers allocated on the graphics card. Without this,
 * dragging the count box from 1 to 24 and back would leak a few hundred buffers a minute.
 *
 * The Set is because stems share one geometry and one material between them -- disposing the
 * same buffer twenty-four times is harmless but pointless.
 */
function disposeGroup(group) {
    const seen = new Set();

    group.traverse(function (node) {
        for (const resource of [node.geometry, node.material]) {
            if (resource && !seen.has(resource)) {
                seen.add(resource);
                resource.dispose();
            }
        }
    });
}

/* ====================================================================
                        The stage
==================================================================== */

// Where the camera looks when there is no bouquet to measure -- roughly the middle of the
// default one. Every bouquet that does get built replaces this with its own middle.
const TARGET_Y = 0.8;

/*
 * How far in and out of the fitted distance the wheel may go, as a fraction of it rather
 * than as two fixed distances.
 *
 * Fixed distances were wrong once the camera started framing the bouquet: 5.0 is a close
 * look at one flower and inside a two-dozen one, and 12.0 is a sensible way back from two
 * dozen and loses a single rose in the middle of the stage. A fraction means "a bit closer
 * than framed" and "a bit further out" mean the same thing at both ends.
 */
const MIN_ZOOM = 0.62;
const MAX_ZOOM = 1.45;

/*
 * How far the drag may tilt. Named rather than written into the clamp because fitView()
 * frames the bouquet at the steepest of them: the two numbers have to be the same one, or
 * the shot is composed for a tilt the drag can go past.
 */
const MIN_PITCH = -0.3;
const MAX_PITCH = 0.95;

// Used only until the first bouquet is measured, and when a bouquet has no geometry at all
// to measure -- every kind withdrawn, which draws nothing.
const DEFAULT_DISTANCE = 7.6;

/**
 * Set up the scene, or return null if this browser cannot draw it.
 *
 * The try is the whole point. A machine with no WebGL, a driver on Chrome's blocklist, or a
 * browser that has simply run out of contexts throws here, and the caller then leaves the
 * page exactly as the server rendered it. That is the second of the page's two fallbacks:
 * one for no JavaScript, one for no WebGL.
 */
function makeStage(canvas) {
    let renderer;

    try {
        renderer = new THREE.WebGLRenderer({
            canvas: canvas,
            antialias: true,
            /*
             * Needed so a screenshot of this canvas contains the bouquet. Without it the
             * drawing buffer may be cleared once the frame is composited, and a capture that
             * happens to land after that comes back as flat background -- which is exactly
             * the failure the Chrome check is meant to catch, so it must not be caused by
             * the check itself.
             */
            preserveDrawingBuffer: true,
        });
    } catch (error) {
        return null;
    }

    renderer.setPixelRatio(Math.min(window.devicePixelRatio || 1, 2));

    const scene = new THREE.Scene();
    scene.background = new THREE.Color('#eff7f3');

    const camera = new THREE.PerspectiveCamera(38, 4 / 3, 0.1, 100);

    /*
     * Three lights and no shadows. The hemisphere light is the room -- pale from above, the
     * stage colour bounced from below -- and the two directionals are a window on one side
     * and a soft fill on the other, so a white wrap on a white background still has an edge.
     * Shadow maps would cost a depth pass per frame to darken the underside of a bouquet
     * nobody is inspecting from below.
     */
    scene.add(new THREE.HemisphereLight('#ffffff', '#cbd7d1', 1.15));

    const key = new THREE.DirectionalLight('#ffffff', 1.5);
    key.position.set(3.5, 6, 5);
    scene.add(key);

    const fill = new THREE.DirectionalLight('#ffffff', 0.45);
    fill.position.set(-4, 1.5, -3.5);
    scene.add(fill);

    return { renderer: renderer, scene: scene, camera: camera };
}

/**
 * How far back the camera has to be for all of `object` to fit on the stage, and what it
 * should be looking at.
 *
 * The bouquet is measured rather than predicted. Its height is not a constant: a round posy
 * lengthens every stem by 1/cos(lean) so the heads sit on a flat disc, "loose and airy"
 * adds a per-stem wobble on top of that, and the wrap grows with the flower count. A camera
 * parked at one distance framed the six-flower dome it was chosen for and cut the heads off
 * everything taller -- including, at one flower, slicing the single rose flat across the top
 * of the canvas.
 *
 * What is measured is a cylinder, not a box and not a sphere. Not a box because the bouquet
 * turns: a box fits one yaw and clips at the next. Not a sphere because a bouquet is tall
 * and narrow, and the sphere that holds one is mostly empty air -- fitting that put a rose
 * in the middle of a field of background. A cylinder about the upright axis is the shape
 * the thing actually is, and both of its measurements survive a spin: height does not change
 * when you turn something about a vertical axis, and neither does the radius.
 *
 * The fit holds at every tilt the drag allows, not just the one the page opens at, so
 * turning the bouquet over to look down into the flowers cannot push them out of frame -- a
 * bouquet that fits now and clips when you drag is the same bug in a slower form.
 */
function fitView(camera, object) {
    const bounds = new THREE.Box3().setFromObject(object);

    if (bounds.isEmpty()) {
        return { distance: DEFAULT_DISTANCE, centre: new THREE.Vector3(0, TARGET_Y, 0) };
    }

    // The pivot stays on the upright axis -- the bouquet is built around it and a tie point
    // off to one side would swing as it turned -- but rides to the middle of what was built,
    // so a tall bouquet is not framed from the height of a short one.
    const centre = new THREE.Vector3(0, (bounds.min.y + bounds.max.y) / 2, 0);
    const above = bounds.max.y - centre.y;
    const below = centre.y - bounds.min.y;
    const radius = Math.max(
        Math.abs(bounds.min.x), Math.abs(bounds.max.x),
        Math.abs(bounds.min.z), Math.abs(bounds.max.z),
    );

    const halfVertical = THREE.MathUtils.degToRad(camera.fov) / 2;
    const halfHorizontal = Math.atan(Math.tan(halfVertical) * camera.aspect);
    const tan = Math.tan(halfVertical);

    /*
     * For a point h above the pivot and r out from it, on the side of the cylinder nearest
     * the camera, staying inside the frustum means |h·cos - r·sin| <= tan · (d - h·sin -
     * r·cos). Solved for d. The near side is the worst case, because a point closer to the
     * camera needs more room for the same height.
     */
    function reach(height, out, pitch) {
        const sin = Math.sin(pitch);
        const cos = Math.cos(pitch);

        return (Math.abs(height * cos - out * sin) / tan) + height * sin + out * cos;
    }

    /*
     * Walked across the whole range of tilts rather than evaluated at the steepest, which is
     * what the first version of this did and why it still clipped. Tilting the camera down
     * foreshortens the bouquet -- at the steepest tilt a tall bouquet is the shortest it ever
     * looks -- so fitting there produces the closest camera of any tilt, and every shallower
     * angle then clips. The binding tilt is somewhere in the middle, so the whole range gets
     * asked and the furthest answer wins.
     */
    let distance = 0;
    const steps = 12;

    for (let i = 0; i <= steps; i += 1) {
        const pitch = MIN_PITCH + (MAX_PITCH - MIN_PITCH) * (i / steps);

        distance = Math.max(distance, reach(above, radius, pitch), reach(-below, radius, pitch));
    }

    return {
        distance: 1.03 * Math.max(distance, radius / Math.sin(halfHorizontal)),
        centre: centre,
    };
}

/**
 * Drag to turn it, wheel to come closer. About thirty lines, which is why OrbitControls is
 * not vendored alongside Three.js: it is a second file to keep in step for two gestures.
 *
 * The camera is worked out from a yaw, a pitch and a distance rather than moved directly, so
 * it cannot drift below the floor or end up inside the bouquet however long somebody drags.
 * The pitch clamp keeps it between looking slightly up at the stems and looking down into
 * the flowers; both of those are views of a bouquet, and underneath it is not.
 *
 * Returns a handle so the page can re-frame the shot when the bouquet is rebuilt or the
 * canvas is resized. Zoom is kept as a fraction of the fitted distance rather than as a
 * distance, so somebody who has zoomed in on a rose and then asks for a dozen keeps the
 * closer view of the bigger bouquet instead of having it snap back.
 */
function spin(canvas, camera, render) {
    let yaw = 0.35;
    let pitch = 0.18;
    let zoom = 1;
    let fitted = DEFAULT_DISTANCE;
    let centre = new THREE.Vector3(0, TARGET_Y, 0);
    let subject = null;
    let dragging = false;
    let lastX = 0;
    let lastY = 0;

    function place() {
        const distance = fitted * zoom;
        const flat = Math.cos(pitch) * distance;

        camera.position.set(
            centre.x + Math.sin(yaw) * flat,
            centre.y + Math.sin(pitch) * distance,
            centre.z + Math.cos(yaw) * flat,
        );
        camera.lookAt(centre);
    }

    canvas.addEventListener('pointerdown', function (event) {
        dragging = true;
        lastX = event.clientX;
        lastY = event.clientY;

        /*
         * So a drag that leaves the canvas keeps turning the bouquet instead of stopping
         * dead at the edge. In a try because setPointerCapture throws if the browser no
         * longer considers this pointer active -- which it can, for a synthesised event --
         * and losing capture is a worse drag, not a broken page.
         */
        try {
            canvas.setPointerCapture(event.pointerId);
        } catch (error) {
            // Nothing to do: the drag still works, it just ends at the edge of the canvas.
        }
    });

    canvas.addEventListener('pointermove', function (event) {
        if (!dragging) {
            return;
        }

        yaw -= (event.clientX - lastX) * 0.008;
        pitch = Math.max(MIN_PITCH, Math.min(MAX_PITCH, pitch + (event.clientY - lastY) * 0.005));
        lastX = event.clientX;
        lastY = event.clientY;

        place();
        render();
    });

    function stop(event) {
        dragging = false;

        if (canvas.hasPointerCapture(event.pointerId)) {
            canvas.releasePointerCapture(event.pointerId);
        }
    }

    canvas.addEventListener('pointerup', stop);
    canvas.addEventListener('pointercancel', stop);

    /*
     * passive: false because this calls preventDefault(): a wheel over the stage should zoom
     * the bouquet and not scroll the page out from under the pointer. Chrome treats a wheel
     * listener as passive by default and would ignore the preventDefault() otherwise.
     *
     * The equivalent for touch is touch-action: none on the canvas in css/main.css -- a
     * one-finger drag has to turn the bouquet rather than scroll, and that is not something
     * a listener can decide after the fact.
     */
    canvas.addEventListener('wheel', function (event) {
        event.preventDefault();
        // Scaled by the fitted distance so one notch of the wheel moves the view by the same
        // visible amount whether the stage holds one flower or two dozen.
        zoom = Math.max(MIN_ZOOM, Math.min(MAX_ZOOM, zoom + event.deltaY * 0.006 / fitted));
        place();
        render();
    }, { passive: false });

    place();

    return {
        /*
         * Frame this object, and keep framing it. Called with the new group every time the
         * bouquet is rebuilt, and with no argument by the resize handler -- a narrower canvas
         * is a narrower frustum, so the distance that fitted a moment ago may not any more.
         */
        frame(object) {
            if (object !== undefined) {
                subject = object;
            }

            if (!subject) {
                return;
            }

            const fit = fitView(camera, subject);

            fitted = fit.distance;
            centre = fit.centre;
            place();
        },
    };
}

/* ====================================================================
                        Keeping the words in step with the picture
==================================================================== */

/*
 * The mirror of designSentence(): what the canvas tells a screen reader it is showing.
 *
 * The blooms come first and each says its own quantity, because that is the difference the
 * mix makes -- "4 Red Rose Head, 2 Sunflower Head" is the bouquet, and a sentence that only
 * said "6 flowers" would be describing the one bouquet the mix cannot be.
 *
 * The arrangement label comes from the payload rather than from words written here, so the
 * sentence ends the way designArrangementLabel() ends it.
 */
function sentenceFor(payload, mix, selection, arrangement) {
    const named = [];
    const count = mixCount(mix);

    for (const entry of mix) {
        named.push(entry.quantity + ' ' + entry.part.name);
    }

    for (const kind of SINGLE_KINDS) {
        const part = selection[kind];

        if (part) {
            named.push(part.perStem ? count + ' ' + part.name : part.name);
        }
    }

    if (named.length === 0) {
        return 'An empty bouquet. Nothing is available to build with right now.';
    }

    const labels = payload.arrangements || {};
    const label = typeof labels[arrangement] === 'string' ? labels[arrangement] : arrangement;

    return 'A bouquet of ' + named.join(', ') + '. ' + label + ' arrangement.';
}

/** One row of the breakdown, built the way php/customize.php prints it. */
function breakdownRow(part, quantity, perStem) {
    const row = document.createElement('li');

    const what = document.createElement('span');
    what.className = 'customizer-breakdown-what';
    what.textContent = perStem ? quantity + ' × ' + part.name : part.name;

    const each = document.createElement('span');
    each.className = 'customizer-breakdown-each';
    each.textContent = perStem ? formatPesos(priceCentavos(part)) + ' each' : 'just the one';

    const line = document.createElement('span');
    line.className = 'customizer-breakdown-line';
    line.textContent = formatPesos(priceCentavos(part) * quantity);

    row.append(what, each, line);

    return row;
}

/*
 * The breakdown list, rebuilt to match the markup php/customize.php ships.
 *
 * Same order as designSummary(): every bloom in the mix at its own quantity, then wire, wrap
 * and accent. A per-stem kind is charged by the whole bouquet's flower count, which is why
 * the wire line moves when a bloom quantity changes even though the wire did not.
 *
 * createElement and textContent throughout, never innerHTML. Part names come out of the
 * database and go through escape() on the server; doing it by hand here would mean a second
 * escaping rule to get right, and textContent has no such rule to get wrong.
 */
function writeBreakdown(list, mix, selection) {
    const count = mixCount(mix);

    list.textContent = '';

    for (const entry of mix) {
        list.append(breakdownRow(entry.part, entry.quantity, true));
    }

    for (const kind of SINGLE_KINDS) {
        const part = selection[kind];

        if (part) {
            list.append(breakdownRow(part, part.perStem ? count : 1, part.perStem));
        }
    }
}

/** The line under the total: how many flowers, arranged how. */
function countSentence(payload, mix, arrangement) {
    const count = mixCount(mix);
    const labels = payload.arrangements || {};
    const label = typeof labels[arrangement] === 'string' ? labels[arrangement] : arrangement;

    return count + ' ' + (count === 1 ? 'flower' : 'flowers') + ', ' + label.toLowerCase() + '.';
}

/**
 * The design as a query string, in the same shape designQuery() builds on the server.
 *
 * Used for three things: the shareable link in the total panel, the hidden field the save
 * form posts, and replaceState -- so that reloading, copying the address bar or pressing
 * Save all land on the bouquet actually on screen rather than the one the page opened with.
 *
 * No leading "?", because two of those three uses do not want one.
 *
 * URLSearchParams percent-encodes the brackets in mix[7]; so does http_build_query on the
 * server, and PHP decodes them back into an array either way. The two sides agree because
 * they are both writing the same thing, not because either is being careful.
 */
function designQuery(mix, selection, arrangement) {
    const query = new URLSearchParams();

    for (const entry of mix) {
        query.set('mix[' + entry.part.id + ']', String(entry.quantity));
    }

    for (const kind of SINGLE_KINDS) {
        if (selection[kind]) {
            query.set(kind, String(selection[kind].id));
        }
    }

    query.set('arrangement', arrangement);

    return query.toString();
}

/* ====================================================================
                        Starting up
==================================================================== */

function boot() {
    const design = readDesign();

    if (!design) {
        return;
    }

    const payload = design.payload;
    const form = design.root.querySelector('[data-customizer-form]');
    const canvas = design.root.querySelector('[data-customizer-stage]');
    const fallback = design.root.querySelector('[data-customizer-static]');
    /*
     * EVERY place the total is printed, not the first one.
     *
     * There are two as of 2026-10-11: the figure in the "What it comes to" panel, and the one in
     * the bar that sticks to the bottom of a phone screen so the price stays visible while
     * somebody is scrolled down among the pickers. A querySelector() here would have updated the
     * panel and left the sticky bar showing the price the page opened with -- and a stale price
     * beside a live Add to basket button is worse than no price at all. Same reason
     * data-customizer-add-design is already a querySelectorAll below.
     */
    const amounts = design.root.querySelectorAll('[data-customizer-amount]');
    const breakdown = design.root.querySelector('[data-customizer-breakdown]');
    const countRead = design.root.querySelector('[data-customizer-count-read]');
    const share = design.root.querySelector('[data-customizer-share]');

    /*
     * The hidden field the save form posts. It carries the same query string the share link
     * does, rewritten on every change, so pressing Save keeps the bouquet on the stage rather
     * than the one the page was rendered with. Absent for a signed-out visitor, who is shown
     * an invitation to sign in instead of a form -- hence the null check at every use.
     */
    const saveField = design.root.querySelector('[data-customizer-save-design]');

    /*
     * The same query string again, for the add-to-basket form. Kept in step for a stronger reason
     * than the save field is: saving the wrong bouquet is an annoyance somebody can rename or
     * delete, while adding the wrong one puts a price in a basket that does not match the figure
     * they were looking at when they pressed the button. Always present -- adding needs no
     * account -- but still null-checked, because a cached page from before this field existed
     * must not throw on every click.
     */
    const addFields = design.root.querySelectorAll('[data-customizer-add-design]');

    if (!form || !canvas) {
        return;
    }

    if (typeof payload.currency === 'string' && payload.currency !== '') {
        currency = payload.currency;
    }

    const stage = makeStage(canvas);

    /*
     * No WebGL. Nothing is touched: the canvas stays hidden, the written summary stays
     * visible, the form stays a working GET form, and the note under the summary already
     * says the preview needs WebGL. Leaving the page as the server rendered it is the
     * fallback -- there is nothing to switch to.
     */
    if (!stage) {
        return;
    }

    canvas.hidden = false;

    /*
     * Two handles onto the stage, for the Chrome/CDP suite and nothing else.
     *
     * They are here because the canvas cannot otherwise be read from outside: getContext('2d')
     * returns null on a WebGL canvas, so there is no getImageData, and a fresh
     * getContext('webgl2') is not guaranteed to hand back the context Three.js is drawing
     * into. readPixels on this renderer's own context is the only way to prove the bouquet
     * was actually painted rather than measured into existence.
     *
     * Kept deliberately: a page that cannot be checked is worse than two properties on
     * window. They are read-only handles to objects the page already owns, so nothing here
     * changes what a visitor gets.
     */
    window.__customizerRenderer = stage.renderer;
    window.__customizerCamera = stage.camera;

    /*
     * The written summary is what the canvas replaces, so it goes now. It is not kept in
     * step afterwards: it is hidden, so nothing reads it, and the canvas carries the same
     * sentence in its aria-label for anybody not looking at the picture.
     */
    if (fallback) {
        fallback.hidden = true;
    }

    let bouquet = null;

    function render() {
        stage.renderer.render(stage.scene, stage.camera);
    }

    /*
     * The canvas has a fixed aspect ratio in CSS, so it always has a layout size to read --
     * which is what stops the first render happening at 0x0 and staying blank. setSize's
     * third argument is false so Three.js leaves the CSS width and height alone; the
     * stylesheet owns the box, this owns the number of pixels in it.
     */
    function resize() {
        const width = canvas.clientWidth;
        const height = canvas.clientHeight;

        if (width === 0 || height === 0) {
            return;
        }

        stage.renderer.setSize(width, height, false);
        stage.camera.aspect = width / height;
        stage.camera.updateProjectionMatrix();
        // A new aspect is a new frustum, so what fitted on the old stage may not fit on this
        // one. Nothing happens until the first bouquet has been framed.
        view.frame();
        render();
    }

    /*
     * The picked-out ring round a bloom row is server-rendered from the quantity, so it has
     * to be kept in step here or it would still be showing the bouquet the page opened with.
     * Reading the box rather than the mix: the mix is clamped to the flower limit, and a row
     * somebody has typed a number into is a row they have chosen even when the last one over
     * the limit is not being drawn.
     */
    function syncChosen() {
        for (const box of form.querySelectorAll('[name^="mix["]')) {
            const row = box.closest('.customizer-mix-row');

            if (row) {
                row.classList.toggle('is-chosen', Number.parseInt(box.value, 10) > 0);
            }
        }
    }

    function apply(first) {
        const selection = currentSelection(payload, form);
        const mix = currentMix(payload, form);
        const arrangement = currentArrangement(payload, form);

        if (first) {
            checkAgainstServer(payload, mix, selection);
        }

        if (bouquet) {
            stage.scene.remove(bouquet);
            disposeGroup(bouquet);
        }

        bouquet = buildBouquet(mix, selection, arrangement, payload.max);
        stage.scene.add(bouquet);
        view.frame(bouquet);

        canvas.setAttribute('aria-label', sentenceFor(payload, mix, selection, arrangement));

        const pesos = formatPesos(totalCentavos(mix, selection));

        for (const node of amounts) {
            node.textContent = pesos;
        }

        if (breakdown) {
            writeBreakdown(breakdown, mix, selection);
        }

        if (countRead) {
            countRead.textContent = countSentence(payload, mix, arrangement);
        }

        syncChosen();

        const query = designQuery(mix, selection, arrangement);

        if (share) {
            share.setAttribute('href', '?' + query);
        }

        if (saveField) {
            saveField.value = query;
        }

        addFields.forEach(function (field) {
            field.value = query;
        });

        /*
         * replaceState rather than pushState: turning a bouquet from six flowers to seven is
         * an adjustment, not a page visited. pushState would make the back button walk
         * through every click somebody made instead of returning them to where they came
         * from.
         *
         * The quantity boxes are deliberately not written back to their clamped values --
         * somebody typing "12" passes through "1", and correcting them mid-keystroke would
         * fight the typing. The URL and the price use the clamped numbers; the boxes keep
         * what was typed.
         */
        window.history.replaceState(null, '', '?' + query);

        render();
    }

    const view = spin(canvas, stage.camera, render);
    resize();
    apply(true);

    /*
     * One listener, on input rather than change. A radio fires input as well as change, and a
     * quantity box fires it on every keystroke, so this covers both -- listening for both
     * events would rebuild the bouquet twice for every click on a radio.
     */
    form.addEventListener('input', function () {
        apply(false);
    });

    /*
     * The enhanced path never round-trips. Without this, Enter in a quantity box would submit
     * the form and reload a page that is already showing the right answer.
     */
    form.addEventListener('submit', function (event) {
        event.preventDefault();
        apply(false);
    });

    if (window.ResizeObserver) {
        new ResizeObserver(resize).observe(canvas);
    }

    /*
     * A lost context is a canvas full of nothing -- the GPU has taken its buffers back, after
     * a driver reset or the tab being backgrounded too long. preventDefault() is what would
     * let it be restored, but restoring means rebuilding every buffer, and the honest cheap
     * answer is to put the written summary back and let the page be what it was without
     * JavaScript.
     */
    canvas.addEventListener('webglcontextlost', function (event) {
        event.preventDefault();
        canvas.hidden = true;

        if (fallback) {
            fallback.hidden = false;
        }
    });
}

/*
 * No DOMContentLoaded wait. A module is deferred by definition, so the document is already
 * parsed by the time this line runs -- which is also why renderHeader() can put the tag in
 * the <head> beside the stylesheet.
 */
boot();


