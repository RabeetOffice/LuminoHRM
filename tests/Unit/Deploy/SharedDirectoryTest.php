<?php

use App\Support\SharedDirectory;

function makeDeployLayout(bool $withShared): string
{
    $root = sys_get_temp_dir().DIRECTORY_SEPARATOR.'lumino-shared-'.bin2hex(random_bytes(4));
    mkdir($root.DIRECTORY_SEPARATOR.'public_html', 0777, true);

    if ($withShared) {
        mkdir($root.DIRECTORY_SEPARATOR.'shared');
    }

    return $root;
}

function removeDeployLayout(string $root): void
{
    $items = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST,
    );

    foreach ($items as $item) {
        $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
    }

    rmdir($root);
}

it('uses the sibling shared directory for env and storage when it exists', function () {
    $root = makeDeployLayout(withShared: true);
    $base = $root.DIRECTORY_SEPARATOR.'public_html';

    try {
        $resolver = SharedDirectory::forBasePath($base);

        expect($resolver->exists())->toBeTrue()
            ->and($resolver->path())->toBe($root.DIRECTORY_SEPARATOR.'shared')
            ->and($resolver->environmentPath($base))->toBe($root.DIRECTORY_SEPARATOR.'shared')
            ->and($resolver->storagePath($base))->toBe($root.DIRECTORY_SEPARATOR.'shared'.DIRECTORY_SEPARATOR.'storage');
    } finally {
        removeDeployLayout($root);
    }
});

it('falls back to the application defaults when there is no shared directory', function () {
    $root = makeDeployLayout(withShared: false);
    $base = $root.DIRECTORY_SEPARATOR.'public_html';

    try {
        $resolver = SharedDirectory::forBasePath($base);

        expect($resolver->exists())->toBeFalse()
            ->and($resolver->environmentPath($base))->toBe($base)
            ->and($resolver->storagePath($base))->toBe($base.DIRECTORY_SEPARATOR.'storage');
    } finally {
        removeDeployLayout($root);
    }
});

it('does not treat a plain file called shared as the shared directory', function () {
    $root = makeDeployLayout(withShared: false);
    $base = $root.DIRECTORY_SEPARATOR.'public_html';
    file_put_contents($root.DIRECTORY_SEPARATOR.'shared', 'not a directory');

    try {
        expect(SharedDirectory::forBasePath($base)->exists())->toBeFalse();
    } finally {
        removeDeployLayout($root);
    }
});

it('is not active for the local development checkout', function () {
    expect(SharedDirectory::forBasePath(base_path())->exists())->toBeFalse()
        ->and(app()->environmentPath())->toBe(base_path())
        ->and(app()->storagePath())->toBe(base_path('storage'));
});
