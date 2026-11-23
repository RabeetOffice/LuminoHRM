/**
 * Branding accent colour -> the CSS variables the shell already uses.
 *
 * The accent is used three ways: as a fill (buttons, active marks), as text on white (needs an
 * "ink" that is dark enough), and as text on the dark theme surface (needs a lighter ink).
 * The ink colours are derived here, so a custom accent never leaves the old green ink behind.
 */

export const DEFAULT_ACCENT = '#10b77f';

/** Text must reach this contrast against its background (WCAG AA for body text). */
const MIN_CONTRAST = 4.5;
const WHITE = '#ffffff';
/** Close to the dark theme background, oklch(0.145 0 0). */
const DARK_SURFACE = '#0a0a0a';
const DARK_TEXT = '#111111';

/** Only a plain 6-digit hex colour may reach a style element. */
export function isHexColour(value: unknown): value is string {
    return typeof value === 'string' && /^#[0-9a-fA-F]{6}$/.test(value);
}

type Rgb = [number, number, number];

function toRgb(hex: string): Rgb {
    return [
        parseInt(hex.slice(1, 3), 16),
        parseInt(hex.slice(3, 5), 16),
        parseInt(hex.slice(5, 7), 16),
    ];
}

function toHex([r, g, b]: Rgb): string {
    return (
        '#' +
        [r, g, b]
            .map((c) => Math.round(c).toString(16).padStart(2, '0'))
            .join('')
    );
}

function luminance(hex: string): number {
    const [r, g, b] = toRgb(hex).map((channel) => {
        const c = channel / 255;

        return c <= 0.03928 ? c / 12.92 : ((c + 0.055) / 1.055) ** 2.4;
    }) as Rgb;

    return 0.2126 * r + 0.7152 * g + 0.0722 * b;
}

/** WCAG contrast ratio, 1 (none) to 21 (black on white). */
export function contrastRatio(a: string, b: string): number {
    const [light, dark] = [luminance(a), luminance(b)].sort((x, y) => y - x);

    return (light + 0.05) / (dark + 0.05);
}

/** Moves a colour toward `target` in 2% steps until it reads against `background`. */
function shiftUntilReadable(
    accent: string,
    background: string,
    target: Rgb,
): string {
    const start = toRgb(accent.toLowerCase());
    let colour = toHex(start);

    for (let step = 1; step <= 50; step++) {
        if (contrastRatio(colour, background) >= MIN_CONTRAST) {
            return colour;
        }

        const t = step * 0.02;
        colour = toHex(start.map((c, i) => c + (target[i] - c) * t) as Rgb);
    }

    return toHex(target);
}

/** The accent as text on white: darkened until it reaches 4.5:1. */
export function inkForLight(accent: string): string {
    return shiftUntilReadable(accent, WHITE, [0, 0, 0]);
}

/** The accent as text on the dark surface: lightened until it reaches 4.5:1. */
export function inkForDark(accent: string): string {
    return shiftUntilReadable(accent, DARK_SURFACE, [255, 255, 255]);
}

/** Text colour on an accent fill: white, unless the accent is so pale that white is under 2:1. */
export function onAccent(accent: string): string {
    return contrastRatio(accent, WHITE) >= 2 ? WHITE : DARK_TEXT;
}

/**
 * Rules that override the shell variables for light and dark mode. Returns an empty string for
 * anything that is not a 6-digit hex colour, so nothing else can reach a style element.
 */
export function accentCss(accent: string): string {
    if (!isHexColour(accent)) {
        return '';
    }

    const fill = accent.toLowerCase();
    const foreground = onAccent(fill);

    const shared = (ink: string, tint: string) =>
        [
            `--primary: ${fill};`,
            `--primary-foreground: ${foreground};`,
            `--ring: ${fill};`,
            `--sidebar-primary: ${fill};`,
            `--sidebar-primary-foreground: ${foreground};`,
            `--sidebar-ring: ${fill};`,
            `--brand-ink: ${ink};`,
            `--sidebar-accent: ${tint};`,
            `--sidebar-accent-foreground: ${ink};`,
        ].join(' ');

    return [
        `:root { ${shared(inkForLight(fill), `color-mix(in srgb, ${fill} 12%, white)`)} }`,
        `.dark { ${shared(inkForDark(fill), `color-mix(in srgb, ${fill} 22%, #1f1f1f)`)} }`,
    ].join('\n');
}
