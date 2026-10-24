import { ArrowDown, ArrowUp, ChevronLeft, ChevronRight, ChevronsUpDown } from 'lucide-react';
import type { ReactNode } from 'react';
import { Alert } from '@/components/ui/Alert';
import { Button } from '@/components/ui/button';
import { Skeleton } from '@/components/ui/skeleton';
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table';
import type { PageMeta } from '@/lib/api';

export interface Column<T> {
    id: string;
    header: string;
    /** Backend sort key; omit for columns that cannot be sorted. */
    sortKey?: string;
    cell: (row: T) => ReactNode;
    className?: string;
}

interface Props<T> {
    columns: Column<T>[];
    rows?: T[];
    meta?: PageMeta;
    rowKey: (row: T) => string;
    isLoading?: boolean;
    error?: string;
    onRetry?: () => void;
    sort?: string;
    direction?: 'asc' | 'desc';
    onSortChange?: (sort: string, direction: 'asc' | 'desc') => void;
    onPageChange?: (page: number) => void;
    emptyTitle?: string;
    emptyHint?: string;
}

/** The one table every admin listing uses: server-driven paging and sorting, with loading, empty and error states. */
export function DataTable<T>({
    columns,
    rows,
    meta,
    rowKey,
    isLoading,
    error,
    onRetry,
    sort,
    direction,
    onSortChange,
    onPageChange,
    emptyTitle = 'Nothing here yet',
    emptyHint,
}: Props<T>) {
    if (error && !rows) {
        return (
            <div className="flex flex-col items-start gap-3 rounded-lg border bg-card p-6">
                <Alert>{error}</Alert>
                {onRetry && (
                    <Button variant="outline" onClick={onRetry}>
                        Try again
                    </Button>
                )}
            </div>
        );
    }

    const empty = !isLoading && rows?.length === 0;
    const from = meta && rows?.length ? (meta.current_page - 1) * meta.per_page + 1 : 0;
    const to = meta && rows?.length ? from + rows.length - 1 : 0;

    return (
        <div className="overflow-hidden rounded-lg border bg-card">
            <Table aria-busy={isLoading || undefined}>
                <TableHeader>
                    <TableRow className="bg-muted/50 hover:bg-muted/50">
                        {columns.map((column) => (
                            <TableHead
                                key={column.id}
                                className={column.className}
                                aria-sort={
                                    column.sortKey ? (sort === column.sortKey ? (direction === 'desc' ? 'descending' : 'ascending') : 'none') : undefined
                                }
                            >
                                {column.sortKey ? (
                                    <button
                                        type="button"
                                        className="-ml-1 inline-flex items-center gap-1 rounded px-1 py-0.5 hover:bg-muted"
                                        onClick={() => onSortChange?.(column.sortKey!, sort === column.sortKey && direction === 'asc' ? 'desc' : 'asc')}
                                    >
                                        {column.header}
                                        {sort === column.sortKey ? (
                                            direction === 'desc' ? (
                                                <ArrowDown aria-hidden className="size-3.5" />
                                            ) : (
                                                <ArrowUp aria-hidden className="size-3.5" />
                                            )
                                        ) : (
                                            <ChevronsUpDown aria-hidden className="size-3.5 text-muted-foreground" />
                                        )}
                                    </button>
                                ) : (
                                    column.header
                                )}
                            </TableHead>
                        ))}
                    </TableRow>
                </TableHeader>
                <TableBody>
                    {isLoading &&
                        Array.from({ length: 5 }, (_, i) => (
                            <TableRow key={`loading-${i}`}>
                                {columns.map((column) => (
                                    <TableCell key={column.id}>
                                        <Skeleton className="h-4 w-3/4" />
                                    </TableCell>
                                ))}
                            </TableRow>
                        ))}
                    {!isLoading &&
                        rows?.map((row) => (
                            <TableRow key={rowKey(row)}>
                                {columns.map((column) => (
                                    <TableCell key={column.id} className={column.className}>
                                        {column.cell(row)}
                                    </TableCell>
                                ))}
                            </TableRow>
                        ))}
                </TableBody>
            </Table>

            {empty && (
                <div className="px-6 py-12 text-center">
                    <p className="font-medium">{emptyTitle}</p>
                    {emptyHint && <p className="mt-1 text-sm text-muted-foreground">{emptyHint}</p>}
                </div>
            )}

            {meta && !empty && !isLoading && (
                <div className="flex items-center justify-between border-t px-4 py-3 text-sm">
                    <p className="text-muted-foreground">
                        Showing {from}–{to} of {meta.total}
                    </p>
                    <div className="flex gap-2">
                        <Button
                            variant="outline"
                            size="icon"
                            aria-label="Previous page"
                            disabled={meta.current_page <= 1}
                            onClick={() => onPageChange?.(meta.current_page - 1)}
                        >
                            <ChevronLeft aria-hidden />
                        </Button>
                        <Button
                            variant="outline"
                            size="icon"
                            aria-label="Next page"
                            disabled={meta.current_page >= meta.last_page}
                            onClick={() => onPageChange?.(meta.current_page + 1)}
                        >
                            <ChevronRight aria-hidden />
                        </Button>
                    </div>
                </div>
            )}
        </div>
    );
}
