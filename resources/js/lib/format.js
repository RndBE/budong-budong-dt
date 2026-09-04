const MONTHS = ['Jan', 'Feb', 'Mar', 'Apr', 'Mei', 'Jun', 'Jul', 'Agu', 'Sept', 'Okt', 'Nov', 'Des'];
const DAYS = ['Minggu', 'Senin', 'Selasa', 'Rabu', 'Kamis', 'Jumat', 'Sabtu'];

/** Wall-clock time at the site, independent of the browser's own timezone. */
export function siteNow(offsetMinutes, skewMs = 0) {
    const nowUtc = Date.now() + skewMs;

    return new Date(nowUtc + offsetMinutes * 60_000);
}

export function formatClock(date) {
    const pad = (value) => String(value).padStart(2, '0');

    return `${pad(date.getUTCHours())}:${pad(date.getUTCMinutes())}:${pad(date.getUTCSeconds())}`;
}

export function formatSiteDate(date) {
    return `${DAYS[date.getUTCDay()]}, ${String(date.getUTCDate()).padStart(2, '0')} ${MONTHS[date.getUTCMonth()]} ${date.getUTCFullYear()}`;
}

export function formatNumber(value, decimals = 2) {
    if (value === null || value === undefined || Number.isNaN(value)) {
        return '—';
    }

    return Number(value).toLocaleString('id-ID', {
        minimumFractionDigits: decimals,
        maximumFractionDigits: decimals,
    });
}

export function relativeTime(iso) {
    if (!iso) {
        return '—';
    }

    const diff = (Date.now() - new Date(iso).getTime()) / 1000;

    if (diff < 90) return 'baru saja';
    if (diff < 3600) return `${Math.round(diff / 60)} menit lalu`;
    if (diff < 86_400) return `${Math.round(diff / 3600)} jam lalu`;

    return `${Math.round(diff / 86_400)} hari lalu`;
}

export const STATUS_COLORS = {
    normal: '#34d399',
    waspada: '#fbbf24',
    siaga: '#fb923c',
    bahaya: '#f87171',
    offline: '#94a3b8',
};

export function statusColor(status) {
    return STATUS_COLORS[status] ?? STATUS_COLORS.normal;
}
