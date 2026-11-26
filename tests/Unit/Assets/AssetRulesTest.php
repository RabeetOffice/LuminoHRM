<?php

use App\Models\Asset;
use App\Models\AssetAssignment;
use App\Models\Employee;
use App\Models\User;
use App\Support\Assets\AssetRules;

// The unit suite has no database: users here are plain objects answering can().
function rulesUser(string $role): User
{
    return new class($role) extends User
    {
        public function __construct(private string $roleName) {}

        public function can($abilities, $arguments = []): bool
        {
            return match ($abilities) {
                'assets.manage' => $this->roleName === 'admin',
                'assets.assign' => in_array($this->roleName, ['admin', 'hr'], true),
                default => false,
            };
        }
    };
}

function heldAsset(string $code, string $first, string $last): Asset
{
    $asset = new Asset(['asset_code' => $code]);
    $asset->setRelation('openAssignment', (new AssetAssignment)->setRelation('employee', new Employee(['first_name' => $first, 'last_name' => $last])));

    return $asset;
}

it('follows the status matrix for every cell', function (string $from, string $to, string $role, ?string $expected) {
    expect(AssetRules::statusRefusal(rulesUser($role), $from, $to, false))->toBe($expected);
})->with([
    'available to repair, admin' => ['available', 'in_repair', 'admin', null],
    'available to repair, hr' => ['available', 'in_repair', 'hr', null],
    'repair to available, admin' => ['in_repair', 'available', 'admin', null],
    'repair to available, hr' => ['in_repair', 'available', 'hr', null],
    'available to retired, admin' => ['available', 'retired', 'admin', null],
    'available to retired, hr' => ['available', 'retired', 'hr', 'Only an Admin can retire an asset.'],
    'repair to retired, admin' => ['in_repair', 'retired', 'admin', null],
    'repair to retired, hr' => ['in_repair', 'retired', 'hr', 'Only an Admin can retire an asset.'],
    'retired to available, admin' => ['retired', 'available', 'admin', null],
    'retired to available, hr' => ['retired', 'available', 'hr', 'Only an Admin can bring a retired asset back.'],
    'retired to repair, admin' => ['retired', 'in_repair', 'admin', 'Bring a retired asset back to available first.'],
    'retired to repair, hr' => ['retired', 'in_repair', 'hr', 'Bring a retired asset back to available first.'],
    'available to assigned, admin' => ['available', 'assigned', 'admin', 'Assign the asset to an employee instead.'],
    'repair to assigned, hr' => ['in_repair', 'assigned', 'hr', 'Assign the asset to an employee instead.'],
    'retired to assigned, admin' => ['retired', 'assigned', 'admin', 'Assign the asset to an employee instead.'],
    'same: available' => ['available', 'available', 'admin', 'The asset is already available.'],
    'same: repair' => ['in_repair', 'in_repair', 'hr', 'The asset is already in repair.'],
    'same: retired' => ['retired', 'retired', 'admin', 'The asset is already retired.'],
]);

it('refuses a change on a held asset with the holder named', function (string $to) {
    expect(AssetRules::statusRefusal(rulesUser('admin'), 'assigned', $to, true, heldAsset('AST-0007', 'Sara', 'Khan')))
        ->toBe('AST-0007 is held by Sara Khan. Return it first.');
})->with(['available', 'in_repair', 'retired', 'assigned']);

it('treats an open assignment as held even when the status says otherwise', function () {
    expect(AssetRules::statusRefusal(rulesUser('hr'), 'available', 'in_repair', true, heldAsset('AST-0008', 'Ali', 'Raza')))
        ->toBe('AST-0008 is held by Ali Raza. Return it first.');
});
