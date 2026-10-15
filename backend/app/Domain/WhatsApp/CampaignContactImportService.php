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

    private const MAX_XLSX_XML_BYTES = 20971520;

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

    /**
     * @param  array{name?:string,phone?:string,email?:string|null,consent_state?:string|null,consent_source?:string|null,consent_evidence?:string|null,consent_date?:string|null}  $mapping
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
                $rows[] = [
                    'name' => null,
                    'phone' => null,
                    'email' => null,
                    'consent_state' => null,
                    'consent_source' => null,
                    'consent_evidence' => null,
                    'consent_date' => null,
                ];

                continue;
            }
            $rows[] = [
                'name' => $row[$mapping['name']] ?? null,
                'phone' => $row[$mapping['phone']] ?? null,
                'email' => $mapping['email'] === null ? null : ($row[$mapping['email']] ?? null),
                'consent_state' => $mapping['consent_state'] === null ? null : ($row[$mapping['consent_state']] ?? null),
                'consent_source' => $mapping['consent_source'] === null ? null : ($row[$mapping['consent_source']] ?? null),
                'consent_evidence' => $mapping['consent_evidence'] === null ? null : ($row[$mapping['consent_evidence']] ?? null),
                'consent_date' => $mapping['consent_date'] === null ? null : ($row[$mapping['consent_date']] ?? null),
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
            $batchId,
        );

        DB::table('whats_app_campaign_contact_imports')->where('id', $batchId)->update([
            'channel' => strtolower((string) DB::table('stores')
                ->join('store_types', 'store_types.id', '=', 'stores.store_type_id')
                ->where('stores.id', $storeId)
                ->value('store_types.code')),
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
                if ($this->blankRow($values)) {
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

        $zip = new ZipArchive;
        if ($zip->open(Storage::disk('local')->path($path)) !== true) {
            throw ValidationException::withMessages(['file' => ['XLSX file is invalid or unreadable.']]);
        }

        try {
            $this->assertEntrySize($zip, 'xl/worksheets/sheet1.xml');
            $this->assertEntrySize($zip, 'xl/sharedStrings.xml', optional: true);
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
            $namespace = (string) ($namespaces[''] ?? 'http://schemas.openxmlformats.org/spreadsheetml/2006/main');
            $sheetChildren = $sheet->children($namespace);
            $sheetData = $sheetChildren->sheetData;

            $matrix = [];
            $formulaRows = 0;
            foreach ($sheetData->children($namespace)->row as $rowNode) {
                $rowChildren = $rowNode->children($namespace);
                $values = [];
                $hasFormula = false;
                foreach ($rowChildren->c as $cell) {
                    $cellChildren = $cell->children($namespace);
                    $attributes = $cell->attributes();
                    $reference = (string) ($attributes['r'] ?? '');
                    $index = $this->columnIndex($reference);
                    if (isset($cellChildren->f)) {
                        $hasFormula = true;
                    }
                    $values[$index] = $this->xlsxCellValue($cell, $shared, $namespace);
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

    private function assertEntrySize(ZipArchive $zip, string $name, bool $optional = false): void
    {
        $stat = $zip->statName($name);
        if ($stat === false) {
            if ($optional) {
                return;
            }
            throw ValidationException::withMessages(['file' => ['Required XLSX worksheet data is missing.']]);
        }

        $size = (int) $stat['size'];
        if ($size < 0 || $size > self::MAX_XLSX_XML_BYTES) {
            throw ValidationException::withMessages([
                'file' => ['XLSX worksheet data exceeds the safe uncompressed size limit.'],
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

        libxml_use_internal_errors(true);
        $root = simplexml_load_string($xml);
        if ($root === false) {
            return [];
        }

        $namespaces = $root->getNamespaces(true);
        $namespace = (string) ($namespaces[''] ?? 'http://schemas.openxmlformats.org/spreadsheetml/2006/main');
        $main = $root->children($namespace);
        $values = [];
        foreach ($main->si as $si) {
            $siChildren = $si->children($namespace);
            if (isset($siChildren->t)) {
                $values[] = (string) $siChildren->t;

                continue;
            }
            $text = '';
            foreach ($siChildren->r as $run) {
                $runChildren = $run->children($namespace);
                $text .= (string) $runChildren->t;
            }
            $values[] = $text;
        }

        return $values;
    }

    private function xlsxCellValue(\SimpleXMLElement $cell, array $shared, string $namespace): string
    {
        $type = (string) ($cell->attributes()['t'] ?? '');
        $children = $cell->children($namespace);
        if ($type === 'inlineStr') {
            $inline = $children->is;
            $inlineChildren = $inline->children($namespace);
            if (isset($inlineChildren->t)) {
                return (string) $inlineChildren->t;
            }
            $value = '';
            foreach ($inlineChildren->r as $run) {
                $runChildren = $run->children($namespace);
                $value .= (string) $runChildren->t;
            }

            return $value;
        }

        $raw = (string) ($children->v ?? '');
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

    /**
     * @param  list<string>  $headers
     * @return array{name:?string,phone:?string,email:?string,consent_state:?string,consent_source:?string,consent_evidence:?string,consent_date:?string}
     */
    private function detectMapping(array $headers): array
    {
        $aliases = [
            'name' => ['name', 'customer name', 'customer_name', 'full name', 'fullname', 'الاسم', 'اسم العميل'],
            'phone' => ['phone', 'mobile', 'whatsapp', 'phone number', 'phone_number', 'رقم الهاتف', 'الهاتف', 'الموبايل', 'واتساب'],
            'email' => ['email', 'e-mail', 'mail', 'البريد', 'البريد الالكتروني', 'البريد الإلكتروني'],
            'consent_state' => ['consent state', 'consent_state', 'marketing consent', 'marketing_consent', 'حالة الموافقة', 'الموافقة التسويقية'],
            'consent_source' => ['consent source', 'consent_source', 'مصدر الموافقة'],
            'consent_evidence' => ['consent evidence', 'consent_evidence', 'دليل الموافقة'],
            'consent_date' => ['consent date', 'consent_date', 'consented at', 'consented_at', 'تاريخ الموافقة'],
        ];

        $mapping = [
            'name' => null,
            'phone' => null,
            'email' => null,
            'consent_state' => null,
            'consent_source' => null,
            'consent_evidence' => null,
            'consent_date' => null,
        ];
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

    /**
     * @param  list<string>  $headers
     * @param  array{name?:string,phone?:string,email?:string|null,consent_state?:string|null,consent_source?:string|null,consent_evidence?:string|null,consent_date?:string|null}  $mapping
     * @return array{name:string,phone:string,email:?string,consent_state:?string,consent_source:?string,consent_evidence:?string,consent_date:?string}
     */
    private function validatedMapping(array $headers, array $mapping): array
    {
        $detected = $this->detectMapping($headers);
        $name = trim((string) ($mapping['name'] ?? $detected['name'] ?? ''));
        $phone = trim((string) ($mapping['phone'] ?? $detected['phone'] ?? ''));
        $emailValue = $mapping['email'] ?? $detected['email'];
        $email = $emailValue === null ? null : trim((string) $emailValue);
        $optional = [];
        foreach (['consent_state', 'consent_source', 'consent_evidence', 'consent_date'] as $field) {
            $value = $mapping[$field] ?? $detected[$field];
            $optional[$field] = $value === null ? null : trim((string) $value);
        }

        if ($name === '' || $phone === '' || ! in_array($name, $headers, true) || ! in_array($phone, $headers, true)) {
            throw ValidationException::withMessages([
                'mapping' => ['Map a valid name column and phone column before importing.'],
            ]);
        }
        if ($email !== null && $email !== '' && ! in_array($email, $headers, true)) {
            throw ValidationException::withMessages(['mapping.email' => ['Email mapping is not a valid file column.']]);
        }
        foreach ($optional as $field => $value) {
            if ($value !== null && $value !== '' && ! in_array($value, $headers, true)) {
                throw ValidationException::withMessages([
                    'mapping.'.$field => ['Consent mapping is not a valid file column.'],
                ]);
            }
            $optional[$field] = $value === '' ? null : $value;
        }

        return [
            'name' => $name,
            'phone' => $phone,
            'email' => $email === '' ? null : $email,
            'consent_state' => $optional['consent_state'],
            'consent_source' => $optional['consent_source'],
            'consent_evidence' => $optional['consent_evidence'],
            'consent_date' => $optional['consent_date'],
        ];
    }
}
