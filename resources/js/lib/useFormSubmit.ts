import { useState, type FormEvent } from 'react';
import { ApiError } from '@/lib/api';

/** Shared submit handling for forms: pending flag, field errors, and a general message. */
export function useFormSubmit<T>(action: () => Promise<T>, onSuccess: (result: T) => void) {
    const [pending, setPending] = useState(false);
    const [error, setError] = useState<ApiError | null>(null);

    async function submit(event: FormEvent) {
        event.preventDefault();
        setPending(true);
        setError(null);
        try {
            onSuccess(await action());
        } catch (e) {
            setError(e instanceof ApiError ? e : new ApiError('Something went wrong.', 0));
        } finally {
            setPending(false);
        }
    }

    return {
        submit,
        pending,
        fieldError: (field: string) => error?.firstError(field),
        formError: error && !error.isValidation ? error.message : undefined,
    };
}
