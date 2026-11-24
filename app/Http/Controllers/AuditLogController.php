<?php

namespace App\Http\Controllers;

use App\Models\ActivityEntry;
use App\Models\User;
use App\Support\Audit\Audit;
use Carbon\CarbonImmutable;
use DateTimeZone;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Throwable;

/**
 * The read-only audit log viewer. One route, GET /audit-log, behind can:audit.view.
 * There is deliberately no way to change or delete an entry from here or anywhere else in the app.
 */
class AuditLogController extends Controller
{
    public const PER_PAGE_OPTIONS = [10, 25, 50];

    /** The filter value for entries with no signed-in user (console commands, failed sign-ins). */
    public const SYSTEM = 'system';

    /** Longest value sent to the browser for one old/new cell; the expanded row shows up to this much. */
    private const VALUE_LIMIT = 10000;

    private const USER_OPTION_LIMIT = 200;

    /** Properties shown as plain details in the expanded row, in this order. */
    private const DETAIL_LABELS = [
        'attempted_email' => 'Attempted email',
        'ip' => 'IP address',
        'user_agent' => 'Browser',
    ];

    public function index(Request $request): Response|RedirectResponse
    {
        $userId = $this->userFilter($request);
        $area = $this->area($request);
        $from = $this->date($request, 'from');
        $to = $this->date($request, 'to');
        $search = $this->searchTerm($request);
        $perPage = $this->perPage($request);
        $userMorph = (new User)->getMorphClass();

        $query = ActivityEntry::query()
            ->select(['id', 'log_name', 'description', 'subject_type', 'subject_id', 'causer_type', 'causer_id', 'properties', 'created_at'])
            ->when($userId === self::SYSTEM, fn (Builder $q) => $q->whereNull('causer_id'))
            ->when(is_int($userId), fn (Builder $q) => $q->where('causer_type', $userMorph)->where('causer_id', $userId))
            ->when($area !== '', fn (Builder $q) => $q->where('log_name', $area))
            // Whole days in the display timezone, both ends inclusive; stored times are in the app timezone.
            ->when($from !== null, fn (Builder $q) => $q->where('created_at', '>=', $this->stored($this->startOfDay($from))))
            ->when($to !== null, fn (Builder $q) => $q->where('created_at', '<', $this->stored($this->startOfDay($to)->addDay())))
            ->when($search !== '', fn (Builder $q) => $q->whereRaw("LOWER(description) LIKE ? ESCAPE '!'", [$this->likePattern($search)]))
            ->orderByDesc('created_at')
            ->orderByDesc('id');

        $requested = $request->query('page');
        // Very long digit strings would overflow the int / the SQL offset: treat them as "far past the end".
        $page = is_scalar($requested) && ctype_digit((string) $requested)
            ? max(1, strlen((string) $requested) > 9 ? 1_000_000_000 : (int) $requested)
            : 1;
        $paginator = $query->paginate($perPage, ['*'], 'page', $page);

        if ($page > $paginator->lastPage()) {
            return redirect()->to(rtrim($request->fullUrlWithQuery([
                'page' => $paginator->lastPage() === 1 ? null : $paginator->lastPage(),
            ]), '?'));
        }

        $names = User::query()
            ->whereIn('id', $paginator->getCollection()->where('causer_type', $userMorph)->pluck('causer_id')->filter()->unique()->all())
            ->pluck('name', 'id');

        return Inertia::render('audit-log/index', [
            'entries' => [
                'data' => $paginator->getCollection()->map(fn (ActivityEntry $e) => $this->row($e, $names->all(), $userMorph))->values()->all(),
                'current_page' => $paginator->currentPage(),
                'last_page' => $paginator->lastPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
                'from' => $paginator->firstItem(),
                'to' => $paginator->lastItem(),
            ],
            'areaOptions' => collect(Audit::AREAS)->map(fn (string $label, string $value) => ['value' => $value, 'label' => $label])->values()->all(),
            'userOptions' => $this->userOptions($userMorph, is_int($userId) ? $userId : null),
            'filters' => [
                'user_id' => $userId === null ? '' : (string) $userId,
                'area' => $area,
                'from' => $from ?? '',
                'to' => $to ?? '',
                'search' => $search,
                'per_page' => $perPage,
            ],
        ]);
    }

    /**
     * @param  array<int, string>  $names  causer id => name
     * @return array<string, mixed>
     */
    private function row(ActivityEntry $entry, array $names, string $userMorph): array
    {
        $properties = $entry->properties?->toArray() ?? [];
        $old = is_array($properties['old'] ?? null) ? $properties['old'] : [];
        $new = is_array($properties['attributes'] ?? null) ? $properties['attributes'] : [];

        $changes = [];

        foreach (array_unique(array_merge(array_keys($old), array_keys($new))) as $key) {
            $changes[] = [
                'key' => (string) $key,
                'old' => $this->text($old[$key] ?? null),
                'new' => $this->text($new[$key] ?? null),
            ];
        }

        $details = [];

        foreach (self::DETAIL_LABELS as $key => $label) {
            $value = $this->text($properties[$key] ?? null);

            if ($value !== null && $value !== '') {
                $details[] = ['label' => $label, 'value' => $value];
            }
        }

        $causerId = $entry->causer_type === $userMorph ? $entry->causer_id : null;

        return [
            'id' => $entry->id,
            'created_at' => $entry->created_at?->toIso8601String(),
            // A user who no longer exists still shows as a person, not as the system.
            'user' => $causerId !== null ? ['id' => $causerId, 'name' => $names[$causerId] ?? 'Deleted user'] : null,
            'area' => (string) $entry->log_name,
            'area_label' => Audit::AREAS[$entry->log_name] ?? ucfirst((string) $entry->log_name),
            'description' => $entry->description,
            // The last known name travels with the entry, so it survives the record being deleted.
            'subject' => $entry->subject_type === null ? null : [
                'type' => class_basename($entry->subject_type),
                'label' => $this->text($properties['subject_label'] ?? null),
            ],
            'changes' => $changes,
            'details' => $details,
        ];
    }

    /** A value as text for the browser. Never HTML: the page renders it as plain text. */
    private function text(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $text = match (true) {
            is_bool($value) => $value ? 'true' : 'false',
            is_scalar($value) => (string) $value,
            default => (string) json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PARTIAL_OUTPUT_ON_ERROR),
        };

        return mb_strlen($text) > self::VALUE_LIMIT ? mb_substr($text, 0, self::VALUE_LIMIT).'…' : $text;
    }

    /**
     * Only people who appear as the user of some entry (never the whole user table), at most 200 by name,
     * plus the one currently selected. Names only.
     *
     * @return list<array{id: int, name: string}>
     */
    private function userOptions(string $userMorph, ?int $selected): array
    {
        $causers = ActivityEntry::query()->where('causer_type', $userMorph)->select('causer_id')->distinct();

        $options = User::query()
            ->whereIn('id', $causers)
            ->orderBy('name')
            ->orderBy('id')
            ->limit(self::USER_OPTION_LIMIT)
            ->get(['id', 'name']);

        if ($selected !== null && ! $options->contains('id', $selected)) {
            $options = $options->concat(User::query()->whereIn('id', $causers)->where('id', $selected)->get(['id', 'name']));
        }

        return $options->map(fn (User $u) => ['id' => $u->id, 'name' => $u->name])->values()->all();
    }

    /** A user id (digits), the SYSTEM marker, or null. Anything else (arrays, junk, huge numbers) is ignored. */
    private function userFilter(Request $request): int|string|null
    {
        $value = $request->query('user_id');

        if ($value === self::SYSTEM) {
            return self::SYSTEM;
        }

        if (is_string($value) && ctype_digit($value) && strlen($value) <= 18 && (int) $value > 0) {
            return (int) $value;
        }

        return null;
    }

    private function area(Request $request): string
    {
        $value = $request->query('area');

        return is_string($value) && array_key_exists($value, Audit::AREAS) ? $value : '';
    }

    /** A real calendar date as Y-m-d, or null (junk, arrays and impossible dates are ignored). */
    private function date(Request $request, string $name): ?string
    {
        $value = $request->query($name);

        if (! is_string($value) || preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $value, $m) !== 1) {
            return null;
        }

        return checkdate((int) $m[2], (int) $m[3], (int) $m[1]) && (int) $m[1] >= 1970 ? $value : null;
    }

    private function displayZone(): string
    {
        $zone = (string) settings('company.timezone', config('app.timezone'));

        try {
            new DateTimeZone($zone);
        } catch (Throwable) {
            return (string) config('app.timezone');
        }

        return $zone;
    }

    private function startOfDay(string $date): CarbonImmutable
    {
        return CarbonImmutable::createFromFormat('!Y-m-d', $date, $this->displayZone());
    }

    /** The instant as the database stores it: a string in PHP's default timezone (config app.timezone, set at boot). */
    private function stored(CarbonImmutable $instant): string
    {
        return $instant->setTimezone(date_default_timezone_get())->format('Y-m-d H:i:s');
    }

    private function perPage(Request $request): int
    {
        $value = $request->query('per_page');

        if (is_scalar($value) && ctype_digit((string) $value) && in_array((int) $value, self::PER_PAGE_OPTIONS, true)) {
            return (int) $value;
        }

        return self::PER_PAGE_OPTIONS[0];
    }

    private function searchTerm(Request $request): string
    {
        $value = $request->query('search');

        return is_string($value) ? mb_substr(trim($value), 0, 100) : '';
    }

    /** A LIKE "contains" pattern (escape character !) in which % and _ in the term are literal. */
    private function likePattern(string $term): string
    {
        return '%'.str_replace(['!', '%', '_'], ['!!', '!%', '!_'], mb_strtolower($term)).'%';
    }
}
