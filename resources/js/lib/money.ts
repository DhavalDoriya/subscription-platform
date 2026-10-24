/** Money is always an integer in minor units (cents). These helpers convert at the edges and never use float maths on amounts. */

const fractionDigits = (currency: string) =>
    new Intl.NumberFormat('en', { style: 'currency', currency: currency.toUpperCase() }).resolvedOptions().maximumFractionDigits ?? 2;

export function formatMoney(minor: number, currency: string): string {
    const digits = fractionDigits(currency);

    return new Intl.NumberFormat('en', { style: 'currency', currency: currency.toUpperCase() }).format(minor / 10 ** digits);
}

/** What a person typed ("19.50") to minor units (1950), or null if it is not a valid amount for the currency. */
export function toMinor(input: string, currency: string): number | null {
    const digits = fractionDigits(currency);
    const match = /^(\d+)(?:\.(\d+))?$/.exec(input.trim());

    if (!match) return null;

    const [, whole, fraction = ''] = match;
    if (fraction.length > digits) return null;

    return Number(whole + fraction.padEnd(digits, '0'));
}

/** Minor units back to an editable string: 1950 -> "19.50". */
export function toMajorString(minor: number, currency: string): string {
    const digits = fractionDigits(currency);
    if (digits === 0) return String(minor);

    const padded = String(minor).padStart(digits + 1, '0');

    return `${padded.slice(0, -digits)}.${padded.slice(-digits)}`;
}

export interface PriceLike {
    amount: number;
    currency: string;
    interval: string;
    interval_count: number;
}

export function formatPrice(price: PriceLike): string {
    const money = formatMoney(price.amount, price.currency);

    return price.interval_count === 1 ? `${money} / ${price.interval}` : `${money} every ${price.interval_count} ${price.interval}s`;
}
