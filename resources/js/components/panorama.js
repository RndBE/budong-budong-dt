/*
 * Photo Sphere Viewer pulls in three.js, so it is only fetched once a
 * panorama is actually shown. Its stylesheets ship with app.css.
 *
 * The stage owns the viewer itself (`twin-sphere.js`); this module keeps the
 * loader and the markup helpers both the stage and its hotspots share.
 */
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

export function hotspotHtml(hotspot, value, color) {
    const icon = hotspot.type === 'link'
        ? '<svg viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="currentColor" stroke-width="2"><path d="M5 12h14M13 6l6 6-6 6"/></svg>'
        : '<span class="psv-dot" style="background:' + color + '"></span>';

    return `
        <div class="psv-hotspot" data-type="${hotspot.type}">
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
