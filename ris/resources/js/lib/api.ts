/**
 * Helper HTTP untuk endpoint `/api/*` yang dilindungi **sesi web** (lihat
 * routes/api.php → grup `$spa = ['web', 'auth']`).
 *
 * Kenapa perlu: endpoint tersebut memakai sesi + CSRF, sedangkan `fetch()`
 * bawaan browser tidak menyertakan token CSRF. Helper ini membaca cookie
 * `XSRF-TOKEN` (di-set Laravel) dan mengirimnya sebagai header `X-XSRF-TOKEN`
 * — pola yang sama dengan axios pada umumnya.
 */

export class ApiError extends Error {
    status: number;

    constructor(status: number, message: string) {
        super(message);
        this.name = 'ApiError';
        this.status = status;
    }
}

function csrfToken(): string | null {
    const match = document.cookie.match(/(?:^|;\s*)XSRF-TOKEN=([^;]+)/);

    return match ? decodeURIComponent(match[1]) : null;
}

async function request<T>(
    method: string,
    url: string,
    body?: unknown,
): Promise<T> {
    const headers: Record<string, string> = {
        Accept: 'application/json',
        'X-Requested-With': 'XMLHttpRequest',
    };

    const token = csrfToken();
    if (token) {
        headers['X-XSRF-TOKEN'] = token;
    }

    if (body !== undefined) {
        headers['Content-Type'] = 'application/json';
    }

    const res = await fetch(url, {
        method,
        headers,
        // Kirim cookie sesi (same-origin) — wajib untuk guard `web`.
        credentials: 'same-origin',
        body: body === undefined ? undefined : JSON.stringify(body),
    });

    if (res.status === 401) {
        // Sesi habis → kembali ke login.
        window.location.href = '/login';

        throw new ApiError(401, 'Sesi berakhir, silakan login ulang.');
    }

    if (!res.ok) {
        const payload = (await res.json().catch(() => null)) as {
            message?: string;
            errors?: Record<string, string[]>;
        } | null;

        let message = payload?.message ?? null;

        if (!message && payload?.errors) {
            const first = Object.values(payload.errors)[0];
            message = Array.isArray(first) ? first[0] : String(first);
        }

        throw new ApiError(
            res.status,
            message ?? `Permintaan gagal (HTTP ${res.status})`,
        );
    }

    if (res.status === 204) {
        return undefined as T;
    }

    return (await res.json()) as T;
}

export function apiGet<T>(url: string): Promise<T> {
    return request<T>('GET', url);
}

export function apiPost<T>(url: string, body?: unknown): Promise<T> {
    return request<T>('POST', url, body ?? {});
}

export function apiPut<T>(url: string, body?: unknown): Promise<T> {
    return request<T>('PUT', url, body ?? {});
}

export function apiDelete<T>(url: string): Promise<T> {
    return request<T>('DELETE', url);
}
