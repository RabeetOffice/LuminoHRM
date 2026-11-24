/**
 * Soft background and text colours for a person with no photo, light and dark. Whole class names (not built
 * from pieces) so Tailwind sees them.
 */
export const AVATAR_TINTS = [
    'bg-blue-100 text-blue-700 dark:bg-blue-500/20 dark:text-blue-300',
    'bg-emerald-100 text-emerald-700 dark:bg-emerald-500/20 dark:text-emerald-300',
    'bg-amber-100 text-amber-700 dark:bg-amber-500/20 dark:text-amber-300',
    'bg-rose-100 text-rose-700 dark:bg-rose-500/20 dark:text-rose-300',
    'bg-violet-100 text-violet-700 dark:bg-violet-500/20 dark:text-violet-300',
    'bg-cyan-100 text-cyan-700 dark:bg-cyan-500/20 dark:text-cyan-300',
    'bg-orange-100 text-orange-700 dark:bg-orange-500/20 dark:text-orange-300',
    'bg-fuchsia-100 text-fuchsia-700 dark:bg-fuchsia-500/20 dark:text-fuchsia-300',
] as const;

const FIRST_LETTER = /\p{L}/u;

/**
 * The letters shown for a person with no photo: the first letter of the first and of the last word, upper
 * case ("Ali Khan" -> "AK"). One word gives one letter. Digits, punctuation and extra spaces are skipped;
 * a word with no letter at all does not count.
 */
export function initials(name: string): string {
    const letters = name
        .split(/\s+/u)
        .map((word) => word.match(FIRST_LETTER)?.[0])
        .filter((letter): letter is string => letter !== undefined);

    if (letters.length === 0) {
        return '';
    }

    const first = letters[0];
    const last = letters.length > 1 ? letters[letters.length - 1] : '';

    return `${first}${last}`.toLocaleUpperCase();
}

/** A colour from AVATAR_TINTS that depends only on the name (case and outer spaces ignored). */
export function tintFor(name: string): (typeof AVATAR_TINTS)[number] {
    let hash = 0;

    for (const char of name.trim().toLowerCase()) {
        hash = (hash * 31 + (char.codePointAt(0) ?? 0)) >>> 0;
    }

    return AVATAR_TINTS[hash % AVATAR_TINTS.length];
}
