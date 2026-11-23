import { usePage } from '@inertiajs/react';
import { accentCss, DEFAULT_ACCENT } from '@/lib/accent';

/**
 * Applies the saved branding accent by overriding the shell's colour variables.
 * Mounted once in each layout. The default accent needs no override (app.css already has it),
 * and accentCss() returns nothing for a value that is not a plain hex colour.
 */
export function AccentStyle() {
    const { app } = usePage().props;

    if (app.accent.toLowerCase() === DEFAULT_ACCENT) {
        return null;
    }

    const css = accentCss(app.accent);

    return css === '' ? null : <style data-accent>{css}</style>;
}
