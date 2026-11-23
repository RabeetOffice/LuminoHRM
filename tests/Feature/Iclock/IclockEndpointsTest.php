<?php

use App\Models\DeviceLog;

beforeEach(function () {
    config(['hrm.devices.allowed_serials' => ['TESTSN001']]);
});

it('answers the handshake in plain text with device options', function () {
    $r = $this->get('/iclock/cdata?SN=TESTSN001&options=all');
    $r->assertOk()->assertHeader('Content-Type', 'text/plain; charset=UTF-8');
    expect($r->getContent())->toStartWith("GET OPTION FROM: TESTSN001\n")
        ->toContain('Realtime=1')->toContain('TransFlag=TransData AttLog OpLog');
    expect(DeviceLog::where('serial_no', 'TESTSN001')->where('accepted', true)->count())->toBe(1);
});

it('accepts ATTLOG lines without a CSRF token and reports the count', function () {
    $body = "101\t2026-11-23 09:00:17\t0\t1\t0\t0\n102\t2026-11-23 09:02:00\t0\t1\t0\t0\n";
    $r = $this->call('POST', '/iclock/cdata?SN=TESTSN001&table=ATTLOG&Stamp=9999', [], [], [], ['CONTENT_TYPE' => 'text/plain'], $body);
    $r->assertOk();
    expect($r->getContent())->toBe("OK: 2\n");
    expect(DeviceLog::latest('id')->first()->body)->toBe($body);
});

it('skips malformed lines instead of failing', function () {
    $r = $this->call('POST', '/iclock/cdata?SN=TESTSN001&table=ATTLOG', [], [], [], [], "garbage\n\n103\t2026-11-23 18:30:00\t1\t1\n");
    expect($r->getContent())->toBe("OK: 1\n");
});

it('rejects an unknown serial number and logs it', function () {
    $r = $this->get('/iclock/cdata?SN=EVIL999');
    $r->assertStatus(403);
    expect($r->getContent())->toBe("ERROR: unknown device\n");
    expect(DeviceLog::where('serial_no', 'EVIL999')->where('accepted', false)->exists())->toBeTrue();
});

it('rejects a request with no serial number', function () {
    $this->get('/iclock/getrequest')->assertStatus(400);
});

it('answers command polling and command results with OK', function () {
    expect($this->get('/iclock/getrequest?SN=TESTSN001')->getContent())->toBe("OK\n");
    expect($this->call('POST', '/iclock/devicecmd?SN=TESTSN001', [], [], [], [], "ID=1&Return=0&CMD=DATA")->getContent())->toBe("OK\n");
});

it('accepts OPERLOG uploads', function () {
    expect($this->call('POST', '/iclock/cdata?SN=TESTSN001&table=OPERLOG', [], [], [], [], "OPLOG 4\t0\t2026-11-23 09:00:00\t0\t0\t0\t0\n")->getContent())->toBe("OK\n");
});

it('does not start a session or set cookies', function () {
    expect($this->get('/iclock/getrequest?SN=TESTSN001')->headers->getCookies())->toBeEmpty();
});

it('treats an array SN as a missing serial number instead of failing', function () {
    $r = $this->get('/iclock/getrequest?SN[]=x');
    $r->assertStatus(400);
    expect($r->getContent())->toBe("ERROR: missing serial number\n");
    expect($r->headers->get('Content-Type'))->toBe('text/plain; charset=UTF-8');
    expect(DeviceLog::whereNull('serial_no')->where('accepted', false)->count())->toBe(1);
});

it('ignores an array table parameter from a registered device', function () {
    $r = $this->call('POST', '/iclock/cdata?SN=TESTSN001&table[]=ATTLOG', [], [], [], [], "101\t2026-11-23 09:00:17\t0\t1\n");
    $r->assertOk();
    expect($r->getContent())->toBe("OK\n");
});

it('logs a missing serial number with a null serial_no and a plain-text 400', function () {
    $r = $this->get('/iclock/getrequest');
    expect($r->getContent())->toBe("ERROR: missing serial number\n");
    expect(DeviceLog::whereNull('serial_no')->where('accepted', false)->count())->toBe(1);
});

it('logs the malformed-lines upload with its body', function () {
    $body = "garbage\n\n103\t2026-11-23 18:30:00\t1\t1\n";
    $this->call('POST', '/iclock/cdata?SN=TESTSN001&table=ATTLOG', [], [], [], [], $body);
    $log = DeviceLog::latest('id')->first();
    expect($log->body)->toBe($body)->and($log->accepted)->toBeTrue();
});

it('stores a body with invalid UTF-8 as valid UTF-8 and still replies', function () {
    $body = "OPLOG 4\t0\t2026-11-23 09:00:00\t\xB9\xA4\xBA\xC5\n";
    $r = $this->call('POST', '/iclock/cdata?SN=TESTSN001&table=OPERLOG', [], [], [], [], $body);
    expect($r->getContent())->toBe("OK\n");
    $stored = DeviceLog::latest('id')->first()->body;
    expect(mb_check_encoding($stored, 'UTF-8'))->toBeTrue()
        ->and($stored)->toStartWith("OPLOG 4\t0\t2026-11-23 09:00:00\t");
});

it('stores an invalid UTF-8 serial number as valid UTF-8', function () {
    $r = $this->get('/iclock/getrequest?SN=%FF%FEbad');
    $r->assertStatus(403);
    $sn = DeviceLog::latest('id')->first()->serial_no;
    expect(mb_check_encoding($sn, 'UTF-8'))->toBeTrue();
});

it('truncates an over-long serial number and query before logging', function () {
    $r = $this->get('/iclock/getrequest?SN='.str_repeat('A', 400).'&pad='.str_repeat('b', 70000));
    $r->assertStatus(403);
    $log = DeviceLog::latest('id')->first();
    expect(strlen($log->serial_no))->toBeLessThanOrEqual(255)
        ->and(strlen($log->query))->toBeLessThanOrEqual(65535);
});

it('truncates the stored body at 1 MB without changing the reply', function () {
    $line = "101\t2026-11-23 09:00:17\t0\t1\n";
    $body = str_repeat($line, (int) ceil(1048576 / strlen($line)) + 10);
    $r = $this->call('POST', '/iclock/cdata?SN=TESTSN001&table=ATTLOG', [], [], [], [], $body);
    expect($r->getContent())->toBe('OK: '.(int) ceil(1048576 / strlen($line) + 10)."\n");
    expect(strlen(DeviceLog::latest('id')->first()->body))->toBe(1048576);
});

it('keeps the reply unchanged when writing the log fails', function () {
    Illuminate\Support\Facades\Schema::drop('device_logs');
    $r = $this->get('/iclock/getrequest?SN=TESTSN001');
    $r->assertOk();
    expect($r->getContent())->toBe("OK\n");
});
