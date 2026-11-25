<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Models\Device;
use App\Models\DeviceCommand;
use App\Models\DeviceLog;
use App\Models\DevicePunch;
use App\Models\User;
use App\Support\Attendance\AttendanceClock;
use App\Support\Audit\Audit;
use Carbon\CarbonInterface;
use Closure;
use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\QueryException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Settings > ZKTeco Device (settings.manage, Admin): the attendance machines, their status, the unknown machine user IDs,
 * a 7-day sync log and the "Re-send punches" command.
 *
 * Device add, rename, activate / deactivate and remove are audited by the Device model (area attendance, inside the same
 * transaction). Queuing a command is audited here. The sync log shows each request's type and summary only: request
 * bodies and query strings stay in device_logs and never reach the browser.
 */
class DeviceController extends Controller
{
    /** A machine is online when the server heard from it within this many minutes. */
    public const ONLINE_MINUTES = 5;

    /** device_logs rows are kept this many days (hrm:prune-device-logs); the sync log shows the same window. */
    public const LOG_DAYS = 7;

    /** Sync log rows per page. */
    private const LOG_PER_PAGE = 25;

    /**
     * The sync log's type filter: key => [label, path, method or null]. "other" is every path not listed here.
     *
     * @var array<string, array{0: string, 1: string, 2: string|null}>
     */
    public const LOG_TYPES = [
        'handshake' => ['Handshake', 'iclock/cdata', 'GET'],
        'upload' => ['Upload', 'iclock/cdata', 'POST'],
        'poll' => ['Command poll', 'iclock/getrequest', null],
        'result' => ['Command result', 'iclock/devicecmd', null],
    ];

    /** The summary IclockController writes for a poll that found no command: the machine's routine 10-second check. */
    public const ROUTINE_POLL_SUMMARY = 'command poll';

    /** Longest range "Re-send punches" may ask for, in days (both ends included). */
    public const MAX_RESEND_DAYS = 31;

    private const UNKNOWN_LIMIT = 50;

    public function index(Request $request, AttendanceClock $clock): Response
    {
        $now = $clock->now();
        $today = $clock->attendanceToday();
        $zone = $clock->zone();

        $devices = Device::query()->withExists('punches')->orderBy('name')->orderBy('id')->get();

        $todayCounts = DevicePunch::query()
            ->where('punch_date', $today)
            ->groupBy('device_id')
            ->selectRaw('device_id, COUNT(*) AS aggregate')
            ->pluck('aggregate', 'device_id');

        $requested = $request->query('device');
        $selected = is_string($requested) && ctype_digit($requested)
            ? $devices->firstWhere('id', (int) $requested)
            : null;
        $selected ??= $devices->first();
        $logFilters = $this->logFilters($request);

        return Inertia::render('hrm-settings/devices', [
            'groups' => HrmSettingsController::groupsFor($request->user()),
            'devices' => $devices->map(fn (Device $d) => [
                'id' => $d->id,
                'name' => $d->name,
                'serial_no' => $d->serial_no,
                'is_active' => $d->is_active,
                'online' => $d->is_active && $this->online($d->last_seen_at, $now),
                'last_seen_at' => $this->local($d->last_seen_at, $zone),
                // Naive machine time, shown as it is.
                'last_punch_at' => $d->last_punch_at === null ? null : substr((string) $d->getRawOriginal('last_punch_at'), 0, 16),
                'punches_today' => (int) ($todayCounts[$d->id] ?? 0),
                'has_punches' => (bool) $d->getAttribute('punches_exists'),
                // Listed in HRM_DEVICE_SERIALS: removing it would not stick (the next check-in re-creates it).
                'env_listed' => self::envListed($d->serial_no),
            ])->values()->all(),
            'selected' => $selected?->id,
            'log' => $this->log($selected, $zone, $logFilters, $request),
            'logFilters' => $logFilters,
            'commands' => $selected === null ? [] : $this->commands($selected, $zone),
            'unknown' => $this->unknownIds(),
            'today' => $now->format('Y-m-d'),
            'maxResendDays' => self::MAX_RESEND_DAYS,
            'onlineMinutes' => self::ONLINE_MINUTES,
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $this->validateDevice($request, null);

        try {
            $device = DB::transaction(fn () => Device::create([...$data, 'is_active' => true]));
        } catch (UniqueConstraintViolationException) {
            throw ValidationException::withMessages(['serial_no' => 'A device with this serial number already exists.']);
        }

        return to_route('hrm-settings.devices.index', ['device' => $device->id])
            ->with('success', "{$device->name} added. Point the machine's Cloud Server setting at this site to start receiving punches.");
    }

    /** Renames a device. The serial number never changes: it is how the machine is recognised. */
    public function update(Request $request, Device $device): RedirectResponse
    {
        $data = $request->validate(['name' => ['required', 'string', 'max:100']]);

        DB::transaction(fn () => $device->fill(['name' => trim($data['name'])])->save());

        return back()->with('success', 'Device renamed.');
    }

    public function toggle(Request $request, Device $device): RedirectResponse
    {
        $data = $request->validate(['is_active' => ['required', 'boolean']]);
        $active = (bool) $data['is_active'];

        DB::transaction(fn () => $device->fill(['is_active' => $active])->save());

        return back()->with('success', $active
            ? "{$device->name} activated. Its punches are accepted again."
            : "{$device->name} deactivated. The server refuses it until it is activated again.");
    }

    /** Removes a device that never sent a punch. A device with punches is deactivated instead (its history stays). */
    public function destroy(Device $device): RedirectResponse
    {
        if (self::envListed($device->serial_no)) {
            return back()->with('error', 'This serial is listed in HRM_DEVICE_SERIALS, so it would come back on the next check-in. Deactivate it, or remove it from the environment first.');
        }

        try {
            $refused = DB::transaction(function () use ($device): bool {
                $locked = Device::query()->whereKey($device->getKey())->lockForUpdate()->firstOrFail();

                if ($locked->punches()->exists()) {
                    return true;
                }

                $locked->delete();

                return false;
            });
        } catch (QueryException) {
            // The punches foreign key restricts the delete: a punch arrived in between.
            $refused = true;
        }

        return $refused
            ? back()->with('error', 'This device has punches stored, so it cannot be removed. Deactivate it instead.')
            : to_route('hrm-settings.devices.index')->with('success', 'Device removed.');
    }

    /** Queues "send me your punches again" for a date range; the machine runs it on its next check-in. */
    public function resend(Request $request, Device $device, AttendanceClock $clock): RedirectResponse
    {
        $today = $clock->now()->format('Y-m-d');

        $data = $request->validate([
            'from' => ['bail', 'required', 'string', 'date_format:Y-m-d', 'before_or_equal:'.$today],
            'to' => ['bail', 'required', 'string', 'date_format:Y-m-d', 'after_or_equal:from', 'before_or_equal:'.$today],
        ], [
            'from.before_or_equal' => 'The start date cannot be in the future.',
            'to.before_or_equal' => 'The end date cannot be in the future.',
            'to.after_or_equal' => 'The end date must be on or after the start date.',
        ], ['from' => 'start date', 'to' => 'end date']);

        $days = (int) (new DateTimeImmutable($data['from']))->diff(new DateTimeImmutable($data['to']))->days + 1;

        if ($days > self::MAX_RESEND_DAYS) {
            throw ValidationException::withMessages(['to' => 'Ask for at most '.self::MAX_RESEND_DAYS.' days at a time.']);
        }

        if (! $device->is_active) {
            throw ValidationException::withMessages(['from' => 'Activate the device first: the server refuses an inactive device, so it would never receive the command.']);
        }

        /** @var User $user */
        $user = $request->user();
        $command = self::resendCommand($data['from'], $data['to']);

        DB::transaction(function () use ($device, $command, $user, $data): void {
            // The same request still waiting for the machine would only make it upload the same punches twice. The
            // device row is locked so two clicks at once cannot both pass this check.
            Device::query()->whereKey($device->getKey())->lockForUpdate()->first();

            $waiting = DeviceCommand::query()
                ->where('device_id', $device->id)
                ->where('status', 'queued')
                ->where('command', $command)
                ->exists();

            if ($waiting) {
                throw ValidationException::withMessages(['from' => 'The same re-send is already waiting for the machine. It runs when the machine next checks in.']);
            }

            $queued = DeviceCommand::create([
                'device_id' => $device->id,
                'command' => $command,
                'status' => 'queued',
                'created_by' => $user->id,
            ]);

            Audit::record('attendance', 'Device command queued', $device, [
                'command_id' => $queued->id,
                'command' => 'Re-send punches',
                'from' => $data['from'],
                'to' => $data['to'],
            ], $user);
        });

        return back()->with('success', "Re-send queued for {$data['from']} to {$data['to']}. It runs when the machine next checks in (usually within a minute while it is online).");
    }

    /** The ADMS command asking the machine to upload its punches between two days again (stored ones are duplicates). */
    public static function resendCommand(string $from, string $to): string
    {
        return "DATA QUERY ATTLOG StartTime={$from} 00:00:00\tEndTime={$to} 23:59:59";
    }

    /**
     * @return array{name: string, serial_no: string}
     */
    private function validateDevice(Request $request, ?Device $device): array
    {
        // Serials are stored upper-cased and compared without regard to case, so SQLite (tests) and MariaDB (a
        // case-insensitive collation) behave the same.
        $request->merge([
            'name' => is_string($request->input('name')) ? trim($request->input('name')) : $request->input('name'),
            'serial_no' => is_string($request->input('serial_no')) ? strtoupper(trim($request->input('serial_no'))) : $request->input('serial_no'),
        ]);

        /** @var array{name: string, serial_no: string} */
        return $request->validate([
            'name' => ['required', 'string', 'max:100'],
            'serial_no' => [
                'bail', 'required', 'string', 'max:50', 'regex:/^[A-Za-z0-9._-]+$/',
                function (string $attribute, mixed $value, Closure $fail) use ($device): void {
                    $taken = Device::query()
                        ->whereRaw('UPPER(serial_no) = ?', [strtoupper((string) $value)])
                        ->when($device !== null, fn ($q) => $q->whereKeyNot($device->getKey()))
                        ->exists();

                    if ($taken) {
                        $fail('A device with this serial number already exists.');
                    }
                },
            ],
        ], [
            'serial_no.regex' => 'The serial number may contain only letters, digits, dots, dashes and underscores.',
        ], ['serial_no' => 'serial number']);
    }

    /** Whether $serial is in config('hrm.devices.allowed_serials') (HRM_DEVICE_SERIALS), ignoring case. */
    public static function envListed(string $serial): bool
    {
        $allowed = config('hrm.devices.allowed_serials', []);

        return is_array($allowed)
            && in_array(strtoupper($serial), array_map(fn ($s) => strtoupper((string) $s), $allowed), true);
    }

    private function online(?CarbonInterface $lastSeen, CarbonInterface $now): bool
    {
        // Both are moments in time; Carbon compares them whatever their zones.
        return $lastSeen !== null && $lastSeen->greaterThanOrEqualTo($now->subMinutes(self::ONLINE_MINUTES));
    }

    /** An app-time moment as the company's wall clock, "Y-m-d H:i". */
    private function local(?CarbonInterface $moment, DateTimeZone $zone): ?string
    {
        return $moment?->toImmutable()->setTimezone($zone)->format('Y-m-d H:i');
    }

    /**
     * The sync log filters as applied: polls (show routine command polls) and type ('' or a LOG_TYPES key / 'other').
     *
     * @return array{polls: bool, type: string}
     */
    private function logFilters(Request $request): array
    {
        $type = $request->query('log_type');

        return [
            'polls' => in_array($request->query('polls'), ['1', 'true'], true),
            'type' => is_string($type) && ($type === 'other' || array_key_exists($type, self::LOG_TYPES)) ? $type : '',
        ];
    }

    /**
     * The last 7 days of requests from this machine, newest first, 25 a page (?log_page=): time, type, accepted and
     * summary only. Never the body or the query string. The machine polls for commands about every 10 seconds, so
     * accepted polls that found nothing are left out unless asked for.
     *
     * @param  array{polls: bool, type: string}  $filters
     * @return array{data: array<int, array{time: string|null, type: string, accepted: bool, summary: string}>, current_page: int, last_page: int, per_page: int, total: int}
     */
    private function log(?Device $device, DateTimeZone $zone, array $filters, Request $request): array
    {
        if ($device === null) {
            return ['data' => [], 'current_page' => 1, 'last_page' => 1, 'per_page' => self::LOG_PER_PAGE, 'total' => 0];
        }

        $page = $request->query('log_page');
        $page = is_string($page) && ctype_digit($page) && strlen($page) <= 6 && (int) $page > 0 ? (int) $page : 1;

        $query = DeviceLog::query()
            ->where('serial_no', $device->serial_no)
            ->where('created_at', '>=', Date::now()->subDays(self::LOG_DAYS));

        if (! $filters['polls']) {
            $query->whereNot(fn (Builder $q) => $q
                ->where('path', self::LOG_TYPES['poll'][1])
                ->where('accepted', true)
                ->where('summary', self::ROUTINE_POLL_SUMMARY));
        }

        if ($filters['type'] === 'other') {
            $query->whereNotIn('path', array_unique(array_column(self::LOG_TYPES, 1)));
        } elseif ($filters['type'] !== '') {
            [, $path, $method] = self::LOG_TYPES[$filters['type']];
            $query->where('path', $path)->when($method !== null, fn (Builder $q) => $q->where('method', $method));
        }

        $paginator = $query
            ->orderByDesc('id')
            ->paginate(self::LOG_PER_PAGE, ['id', 'method', 'path', 'accepted', 'summary', 'created_at'], 'log_page', $page);

        return [
            'data' => $paginator->getCollection()->map(fn (DeviceLog $log) => [
                'time' => $this->local($log->created_at, $zone),
                'type' => self::logType((string) $log->method, (string) $log->path),
                'accepted' => (bool) $log->accepted,
                'summary' => (string) $log->summary,
            ])->values()->all(),
            'current_page' => $paginator->currentPage(),
            'last_page' => $paginator->lastPage(),
            'per_page' => $paginator->perPage(),
            'total' => $paginator->total(),
        ];
    }

    public static function logType(string $method, string $path): string
    {
        return match (trim($path, '/')) {
            'iclock/cdata' => strtoupper($method) === 'POST' ? 'Upload' : 'Handshake',
            'iclock/getrequest' => 'Command poll',
            'iclock/devicecmd' => 'Command result',
            default => 'Other',
        };
    }

    /**
     * The device's ten most recent commands.
     *
     * @return array<int, array<string, mixed>>
     */
    private function commands(Device $device, DateTimeZone $zone): array
    {
        return DeviceCommand::query()
            ->where('device_id', $device->id)
            ->orderByDesc('id')
            ->limit(10)
            ->get(['id', 'command', 'status', 'sent_at', 'created_at'])
            ->map(fn (DeviceCommand $c) => [
                'id' => $c->id,
                'label' => self::commandLabel($c->command),
                'status' => $c->status,
                'queued_at' => $this->local($c->created_at, $zone),
                'sent_at' => $this->local($c->sent_at, $zone),
            ])
            ->values()
            ->all();
    }

    public static function commandLabel(string $command): string
    {
        if (preg_match('/^DATA QUERY ATTLOG StartTime=(\d{4}-\d{2}-\d{2}) [\d:]+\tEndTime=(\d{4}-\d{2}-\d{2}) [\d:]+$/', $command, $m) === 1) {
            return "Re-send punches {$m[1]} to {$m[2]}";
        }

        return mb_substr(str_replace(["\t", "\r", "\n"], ' ', $command), 0, 80);
    }

    /**
     * Machine user IDs with punches that no employee was matched to, most punches first.
     *
     * @return array<int, array{device_user_id: string, punches: int, last_punch_at: string}>
     */
    private function unknownIds(): array
    {
        return DevicePunch::query()
            ->whereNull('employee_id')
            ->groupBy('device_user_id')
            ->selectRaw('device_user_id, COUNT(*) AS punches, MAX(punch_time) AS last_punch_at')
            ->orderByDesc('punches')
            ->orderBy('device_user_id')
            ->limit(self::UNKNOWN_LIMIT)
            ->toBase()
            ->get()
            ->map(fn (object $row) => [
                'device_user_id' => (string) $row->device_user_id,
                'punches' => (int) $row->punches,
                'last_punch_at' => substr((string) $row->last_punch_at, 0, 16),
            ])
            ->values()
            ->all();
    }
}
