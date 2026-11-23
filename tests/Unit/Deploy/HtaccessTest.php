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
        ->toMatch('#RewriteCond %\{REQUEST_URI\} !\^/iclock\(/\|\$\)#');
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
