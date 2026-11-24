<?php

namespace App\Support;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;

/**
 * Query-string helpers shared by every paginated, searchable list (departments, users, employees, ...).
 *
 * All of them accept whatever a visitor can put in a URL: array-valued parameters (?page[]=2), negative or
 * absurdly long numbers and wildcard characters never cause a server error.
 */
class ListQuery
{
    /** Rows per page the screens offer; the first is the default. */
    public const PER_PAGE_OPTIONS = [10, 25, 50];

    /** Longest search term kept. */
    public const SEARCH_MAX = 100;

    /**
     * Makes a search term safe for `LOWER(col) LIKE ? ESCAPE '!'`: lower-cased, with `!`, `%` and `_` escaped so
     * they match literally. The caller adds the surrounding wildcards:
     *
     *   $q->whereRaw("LOWER(name) LIKE ? ESCAPE '!'", ['%'.ListQuery::escapeLike($term).'%']);
     */
    public static function escapeLike(string $term): string
    {
        return str_replace(['!', '%', '_'], ['!!', '!%', '!_'], mb_strtolower($term));
    }

    /** Rows per page: 10, 25 or 50; anything else falls back to 10. */
    public static function perPage(Request $request): int
    {
        $value = $request->query('per_page');

        if (is_scalar($value) && ctype_digit((string) $value) && in_array((int) $value, self::PER_PAGE_OPTIONS, true)) {
            return (int) $value;
        }

        return self::PER_PAGE_OPTIONS[0];
    }

    /**
     * The requested page (at least 1) from the query parameter $key. A number too long to be an int is treated
     * as "far past the end" so the clamp redirect sends the visitor to the real last page.
     */
    public static function page(Request $request, string $key = 'page'): int
    {
        $requested = $request->query($key);

        if (! is_scalar($requested) || ! ctype_digit((string) $requested)) {
            return 1;
        }

        return max(1, strlen((string) $requested) > 9 ? 1_000_000_000 : (int) $requested);
    }

    /** A trimmed text parameter of at most 100 characters; anything that is not a string gives ''. */
    public static function stringParam(Request $request, string $key): string
    {
        $value = $request->query($key);

        return is_string($value) ? mb_substr(trim($value), 0, self::SEARCH_MAX) : '';
    }

    /**
     * When any paginator was asked for a page past its last one (for example after the last row of the last page was
     * deleted or filtered out), a redirect to the same address with that page set to the real last page (the
     * parameter is dropped for page 1); otherwise null.
     *
     *   $rows = $query->paginate($perPage, ['*'], 'page', ListQuery::page($request, 'page'));
     *   if ($redirect = ListQuery::clampRedirect($request, ['page' => $rows])) {
     *       return $redirect;
     *   }
     *
     * @param  array<string, LengthAwarePaginator<int, *>>  $paginators  page parameter name => paginator built for the requested page
     */
    public static function clampRedirect(Request $request, array $paginators): ?RedirectResponse
    {
        $clamped = [];

        foreach ($paginators as $key => $paginator) {
            if (self::page($request, $key) > $paginator->lastPage()) {
                $clamped[$key] = $paginator->lastPage() === 1 ? null : $paginator->lastPage();
            }
        }

        if ($clamped === []) {
            return null;
        }

        return redirect()->to(rtrim($request->fullUrlWithQuery($clamped), '?'));
    }
}
