<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

beforeEach(function () {
    Route::get('/_test/ip', fn (Request $r) => response()->json(['ip' => $r->ip(), 'secure' => $r->isSecure()]));
});

function viaProxy($test, string $uri = '/_test/ip', string $remote = '10.1.1.5')
{
    return $test->withServerVariables(['REMOTE_ADDR' => $remote])
        ->withHeaders(['X-Forwarded-For' => '203.0.113.9', 'X-Forwarded-Proto' => 'https'])
        ->get($uri);
}

it('ignores X-Forwarded-For by default (no trusted proxies)', function () {
    expect(config('hrm.trusted_proxies'))->toBe([]);
    viaProxy($this)->assertJson(['ip' => '10.1.1.5', 'secure' => false]);
});

it('honours X-Forwarded-For from a configured trusted proxy (IP or CIDR)', function () {
    config(['hrm.trusted_proxies' => ['10.1.1.0/24']]);
    viaProxy($this)->assertJson(['ip' => '203.0.113.9', 'secure' => true]);

    config(['hrm.trusted_proxies' => ['10.1.1.5']]);
    viaProxy($this)->assertJson(['ip' => '203.0.113.9']);
});

it('ignores X-Forwarded-For from an address that is not a trusted proxy', function () {
    config(['hrm.trusted_proxies' => ['10.1.1.0/24']]);
    viaProxy($this, remote: '198.51.100.7')->assertJson(['ip' => '198.51.100.7', 'secure' => false]);
});

it('keeps the iclock routes working with and without a trusted proxy', function () {
    config(['hrm.devices.allowed_serials' => ['TESTSN001']]);
    foreach ([[], ['10.1.1.0/24']] as $proxies) {
        config(['hrm.trusted_proxies' => $proxies]);
        viaProxy($this, '/iclock/cdata?SN=TESTSN001&options=all')->assertOk();
    }
});

it('parses the comma separated environment value into a list', function () {
    $raw = ' 10.0.0.1 , 192.168.0.0/16 ,,';
    putenv('HRM_TRUSTED_PROXIES='.$raw);
    $_ENV['HRM_TRUSTED_PROXIES'] = $_SERVER['HRM_TRUSTED_PROXIES'] = $raw;
    try {
        $parsed = (require base_path('config/hrm.php'))['trusted_proxies'];
    } finally {
        putenv('HRM_TRUSTED_PROXIES');
        unset($_ENV['HRM_TRUSTED_PROXIES'], $_SERVER['HRM_TRUSTED_PROXIES']);
    }
    expect($parsed)->toBe(['10.0.0.1', '192.168.0.0/16']);
});
