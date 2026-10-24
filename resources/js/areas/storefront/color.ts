/** Black or white text, whichever is easier to read on this #rrggbb background (WCAG relative luminance). */
export function readableOn(hex: string): string {
    const channel = (offset: number) => {
        const v = parseInt(hex.slice(offset, offset + 2), 16) / 255;
        return v <= 0.03928 ? v / 12.92 : ((v + 0.055) / 1.055) ** 2.4;
    };
    const luminance = 0.2126 * channel(1) + 0.7152 * channel(3) + 0.0722 * channel(5);

    return luminance > 0.4 ? '#101828' : '#ffffff';
}
