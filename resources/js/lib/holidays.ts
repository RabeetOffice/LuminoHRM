/**
 * Where a new holiday's date starts in the add dialog: empty when viewing this year (the date picker then opens on
 * today), otherwise 1 January of the year being viewed, so the picker opens in that year.
 */
export function firstDateIn(year: number, today: Date = new Date()): string {
    return year === today.getFullYear()
        ? ''
        : `${String(year).padStart(4, '0')}-01-01`;
}
