import { screen, within } from '@testing-library/react';
import { http, HttpResponse } from 'msw';
import { setupServer } from 'msw/node';
import { renderWithProviders } from '@/test/render';
import { OverviewMetrics } from './OverviewMetrics';

const url = (p: string) => `http://localhost/api/v1${p}`;
const server = setupServer();
beforeAll(() => server.listen({ onUnhandledFrame: 'error' }));
afterEach(() => server.resetHandlers());
afterAll(() => server.close());

describe('FR-PD1 provider overview metrics', () => {
    it('shows MRR in the provider currency and the subscriber counts', async () => {
        server.use(
            http.get(url('/provider/overview'), () =>
                HttpResponse.json({
                    data: {
                        currency: 'usd',
                        mrr: 482000,
                        active_subscribers: 87,
                        new_30d: 12,
                        churned_30d: 3,
                        failed_payments_30d: 4,
                        upcoming_renewals_7d: 21,
                        dunning: { open_invoices: 3, open_amount: 5700, at_risk_mrr: 4500, recovered_30d: 2, lost_30d: 1, recovery_rate: 67 },
                    },
                }),
            ),
        );
        renderWithProviders(<OverviewMetrics />);

        expect(await screen.findByText('$4,820.00')).toBeInTheDocument();
        for (const [label, value] of [
            ['Monthly recurring revenue', '$4,820.00'],
            ['Active subscribers', '87'],
            ['New in 30 days', '12'],
            ['Churned in 30 days', '3'],
            ['Failed payments (30 days)', '4'],
            ['Renewals in 7 days', '21'],
        ])
            expect(screen.getByText(label).parentElement).toHaveTextContent(value);
    });

    it('FR-R12 shows failed payments, what is owed, revenue at risk and the recovery rate', async () => {
        server.use(
            http.get(url('/provider/overview'), () =>
                HttpResponse.json({
                    data: {
                        currency: 'usd',
                        mrr: 0,
                        active_subscribers: 0,
                        new_30d: 0,
                        churned_30d: 0,
                        failed_payments_30d: 0,
                        upcoming_renewals_7d: 0,
                        dunning: { open_invoices: 3, open_amount: 5700, at_risk_mrr: 4500, recovered_30d: 2, lost_30d: 1, recovery_rate: 67 },
                    },
                }),
            ),
        );
        renderWithProviders(<OverviewMetrics />);

        const recovery = await screen.findByRole('region', { name: 'Payment recovery' });
        for (const [label, value] of [
            ['Failed invoices', '3'],
            ['Amount outstanding', '$57.00'],
            ['Monthly revenue at risk', '$45.00'],
            ['Recovery rate (30 days)', '67%'],
        ])
            expect(within(recovery).getByText(label).parentElement).toHaveTextContent(value);
    });

    it('FR-R12 shows a dash for the recovery rate until something was recovered or lost', async () => {
        server.use(
            http.get(url('/provider/overview'), () =>
                HttpResponse.json({
                    data: {
                        currency: 'usd',
                        mrr: 0,
                        active_subscribers: 0,
                        new_30d: 0,
                        churned_30d: 0,
                        failed_payments_30d: 0,
                        upcoming_renewals_7d: 0,
                        dunning: { open_invoices: 0, open_amount: 0, at_risk_mrr: 0, recovered_30d: 0, lost_30d: 0, recovery_rate: null },
                    },
                }),
            ),
        );
        renderWithProviders(<OverviewMetrics />);

        const recovery = await screen.findByRole('region', { name: 'Payment recovery' });
        expect(within(recovery).getByText('Recovery rate (30 days)').parentElement).toHaveTextContent('—');
    });

    it('says so when the numbers cannot be loaded', async () => {
        server.use(http.get(url('/provider/overview'), () => HttpResponse.json({ message: 'Server error.' }, { status: 500 })));
        renderWithProviders(<OverviewMetrics />);

        expect(await screen.findByRole('alert')).toHaveTextContent(/server error/i);
    });
});
