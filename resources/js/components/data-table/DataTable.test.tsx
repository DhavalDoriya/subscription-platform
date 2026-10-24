import { render, screen, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { DataTable, type Column } from './DataTable';

interface Row {
    id: string;
    name: string;
}

const columns: Column<Row>[] = [
    { id: 'name', header: 'Name', sortKey: 'name', cell: (r) => r.name },
    { id: 'id', header: 'Identifier', cell: (r) => r.id },
];
const meta = { current_page: 1, per_page: 2, total: 5, last_page: 3 };

function setup(props: Partial<React.ComponentProps<typeof DataTable<Row>>> = {}) {
    const handlers = { onPageChange: vi.fn(), onSortChange: vi.fn() };
    render(
        <DataTable
            columns={columns}
            rows={[
                { id: 'a1', name: 'Alpha' },
                { id: 'b2', name: 'Beta' },
            ]}
            meta={meta}
            rowKey={(r) => r.id}
            sort="name"
            direction="asc"
            {...handlers}
            {...props}
        />,
    );
    return handlers;
}

describe('DataTable', () => {
    it('renders a header and one row per item', () => {
        setup();
        expect(screen.getAllByRole('columnheader').map((h) => h.textContent)).toEqual(['Name', 'Identifier']);
        expect(screen.getAllByRole('row')).toHaveLength(3); // header + 2
        expect(screen.getByRole('cell', { name: 'Alpha' })).toBeInTheDocument();
    });

    it('shows the range and total', () => {
        setup();
        expect(screen.getByText('Showing 1–2 of 5')).toBeInTheDocument();
    });

    it('pages forward and back, disabling the ends', async () => {
        const { onPageChange } = setup();
        expect(screen.getByRole('button', { name: 'Previous page' })).toBeDisabled();
        await userEvent.click(screen.getByRole('button', { name: 'Next page' }));
        expect(onPageChange).toHaveBeenCalledWith(2);
    });

    it('disables next on the last page', () => {
        setup({ meta: { ...meta, current_page: 3 } });
        expect(screen.getByRole('button', { name: 'Next page' })).toBeDisabled();
    });

    it('toggles sort direction on the active column and starts ascending on another', async () => {
        const { onSortChange } = setup();
        const header = screen.getByRole('columnheader', { name: 'Name' });
        expect(header).toHaveAttribute('aria-sort', 'ascending');
        await userEvent.click(within(header).getByRole('button'));
        expect(onSortChange).toHaveBeenCalledWith('name', 'desc');
    });

    it('does not make unsortable columns clickable', () => {
        setup();
        expect(within(screen.getByRole('columnheader', { name: 'Identifier' })).queryByRole('button')).not.toBeInTheDocument();
    });

    it('shows an empty state', () => {
        setup({ rows: [], meta: { ...meta, total: 0, last_page: 1 }, emptyTitle: 'No providers yet' });
        expect(screen.getByText('No providers yet')).toBeInTheDocument();
        expect(screen.queryByRole('button', { name: 'Next page' })).not.toBeInTheDocument();
    });

    it('shows loading placeholders instead of rows', () => {
        setup({ rows: undefined, isLoading: true });
        expect(screen.getByRole('table')).toHaveAttribute('aria-busy', 'true');
        expect(screen.queryByRole('cell', { name: 'Alpha' })).not.toBeInTheDocument();
    });

    it('shows an error with a retry', async () => {
        const onRetry = vi.fn();
        setup({ rows: undefined, error: 'Server error.', onRetry });
        expect(screen.getByRole('alert')).toHaveTextContent('Server error.');
        await userEvent.click(screen.getByRole('button', { name: 'Try again' }));
        expect(onRetry).toHaveBeenCalled();
    });
});
