<?php

namespace App\Domain\WhatsApp;

use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use ZipArchive;

final class CampaignContactImportService
{
    private const MAX_ROWS = 10000;

    public function __construct(private readonly CampaignAudienceService $audience) {}

    /** @return array<string,mixed> */
    public function preview(UploadedFile $file, User $actor, int $storeId): array
    {
        $extension = strtolower((string) $file->getClientOriginalExtension());
        if (! in_array($extension, ['csv', 'xlsx'], true)) {
            throw ValidationException::withMessages([
                'file' => ['Only CSV and XLSX campaign contact files are supported.'],
            ]);
        }

        $publicId = (string) Str::uuid();
        $stored = $file->storeAs(
            'whatsapp-campaign-imports/'.$publicId,
            'source.'.$extension,
            'local',
        );
        if (! is_string($stored) || $stored === '') {
            throw ValidationException::withMessages(['file' => ['Campaign contact import could not be stored.']]);
        }

        $parsed = $this->parse($stored, $extension);
        $mapping = $this->detectMapping($parsed['headers']);

        $batchId = (int) DB::table('whats_app_campaign_contact_imports')->insertGetId([
            'public_id' => $publicId,
            'actor_user_id' => $actor->getKey(),
            'store_id' => $storeId,
            'channel' => null,
            'source_type' => $extension,
            'original_filename' => mb_substr($file->getClientOriginalName(), 0, 255),
            'stored_path' => $stored,
            'status' => 'preview',
            'rows_total' => count($parsed['rows']),
            'created_count' => 0,
            'matched_count' => 0,
            'updated_count' => 0,
            'skipped_count' => 0,
            'invalid_count' => 0,
            'summary' => json_encode([
                'headers' => $parsed['headers'],
                'formula_rows' => $parsed['formula_rows'],
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return [
            'batch_id' => $batchId,
            'public_id' => $publicId,
            'headers' => $parsed['headers'],
            'mapping' => $mapping,
            'rows_total' => count($parsed['rows']),
            'formula_rows' => $parsed['formula_rows'],
            'sample' => array_slice($parsed['rows'], 0, 20),
        ];
    }

    /** @param array{name?:string,phone?:string,email?:string|null} $mapping
     * @return array<string,mixed>
     */
    public function commit(
        int $batchId,
        User $actor,
        int $storeId,
        array $mapping,
        ?string $consentState,
        ?string $consentSource,
        ?string $consentEvidence,
    ): array {
        $batch = DB::table('whats_app_campaign_contact_imports')
            ->where('id', $batchId)
            ->where('actor_user_id', $actor->getKey())
            ->first();
        if ($batch === null || $batch->status !== 'preview') {
            throw ValidationException::withMessages(['batch_id' => ['Import preview is missing, expired or already committed.']]);
        }
        if ((int) $batch->store_id !== $storeId) {
            throw ValidationException::withMessages(['store_id' => ['Import store cannot change after preview.']]);
        }

        $extension = strtolower((string) $batch->source_type);
        $path = trim((string) $batch->stored_path);
        if ($path === '' || ! Storage::disk('local')->exists($path)) {
            throw ValidationException::withMessages(['batch_id' => ['Import source file is no longer available.']]);
        }

        $parsed = $this->parse($path, $extension);
        $headers = $parsed['headers'];
        $mapping = $this->validatedMapping($headers, $mapping);
        $rows = [];
        $formulaInvalid = 0;

        foreach ($parsed['rows'] as $row) {
            if (($row['__formula'] ?? false) === true) {
                $formulaInvalid++;
                $rows[] = ['name' => null, 'phone' => null, 'email' => null];
                continue;
            }
            $rows[] = [
                'name' => $row[$mapping['name']] ?? null,
                'phone' => $row[$mapping['phone']] ?? null,
                'email' => $mapping['email'] === null ? null : ($row[$mapping['email']] ?? null),
            ];
        }

        $result = $this->audience->importRows(
            $actor,
            $rows,
            $storeId,
            'campaign_import',
            $consentState,
            $consentSource,
            $consentEvidence,
        );

        DB::table('whats_app_campaign_contact_imports')->where('id', $batchId)->update([
            'channel' => DB::table('stores')
                ->join('store_types', 'store_types.id', '=', 'stores.store_type_id')
                ->where('stores.id', $storeId)
                ->value(DB::raw('LOWER(store_types.code)')),
            'status' => 'committed',
            'created_count' => $result['created'],
            'matched_count' => $result['matched'],
            'updated_count' => $result['updated'],
            'skipped_count' => $result['skipped'],
            'invalid_count' => $result['invalid'],
            'summary' => json_encode([
                'mapping' => $mapping,
                'formula_rows' => $parsed['formula_rows'],
                'errors' => array_slice($result['errors'], 0, 200),
                'customer_ids' => $result['customer_ids'],
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'updated_at' => now(),
        ]);

        return $result + [
            'batch_id' => $batchId,
            'formula_invalid' => $formulaInvalid,
        ];
    }

    /** @return array{headers:list<string>,rows:list<array<string,mixed>>,formula_rows:int} */
    private function parse(string $path, string $extension): array
    {
        return $extension === 'xlsx'
            ? $this->parseXlsx($path)
            : $this->parseCsv($path);
    }

    /** @return array{headers:list<string>,rows:list<array<string,mixed>>,formula_rows:int} */
    private function parseCsv(string $path): array
    {
        $handle = fopen(Storage::disk('local')->path($path), 'rb');
        if ($handle === false) {
            throw ValidationException::withMessages(['file' => ['CSV file could not be opened.']]);
        }

        try {
            $first = fgetcsv($handle);
            if (! is_array($first)) {
                throw ValidationException::withMessages(['file' => ['CSV file has no header row.']]);
            }
            $headers = $this->cleanHeaders($first);
            $rows = [];
            while (($values = fgetcsv($handle)) !== false) {
                if (count($rows) >= self::MAX_ROWS) {
                    throw ValidationException::withMessages(['file' => ['Campaign imports are limited to '.self::MAX_ROWS.' rows.']]);
                }
                if (! is_array($values) || $this->blankRow($values)) {
                    continue;
                }
                $row = [];
                foreach ($headers as $index => $header) {
                    $row[$header] = isset($values[$index]) ? trim((string) $values[$index]) : '';
                }
                $rows[] = $row;
            }
        } finally {
            fclose($handle);
        }

        return ['headers' => $headers, 'rows' => $rows, 'formula_rows' => 0];
    }

    /** @return array{headers:list<string>,rows:list<array<string,mixed>>,formula_rows:int} */
    private function parseXlsx(string $path): array
    {
        if (! class_exists(ZipArchive::class)) {
            throw ValidationException::withMessages(['file' => ['XLSX import requires the PHP ZIP extension.']]);
        }

        $zip = new ZipArchive();
        if ($zip->open(Storage::disk('local')->path($path)) !== true) {
            throw ValidationException::withMessages(['file' => ['XLSX file is invalid or unreadable.']]);
        }

        try {
            $shared = $this->sharedStrings($zip);
            $sheetXml = $zip->getFromName('xl/worksheets/sheet1.xml');
            if (! is_string($sheetXml) || $sheetXml === '') {
                throw ValidationException::withMessages(['file' => ['XLSX first worksheet is missing.']]);
            }

            libxml_use_internal_errors(true);
            $sheet = simplexml_load_string($sheetXml);
            if ($sheet === false) {
                throw ValidationException::withMessages(['file' => ['XLSX worksheet XML is invalid.']]);
            }

            $namespaces = $sheet->getNamespaces(true);
            $main = isset($namespaces['']) ? $sheet->children($namespaces['']) : $sheet;

            $matrix = [];
            $formulaRows = 0;
            foreach ($main->sheetData->row as $rowNodeRaw) {
                $rowNode = isset($namespaces['']) ? $rowNodeRaw->children($namespaces['']) : $rowNodeRaw;
                $values = [];
                $hasFormula = false;
                foreach ($rowNode->c as $cellRaw) {
                    $cell = isset($namespaces['']) ? $cellRaw->children($namespaces['']) : $cellRaw;
                    $attributes = $cell->attributes();
                    $reference = (string) ($attributes['r'] ?? '');
                    $index = $this->columnIndex($reference);
                    if (isset($cell->f)) {
                        $hasFormula = true;
                    }
                    $values[$index] = $this->xlsxCellValue($cell, $shared);
                }
                if ($values === []) {
                    continue;
                }
                ksort($values);
                $max = max(array_keys($values));
                $dense = [];
                for ($i = 0; $i <= $max; $i++) {
                    $dense[] = trim((string) ($values[$i] ?? ''));
                }
                if ($this->blankRow($dense)) {
                    continue;
                }
                $matrix[] = ['values' => $dense, 'formula' => $hasFormula];
                if ($hasFormula) {
                    $formulaRows++;
                }
                if (count($matrix) > self::MAX_ROWS + 1) {
                    throw ValidationException::withMessages(['file' => ['Campaign imports are limited to '.self::MAX_ROWS.' rows.']]);
                }
            }

            if ($matrix === []) {
                throw ValidationException::withMessages(['file' => ['XLSX file contains no rows.']]);
            }

            $headers = $this->cleanHeaders($matrix[0]['values']);
            $rows = [];
            foreach (array_slice($matrix, 1) as $entry) {
                $row = ['__formula' => (bool) $entry['formula']];
                foreach ($headers as $index => $header) {
                    $row[$header] = $entry['values'][$index] ?? '';
                }
                $rows[] = $row;
            }

            return ['headers' => $headers, 'rows' => $rows, 'formula_rows' => $formulaRows];
        } finally {
            $zip->close();
            libxml_clear_errors();
        }
    }

    /** @return list<string> */
    private function sharedStrings(ZipArchive $zip): array
    {
        $xml = $zip->getFromName('xl/sharedStrings.xml');
        if (! is_string($xml) || $xml === '') {
            return [];
        }

        libxml_use_internal_errors(true);
        $root = simplexml_load_string($xml);
        if ($root === false) {
            return [];
        }

        $namespaces = $root->getNamespaces(true);
        $main = isset($namespaces['']) ? $root->children($namespaces['']) : $root;
        $values = [];
        foreach ($main->si as $siRaw) {
            $si = isset($namespaces['']) ? $siRaw->children($namespaces['']) : $siRaw;
            if (isset($si->t)) {
                $values[] = (string) $si->t;
                continue;
            }
            $text = '';
            foreach ($si->r as $runRaw) {
                $run = isset($namespaces['']) ? $runRaw->children($namespaces['']) : $runRaw;
                $text .= (string) $run->t;
            }
            $values[] = $text;
        }

        return $values;
    }

    private function xlsxCellValue(\SimpleXMLElement $cell, array $shared): string
    {
        $type = (string) ($cell->attributes()['t'] ?? '');
        if ($type === 'inlineStr') {
            if (isset($cell->is->t)) {
                return (string) $cell->is->t;
            }
            $value = '';
            foreach ($cell->is->r as $run) {
                $value .= (string) $run->t;
            }

            return $value;
        }

        $raw = (string) ($cell->v ?? '');
        if ($type === 's' && ctype_digit($raw)) {
            return (string) ($shared[(int) $raw] ?? '');
        }
        if ($type === 'b') {
            return $raw === '1' ? '1' : '0';
        }

        return $raw;
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

    /** @param list<mixed> $raw
     * @return list<string>
     */
    private function cleanHeaders(array $raw): array
    {
        $headers = [];
        $used = [];
        foreach ($raw as $index => $value) {
            $name = trim((string) $value);
            $name = $name !== '' ? $name : 'column_'.($index + 1);
            $base = $name;
            $suffix = 1;
            while (isset($used[mb_strtolower($name)])) {
                $suffix++;
                $name = $base.'_'.$suffix;
            }
            $used[mb_strtolower($name)] = true;
            $headers[] = $name;
        }

        return $headers;
    }

    /** @param list<mixed> $values */
    private function blankRow(array $values): bool
    {
        foreach ($values as $value) {
            if (trim((string) $value) !== '') {
                return false;
            }
        }

        return true;
    }

    /** @param list<string> $headers
     * @return array{name:?string,phone:?string,email:?string}
     */
    private function detectMapping(array $headers): array
    {
        $aliases = [
            'name' => ['name', 'customer name', 'customer_name', 'full name', 'fullname', 'الاسم', 'اسم العميل'],
            'phone' => ['phone', 'mobile', 'whatsapp', 'phone number', 'phone_number', 'رقم الهاتف', 'الهاتف', 'الموبايل', 'واتساب'],
            'email' => ['email', 'e-mail', 'mail', 'البريد', 'البريد الالكتروني', 'البريد الإلكتروني'],
        ];

        $mapping = ['name' => null, 'phone' => null, 'email' => null];
        foreach ($headers as $header) {
            $normalized = mb_strtolower(trim($header));
            foreach ($aliases as $field => $values) {
                if ($mapping[$field] === null && in_array($normalized, $values, true)) {
                    $mapping[$field] = $header;
                }
            }
        }

        return $mapping;
    }

    /** @param list<string> $headers
     * @param array{name?:string,phone?:string,email?:string|null} $mapping
     * @return array{name:string,phone:string,email:?string}
     */
    private function validatedMapping(array $headers, array $mapping): array
    {
        $detected = $this->detectMapping($headers);
        $name = trim((string) ($mapping['name'] ?? $detected['name'] ?? ''));
        $phone = trim((string) ($mapping['phone'] ?? $detected['phone'] ?? ''));
        $emailValue = $mapping['email'] ?? $detected['email'];
        $email = $emailValue === null ? null : trim((string) $emailValue);
        if ($name === '' || $phone === '' || ! in_array($name, $headers, true) || ! in_array($phone, $headers, true)) {
            throw ValidationException::withMessages([
                'mapping' => ['Map a valid name column and phone column before importing.'],
            ]);
        }
        if ($email !== null && $email !== '' && ! in_array($email, $headers, true)) {
            throw ValidationException::withMessages(['mapping.email' => ['Email mapping is not a valid file column.']]);
        }

        return ['name' => $name, 'phone' => $phone, 'email' => $email === '' ? null : $email];
    }
}
