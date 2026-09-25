/**
 * Shared helpers for the POS screens: JSON requests, silent printing and the
 * WebSocket (Reverb) connection state.
 */

const csrf = () => document.querySelector('meta[name="csrf-token"]')?.content ?? '';

export class ApiError extends Error {
    constructor(message, status, errors = {}, data = {}) {
        super(message);
        this.status = status;
        this.errors = errors;
        this.data = data;
    }

    /** First validation message, or the general message. */
    get first() {
        const messages = Object.values(this.errors ?? {}).flat();
        return messages[0] ?? this.message;
    }
}

export async function api(url, { method = 'GET', body = null, signal = null } = {}) {
    const response = await fetch(url, {
        method,
        signal,
        credentials: 'same-origin',
        headers: {
            Accept: 'application/json',
            'Content-Type': 'application/json',
            'X-CSRF-TOKEN': csrf(),
            'X-Requested-With': 'XMLHttpRequest',
        },
        body: body !== null ? JSON.stringify(body) : null,
    });

    let data = {};
    try {
        data = await response.json();
    } catch {
        data = {};
    }

    if (!response.ok) {
        if (response.status === 419) {
            throw new ApiError('Your session expired. Reload the page and sign in again.', 419);
        }
        throw new ApiError(data.message || `Request failed (${response.status}).`, response.status, data.errors, data);
    }

    return data;
}

export function uuid() {
    if (window.crypto?.randomUUID) {
        return window.crypto.randomUUID();
    }
    // Fallback for older browsers (not a security token).
    return 'xxxxxxxx-xxxx-4xxx-yxxx-xxxxxxxxxxxx'.replace(/[xy]/g, (c) => {
        const r = (Math.random() * 16) | 0;
        return (c === 'x' ? r : (r & 0x3) | 0x8).toString(16);
    });
}

export function randomKey() {
    return uuid().replace(/-/g, '') + Date.now().toString(36);
}

/**
 * Print a page silently on this PC's printer: a hidden iframe loads it and the page calls
 * print() itself (Chrome --kiosk-printing sends it to the default printer). Resolves true
 * when the page reports back, false after `timeout` ms ("Check printer").
 */
export function printPage(url, { timeout = 10000 } = {}) {
    return new Promise((resolve) => {
        const frame = document.createElement('iframe');
        frame.setAttribute('aria-hidden', 'true');
        frame.style.cssText = 'position:fixed;right:0;bottom:0;width:1px;height:1px;border:0;opacity:0;pointer-events:none;';

        let done = false;
        const finish = (ok) => {
            if (done) return;
            done = true;
            window.removeEventListener('message', onMessage);
            clearTimeout(timer);
            // Leave the frame a moment so the print spooler has the page.
            setTimeout(() => frame.remove(), 60000);
            resolve(ok);
        };

        const onMessage = (event) => {
            if (event.origin !== window.location.origin || event.source !== frame.contentWindow) return;
            if (event.data?.type !== 'citizens:printed') return;
            finish(true);
        };

        const timer = setTimeout(() => finish(false), timeout);
        window.addEventListener('message', onMessage);
        frame.src = url;
        document.body.appendChild(frame);
    });
}

/**
 * Global message listener (app.js): a print page in an iframe says it was sent to the printer.
 */
export function confirmPrinted(event) {
    if (event.origin !== window.location.origin || event.data?.type !== 'citizens:printed' || !event.data.job) return;
    api(`/api/pos/print-jobs/${event.data.job}/printed`, { method: 'POST' }).catch(() => {});
}

/**
 * Reverb connection helpers. `window.Echo` is created in resources/js/echo.js; when the
 * server is down the screens poll instead.
 */
export function echoConnection() {
    return window.Echo?.connector?.pusher?.connection ?? null;
}

export function isLive() {
    return echoConnection()?.state === 'connected';
}

export function onLiveChange(callback) {
    const connection = echoConnection();
    if (!connection) return;
    connection.bind('state_change', ({ current }) => callback(current === 'connected'));
}

export function money(value) {
    const number = Number(value ?? 0);
    return number.toLocaleString('en-LK', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
}

export function qty(value) {
    const number = Number(value ?? 0);
    return String(Number(number.toFixed(3)));
}

/** Short beep for "a counter printed an invoice" (optional setting). */
export function beep() {
    try {
        const context = new (window.AudioContext || window.webkitAudioContext)();
        const oscillator = context.createOscillator();
        const gain = context.createGain();
        oscillator.frequency.value = 880;
        gain.gain.setValueAtTime(0.15, context.currentTime);
        gain.gain.exponentialRampToValueAtTime(0.0001, context.currentTime + 0.35);
        oscillator.connect(gain).connect(context.destination);
        oscillator.start();
        oscillator.stop(context.currentTime + 0.35);
    } catch {
        // Sound is optional.
    }
}
