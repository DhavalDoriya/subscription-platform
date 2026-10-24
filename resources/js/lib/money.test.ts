import { formatMoney, formatPrice, toMajorString, toMinor } from './money';

describe('money', () => {
    it('formats integer minor units in the currency', () => {
        expect(formatMoney(1900, 'usd')).toBe('$19.00');
        expect(formatMoney(5, 'usd')).toBe('$0.05');
        expect(formatMoney(1900, 'jpy')).toBe('¥1,900'); // zero-decimal currency
    });

    it('turns what a person types into minor units, or null when it is not a valid amount', () => {
        expect(toMinor('19', 'usd')).toBe(1900);
        expect(toMinor('19.5', 'usd')).toBe(1950);
        expect(toMinor('19.50', 'usd')).toBe(1950);
        expect(toMinor(' 0.99 ', 'usd')).toBe(99);
        expect(toMinor('1900', 'jpy')).toBe(1900);
        expect(toMinor('19.999', 'usd')).toBeNull();
        expect(toMinor('19.5', 'jpy')).toBeNull();
        expect(toMinor('abc', 'usd')).toBeNull();
        expect(toMinor('', 'usd')).toBeNull();
        expect(toMinor('-5', 'usd')).toBeNull();
    });

    it('never goes through floating point for money', () => {
        expect(toMinor('0.29', 'usd')).toBe(29); // 0.29 * 100 === 28.999999999999996 in floats
        expect(toMinor('1.15', 'usd')).toBe(115);
    });

    it('shows minor units as an editable decimal string', () => {
        expect(toMajorString(1950, 'usd')).toBe('19.50');
        expect(toMajorString(1900, 'jpy')).toBe('1900');
    });

    it('describes a price with its billing interval', () => {
        expect(formatPrice({ amount: 1900, currency: 'usd', interval: 'month', interval_count: 1 })).toBe('$19.00 / month');
        expect(formatPrice({ amount: 19000, currency: 'usd', interval: 'year', interval_count: 1 })).toBe('$190.00 / year');
        expect(formatPrice({ amount: 4900, currency: 'usd', interval: 'month', interval_count: 3 })).toBe('$49.00 every 3 months');
    });
});
