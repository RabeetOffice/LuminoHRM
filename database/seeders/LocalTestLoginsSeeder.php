<?php

namespace Database\Seeders;

use App\Models\Employee;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use RuntimeException;

/**
 * Local-only test logins for checking each role in the browser:
 *   testadmin@lumino.test   Admin    (no employee record)
 *   testhr@lumino.test      HR       linked to employee LD-0004
 *   testmanager@lumino.test Manager  linked to employee LD-0001 (has direct reports)
 *   testemployee@lumino.test Employee linked to employee LD-0003 (reports to LD-0001)
 *
 * The shared password is read from LOCAL_TEST_PASSWORD in .env (git-ignored) via config('hrm.local_test_password'),
 * never committed.
 * Refuses to run unless APP_ENV=local. Safe to re-run: accounts are updated, not duplicated.
 *
 *   C:\php84\php.exe artisan db:seed --class=LocalTestLoginsSeeder
 */
class LocalTestLoginsSeeder extends Seeder
{
    public function run(): void
    {
        if (! app()->environment('local')) {
            throw new RuntimeException('LocalTestLoginsSeeder only runs when APP_ENV=local.');
        }

        $password = (string) config('hrm.local_test_password', '');
        if (strlen($password) < 12) {
            throw new RuntimeException('Set LOCAL_TEST_PASSWORD (12+ characters) in .env first.');
        }

        $logins = [
            ['email' => 'testadmin@lumino.test', 'name' => 'Test Admin', 'role' => 'admin', 'code' => null],
            ['email' => 'testhr@lumino.test', 'name' => 'Test HR', 'role' => 'hr', 'code' => 'LD-0004'],
            ['email' => 'testmanager@lumino.test', 'name' => 'Test Manager', 'role' => 'manager', 'code' => 'LD-0001'],
            ['email' => 'testemployee@lumino.test', 'name' => 'Test Employee', 'role' => 'employee', 'code' => 'LD-0003'],
        ];

        foreach ($logins as $login) {
            $employee = $login['code'] === null ? null : Employee::where('employee_code', $login['code'])->first();
            if ($login['code'] !== null && $employee === null) {
                $this->command?->warn("Skipped {$login['email']}: employee {$login['code']} not found.");

                continue;
            }

            if ($employee !== null) {
                $taken = User::where('employee_id', $employee->id)->where('email', '!=', $login['email'])->first();
                if ($taken !== null) {
                    $this->command?->warn("Skipped {$login['email']}: {$login['code']} already has login {$taken->email}.");

                    continue;
                }
            }

            $user = User::firstOrNew(['email' => $login['email']]);
            $user->name = $login['name'];
            $user->password = Hash::make($password);
            $user->is_active = true;
            $user->forceFill(['employee_id' => $employee?->id, 'email_verified_at' => now()]);
            $user->save();
            $user->syncRoles([$login['role']]);

            $this->command?->info("Ready: {$login['email']} ({$login['role']})");
        }
    }
}
