import type { ErrorResponse } from './types';

/** Every failed request surfaces as this, whatever the cause (HTTP error, network, bad payload). */
export class ApiError extends Error {
    constructor(
        message: string,
        readonly status: number,
        readonly fieldErrors: Record<string, string[]> = {},
        readonly code?: string,
    ) {
        super(message);
        this.name = 'ApiError';
    }

    static fromBody(status: number, body: Partial<ErrorResponse> | undefined): ApiError {
        return new ApiError(body?.message ?? 'Something went wrong.', status, body?.errors ?? {}, body?.code);
    }

    static network(): ApiError {
        return new ApiError('Network error. Check your connection and try again.', 0);
    }

    get isValidation(): boolean {
        return this.status === 422;
    }

    get isUnauthenticated(): boolean {
        return this.status === 401;
    }

    /** First message for a form field, for inline display. */
    firstError(field: string): string | undefined {
        return this.fieldErrors[field]?.[0];
    }
}
