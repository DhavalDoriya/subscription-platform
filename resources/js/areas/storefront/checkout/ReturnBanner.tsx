import { accountUrl } from './ActivationStep';

/** Some banks redirect away for 3-D Secure; Stripe sends the customer back with `redirect_status` in the address. */
export function ReturnBanner({ search }: { search: string }) {
    const status = new URLSearchParams(search).get('redirect_status');

    if (!status) return null;

    if (status === 'succeeded' || status === 'processing')
        return (
            <div role="status" className="bg-green-50 px-4 py-3 text-center text-sm text-green-900">
                Payment received. Your subscription will be active in a moment.{' '}
                <a href={accountUrl()} className="font-medium underline">
                    Manage your subscription
                </a>
            </div>
        );

    return (
        <div role="alert" className="bg-red-50 px-4 py-3 text-center text-sm text-red-900">
            The payment did not go through. You have not been charged; please try again.
        </div>
    );
}
