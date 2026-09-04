/**
 * Marker glyphs for client-rendered map pins and panorama hotspots.
 * Paths mirror the ones in resources/views/components/icon.blade.php.
 */
const PATHS = {
    'water-level': '<path d="M4 15.5c2.4-2 4-2 6 0s3.6 2 6 0 3.6-2 4 0"/><path d="M7 11V4.5M12 11V6.5M17 11V3"/>',
    gate: '<path d="M4 5h16v14H4Z"/><path d="M4 10h16M4 15h16M12 5v14"/>',
    droplet: '<path d="M12 3.2s5.4 5.8 5.4 9.4a5.4 5.4 0 0 1-10.8 0C6.6 9 12 3.2 12 3.2Z"/>',
    rain: '<path d="M7 15a4.5 4.5 0 0 1 .8-8.9A5.4 5.4 0 0 1 18 7.4a3.8 3.8 0 0 1-.4 7.6Z"/><path d="M9 18.5l-.8 2M13 18.5l-.8 2M17 18.5l-.8 2"/>',
    deformation: '<path d="M4 18h16"/><path d="M6 18V9l6-4.5 6 4.5v9"/><path d="M15 18v-4.5h-6"/>',
    pressure: '<circle cx="12" cy="13" r="7"/><path d="M12 13l3.4-3.4M12 6V4"/>',
    gauge: '<path d="M4.4 17a8.4 8.4 0 1 1 15.2 0"/><path d="M12 13.4 15.6 10"/><circle cx="12" cy="14" r="1.4"/>',
    seepage: '<path d="M12 3.5s5 5.6 5 9a5 5 0 0 1-10 0c0-3.4 5-9 5-9Z"/><path d="M9.5 13.5h5"/>',
    camera: '<path d="M4 8.5h3l1.4-2h7.2L17 8.5h3v10H4Z"/><circle cx="12" cy="13.5" r="3"/>',
    siren: '<path d="M6 17v-4a6 6 0 0 1 12 0v4Z"/><path d="M4 20h16M12 3.5V2"/>',
    radio: '<circle cx="12" cy="14" r="2.2"/><path d="M8.5 10.5a5 5 0 0 1 7 0M5.5 7.5a9 9 0 0 1 13 0"/>',
    cube: '<path d="M12 2.8 20.5 7v10L12 21.2 3.5 17V7Z"/><path d="M3.5 7 12 11.4 20.5 7M12 11.4v9.8"/>',
    sensor: '<circle cx="12" cy="12" r="2.4"/><path d="M7.8 7.8a6 6 0 0 0 0 8.4M16.2 16.2a6 6 0 0 0 0-8.4"/>',
};

const TYPE_ICON = {
    water_level: 'water-level',
    gate: 'gate',
    water_quality: 'droplet',
    sediment: 'droplet',
    weather: 'rain',
    rainfall: 'rain',
    deformation: 'deformation',
    piezometer: 'pressure',
    observation_well: 'gauge',
    seepage: 'seepage',
    cctv: 'camera',
    ews: 'siren',
    network: 'radio',
    overview: 'cube',
};

export function iconSvg(type, size = 15) {
    const key = TYPE_ICON[type] ?? 'sensor';

    return `<svg viewBox="0 0 24 24" width="${size}" height="${size}" fill="none" stroke="currentColor"
                 stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round">${PATHS[key]}</svg>`;
}
