const csrfToken = () => document.querySelector('meta[name="csrf-token"]')?.content ?? '';

async function handle(response) {
    if (!response.ok) {
        const message = await response.text().catch(() => response.statusText);
        throw new Error(`${response.status} ${message.slice(0, 200)}`);
    }

    return response.json();
}

export function getJson(url, params = {}) {
    const target = new URL(url, window.location.origin);
    Object.entries(params).forEach(([key, value]) => {
        if (value !== undefined && value !== null && value !== '') {
            target.searchParams.set(key, value);
        }
    });

    return fetch(target, {
        headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
        credentials: 'same-origin',
    }).then(handle);
}

export function postJson(url, body = {}) {
    return fetch(url, {
        method: 'POST',
        headers: {
            Accept: 'application/json',
            'Content-Type': 'application/json',
            'X-CSRF-TOKEN': csrfToken(),
            'X-Requested-With': 'XMLHttpRequest',
        },
        credentials: 'same-origin',
        body: JSON.stringify(body),
    }).then(handle);
}

/** Poll `task` on an interval, pausing while the tab is hidden. */
export function poll(task, interval) {
    let timer = null;

    const tick = async () => {
        if (!document.hidden) {
            try {
                await task();
            } catch (error) {
                console.warn('[poll]', error.message);
            }
        }

        timer = window.setTimeout(tick, interval);
    };

    timer = window.setTimeout(tick, interval);

    return () => window.clearTimeout(timer);
}
