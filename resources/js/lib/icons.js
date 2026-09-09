/**
 * Marker glyphs for client-rendered map pins and panorama hotspots.
 * Paths mirror the ones in resources/views/components/icon.blade.php.
 */
const PATHS = {
    'water-level': '<path d="M4 15.5c2.4-2 4-2 6 0s3.6 2 6 0 3.6-2 4 0"/><path d="M7 11V4.5M12 11V6.5M17 11V3"/>',
    gate: '<path d="M2.6 5.8h18.8v2.8H2.6Z"/><path d="M5.6 8.6v10.8M12 8.6v10.8M18.4 8.6v10.8"/><path d="M6.7 11.8h4.2v5.6H6.7ZM13.1 11.8h4.2v5.6h-4.2Z"/>',
    droplet: '<path d="M12 3.2s5.4 5.8 5.4 9.4a5.4 5.4 0 0 1-10.8 0C6.6 9 12 3.2 12 3.2Z"/>',
    rain: '<path d="M7 15a4.5 4.5 0 0 1 .8-8.9A5.4 5.4 0 0 1 18 7.4a3.8 3.8 0 0 1-.4 7.6Z"/><path d="M9 18.5l-.8 2M13 18.5l-.8 2M17 18.5l-.8 2"/>',
    deformation: '<path d="M4 18h16"/><path d="M6 18V9l6-4.5 6 4.5v9"/><path d="M15 18v-4.5h-6"/>',
    'total-station': '<path d="M6.4 6.2c1-2.1 2.9-3.2 5.6-3.2s4.6 1.1 5.6 3.2"/><path d="M4.6 6.2h14.8v11.4H4.6Z"/><path d="M4.6 13.4h14.8"/><circle cx="12" cy="9.8" r="2.5"/><path d="M7.6 17.6v2.6h8.8v-2.6"/>',
    antenna: '<path d="M6.6 8.8h10.8l-1.7 2.8H8.3Z"/><path d="M12 11.6v6.2"/><path d="M9.4 17.8h5.2v3.2H9.4Z"/><path d="M8.7 6a4.7 4.7 0 0 1 6.6 0M6.4 3.6a8 8 0 0 1 11.2 0"/>',
    tilt: '<path d="M3.5 19.5 20 8"/><path d="M3.5 19.5h5.6M3.5 19.5v-5.6"/><circle cx="16.5" cy="10.5" r="1.4"/>',
    pressure: '<circle cx="12" cy="13" r="7"/><path d="M12 13l3.4-3.4M12 6V4"/>',
    gauge: '<path d="M4.4 17a8.4 8.4 0 1 1 15.2 0"/><path d="M12 13.4 15.6 10"/><circle cx="12" cy="14" r="1.4"/>',
    standpipe: '<path d="M9.2 3.5h5.6v15H9.2Z"/><path d="M9.2 11.5c1.9-1.2 3.7-1.2 5.6 0"/><path d="M3.5 18.5h17"/>',
    seepage: '<path d="M12 3.5s5 5.6 5 9a5 5 0 0 1-10 0c0-3.4 5-9 5-9Z"/><path d="M9.5 13.5h5"/>',
    weir: '<path d="M3.5 7.5h5.5L12 14l3-6.5h5.5"/><path d="M3.5 7.5v12M20.5 7.5v12M3.5 19.5h17"/>',
    flask: '<path d="M10 3h4"/><path d="M10.5 3v6.2L5.9 17a2 2 0 0 0 1.7 3h8.8a2 2 0 0 0 1.7-3l-4.6-7.8V3"/><path d="M8.2 15.6h7.6"/>',
    sediment: '<path d="M3.5 6.5c2.8-2 5.7-2 8.5 0s5.7 2 8.5 0"/><path d="M3.5 19.5c3.4-4.8 6.2-7.2 8.5-7.2s5.1 2.4 8.5 7.2Z"/><circle cx="9" cy="10" r=".9"/><circle cx="15" cy="11.4" r=".9"/>',
    camera: '<path d="M4 8.5h3l1.4-2h7.2L17 8.5h3v10H4Z"/><circle cx="12" cy="13.5" r="3"/>',
    siren: '<path d="M6 17v-4a6 6 0 0 1 12 0v4Z"/><path d="M4 20h16M12 3.5V2"/>',
    radio: '<circle cx="12" cy="14" r="2.2"/><path d="M8.5 10.5a5 5 0 0 1 7 0M5.5 7.5a9 9 0 0 1 13 0"/>',
    cube: '<path d="M12 2.8 20.5 7v10L12 21.2 3.5 17V7Z"/><path d="M3.5 7 12 11.4 20.5 7M12 11.4v9.8"/>',
    sensor: '<circle cx="12" cy="12" r="2.4"/><path d="M7.8 7.8a6 6 0 0 0 0 8.4M16.2 16.2a6 6 0 0 0 0-8.4"/>',
    section: '<path d="M2.5 19.5h19"/><path d="M5 19.5 9.8 6.5h4.4L19 19.5"/><path d="M3.5 12.8h5.6M15 12.8h5.5"/><circle cx="10.9" cy="15.6" r=".9"/><circle cx="13.4" cy="11.4" r=".9"/>',
};

/*
 * One glyph per instrument family, not per measurement: a reader on the stage
 * picks a pin by what stands at it. Two stations of the same family (three
 * AWLR, two ADR) share a glyph — that is the point — but two families that
 * happen to measure the same thing must not. AWQR and the sediment sampler
 * were both a droplet, which made the two indistinguishable at 13 px.
 */
const TYPE_ICON = {
    water_level: 'water-level',
    gate: 'gate',
    water_quality: 'flask',
    sediment: 'sediment',
    weather: 'rain',
    rainfall: 'rain',
    deformation: 'total-station',
    gnss: 'antenna',
    piezometer: 'pressure',
    observation_well: 'standpipe',
    seepage: 'weir',
    cctv: 'camera',
    ews: 'siren',
    network: 'radio',
    overview: 'cube',
};

/*
 * A 24-unit glyph drawn at 15 px loses about a third of its contrast to the
 * downscale, so the small sizes are given a heavier stroke. Without it the
 * weir and the sediment mound read as the same grey smudge on a pin.
 */
/**
 * One glyph by *name*, not by station type.
 *
 * `iconSvg` answers for a station's instrument family; this is for the few
 * markers that stand for something else entirely and simply need a picture.
 */
export function glyphSvg(name, size = 16) {
    const stroke = size <= 18 ? 1.95 : 1.7;

    return `<svg viewBox="0 0 24 24" width="${size}" height="${size}" fill="none" stroke="currentColor"
                 stroke-width="${stroke}" stroke-linecap="round" stroke-linejoin="round">${PATHS[name] ?? PATHS.sensor}</svg>`;
}

export function iconSvg(type, size = 16) {
    const key = TYPE_ICON[type] ?? 'sensor';
    const stroke = size <= 18 ? 1.95 : 1.7;

    return `<svg viewBox="0 0 24 24" width="${size}" height="${size}" fill="none" stroke="currentColor"
                 stroke-width="${stroke}" stroke-linecap="round" stroke-linejoin="round">${PATHS[key]}</svg>`;
}
