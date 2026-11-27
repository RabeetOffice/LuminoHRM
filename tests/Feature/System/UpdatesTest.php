<?php

use App\Enums\DeploymentStatus;
use App\Models\ActivityEntry;
use App\Models\Deployment;
use App\Support\System\Changelog;
use App\Support\System\DatabaseBackup;
use App\Support\System\SystemUpdater;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;

/*
 * System > Updates: new code arrives by itself, its database changes wait for an Admin, who applies them with
 * "Update system" (backup first, then migrate, access sync, caches, version recorded). Nobody else sees any of it.
 */

const UPDATE_TEST_MIGRATION = '2099_01_01_000000_update_test_change';

beforeEach(function () {
    $this->withoutVite(); // the pages are built once by the coordinator
    Cache::flush();
    Changelog::forget();
    $this->admin = userWithRole('admin');
    $this->version = Changelog::latestVersion();
});

afterEach(function () {
    if (isset($this->migrationDir)) {
        File::deleteDirectory($this->migrationDir);
    }
});

/** A database change that creates one small table stands in for a real one. */
function waitingMigration(object $test): void
{
    $test->migrationDir = storage_path('framework/testing/update-'.uniqid());
    File::ensureDirectoryExists($test->migrationDir);
    File::put($test->migrationDir.'/'.UPDATE_TEST_MIGRATION.'.php', <<<'PHP'
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('update_test_things', function (Blueprint $table) {
            $table->id();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('update_test_things');
    }
};
PHP);
    app('migrator')->path($test->migrationDir);
}

function backupThat(bool $ok, string $output = '', int $times = 1): void
{
    $backup = Mockery::mock(DatabaseBackup::class);
    $backup->shouldReceive('run')->times($times)->andReturn(['ok' => $ok, 'output' => $output]);
    app()->instance(DatabaseBackup::class, $backup);
}

function noBackupExpected(): void
{
    $backup = Mockery::mock(DatabaseBackup::class);
    $backup->shouldNotReceive('run');
    app()->instance(DatabaseBackup::class, $backup);
}

it('lists the versions newest first, with known change types, and the newest is the code version', function () {
    $releases = Changelog::releases();

    expect($releases)->not->toBeEmpty()
        ->and($this->version)->toBe('1.1.0')
        ->and($releases[0]['title'])->toBe('Updates screen and first sign-in password change')
        ->and($releases[0]['date'])->toMatch('/^\d{4}-\d\d-\d\d$/')
        ->and(array_column(Changelog::since('1.0.0'), 'version'))->not->toContain('1.0.0')->toContain('1.1.0')
        ->and(Changelog::since($this->version))->toBe([])
        ->and(Changelog::since(null))->toHaveCount(1);

    $versions = array_column($releases, 'version');
    $sorted = $versions;
    usort($sorted, fn ($a, $b) => version_compare($b, $a));
    expect($versions)->toBe($sorted);

    foreach ($releases as $release) {
        foreach ($release['changes'] as $change) {
            expect(array_key_exists($change['type'], Changelog::TYPES))->toBeTrue();
        }
    }
});

it('refuses the page and the action to everyone but Admins', function (string $role) {
    $user = userWithRole($role);

    $this->actingAs($user)->get('/system/updates')->assertForbidden();
    $this->actingAs($user)->post('/system/updates', ['password' => 'password'])->assertForbidden();

    expect(Deployment::query()->count())->toBe(0);
})->with(['hr', 'manager', 'employee']);

it('sends guests to sign-in', function () {
    $this->get('/system/updates')->assertRedirect('/login');
    $this->post('/system/updates')->assertRedirect('/login');
});

it('shows Admins that a version is ready before the first update', function () {
    $this->actingAs($this->admin)->get('/system/updates')
        ->assertOk()
        ->assertInertia(fn ($page) => $page->component('system/updates')
            ->where('available', true)
            ->where('codeVersion', $this->version)
            ->where('installedVersion', null)
            ->where('pending', [])
            ->where('failure', null)
            ->where('history', [])
            ->where('releases.0.version', $this->version)
            ->where('releases.0.state', 'waiting')
            ->where('releases.1.state', null)
            ->where('types.new', 'New'));
});

it('applies a version without database changes with no password and no backup, then is up to date', function () {
    noBackupExpected();

    $this->actingAs($this->admin)->post('/system/updates')
        ->assertRedirect()
        ->assertSessionHas('success', 'Lumino HRM is on version '.$this->version.'. No database changes were needed.');

    $deployment = Deployment::query()->sole();
    $updater = app(SystemUpdater::class);

    expect($deployment->status)->toBe(DeploymentStatus::Success)
        ->and($deployment->release)->toBe($this->version)
        ->and($deployment->triggered_by)->toBe($this->admin->id)
        ->and($deployment->migrations_run)->toBe([])
        ->and($deployment->log)->toContain('No database changes')->toContain('Step 3/4')->toContain('Step 4/4')
        ->and($updater->installedVersion())->toBe($this->version)
        ->and($updater->available())->toBeFalse();

    $this->get('/system/updates')->assertInertia(fn ($page) => $page
        ->where('available', false)
        ->where('installedVersion', $this->version)
        ->where('releases.0.state', 'installed')
        ->where('history.0.status', 'success'));

    // Nothing left to apply.
    $this->post('/system/updates')->assertSessionHas('info');
    expect(Deployment::query()->count())->toBe(1);
});

it('backs up, applies a waiting database change, syncs access and records it', function () {
    waitingMigration($this);
    backupThat(true, 'Backup written: lumino-hrm-2026-11-27.sql.gz');

    expect(app(SystemUpdater::class)->pendingMigrations())->toBe([UPDATE_TEST_MIGRATION]);

    $this->actingAs($this->admin)->get('/system/updates')->assertInertia(fn ($page) => $page
        ->where('available', true)
        ->where('pending', [UPDATE_TEST_MIGRATION]));

    // The database will change: the password is asked first.
    $this->post('/system/updates')->assertSessionHasErrors('password');
    $this->post('/system/updates', ['password' => 'wrong'])->assertSessionHasErrors('password');
    expect(Deployment::query()->count())->toBe(0)->and(Schema::hasTable('update_test_things'))->toBeFalse();

    $this->post('/system/updates', ['password' => 'password'])
        ->assertSessionHas('success', 'Lumino HRM is on version '.$this->version.'. 1 database change applied.');

    $deployment = Deployment::query()->sole();

    expect($deployment->status)->toBe(DeploymentStatus::Success)
        ->and($deployment->migrations_run)->toBe([UPDATE_TEST_MIGRATION])
        ->and($deployment->pending_migrations)->toBe([])
        ->and($deployment->log)->toContain('Backup written')->toContain('Step 2/4')->toContain('access_sync roles=4')
        ->and($deployment->finished_at)->not->toBeNull()
        ->and(Schema::hasTable('update_test_things'))->toBeTrue()
        ->and(app(SystemUpdater::class)->pendingMigrations())->toBe([]);

    $entry = ActivityEntry::query()->where('log_name', 'system')->sole();
    expect($entry->description)->toBe('System updated to version '.$this->version)
        ->and($entry->causer_id)->toBe($this->admin->id)
        ->and($entry->properties['attributes']['database changes'])->toBe(1)
        ->and($entry->properties['attributes']['backup'])->toBe('taken');
});

it('changes nothing when the backup fails, then goes on without one only behind the password', function () {
    waitingMigration($this);
    backupThat(false, 'Backup failed: disk full');

    $this->actingAs($this->admin)->post('/system/updates', ['password' => 'password'])
        ->assertSessionHas('error');

    $failed = Deployment::query()->sole();
    expect($failed->status)->toBe(DeploymentStatus::Failed)
        ->and($failed->meta['backup_failed'])->toBeTrue()
        ->and($failed->log)->toContain('disk full')->toContain('nothing was changed')
        ->and(Schema::hasTable('update_test_things'))->toBeFalse()
        ->and(app(SystemUpdater::class)->pendingMigrations())->toBe([UPDATE_TEST_MIGRATION])
        ->and(app(SystemUpdater::class)->installedVersion())->toBeNull()
        ->and(ActivityEntry::query()->where('log_name', 'system')->sole()->description)->toContain('failed');

    $this->get('/system/updates')->assertInertia(fn ($page) => $page
        ->where('failure.id', $failed->id)
        ->where('failure.backupFailed', true)
        ->where('history.0.status', 'failed'));

    // Without a backup: never without the password.
    $this->post('/system/updates', ['without_backup' => true])->assertSessionHasErrors('password');
    expect(Deployment::query()->count())->toBe(1);

    $this->post('/system/updates', ['without_backup' => true, 'password' => 'password'])->assertSessionHas('success');

    $done = Deployment::query()->where('status', DeploymentStatus::Success->value)->sole();
    expect($done->migrations_run)->toBe([UPDATE_TEST_MIGRATION])
        ->and($done->log)->toContain('Backup skipped')
        ->and(Schema::hasTable('update_test_things'))->toBeTrue()
        ->and(app(SystemUpdater::class)->lastFailure())->toBeNull();
});

it('works on a server where the deployments table does not exist yet', function () {
    // The very update that brings the deployments table: the row is first saved once that change has run.
    DB::table('migrations')->where('migration', '2026_11_27_100000_create_deployments_table')->delete();
    Schema::drop('deployments');
    backupThat(true, 'Backup written.');

    expect(SystemUpdater::flag()['available'])->toBeTrue();

    $this->actingAs($this->admin)->get('/system/updates')->assertOk()->assertInertia(fn ($page) => $page
        ->where('available', true)
        ->where('history', [])
        ->where('pending', ['2026_11_27_100000_create_deployments_table']));

    $this->post('/system/updates', ['password' => 'password'])->assertSessionHas('success');

    $deployment = Deployment::query()->sole();
    expect($deployment->status)->toBe(DeploymentStatus::Success)
        ->and($deployment->migrations_run)->toBe(['2026_11_27_100000_create_deployments_table'])
        ->and($deployment->log)->toContain('started by')->toContain('Backup written.');
});

it('shares the update flag with Admins only, cached, and clears it after an update', function () {
    $this->actingAs($this->admin)->get('/dashboard')
        ->assertInertia(fn ($page) => $page->where('updateFlag.available', true)
            ->where('updateFlag.version', $this->version)
            ->where('updateFlag.pending', 0));

    foreach (['hr', 'manager', 'employee'] as $role) {
        $this->actingAs(userWithRole($role))->get('/dashboard')
            ->assertInertia(fn ($page) => $page->where('updateFlag', null));
    }

    noBackupExpected();
    $this->actingAs($this->admin)->post('/system/updates');

    $this->get('/dashboard')->assertInertia(fn ($page) => $page->where('updateFlag.available', false));
});

it('keeps the flag safe when the database cannot answer', function () {
    Cache::flush();
    $updater = Mockery::mock(SystemUpdater::class);
    $updater->shouldReceive('available')->andThrow(new RuntimeException('no tables'));
    app()->instance(SystemUpdater::class, $updater);

    expect(SystemUpdater::flag())->toBe(['available' => false, 'version' => $this->version, 'pending' => 0]);
});

it('prints the install state and the pending count for the deploy script', function () {
    expect(Artisan::call('hrm:install-state'))->toBe(0)
        ->and(trim(Artisan::output()))->toBe('installed');

    expect(Artisan::call('hrm:pending-migrations'))->toBe(0)
        ->and(trim(Artisan::output()))->toBe('0');

    waitingMigration($this);
    Artisan::call('hrm:pending-migrations');
    expect(trim(Artisan::output()))->toBe('1');
});

it('calls a system with no account yet fresh, so the first deploy still migrates', function () {
    DB::table('model_has_roles')->delete();
    DB::table('users')->delete();

    Artisan::call('hrm:install-state');
    expect(trim(Artisan::output()))->toBe('fresh');

    DB::table('migrations')->delete();
    Artisan::call('hrm:install-state');
    expect(trim(Artisan::output()))->toBe('fresh');
});
