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
