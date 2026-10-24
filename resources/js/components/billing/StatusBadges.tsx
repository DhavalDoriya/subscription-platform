import { StatusBadge } from '@/components/StatusBadge';
import { invoiceStatuses, paymentStatuses, subscriptionStatusLabel, subscriptionStatuses, type Invoice, type Payment, type Subscription } from '@/lib/billing';

export const SubscriptionStatusBadge = ({ subscription }: { subscription: Pick<Subscription, 'status' | 'cancel_at_period_end'> }) => (
    <StatusBadge
        label={subscriptionStatusLabel(subscription)}
        tone={subscription.cancel_at_period_end ? 'warn' : subscriptionStatuses[subscription.status].tone}
    />
);

export const InvoiceStatusBadge = ({ status }: { status: Invoice['status'] }) => (
    <StatusBadge label={invoiceStatuses[status].label} tone={invoiceStatuses[status].tone} />
);

export const PaymentStatusBadge = ({ status }: { status: Payment['status'] }) => (
    <StatusBadge label={paymentStatuses[status].label} tone={paymentStatuses[status].tone} />
);
