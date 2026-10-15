<?php

namespace App\Domain\RestaurantSaaS;

use App\Models\Store;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use ZipArchive;

final class RestaurantMenuImportPreviewService
{
    private const MAX_ROWS = 5000;

    private const MAX_XLSX_XML_BYTES = 20971520;

    /** @return array<string, mixed> */
    public function stage(Store $store, User $actor, UploadedFile $file): array
    {
        $extension = strtolower((string) $file->getClientOriginalExtension());
        if (! in_array($extension, ['csv', 'xlsx'], true)) {
            throw ValidationException::withMessages([
                'menu_import' => [__('restaurant_onboarding.validation.import_format')],
            ]);
        }

        $realPath = $file->getRealPath();
        if (! is_string($realPath) || $realPath === '') {
            throw ValidationException::withMessages([
                'menu_import' => [__('restaurant_onboarding.validation.import_unreadable')],
            ]);
        }

        $preview = $extension === 'xlsx'
            ? $this->previewXlsx($realPath)
            : $this->previewCsv($realPath);

        $publicId = (string) Str::uuid();
        $stored = $file->storeAs(
            'restaurant-onboarding/'.$store->getKey().'/'.$publicId,
            'source.'.$extension,
            'local',
        );

        if (! is_string($stored) || $stored === '') {
            throw ValidationException::withMessages([
                'menu_import' => [__('restaurant_onboarding.validation.import_store_failed')],
            ]);
        }

        DB::table('restaurant_onboarding_imports')->insert([
            'public_id' => $publicId,
            'store_id' => $store->getKey(),
            'actor_user_id' => $actor->getKey(),
            'format' => $extension,
            'original_filename' => mb_substr($file->getClientOriginalName(), 0, 255),
            'stored_path' => $stored,
            'status' => 'REVIEW_REQUIRED',
            'rows_total' => $preview['rows_total'],
            'formula_rows' => $preview['formula_rows'],
            'headers' => json_encode($preview['headers'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'sample' => json_encode($preview['sample'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return [
            'public_id' => $publicId,
            'format' => $extension,
            'original_filename' => $file->getClientOriginalName(),
            'status' => 'REVIEW_REQUIRED',
            ...$preview,
        ];
    }

    /** @return array{headers:list<string>,sample:list<list<string>>,rows_total:int,formula_rows:int} */
    private function previewCsv(string $path): array
    {
        $handle = fopen($path, 'rb');
        if ($handle === false) {
            throw ValidationException::withMessages([
                'menu_import' => [__('restaurant_onboarding.validation.import_unreadable')],
            ]);
        }

        try {
            $headerRow = fgetcsv($handle);
            if (! is_array($headerRow) || $headerRow === []) {
                throw ValidationException::withMessages([
                    'menu_import' => [__('restaurant_onboarding.validation.import_headers')],
                ]);
            }

            $headers = $this->headers($headerRow);
            $sample = [];
            $rowsTotal = 0;
            $formulaRows = 0;

            while (($row = fgetcsv($handle)) !== false) {
                if ($this->blank($row)) {
                    continue;
                }

                $rowsTotal++;
                if ($rowsTotal > self::MAX_ROWS) {
                    throw ValidationException::withMessages([
                        'menu_import' => [__('restaurant_onboarding.validation.import_rows', ['max' => self::MAX_ROWS])],
                    ]);
                }

                $values = $this->normalizeRow($row, count($headers));
                if ($this->hasFormulaLikeValue($values)) {
                    $formulaRows++;
                }

                if (count($sample) < 20) {
                    $sample[] = $values;
                }
            }

            return [
                'headers' => $headers,
                'sample' => $sample,
                'rows_total' => $rowsTotal,
                'formula_rows' => $formulaRows,
            ];
        } finally {
            fclose($handle);
        }
    }

    /** @return array{headers:list<string>,sample:list<list<string>>,rows_total:int,formula_rows:int} */
    private function previewXlsx(string $path): array
    {
        if (! class_exists(ZipArchive::class)) {
            throw ValidationException::withMessages([
                'menu_import' => [__('restaurant_onboarding.validation.xlsx_zip')],
            ]);
        }

        $zip = new ZipArchive;
        if ($zip->open($path) !== true) {
            throw ValidationException::withMessages([
                'menu_import' => [__('restaurant_onboarding.validation.import_unreadable')],
            ]);
        }

        try {
            $this->assertEntry($zip, 'xl/worksheets/sheet1.xml');
            $this->assertEntry($zip, 'xl/sharedStrings.xml', true);
            $sheetXml = $zip->getFromName('xl/worksheets/sheet1.xml');
            if (! is_string($sheetXml) || $sheetXml === '') {
                throw ValidationException::withMessages([
                    'menu_import' => [__('restaurant_onboarding.validation.xlsx_sheet')],
                ]);
            }

            $shared = $this->sharedStrings($zip);
            libxml_use_internal_errors(true);
            $sheet = simplexml_load_string($sheetXml, \SimpleXMLElement::class, LIBXML_NONET);
            if ($sheet === false) {
                throw ValidationException::withMessages([
                    'menu_import' => [__('restaurant_onboarding.validation.import_unreadable')],
                ]);
            }

            $namespace = (string) (($sheet->getNamespaces(true)[''] ?? null)
                ?: 'http://schemas.openxmlformats.org/spreadsheetml/2006/main');
            $rows = [];
            foreach ($sheet->children($namespace)->sheetData->children($namespace)->row as $rowNode) {
                $values = [];
                $hasFormula = false;
                foreach ($rowNode->children($namespace)->c as $cell) {
                    $reference = (string) ($cell->attributes()['r'] ?? '');
                    $index = $this->columnIndex($reference);
                    $children = $cell->children($namespace);
                    $hasFormula = $hasFormula || isset($children->f);
                    $values[$index] = $this->cellValue($cell, $shared, $namespace);
                }

                if ($values === []) {
                    continue;
                }

                ksort($values);
                $dense = [];
                $max = max(array_keys($values));
                for ($index = 0; $index <= $max; $index++) {
                    $dense[] = trim((string) ($values[$index] ?? ''));
                }

                if ($this->blank($dense)) {
                    continue;
                }

                $rows[] = ['values' => $dense, 'formula' => $hasFormula];
                if (count($rows) > self::MAX_ROWS + 1) {
                    throw ValidationException::withMessages([
                        'menu_import' => [__('restaurant_onboarding.validation.import_rows', ['max' => self::MAX_ROWS])],
                    ]);
                }
            }

            if ($rows === []) {
                throw ValidationException::withMessages([
                    'menu_import' => [__('restaurant_onboarding.validation.import_headers')],
                ]);
            }

            $headers = $this->headers($rows[0]['values']);
            $sample = [];
            $formulaRows = 0;
            foreach (array_slice($rows, 1) as $row) {
                if ($row['formula']) {
                    $formulaRows++;
                }
                if (count($sample) < 20) {
                    $sample[] = $this->normalizeRow($row['values'], count($headers));
                }
            }

            return [
                'headers' => $headers,
                'sample' => $sample,
                'rows_total' => max(0, count($rows) - 1),
                'formula_rows' => $formulaRows,
            ];
        } finally {
            $zip->close();
            libxml_clear_errors();
        }
    }

    private function assertEntry(ZipArchive $zip, string $name, bool $optional = false): void
    {
        $stat = $zip->statName($name);
        if ($stat === false) {
            if ($optional) {
                return;
            }

            throw ValidationException::withMessages([
                'menu_import' => [__('restaurant_onboarding.validation.xlsx_sheet')],
            ]);
        }

        if ((int) $stat['size'] > self::MAX_XLSX_XML_BYTES) {
            throw ValidationException::withMessages([
                'menu_import' => [__('restaurant_onboarding.validation.xlsx_size')],
            ]);
        }
    }

    /** @return list<string> */
    private function sharedStrings(ZipArchive $zip): array
    {
        $xml = $zip->getFromName('xl/sharedStrings.xml');
        if (! is_string($xml) || $xml === '') {
            return [];
        }

        $root = simplexml_load_string($xml, \SimpleXMLElement::class, LIBXML_NONET);
        if ($root === false) {
            return [];
        }

        $namespace = (string) (($root->getNamespaces(true)[''] ?? null)
            ?: 'http://schemas.openxmlformats.org/spreadsheetml/2006/main');
        $values = [];
        foreach ($root->children($namespace)->si as $node) {
            $children = $node->children($namespace);
            if (isset($children->t)) {
                $values[] = (string) $children->t;

                continue;
            }

            $value = '';
            foreach ($children->r as $run) {
                $value .= (string) $run->children($namespace)->t;
            }
            $values[] = $value;
        }

        return $values;
    }

    /** @param list<string> $shared */
    private function cellValue(\SimpleXMLElement $cell, array $shared, string $namespace): string
    {
        $type = (string) ($cell->attributes()['t'] ?? '');
        $children = $cell->children($namespace);

        if ($type === 'inlineStr') {
            return trim((string) $children->is->children($namespace)->t);
        }

        $raw = (string) ($children->v ?? '');
        if ($type === 's' && ctype_digit($raw)) {
            return (string) ($shared[(int) $raw] ?? '');
        }

        return $raw;
    }

    /** @param list<mixed> $raw
     * @return list<string>
     */
    private function headers(array $raw): array
    {
        if (count($raw) > 60) {
            throw ValidationException::withMessages([
                'menu_import' => [__('restaurant_onboarding.validation.import_columns')],
            ]);
        }

        $headers = [];
        $used = [];
        foreach ($raw as $index => $value) {
            $name = trim((string) $value);
            $name = $name !== '' ? mb_substr($name, 0, 80) : 'column_'.($index + 1);
            $key = mb_strtolower($name);
            if (isset($used[$key])) {
                throw ValidationException::withMessages([
                    'menu_import' => [__('restaurant_onboarding.validation.import_duplicate_header', ['header' => $name])],
                ]);
            }
            $used[$key] = true;
            $headers[] = $name;
        }

        return $headers;
    }

    /** @param list<mixed> $row
     * @return list<string>
     */
    private function normalizeRow(array $row, int $width): array
    {
        $values = [];
        for ($index = 0; $index < $width; $index++) {
            $values[] = mb_substr(trim((string) ($row[$index] ?? '')), 0, 500);
        }

        return $values;
    }

    /** @param list<mixed> $row */
    private function blank(array $row): bool
    {
        foreach ($row as $value) {
            if (trim((string) $value) !== '') {
                return false;
            }
        }

        return true;
    }

    /** @param list<string> $row */
    private function hasFormulaLikeValue(array $row): bool
    {
        foreach ($row as $value) {
            if (preg_match('/^[=+\-@]/', ltrim($value)) === 1) {
                return true;
            }
        }

        return false;
    }

    private function columnIndex(string $reference): int
    {
        preg_match('/^([A-Z]+)/i', $reference, $matches);
        $letters = strtoupper((string) ($matches[1] ?? 'A'));
        $index = 0;
        foreach (str_split($letters) as $letter) {
            $index = ($index * 26) + (ord($letter) - 64);
        }

        return max(0, $index - 1);
    }
}
