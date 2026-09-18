import type { ReactNode } from 'react';
import { Button } from '@/components/ui/button';
import { Skeleton } from '@/components/ui/skeleton';
import { cn } from '@/lib/utils';

export type Column<T> = {
    key: string;
    header: ReactNode;
    cell: (row: T) => ReactNode;
    className?: string;
    headClassName?: string;
};

type DataTableProps<T> = {
    columns: Column<T>[];
    rows: T[];
    rowKey: (row: T) => string | number;
    loading?: boolean;
    emptyMessage?: string;
    onRowClick?: (row: T) => void;
    className?: string;
    footer?: ReactNode;
};

/**
 * Tabel generik ringan (tanpa dependensi tabel eksternal) untuk semua halaman
 * master data / worklist. Termasuk state loading & kosong.
 */
export function DataTable<T>({
    columns,
    rows,
    rowKey,
    loading = false,
    emptyMessage = 'Belum ada data.',
    onRowClick,
    className,
    footer,
}: DataTableProps<T>) {
    return (
        <div className={cn('overflow-hidden rounded-lg border', className)}>
            <div className="overflow-x-auto">
                <table className="w-full text-sm">
                    <thead className="bg-muted/40">
                        <tr>
                            {columns.map((column) => (
                                <th
                                    key={column.key}
                                    className={cn(
                                        'px-3 py-2 text-left font-medium whitespace-nowrap',
                                        column.headClassName,
                                    )}
                                >
                                    {column.header}
                                </th>
                            ))}
                        </tr>
                    </thead>
                    <tbody>
                        {loading ? (
                            Array.from({ length: 4 }).map((_, rowIndex) => (
                                <tr
                                    key={`skeleton-${rowIndex}`}
                                    className="border-t"
                                >
                                    {columns.map((column) => (
                                        <td
                                            key={column.key}
                                            className="px-3 py-3"
                                        >
                                            <Skeleton className="h-4 w-full" />
                                        </td>
                                    ))}
                                </tr>
                            ))
                        ) : rows.length === 0 ? (
                            <tr className="border-t">
                                <td
                                    colSpan={columns.length}
                                    className="text-muted-foreground px-3 py-8 text-center"
                                >
                                    {emptyMessage}
                                </td>
                            </tr>
                        ) : (
                            rows.map((row) => (
                                <tr
                                    key={rowKey(row)}
                                    className={cn(
                                        'border-t',
                                        onRowClick &&
                                            'hover:bg-muted/40 cursor-pointer',
                                    )}
                                    onClick={
                                        onRowClick
                                            ? () => onRowClick(row)
                                            : undefined
                                    }
                                >
                                    {columns.map((column) => (
                                        <td
                                            key={column.key}
                                            className={cn(
                                                'px-3 py-2 align-middle',
                                                column.className,
                                            )}
                                        >
                                            {column.cell(row)}
                                        </td>
                                    ))}
                                </tr>
                            ))
                        )}
                    </tbody>
                </table>
            </div>
            {footer}
        </div>
    );
}

type PaginationProps = {
    page: number;
    lastPage: number;
    total: number;
    from: number | null;
    to: number | null;
    onPage: (page: number) => void;
};

export function TablePagination({
    page,
    lastPage,
    total,
    from,
    to,
    onPage,
}: PaginationProps) {
    return (
        <div className="text-muted-foreground flex items-center justify-between border-t px-3 py-2 text-xs">
            <span>
                {total === 0
                    ? 'Tidak ada data'
                    : `Menampilkan ${from ?? 0}–${to ?? 0} dari ${total}`}
            </span>
            <div className="flex items-center gap-2">
                <Button
                    variant="outline"
                    size="sm"
                    disabled={page <= 1}
                    onClick={() => onPage(page - 1)}
                >
                    Sebelumnya
                </Button>
                <span>
                    Hal. {page} / {Math.max(lastPage, 1)}
                </span>
                <Button
                    variant="outline"
                    size="sm"
                    disabled={page >= lastPage}
                    onClick={() => onPage(page + 1)}
                >
                    Berikutnya
                </Button>
            </div>
        </div>
    );
}
