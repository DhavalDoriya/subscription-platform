import { render, screen, waitFor } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { PaymentForm } from './PaymentForm';

const stripe = vi.hoisted(() => ({ confirmPayment: vi.fn(), confirmSetup: vi.fn() }));

vi.mock('@/lib/stripe', () => ({ stripeFor: vi.fn(() => Promise.resolve({})) }));
vi.mock('@stripe/react-stripe-js', () => ({
    Elements: ({ children, options }: { children: React.ReactNode; options: { clientSecret: string } }) => (
        <div data-secret={options.clientSecret}>{children}</div>
    ),
    PaymentElement: () => <div>card-fields</div>,
    useStripe: () => stripe,
    useElements: () => ({ fake: 'elements' }),
}));

const props = {
    publishableKey: 'pk_test_1',
    stripeAccount: 'acct_1',
    clientSecret: 'pi_1_secret',
    returnUrl: 'https://acme.platform.test/?paid=1',
    submitLabel: 'Pay $20.00',
};

beforeEach(() => {
    stripe.confirmPayment.mockReset();
    stripe.confirmSetup.mockReset();
});

describe('FR-C1 payment form', () => {
    it('confirms a payment without leaving the page unless the bank asks for it, then reports success', async () => {
        stripe.confirmPayment.mockResolvedValue({ paymentIntent: { status: 'succeeded' } });
        const onSuccess = vi.fn();
        render(<PaymentForm {...props} type="payment" onSuccess={onSuccess} />);

        expect(screen.getByText('card-fields')).toBeInTheDocument();
        await userEvent.click(screen.getByRole('button', { name: 'Pay $20.00' }));

        await waitFor(() => expect(onSuccess).toHaveBeenCalled());
        expect(stripe.confirmPayment).toHaveBeenCalledWith({
            elements: { fake: 'elements' },
            confirmParams: { return_url: props.returnUrl },
            redirect: 'if_required',
        });
    });

    it('confirms a setup intent for a trial or a new card', async () => {
        stripe.confirmSetup.mockResolvedValue({ setupIntent: { status: 'succeeded' } });
        const onSuccess = vi.fn();
        render(<PaymentForm {...props} type="setup" clientSecret="seti_1_secret" onSuccess={onSuccess} />);

        await userEvent.click(screen.getByRole('button', { name: 'Pay $20.00' }));

        await waitFor(() => expect(onSuccess).toHaveBeenCalled());
        expect(stripe.confirmSetup).toHaveBeenCalledWith(expect.objectContaining({ redirect: 'if_required' }));
        expect(stripe.confirmPayment).not.toHaveBeenCalled();
    });

    it("shows the bank's message when the card is declined, and does not report success", async () => {
        stripe.confirmPayment.mockResolvedValue({ error: { message: 'Your card was declined.' } });
        const onSuccess = vi.fn();
        render(<PaymentForm {...props} type="payment" onSuccess={onSuccess} />);

        await userEvent.click(screen.getByRole('button', { name: 'Pay $20.00' }));

        expect(await screen.findByRole('alert')).toHaveTextContent('Your card was declined.');
        expect(onSuccess).not.toHaveBeenCalled();
    });

    it("creates Elements with the intent's client secret", () => {
        const { container } = render(<PaymentForm {...props} type="payment" onSuccess={() => {}} />);

        expect(container.querySelector('[data-secret="pi_1_secret"]')).not.toBeNull();
    });
});
