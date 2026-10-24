import { render, screen } from '@testing-library/react';
import { ReturnBanner } from './ReturnBanner';

describe("FR-C1 coming back from a bank's 3-D Secure page", () => {
    it('confirms the payment when Stripe sends the customer back successfully', () => {
        render(<ReturnBanner search="?payment_intent=pi_1&redirect_status=succeeded" />);

        expect(screen.getByRole('status')).toHaveTextContent('Payment received');
        expect(screen.getByRole('link', { name: 'Manage your subscription' })).toBeInTheDocument();
    });

    it('says the payment did not go through when the bank step failed', () => {
        render(<ReturnBanner search="?redirect_status=failed" />);

        expect(screen.getByRole('alert')).toHaveTextContent(/did not go through/i);
    });

    it('shows nothing on an ordinary visit', () => {
        const { container } = render(<ReturnBanner search="" />);

        expect(container).toBeEmptyDOMElement();
    });
});
