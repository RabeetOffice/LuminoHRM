<?php

use App\Imports\EmployeeSheet;
use App\Models\Department;
use App\Models\Designation;
use App\Models\Employee;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx as XlsxWriter;

/*
 * Timing of a full-size import (EmployeeSheet::MAX_ROWS rows): upload, preview and run, with wall time and peak memory.
 * Skipped unless IMPORT_BENCH=1. Meant for a scratch MariaDB database and production bcrypt cost, for example:
 *
 *   IMPORT_BENCH=1 BCRYPT_ROUNDS=12 DB_CONNECTION=mariadb DB_DATABASE=<scratch> DB_USERNAME=... DB_PASSWORD=... \
 *     php vendor/bin/pest tests/Feature/Employees/EmployeeImportBenchTest.php
 *
 * RefreshDatabase migrates that database from scratch: never point it at a database whose data matters.
 * The figures go to STDERR (and to IMPORT_BENCH_OUT when set).
 */
it('imports the maximum number of rows within the time budget', function () {
    $rounds = (int) config('hashing.bcrypt.rounds');
    $admin = userWithRole('admin');
    $eng = Department::factory()->create(['name' => 'Engineering']);
    Designation::factory()->create(['department_id' => $eng->id, 'name' => 'Engineer']);

    $temp = sys_get_temp_dir().'/lumino-bench-'.bin2hex(random_bytes(6));
    config(['excel.temporary_files.local_path' => $temp]);
    File::ensureDirectoryExists($temp);
    Storage::fake('local');

    $count = EmployeeSheet::MAX_ROWS;
    $book = new Spreadsheet;
    $sheet = $book->getActiveSheet();
    foreach (EmployeeSheet::headers() as $i => $header) {
        $sheet->setCellValueExplicit([$i + 1, 1], $header, DataType::TYPE_STRING);
    }
    $columns = array_flip(array_values(EmployeeSheet::COLUMNS));
    for ($n = 1; $n <= $count; $n++) {
        $values = [
            'first_name' => 'Bench', 'last_name' => 'Person'.$n, 'email' => "bench{$n}@corp.test",
            'phone' => '0300-'.str_pad((string) $n, 7, '0', STR_PAD_LEFT), 'dob' => '1990-05-17', 'gender' => 'Female',
            'cnic' => '42201-'.str_pad((string) $n, 7, '0', STR_PAD_LEFT).'-1', 'department' => 'Engineering',
            'designation' => 'Engineer', 'joining_date' => '2024-03-01', 'employment_type' => 'Full Time',
            // Every tenth person reports to the person before them (in-file managers exercise the ordering).
            'manager_email' => $n % 10 === 0 ? 'bench'.($n - 1).'@corp.test' : '',
            'status' => $n % 50 === 0 ? 'Resigned' : 'Active', 'device_user_id' => (string) (1000 + $n),
            'address' => $n.' Garden Road', 'emergency_name' => 'Kin', 'emergency_phone' => '0300-7654321',
            'bank_name' => 'Meezan Bank', 'account_title' => 'Bench '.$n, 'account_number' => 'PK36MEZN'.str_pad((string) $n, 10, '0', STR_PAD_LEFT),
        ];
        foreach ($values as $key => $value) {
            if ($value !== '') {
                $sheet->setCellValueExplicit([$columns[$key] + 1, $n + 1], $value, DataType::TYPE_STRING);
            }
        }
    }
    $path = $temp.'/bench.xlsx';
    (new XlsxWriter($book))->save($path);
    unset($book, $sheet);

    $measure = function (Closure $step): array {
        memory_reset_peak_usage();
        $start = hrtime(true);
        $result = $step();

        return [$result, (hrtime(true) - $start) / 1e9, memory_get_peak_usage(true) / 1048576];
    };

    [$upload, $uploadSeconds, $uploadMb] = $measure(fn () => $this->actingAs($admin)->post('/employees/import/preview', ['file' => new UploadedFile($path, 'bench.xlsx', null, null, true)]));
    $upload->assertSessionHasNoErrors();
    parse_str((string) parse_url((string) $upload->headers->get('Location'), PHP_URL_QUERY), $query);

    [$page, $previewSeconds, $previewMb] = $measure(fn () => $this->actingAs($admin)->get('/employees/import?token='.$query['token']));
    expect($page->viewData('page')['props']['preview']['totals'])->toBe(['rows' => $count, 'ready' => $count, 'errors' => 0]);

    [$run, $runSeconds, $runMb] = $measure(fn () => $this->actingAs($admin)->post('/employees/import/run', ['token' => $query['token']]));
    $run->assertRedirect('/employees');
    expect(Employee::count())->toBe($count);
    expect(User::where('email', 'like', 'bench%')->distinct()->count('password'))->toBe(1);

    $report = sprintf(
        "IMPORT BENCH: %d rows, driver %s, bcrypt rounds %d, PHP %s\n  upload  %.1f s, peak %.0f MB\n  preview %.1f s, peak %.0f MB\n  run     %.1f s, peak %.0f MB\n",
        $count, DB::connection()->getDriverName(), $rounds, PHP_VERSION,
        $uploadSeconds, $uploadMb, $previewSeconds, $previewMb, $runSeconds, $runMb,
    );
    fwrite(STDERR, $report);
    if (is_string($out = getenv('IMPORT_BENCH_OUT')) && $out !== '') {
        file_put_contents($out, $report);
    }

    File::deleteDirectory($temp);

    expect($runSeconds)->toBeLessThan(120);
})->skip(getenv('IMPORT_BENCH') !== '1', 'Set IMPORT_BENCH=1 (and a scratch database) to run the import benchmark.');
