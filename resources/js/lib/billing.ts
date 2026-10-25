/** Shapes returned by the billing API, and how each subscription status is shown. Money is always integer minor units. */

export type SubscriptionStatus = 'incomplete' | 'trialing' | 'active' | 'past_due' | 'grace' | 'paused' | 'canceled' | 'expired';

export interface Subscription {
    id: string;
    status: SubscriptionStatus;
    has_access: boolean;
    plan?: { id: string; name: string };
    provider?: { id: string; name: string; slug: string };
    customer?: { name: string | null; email: string | null };
    amount: number;
    currency: string;
    interval: string;
    interval_count: number;
    cancel_at_period_end: boolean;
    current_period_end: string | null;
    trial_ends_at: string | null;
    canceled_at: string | null;
    created_at: string;
}

export interface Invoice {
    id: string;
    number: string | null;
    status: 'draft' | 'open' | 'paid' | 'void' | 'uncollectible';
    subscription_id: string | null;
    currency: string;
    amount_due: number;
    amount_paid: number;
    hosted_invoice_url: string | null;
    invoice_pdf_url: string | null;
    period_start: string | null;
    period_end: string | null;
    paid_at: string | null;
    created_at: string;
    /** Where a failed payment is in our retry and dunning engine; null when it never failed. */
    dunning_status?: DunningStatus | null;
    first_failed_at?: string | null;
    next_retry_at?: string | null;
    provider?: { id: string; name: string; slug: string };
    customer?: { name: string | null; email: string | null };
}

export type DunningStatus = 'retrying' | 'awaiting_customer' | 'exhausted' | 'recovered' | 'resolved';

export interface DunningAttempt {
    id: string;
    attempt_number: number;
    kind: 'initial' | 'retry' | 'manual' | 'card_update';
    status: 'succeeded' | 'failed' | 'requires_action';
    decline_code: string | null;
    decline_type: 'soft' | 'hard' | 'auth_required' | null;
    failure_message: string | null;
    attempted_at: string;
    next_retry_at: string | null;
}

export interface DunningEvent {
    id: string;
    type: string;
    template: string | null;
    occurred_at: string;
}

export interface DunningTimeline {
    attempts: DunningAttempt[];
    events: DunningEvent[];
}

export interface Refund {
    id: string;
    payment_id: string;
    amount: number;
    currency: string;
    reason: string | null;
    status: string;
    created_at: string;
}

export interface Payment {
    id: string;
    subscription_id: string | null;
    invoice_id: string | null;
    status: 'pending' | 'succeeded' | 'failed' | 'refunded' | 'partially_refunded';
    amount: number;
    amount_refunded: number;
    refundable: number;
    currency: string;
    failure_code: string | null;
    failure_message: string | null;
    paid_at: string | null;
    created_at: string;
    /** The platform fee on this payment; null when no fee was taken. */
    commission: { rate_percent: string; amount: number; refunded: number; net_fee: number; provider_net: number } | null;
    /** A chargeback against this payment, if any. */
    dispute?: { status: string; amount: number; reason: string | null; evidence_due_by: string | null } | null;
    refunds: Refund[];
}

export interface PaymentMethod {
    id: string;
    brand: string | null;
    last4: string | null;
    exp_month: number | null;
    exp_year: number | null;
    is_default: boolean;
    provider?: { id: string; name: string; slug: string };
}

type Tone = 'good' | 'warn' | 'bad' | 'neutral';

export const subscriptionStatuses: Record<SubscriptionStatus, { label: string; tone: Tone }> = {
    incomplete: { label: 'Incomplete', tone: 'warn' },
    trialing: { label: 'Trial', tone: 'good' },
    active: { label: 'Active', tone: 'good' },
    past_due: { label: 'Past due', tone: 'bad' },
    grace: { label: 'Grace period', tone: 'warn' },
    paused: { label: 'Paused', tone: 'warn' },
    canceled: { label: 'Canceled', tone: 'neutral' },
    expired: { label: 'Expired', tone: 'neutral' },
};

export const invoiceStatuses: Record<Invoice['status'], { label: string; tone: Tone }> = {
    draft: { label: 'Draft', tone: 'neutral' },
    open: { label: 'Open', tone: 'warn' },
    paid: { label: 'Paid', tone: 'good' },
    void: { label: 'Void', tone: 'neutral' },
    uncollectible: { label: 'Uncollectible', tone: 'bad' },
};

export const dunningStatuses: Record<DunningStatus, { label: string; tone: Tone }> = {
    retrying: { label: 'Retrying', tone: 'warn' },
    awaiting_customer: { label: 'Waiting for customer', tone: 'bad' },
    exhausted: { label: 'Retries used up', tone: 'bad' },
    recovered: { label: 'Recovered', tone: 'good' },
    resolved: { label: 'Resolved', tone: 'neutral' },
};

/** Failed payments still being worked on: the ones a provider can retry or settle by hand. */
export const isDunningActive = (status: DunningStatus | null | undefined) => status === 'retrying' || status === 'awaiting_customer' || status === 'exhausted';

export const dunningEventLabels: Record<string, string> = {
    failure_notice: 'Failure notice sent',
    retry_scheduled: 'Retry scheduled',
    auth_required: 'Authentication requested',
    new_card_requested: 'New card requested',
    grace_started: 'Grace period started',
    canceled: 'Subscription canceled',
    paused: 'Subscription paused',
    recovered: 'Payment recovered',
    resolved: 'Marked resolved',
};

export const disputeStatusLabels: Record<string, string> = {
    needs_response: 'Dispute: respond by the deadline',
    under_review: 'Dispute under review',
    won: 'Dispute won',
    lost: 'Dispute lost',
    warning_needs_response: 'Early warning: respond',
    warning_under_review: 'Early warning under review',
    warning_closed: 'Early warning closed',
    charge_refunded: 'Dispute closed: refunded',
};

export const paymentStatuses: Record<Payment['status'], { label: string; tone: Tone }> = {
    pending: { label: 'Pending', tone: 'warn' },
    succeeded: { label: 'Paid', tone: 'good' },
    failed: { label: 'Failed', tone: 'bad' },
    refunded: { label: 'Refunded', tone: 'neutral' },
    partially_refunded: { label: 'Partly refunded', tone: 'warn' },
};

/** "Active", or "Active · ends 3 Nov" when it is set to cancel at the period end. */
export const subscriptionStatusLabel = (s: Pick<Subscription, 'status' | 'cancel_at_period_end'>) =>
    s.cancel_at_period_end && s.status !== 'canceled' ? `${subscriptionStatuses[s.status].label} · ending` : subscriptionStatuses[s.status].label;
