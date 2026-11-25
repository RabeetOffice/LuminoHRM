<?php

namespace App\Http\Controllers\Iclock;

use App\Http\Controllers\Controller;
use App\Models\Device;
use App\Models\DeviceCommand;
use App\Models\DeviceLog;
use App\Support\Attendance\AttendanceClock;
use App\Support\Attendance\PunchIngestor;
use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

class IclockController extends Controller
{
    private const MAX_BODY_BYTES = 1048576;

    /** Rejected requests keep only this much of the body. */
    private const REJECTED_BODY_BYTES = 1024;

    private const REJECTED_PER_IP_PER_HOUR = 60;

    private const REJECTED_WINDOW_SECONDS = 3600;

    private const CAP_MARKER = 'further rejected requests from this address not logged';

    /** GET /iclock/cdata: the machine asks for its options. */
    public function handshake(Request $request): Response
    {
        return $this->handle($request, function (Device $device) {
            $this->touch($device);

            $options = [
                "GET OPTION FROM: {$device->serial_no}",
                // The machine re-sends punches newer than this stamp; None asks for everything it holds.
                'ATTLOGStamp='.($device->last_stamp !== null && $device->last_stamp !== '' ? $device->last_stamp : 'None'),
                'OPERLOGStamp=9999',
                'ATTPHOTOStamp=None',
                'ErrorDelay=30',
                'Delay=10',
                'TransTimes=00:00;14:05',
                'TransInterval=1',
                'TransFlag=TransData AttLog OpLog',
                'TimeZone='.$this->utcOffsetHours(),
                'Realtime=1',
                'Encrypt=None',
            ];

            return ['handshake', implode("\n", $options)."\n"];
        });
    }

    /**
     * POST /iclock/cdata: the machine uploads a table (ATTLOG, OPERLOG, ...). ATTLOG punches are stored before the
     * reply: "OK: n" only after the commit; a storage failure replies "ERROR: retry" (HTTP 500) so the machine re-sends.
     */
    public function upload(Request $request): Response
    {
        return $this->handle($request, function (Device $device) use ($request) {
            $table = strtoupper($this->queryString($request, 'table'));

            if ($table === 'ATTLOG') {
                $stamp = $this->queryString($request, 'Stamp');

                try {
                    $result = app(PunchIngestor::class)->ingest($device, $request->getContent(), $stamp !== '' ? $stamp : null);
                } catch (Throwable $e) {
                    // Class only: a QueryException message embeds the bound punch values.
                    Log::error('iclock: could not store ATTLOG punches', ['exception' => $e::class]);

                    return ['ATTLOG upload failed: '.$e::class, "ERROR: retry\n", 500];
                }

                return [$result->summary(), 'OK: '.$result->acknowledged()."\n"];
            }

            $this->touch($device);

            return [($table !== '' ? $table : 'unknown table').' upload', "OK\n"];
        });
    }

    /** GET /iclock/getrequest: the machine polls for commands; the oldest queued one is handed out and marked sent. */
    public function getrequest(Request $request): Response
    {
        return $this->handle($request, function (Device $device) {
            $this->touch($device);

            $command = DB::transaction(function () use ($device): ?DeviceCommand {
                $command = DeviceCommand::query()
                    ->where('device_id', $device->id)
                    ->where('status', 'queued')
                    ->orderBy('id')
                    ->lockForUpdate()
                    ->first();

                $command?->forceFill(['status' => 'sent', 'sent_at' => Date::now()])->save();

                return $command;
            });

            if ($command === null) {
                return ['command poll', "OK\n"];
            }

            // The protocol is line based: a command never spans lines.
            $text = str_replace(["\r", "\n"], ' ', $command->command);

            return ["command poll: sent command {$command->id}", "C:{$command->id}:{$text}\n"];
        });
    }

    /** POST /iclock/devicecmd: the machine reports command results ("ID=<id>&Return=<code>&CMD=..." per line). */
    public function devicecmd(Request $request): Response
    {
        return $this->handle($request, function (Device $device) use ($request) {
            $this->touch($device);
            $updated = [];

            foreach (preg_split('/\r\n|\n|\r/', $request->getContent()) ?: [] as $line) {
                $line = trim($line);
                parse_str($line, $fields);
                $id = $fields['ID'] ?? null;
                $code = $fields['Return'] ?? null;

                if (! is_string($id) || ! ctype_digit($id) || ! is_string($code) || ! preg_match('/^-?\d+$/', $code)) {
                    continue;
                }

                $changed = DeviceCommand::query()
                    ->whereKey((int) $id)
                    ->where('device_id', $device->id)
                    ->whereIn('status', ['queued', 'sent'])
                    ->update([
                        'status' => (int) $code === 0 ? 'done' : 'failed',
                        'result' => $this->utf8(mb_strcut($line, 0, 60000)),
                        'updated_at' => Date::now(),
                    ]);

                if ($changed > 0) {
                    $updated[] = $id;
                }
            }

            return [$updated === [] ? 'command result' : 'command result: '.implode(', ', $updated), "OK\n"];
        });
    }

    /**
     * The punch lines of an ATTLOG body that would be stored (see PunchIngestor::parse()).
     *
     * @return list<array{pin: string, punched_at: string, status: string, verify: string}>
     */
    public function parseAttlog(string $body): array
    {
        return PunchIngestor::parse($body)['rows'];
    }

    /**
     * Shared flow: find the device by serial number, build the reply, log the request.
     *
     * A serial is accepted when an active devices row has it. A serial listed in HRM_DEVICE_SERIALS with no row yet
     * gets one on first contact. Anything else is rejected as an unknown device (an inactive device too).
     *
     * @param  callable(Device): array{0: string, 1: string, 2?: int}  $respond  returns [summary, body, status = 200]
     */
    private function handle(Request $request, callable $respond): Response
    {
        // Serials are stored upper-cased: matching (and logging) the upper-cased value works the same on every database.
        $sn = strtoupper(trim($this->queryString($request, 'SN')));

        // First of all, before any parsing: an oversized body never reaches the punch parser, and is neither logged
        // nor stored.
        $size = $this->oversizedBody($request);

        if ($size !== null) {
            Log::warning('iclock: request body over the size limit', ['bytes' => $size, 'path' => $request->path()]);
            $this->record($request, $sn !== '' ? $sn : null, false, "rejected: body too large ({$size} bytes)", false);

            return $this->plain("ERROR: body too large\n", 413);
        }

        if ($sn === '') {
            $this->record($request, null, false, 'rejected: no serial number');

            return $this->plain("ERROR: missing serial number\n", 400);
        }

        try {
            $device = $this->device($sn);
        } catch (Throwable $e) {
            Log::error('iclock: could not look up the device', ['exception' => $e::class]);
            $this->record($request, $sn, false, 'device lookup failed: '.$e::class);

            return $this->plain("ERROR: retry\n", 500);
        }

        if ($device === null || ! $device->is_active) {
            $this->record($request, $sn, false, $device === null ? 'rejected: unknown device' : 'rejected: inactive device');

            return $this->plain("ERROR: unknown device\n", 403);
        }

        try {
            $answer = $respond($device);
        } catch (Throwable $e) {
            // Any failure (command poll, command results, ...) is a plain-text retry, never Laravel's HTML error page.
            // Class only: a QueryException message embeds bound values.
            Log::error('iclock: request failed', ['exception' => $e::class]);
            $answer = ['request failed: '.$e::class, "ERROR: retry\n", 500];
        }

        $this->record($request, $sn, true, $answer[0]);

        return $this->plain($answer[1], $answer[2] ?? 200);
    }

    /**
     * The body size when it is over config('hrm.devices.max_body_bytes'), else null. Content-Length is checked first,
     * so an announced oversized body is refused without being read.
     */
    private function oversizedBody(Request $request): ?int
    {
        $limit = config('hrm.devices.max_body_bytes');
        $limit = is_numeric($limit) && (int) $limit > 0 ? (int) $limit : 4194304;
        $declared = $request->server->get('CONTENT_LENGTH', $request->server->get('HTTP_CONTENT_LENGTH'));

        if (is_numeric($declared) && (int) $declared > $limit) {
            return (int) $declared;
        }

        $size = strlen($request->getContent());

        return $size > $limit ? $size : null;
    }

    /** The handshake's TimeZone= value; when the settings cannot be read, the app zone's offset (as AttendanceClock). */
    private function utcOffsetHours(): int|float
    {
        try {
            return app(AttendanceClock::class)->utcOffsetHours();
        } catch (Throwable $e) {
            Log::warning('iclock: could not read the company time zone, using the app zone', ['exception' => $e::class]);
        }

        $zone = config('app.timezone');

        try {
            $seconds = (new DateTimeImmutable('now', new DateTimeZone(is_string($zone) && $zone !== '' ? $zone : 'UTC')))->getOffset();
        } catch (Throwable) {
            $seconds = 0;
        }

        return $seconds % 3600 === 0 ? intdiv($seconds, 3600) : $seconds / 3600;
    }

    /** The devices row for $sn (already upper-cased), created for a serial listed in the environment (any case); null when unknown. */
    private function device(string $sn): ?Device
    {
        // Never query with what cannot be a stored serial (over-long, invalid UTF-8): it is simply unknown.
        if (strlen($sn) > 50 || ! mb_check_encoding($sn, 'UTF-8')) {
            return null;
        }

        $device = Device::query()->where('serial_no', $sn)->first();

        $allowed = array_map(fn ($serial) => strtoupper((string) $serial), (array) config('hrm.devices.allowed_serials', []));

        if ($device !== null || ! in_array($sn, $allowed, true)) {
            return $device;
        }

        try {
            return Device::query()->create(['name' => "MB20 ({$sn})", 'serial_no' => $sn, 'is_active' => true]);
        } catch (UniqueConstraintViolationException) {
            // A parallel first request created it.
            return Device::query()->where('serial_no', $sn)->first();
        }
    }

    /** Heartbeat: the server heard from the machine. A failure here never changes the reply. */
    private function touch(Device $device): void
    {
        try {
            $device->forceFill(['last_seen_at' => Date::now()])->save();
        } catch (Throwable $e) {
            Log::warning('iclock: could not update last_seen_at', ['exception' => $e::class]);
        }
    }

    /** A query parameter as a string; arrays (?SN[]=x) and absent values count as ''. */
    private function queryString(Request $request, string $key): string
    {
        $value = $request->query($key);

        return is_string($value) ? $value : '';
    }

    /** Valid UTF-8 only: invalid bytes become '?', so strict MariaDB never rejects the row. */
    private function utf8(string $value): string
    {
        return mb_check_encoding($value, 'UTF-8') ? $value : mb_convert_encoding($value, 'UTF-8', 'UTF-8');
    }

    private function plain(string $content, int $status = 200): Response
    {
        return response($content, $status, ['Content-Type' => 'text/plain; charset=UTF-8']);
    }

    /**
     * Write one device_logs row. A logging failure must never change the reply.
     *
     * Rejected requests (no or unknown serial number) come from anyone on the internet, so they are
     * cheap on purpose: only the first REJECTED_BODY_BYTES of the body are kept, and each address may
     * add REJECTED_PER_IP_PER_HOUR rows per hour, then one marker row, then nothing until the hour is up.
     */
    private function record(Request $request, ?string $sn, bool $accepted, string $summary, bool $withBody = true): void
    {
        try {
            $bodyLimit = $withBody ? self::MAX_BODY_BYTES : 0;

            if (! $accepted) {
                $verdict = $this->rejectedVerdict($request->ip());

                if ($verdict === 'skip') {
                    return;
                }

                if ($verdict === 'marker') {
                    $summary = self::CAP_MARKER;
                    $bodyLimit = 0;
                } elseif ($withBody) {
                    $bodyLimit = self::REJECTED_BODY_BYTES;
                }
            }

            $body = $bodyLimit > 0 ? $request->getContent() : '';

            DeviceLog::create([
                'serial_no' => $sn !== null ? mb_substr($this->utf8($sn), 0, 255) : null,
                'method' => substr($request->method(), 0, 10),
                'path' => mb_substr($this->utf8($request->path()), 0, 255),
                // Cut on bytes first (TEXT holds 65535 bytes), then sanitise: '?' is 1 byte, so it never grows.
                'query' => $this->utf8(mb_strcut((string) $request->server->get('QUERY_STRING', ''), 0, 60000)),
                'body' => $body === '' ? null : $this->utf8(mb_strcut($body, 0, $bodyLimit)),
                'ip' => $request->ip(),
                'scheme' => $request->isSecure() ? 'https' : 'http',
                'accepted' => $accepted,
                'summary' => mb_substr($summary, 0, 255),
            ]);
        } catch (Throwable $e) {
            // Class only: a QueryException message embeds the bound request body.
            Log::warning('iclock: could not write device_logs row', ['exception' => $e::class]);
        }
    }

    /**
     * 'log' (under the cap), 'marker' (the first request over it) or 'skip'. Uses the app cache,
     * which starts no session and sets no cookie. If the cache is unavailable: 'log'.
     */
    private function rejectedVerdict(?string $ip): string
    {
        try {
            $key = 'iclock:rejected:'.sha1((string) $ip);

            Cache::add($key, 0, self::REJECTED_WINDOW_SECONDS);
            $count = (int) Cache::increment($key);
        } catch (Throwable) {
            return 'log';
        }

        return match (true) {
            $count <= self::REJECTED_PER_IP_PER_HOUR => 'log',
            $count === self::REJECTED_PER_IP_PER_HOUR + 1 => 'marker',
            default => 'skip',
        };
    }
}
