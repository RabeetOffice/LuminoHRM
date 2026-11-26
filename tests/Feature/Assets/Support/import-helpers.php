<?php

// Shared helpers for the asset register import and export tests (Task 7): required by each file, so they are guarded.

use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Testing\TestResponse;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx as XlsxWriter;

if (! defined('ARI_HEADERS')) {
    define('ARI_HEADERS', ['Asset Code', 'Name', 'Category', 'Serial', 'Purchase Date', 'Cost', 'Condition', 'Status', 'Holder Employee Code', 'Assigned Date']);
}

if (! function_exists('ariXlsx')) {
    /**
     * An .xlsx with these headers and rows (ints / floats as number cells, strings as text cells, null left empty).
     *
     * @param  list<list<mixed>>  $rows
     * @param  list<string>|null  $headers
     */
    function ariXlsx(array $rows, ?array $headers = null): UploadedFile
    {
        $book = new Spreadsheet;
        $sheet = $book->getActiveSheet();

        foreach ($headers ?? ARI_HEADERS as $i => $header) {
            $sheet->setCellValueExplicit([$i + 1, 1], $header, DataType::TYPE_STRING);
        }

        foreach ($rows as $r => $row) {
            foreach ($row as $c => $value) {
                if ($value === null) {
                    continue;
                }

                is_string($value)
                    ? $sheet->setCellValueExplicit([$c + 1, $r + 2], $value, DataType::TYPE_STRING)
                    : $sheet->setCellValueExplicit([$c + 1, $r + 2], $value, DataType::TYPE_NUMERIC);
            }
        }

        $path = tempnam(sys_get_temp_dir(), 'ari').'.xlsx';
        (new XlsxWriter($book))->save($path);

        return new UploadedFile($path, 'assets.xlsx', null, null, true);
    }

    /** An uploaded file made from a path on disk (an export read back). */
    function ariFile(string $path): UploadedFile
    {
        $copy = tempnam(sys_get_temp_dir(), 'ari').'.xlsx';
        copy($path, $copy);

        return new UploadedFile($copy, 'assets.xlsx', null, null, true);
    }

    /** Uploads $file as $by and returns the preview props. */
    function ariPreview(User $by, UploadedFile $file): array
    {
        $response = test()->actingAs($by)->post('/assets/import/preview', ['file' => $file]);
        $response->assertSessionHasNoErrors()->assertRedirect();
        parse_str((string) parse_url((string) $response->headers->get('Location'), PHP_URL_QUERY), $query);

        return test()->actingAs($by)->get('/assets/import?token='.$query['token'])->assertOk()->inertiaProps('preview');
    }

    function ariRun(User $by, string $token): TestResponse
    {
        return test()->actingAs($by)->post('/assets/import/run', ['token' => $token]);
    }
}
