<?php

namespace App\Http\Controllers\Iclock;

use App\Http\Controllers\Controller;
use App\Models\DeviceLog;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Throwable;

class IclockController extends Controller
{
    private const MAX_BODY_BYTES = 1048576;

    /** GET /iclock/cdata: the machine asks for its options. */
    public function handshake(Request $request): Response
    {
        return $this->handle($request, function (string $sn) {
            $options = [
                "GET OPTION FROM: {$sn}",
                'ATTLOGStamp=None',
                'OPERLOGStamp=9999',
                'ATTPHOTOStamp=None',
                'ErrorDelay=30',
                'Delay=10',
                'TransTimes=00:00;14:05',
                'TransInterval=1',
                'TransFlag=TransData AttLog OpLog',
                'TimeZone=5',
                'Realtime=1',
                'Encrypt=None',
            ];

            return ['handshake', implode("\n", $options)."\n"];
        });
    }

    /** POST /iclock/cdata: the machine uploads a table (ATTLOG, OPERLOG, ...). */
    public function upload(Request $request): Response
    {
        return $this->handle($request, function (string $sn) use ($request) {
            $table = strtoupper($this->queryString($request, 'table'));

            if ($table === 'ATTLOG') {
                $count = count($this->parseAttlog($request->getContent()));

                return ["ATTLOG upload, {$count} punch line(s)", "OK: {$count}\n"];
            }

            return [($table !== '' ? $table : 'unknown table').' upload', "OK\n"];
        });
    }

    /** GET /iclock/getrequest: the machine polls for commands. Nothing queued yet. */
    public function getrequest(Request $request): Response
    {
        return $this->handle($request, fn (string $sn) => ['command poll', "OK\n"]);
    }

    /** POST /iclock/devicecmd: the machine reports command results. */
    public function devicecmd(Request $request): Response
    {
        return $this->handle($request, fn (string $sn) => ['command result', "OK\n"]);
    }

    /**
     * Parse tab-separated ATTLOG lines: PIN, Y-m-d H:i:s, status, verify, extras ignored.
     * Lines with an empty PIN or an unparseable date-time are skipped.
     *
     * @return list<array{pin: string, punched_at: string, status: string, verify: string}>
     */
    public function parseAttlog(string $body): array
    {
        $rows = [];

        foreach (preg_split('/\r\n|\n|\r/', $body) as $line) {
            if (trim($line) === '') {
                continue;
            }

            $fields = explode("\t", $line);
            $pin = trim($fields[0]);
            $time = trim($fields[1] ?? '');

            if ($pin === '' || $time === '') {
                continue;
            }

            try {
                $punchedAt = Carbon::createFromFormat('!Y-m-d H:i:s', $time);
            } catch (Throwable) {
                continue;
            }

            // Reject overflow dates such as 2026-02-31 that PHP silently rolls over.
            if ($punchedAt->format('Y-m-d H:i:s') !== $time) {
                continue;
            }

            $rows[] = [
                'pin' => $pin,
                'punched_at' => $time,
                'status' => trim($fields[2] ?? ''),
                'verify' => trim($fields[3] ?? ''),
            ];
        }

        return $rows;
    }

    /**
     * Shared flow: check the serial number, build the reply, log the request.
     *
     * @param  callable(string): array{0: string, 1: string}  $respond  returns [summary, body]
     */
    private function handle(Request $request, callable $respond): Response
    {
        $sn = trim($this->queryString($request, 'SN'));

        if ($sn === '') {
            $this->record($request, null, false, 'rejected: no serial number');

            return $this->plain("ERROR: missing serial number\n", 400);
        }

        if (! in_array($sn, config('hrm.devices.allowed_serials', []), true)) {
            $this->record($request, $sn, false, 'rejected: unknown device');

            return $this->plain("ERROR: unknown device\n", 403);
        }

        [$summary, $reply] = $respond($sn);
        $this->record($request, $sn, true, $summary);

        return $this->plain($reply);
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

    /** Write one device_logs row. A logging failure must never change the reply. */
    private function record(Request $request, ?string $sn, bool $accepted, string $summary): void
    {
        try {
            $body = $request->getContent();

            DeviceLog::create([
                'serial_no' => $sn !== null ? mb_substr($this->utf8($sn), 0, 255) : null,
                'method' => substr($request->method(), 0, 10),
                'path' => mb_substr($this->utf8($request->path()), 0, 255),
                // Cut on bytes first (TEXT holds 65535 bytes), then sanitise: '?' is 1 byte, so it never grows.
                'query' => $this->utf8(mb_strcut((string) $request->server->get('QUERY_STRING', ''), 0, 60000)),
                'body' => $body === '' ? null : $this->utf8(mb_strcut($body, 0, self::MAX_BODY_BYTES)),
                'ip' => $request->ip(),
                'scheme' => $request->isSecure() ? 'https' : 'http',
                'accepted' => $accepted,
                'summary' => mb_substr($summary, 0, 255),
            ]);
        } catch (Throwable $e) {
            Log::warning('iclock: could not write device_logs row', ['error' => $e->getMessage()]);
        }
    }
}
