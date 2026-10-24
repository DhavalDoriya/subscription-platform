<?php

namespace App\Enums;

/** FR-R3: what a failed payment asks of us and of the customer. */
enum DeclineType: string
{
    /** Temporary (funds, issuer trouble): worth another try on the schedule. */
    case Soft = 'soft';

    /** Permanent for this card: never retried, the customer must give a new one. */
    case Hard = 'hard';

    /** The bank wants the customer to confirm (3DS): retrying off-session cannot work. */
    case AuthRequired = 'auth_required';

    public function isRetryable(): bool
    {
        return $this === self::Soft;
    }

    public function needsCustomerAction(): bool
    {
        return $this !== self::Soft;
    }
}
