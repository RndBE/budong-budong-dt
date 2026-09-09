/*
 * Photo Sphere Viewer pulls in three.js, so it is only fetched once a
 * panorama is actually shown. Its stylesheets ship with app.css.
 *
 * The stage owns the viewer itself (`twin-sphere.js`); this module keeps the
 * loader and the markup helpers both the stage and its hotspots share.
 */
import { glyphSvg } from '../lib/icons.js';

let psvPromise = null;

export function loadPsv() {
    psvPromise ??= Promise.all([
        import('@photo-sphere-viewer/core'),
        import('@photo-sphere-viewer/markers-plugin'),
        import('@photo-sphere-viewer/compass-plugin'),
        import('@photo-sphere-viewer/autorotate-plugin'),
    ]).then(([core, markers, compass, autorotate]) => ({
        Viewer: core.Viewer,
        MarkersPlugin: markers.MarkersPlugin,
        CompassPlugin: compass.CompassPlugin,
        AutorotatePlugin: autorotate.AutorotatePlugin,
    }));

    return psvPromise;
}

/*
 * A hotspot inside a station panorama.
 *
 * `data-hotspot` is what the drag handler in `twin-sphere.js` picks the marker
 * up by, the same way a station pin carries `data-station`.
 */
/*
 * The one marker on the dam that opens a drawing rather than a place.
 *
 * A piezometer is buried: there is nothing of it in the photograph to stand a
 * pin on, and a pin that pretended otherwise would be pointing at rockfill.
 * What this marks is the *section* it belongs to — the cut through the dam
 * where those instruments can actually be seen — so it is drawn as a plate
 * with a section glyph on it and nothing else. It is the glyph alone rather
 * than a named plate because it stands on the dam body among instruments that
 * are named there already; what it is and what is in it are on the tooltip and
 * in the drawing it opens, which is where a reader who wants them is going
 * anyway.
 *
 * It carries `data-hotspot` as well as `data-section`: inside a station the
 * placement control picks markers up by that attribute, so the section is
 * dragged onto the axis it cuts with the machinery every other hotspot uses,
 * and the drop posts to the same endpoint.
 */
export function sectionHtml(section, color) {
    return `
        <div class="psv-section" data-section="${section.id}" data-hotspot="${section.id}"
             role="button" tabindex="0"
             aria-label="${sectionName(section)}, buka potongan as bendungan">
            <span class="psv-section__mark" style="border-color:${color}">
                ${glyphSvg('section', 19)}
            </span>
        </div>
    `;
}

/** What the section is called, with what is in it. */
export function sectionName(section) {
    const count = (section.points ?? []).length;
    const dry = section.dry ? `, ${section.dry} kering` : '';

    return `${section.label} — ${count} piezometer${dry}`;
}

export function hotspotHtml(hotspot, value, color) {
    const icon = {
        link: '<svg viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="currentColor" stroke-width="2"><path d="M5 12h14M13 6l6 6-6 6"/></svg>',
    }[hotspot.type] ?? `<span class="psv-dot" style="background:${color}"></span>`;

    return `
        <div class="psv-hotspot psv-hotspot--${hotspot.type}" data-type="${hotspot.type}" data-hotspot="${hotspot.id}">
            <span class="psv-hotspot__ring" style="border-color:${color}"></span>
            <span class="psv-hotspot__chip">
                ${icon}
                <span class="psv-hotspot__text">
                    <span class="psv-hotspot__label">${hotspot.label}</span>
                    ${value ? `<span class="psv-hotspot__value">${value}</span>` : ''}
                </span>
            </span>
        </div>
    `;
}

/*
 * One stake, at the centre of its own petak.
 *
 * The petak is a projected polygon because it stands for a piece of ground. A
 * stake is the opposite: a real patok is about a metre of concrete seen from
 * 130 m away, which is a third of a degree — drawn to scale it is a speck
 * nobody can find. So it is drawn as a sign of a fixed size, the survey
 * target — a diamond around a centre dot — outlined dark so it survives
 * rip-rap, grass and water underneath it. It sits in the middle of its petak,
 * which is where the stake stands.
 *
 * It carries `data-hotspot` and `data-stake`: picking a caption up in
 * placement mode moves the whole line, and picking one stake up moves only
 * that stake, which is stored as an offset from where the line would have put
 * it.
 *
 * Its code is a tooltip and an accessible name; what is *printed* under it is
 * the figure an operator watches — the linear displacement in mm, coloured by
 * the status that figure earns. Five codes side by side would be a smear of
 * half-words, but five numbers are what a deformation plot is for.
 *
 * The arrow is the direction of that movement in the picture, drawn only while
 * the reader asks for it (`sphere--vectors`), scaled 10 px per mm.
 */
/**
 * Pixels of arrow per millimetre of movement.
 *
 * A dam creeps in millimetres, and an arrow that turns two of them into forty
 * pixels of pointer tells the reader something the instrument did not: the
 * whole point of printing the scale beside it is that the picture stays honest
 * about how small the figure is. `twinSphere.vectorScale` prints this number
 * on screen, so the two can never drift apart.
 */
export const VECTOR_SCALE = 3.5;

/**
 * One spillway gate's readout: how far its leaf is up, in both the figure the
 * hoist reads and the one an operator opens a gate by.
 *
 * Centimetres lead, because that is what the hoist reports and what a person
 * says when they open a gate; the fraction of the stroke follows, because a
 * length means nothing until you know how far the leaf can go.
 */
export function gateHtml(hotspot, centimetres, percent, color) {
    const figure = centimetres === null ? '—' : `${centimetres} cm`;
    const stroke = percent === null ? '' : `<span class="psv-gate__cm">${percent} %</span>`;

    return `
        <span class="psv-gate" data-hotspot="${hotspot.id}" data-type="gate"
              role="button" tabindex="-1" aria-label="${hotspot.label}, bukaan ${figure}">
            <span class="psv-gate__name">${hotspot.label}</span>
            <span class="psv-gate__read tnum">
                <span class="psv-gate__pct" style="color:${color}">${figure}</span>
                ${stroke}
            </span>
        </span>
    `;
}

export function stakeHtml(hotspot, stake, color, index) {
    const moved = stake?.linear ?? null;

    // A short stem before the head, so a fraction of a millimetre still reads
    // as an arrow — and a cap, so one bad reading cannot draw a line across
    // the whole dam.
    const reach = moved === null ? 0 : Math.min(40, 5 + moved * VECTOR_SCALE);
    const figure = moved === null ? '—' : `${stake.short} mm`;
    const name = moved === null
        ? `Patok ${stake?.code ?? ''}`
        : `Patok ${stake.code}, pergeseran linier ${stake.formatted} milimeter`;

    return `
        <span class="psv-stake" data-hotspot="${hotspot.id}" data-stake="${index}" data-type="stake"
              role="button" tabindex="-1" aria-label="${name}"
              style="--aspect:${stake?.aspect ?? 90}deg; --reach:${reach}px; --shift:${color}">
            <span class="psv-stake__arrow" aria-hidden="true"></span>
            <svg viewBox="0 0 18 18" width="18" height="18" aria-hidden="true">
                <path d="M9 1.2 16.8 9 9 16.8 1.2 9Z"
                      fill="${color}" stroke="rgba(3,11,20,.92)" stroke-width="2.2" stroke-linejoin="round"/>
                <circle cx="9" cy="9" r="2.3" fill="#f6fbff" fill-opacity=".92"/>
            </svg>
            <span class="psv-stake__value tnum" style="color:${color}">${figure}</span>
        </span>
    `;
}

/*
 * The caption of a row of petak, and the handle they are placed by.
 *
 * The petak themselves are drawn in the sphere, one per stake. This chip only
 * names the row, so it sits above them rather than over a stake it would
 * hide, and it carries `data-hotspot` because dragging the caption is what
 * moves the whole row: the petak are derived from its centre, not placed one
 * by one.
 */
export function plotHtml(hotspot, stakes) {
    return `
        <div class="psv-hotspot psv-hotspot--plot" data-type="plot" data-hotspot="${hotspot.id}">
            <span class="psv-hotspot__chip">
                <span class="psv-stake-dot"></span>
                <span class="psv-hotspot__text">
                    <span class="psv-hotspot__label">${hotspot.label}</span>
                    <span class="psv-hotspot__value">${stakes} patok</span>
                </span>
            </span>
        </div>
    `;
}

export function compassSvg() {
    return `
        <svg viewBox="0 0 100 100" xmlns="http://www.w3.org/2000/svg">
            <circle cx="50" cy="50" r="48" fill="rgba(6,20,32,0.55)" stroke="rgba(255,255,255,0.22)" stroke-width="1"/>
            <circle cx="50" cy="50" r="34" fill="none" stroke="rgba(255,255,255,0.12)" stroke-width="1"/>
            <path d="M50 8 L54 24 L50 20 L46 24 Z" fill="#f87171"/>
            <text x="50" y="20" text-anchor="middle" font-size="10" fill="#e4eefb" font-family="sans-serif">N</text>
        </svg>
    `;
}
