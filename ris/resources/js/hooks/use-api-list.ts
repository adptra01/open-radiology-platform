import { useCallback, useEffect, useState } from 'react';
import { ApiError, apiGet } from '@/lib/api';
import type { Paginated } from '@/types/orp';

export type ListParams = Record<
    string,
    string | number | boolean | undefined | null
>;

export type UseApiListResult<T> = {
    items: T[];
    meta: Omit<Paginated<T>, 'data'> | null;
    loading: boolean;
    error: string | null;
    /** Kata kunci pencarian (di-debounce 300 ms). */
    search: string;
    setSearch: (value: string) => void;
    params: ListParams;
    /** Gabungkan filter tambahan; otomatis reset ke halaman 1. */
    setFilter: (patch: ListParams) => void;
    setPage: (page: number) => void;
    reload: () => void;
};

/**
 * Hook daftar data dari endpoint paginasi Laravel (`paginate()`).
 *
 * Tidak memakai react-query (tidak ada di dependensi starter kit) — cukup
 * useState/useEffect dengan debounce pencarian dan guard race-condition.
 */
export function useApiList<T>(
    baseUrl: string,
    initialParams: ListParams = {},
): UseApiListResult<T> {
    const [items, setItems] = useState<T[]>([]);
    const [meta, setMeta] = useState<Omit<Paginated<T>, 'data'> | null>(null);
    const [params, setParams] = useState<ListParams>(initialParams);
    const [search, setSearchRaw] = useState('');
    const [loading, setLoading] = useState(true);
    const [error, setError] = useState<string | null>(null);
    const [tick, setTick] = useState(0);

    const setSearch = useCallback((value: string) => {
        setSearchRaw(value);
    }, []);

    const setFilter = useCallback((patch: ListParams) => {
        setParams((prev) => ({ ...prev, ...patch, page: 1 }));
    }, []);

    const setPage = useCallback((page: number) => {
        setParams((prev) => ({ ...prev, page }));
    }, []);

    const reload = useCallback(() => setTick((t) => t + 1), []);

    // Debounce: pemetaan kata kunci → params.
    useEffect(() => {
        const timer = setTimeout(
            () =>
                setParams((prev) => ({
                    ...prev,
                    page: 1,
                    search: search === '' ? undefined : search,
                })),
            search === '' ? 0 : 300,
        );

        return () => clearTimeout(timer);
    }, [search]);

    useEffect(() => {
        // Guard anti-race: respons lama diabaikan bila params berubah lagi.
        let cancelled = false;

        setLoading(true);

        const query = new URLSearchParams();

        for (const [key, value] of Object.entries(params)) {
            if (value === undefined || value === null || value === '') {
                continue;
            }

            query.set(key, String(value));
        }

        const url = query.size > 0 ? `${baseUrl}?${query}` : baseUrl;

        apiGet<Paginated<T>>(url)
            .then((res) => {
                if (cancelled) {
                    return;
                }

                setItems(res.data ?? []);
                setMeta({
                    current_page: res.current_page,
                    last_page: res.last_page,
                    per_page: res.per_page,
                    total: res.total,
                    from: res.from,
                    to: res.to,
                });
                setError(null);
            })
            .catch((err: unknown) => {
                if (cancelled) {
                    return;
                }

                setError(
                    err instanceof ApiError ? err.message : 'Gagal memuat data',
                );
            })
            .finally(() => {
                if (!cancelled) {
                    setLoading(false);
                }
            });

        return () => {
            cancelled = true;
        };
    }, [baseUrl, params, tick]);

    return {
        items,
        meta,
        loading,
        error,
        search,
        setSearch,
        params,
        setFilter,
        setPage,
        reload,
    };
}
