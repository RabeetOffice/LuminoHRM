<?php

function rootHtaccess(): string
{
    return file_get_contents(base_path('.htaccess'));
}

it('rewrites everything that is not already under public to public', function () {
    expect(rootHtaccess())->toContain('RewriteEngine On')
        ->toMatch('#RewriteRule \^\(\?!public/\)\(\.\*\)\$ public/\$1 \[L\]#');
});

it('denies the sensitive top-level paths', function () {
    $htaccess = rootHtaccess();

    foreach (['storage', 'vendor', 'bootstrap', 'config', 'database', 'routes', 'app', 'resources', 'tests', 'deploy', 'scripts', 'docs'] as $dir) {
        expect($htaccess)->toMatch('/\b'.$dir.'\b/');
    }

    expect($htaccess)->toContain('[F')
        ->toContain('composer')
        ->toContain('artisan');
});

it('denies dotfiles other than .well-known', function () {
    expect(rootHtaccess())->toContain('(?!well-known');
});

it('redirects http to https but exempts the attendance machine path', function () {
    $htaccess = rootHtaccess();

    expect($htaccess)->toContain('%{HTTPS} !=on')
        ->toContain('R=301')
        ->toContain('RewriteCond %{THE_REQUEST} !^[A-Z]+\s+(?:https?://[^/\s]+)?/iclock(?:[/?\s]|$)')
        ->not->toContain('REQUEST_URI} !^/iclock');
});

it('skips the https redirect on local development hosts', function () {
    $htaccess = rootHtaccess();

    expect($htaccess)->toContain('localhost')
        ->toContain('127\.0\.0\.1')
        ->toContain('\.test');
});

it('puts the https redirect before the deny and rewrite rules', function () {
    $htaccess = rootHtaccess();

    expect(strpos($htaccess, 'R=301'))->toBeLessThan(strpos($htaccess, '[F'))
        ->and(strpos($htaccess, '[F'))->toBeLessThan(strpos($htaccess, 'public/$1'));
});

it('redirects trailing slashes itself so the internal public prefix never leaks', function () {
    expect(rootHtaccess())->toContain('RewriteRule ^(?!public/).+/$ %1 [L,R=308]');
});

it('exempts exactly the iclock request lines from the https redirect (pattern executed)', function () {
    preg_match('/RewriteCond %\{THE_REQUEST\} !(\S+)/', rootHtaccess(), $m);
    $pattern = '#'.$m[1].'#';

    foreach ([
        'GET /iclock/cdata?SN=1 HTTP/1.1',
        'POST /iclock/devicecmd?SN=1 HTTP/1.1',
        'GET /iclock/getrequest?SN=1 HTTP/1.0',
        'GET /iclock HTTP/1.1',
        'GET /iclock?SN=1 HTTP/1.1',
    ] as $line) {
        expect(preg_match($pattern, $line))->toBe(1, "should be exempt: $line");
    }

    foreach ([
        'GET /iclockx HTTP/1.1',
        'GET /foo/iclock/ HTTP/1.1',
        'GET /Iclock/cdata HTTP/1.1',
        'GET /login?next=/iclock/ HTTP/1.1',
        'GET / HTTP/1.1',
    ] as $line) {
        expect(preg_match($pattern, $line))->toBe(0, "should be redirected: $line");
    }
});

it('denies the env seed and status files under deploy/', function () {
    preg_match('/RewriteRule (\^\(\?:storage\|\S+\)) - \[F,L\]/', rootHtaccess(), $m);
    $pattern = '#'.$m[1].'#';

    foreach (['deploy/hostinger/env.seed', 'deploy/hostinger/status.txt', 'deploy/hostinger/status.log', 'deploy/hostinger/cron.sh'] as $path) {
        expect(preg_match($pattern, $path))->toBe(1, "should be denied: $path");
    }
    expect(preg_match($pattern, 'build/assets/app.js'))->toBe(0);
});

it('also denies the seed, status and release files in the FilesMatch fallback (mod_rewrite off)', function () {
    preg_match('/<FilesMatch "([^"]+)">/', rootHtaccess(), $m);
    $pattern = '#'.$m[1].'#';

    foreach (['env.seed', 'status.txt', 'status.log', 'release.txt', 'release.manifest', 'cron.sh', 'env.production.example', '.env', 'composer.json', 'artisan'] as $name) {
        expect(preg_match($pattern, $name))->toBe(1, "should be denied: $name");
    }
    foreach (['index.php', 'robots.txt', 'app.js'] as $name) {
        expect(preg_match($pattern, $name))->toBe(0, "should be served: $name");
    }
});

it('answers a direct /public/ request with 404 and decides on the original request line (pattern executed)', function () {
    $htaccess = rootHtaccess();

    expect($htaccess)->toContain('RewriteRule ^ - [R=404,L]');

    preg_match('/RewriteCond %\{THE_REQUEST\} (\^\S+) \[NC\]\n\s*RewriteRule \^ - \[R=404,L\]/', $htaccess, $m);
    $pattern = '#'.$m[1].'#i';

    foreach ([
        'GET /public/index.php HTTP/1.1',
        'GET /public/build/assets/app.js HTTP/1.1',
        'GET /public HTTP/1.1',
        'GET /public/ HTTP/1.1',
        'GET /public?x=1 HTTP/1.1',
        'POST https://hrm.example.com/public/login HTTP/1.1',
    ] as $line) {
        expect(preg_match($pattern, $line))->toBe(1, "should be 404: $line");
    }

    // The internal rewrite changes REQUEST_URI but never the request line, so normal pages are untouched.
    foreach ([
        'GET / HTTP/1.1',
        'GET /dashboard HTTP/1.1',
        'GET /build/assets/app.js HTTP/1.1',
        'GET /publications HTTP/1.1',
        'GET /iclock/cdata?SN=1 HTTP/1.1',
        'GET /login?next=/public/ HTTP/1.1',
    ] as $line) {
        expect(preg_match($pattern, $line))->toBe(0, "should be served: $line");
    }

    // After the HTTPS redirect, before the deny list and the rewrite to public/.
    expect(strpos($htaccess, 'R=301'))->toBeLessThan(strpos($htaccess, 'R=404'))
        ->and(strpos($htaccess, 'R=404'))->toBeLessThan(strpos($htaccess, '[F'))
        ->and($htaccess)->toContain('RewriteRule ^(?!public/)(.*)$ public/$1 [L]');
});

it('sets the security headers behind mod_headers so a missing module cannot 500 the site', function () {
    $htaccess = rootHtaccess();

    expect(preg_match('#<IfModule mod_headers\.c>(.*?)</IfModule>#s', $htaccess, $m))->toBe(1);
    $block = $m[1];

    expect($block)->toContain('Header always set X-Content-Type-Options "nosniff"')
        ->toContain('Header always set X-Frame-Options "DENY"')
        ->toContain("Header always set Content-Security-Policy \"frame-ancestors 'none'\"")
        ->toContain('Header always set Referrer-Policy "strict-origin-when-cross-origin"');

    // No restrictive script/style policy in this pass.
    expect($block)->not->toContain('script-src')->not->toContain('style-src')->not->toContain('default-src');

    // No Header directive outside an IfModule guard.
    $outside = preg_replace('#<IfModule mod_headers\.c>.*?</IfModule>#s', '', $htaccess);
    expect($outside)->not->toMatch('/^\s*Header\s/m');
});

it('sends HSTS only on HTTPS responses, never on plain-HTTP /iclock', function () {
    $htaccess = rootHtaccess();

    expect($htaccess)->toContain('Header always set Strict-Transport-Security "max-age=31536000" env=HTTPS')
        ->and(substr_count($htaccess, 'Strict-Transport-Security'))->toBe(1);

    // The iclock exemption from the HTTPS redirect is unchanged.
    expect($htaccess)->toContain('RewriteCond %{THE_REQUEST} !^[A-Z]+\s+(?:https?://[^/\s]+)?/iclock(?:[/?\s]|$)');
});
