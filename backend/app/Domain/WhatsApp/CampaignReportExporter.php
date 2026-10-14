<?php

namespace App\Domain\WhatsApp;

use Illuminate\Support\Collection;
use RuntimeException;
use ZipArchive;

final class CampaignReportExporter
{
    /** @param Collection<int,object> $rows */
    public function csv(Collection $rows): string
    {
        $stream = fopen('php://temp', 'w+b');
        if ($stream === false) {
            throw new RuntimeException('Unable to open campaign export buffer.');
        }

        fputcsv($stream, $this->headers());
        foreach ($rows as $row) {
            fputcsv($stream, array_map(
                fn (mixed $value): string => $this->csvSafe($value),
                $this->values($row),
            ));
        }

        rewind($stream);
        $contents = stream_get_contents($stream);
        fclose($stream);

        return is_string($contents) ? $contents : '';
    }

    /** @param Collection<int,object> $rows */
    public function xlsx(Collection $rows): string
    {
        if (! class_exists(ZipArchive::class)) {
            throw new RuntimeException('XLSX export requires the PHP ZIP extension.');
        }

        $path = tempnam(sys_get_temp_dir(), 'foodex-wa-report-');
        if (! is_string($path)) {
            throw new RuntimeException('Unable to allocate XLSX export file.');
        }
        @unlink($path);
        $path .= '.xlsx';

        $zip = new ZipArchive();
        if ($zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            throw new RuntimeException('Unable to create XLSX export.');
        }

        try {
            $zip->addFromString('[Content_Types].xml', $this->contentTypes());
            $zip->addFromString('_rels/.rels', $this->rootRelationships());
            $zip->addFromString('xl/workbook.xml', $this->workbook());
            $zip->addFromString('xl/_rels/workbook.xml.rels', $this->workbookRelationships());
            $zip->addFromString('xl/styles.xml', $this->styles());
            $zip->addFromString('xl/worksheets/sheet1.xml', $this->sheet($rows));
        } finally {
            $zip->close();
        }

        return $path;
    }

    /** @return list<string> */
    private function headers(): array
    {
        return [
            'Customer',
            'Phone',
            'Email',
            'Channel',
            'Store',
            'Consent',
            'Eligibility',
            'Suppression reason',
            'Send status',
            'Provider message ID',
            'Queued at',
            'Sent at',
            'Delivered at',
            'Read at',
            'Failed at',
            'Replied at',
            'Failure reason',
            'Follow-up status',
            'Follow-up due at',
            'Agent',
            'Conversation ID',
        ];
    }

    /** @return list<mixed> */
    private function values(object $row): array
    {
        return [
            $row->customer_name,
            $row->phone,
            $row->email,
            $row->origin_channel,
            $row->store_name,
            $row->consent_state,
            $row->eligibility_status,
            $row->suppression_reason,
            $row->send_status,
            $row->provider_message_ref,
            $row->queued_at,
            $row->sent_at,
            $row->delivered_at,
            $row->read_at,
            $row->failed_at,
            $row->replied_at,
            $row->last_error,
            $row->followup_status,
            $row->followup_due_at,
            $row->agent_name,
            $row->conversation_id,
        ];
    }

    private function csvSafe(mixed $value): string
    {
        $value = trim((string) ($value ?? ''));
        if ($value !== '' && preg_match('/^[=+\-@]/u', $value) === 1) {
            return "'".$value;
        }

        return $value;
    }

    /** @param Collection<int,object> $rows */
    private function sheet(Collection $rows): string
    {
        $sheetRows = [];
        $sheetRows[] = $this->xmlRow(1, $this->headers());
        $rowNumber = 2;
        foreach ($rows as $row) {
            $sheetRows[] = $this->xmlRow($rowNumber++, array_map(
                static fn (mixed $value): string => (string) ($value ?? ''),
                $this->values($row),
            ));
        }

        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
            .'<sheetData>'.implode('', $sheetRows).'</sheetData>'
            .'</worksheet>';
    }

    /** @param list<string> $values */
    private function xmlRow(int $row, array $values): string
    {
        $cells = '';
        foreach ($values as $index => $value) {
            $cell = $this->columnName($index + 1).$row;
            $cells .= '<c r="'.$cell.'" t="inlineStr"><is><t xml:space="preserve">'
                .$this->xml($value)
                .'</t></is></c>';
        }

        return '<row r="'.$row.'">'.$cells.'</row>';
    }

    private function columnName(int $number): string
    {
        $name = '';
        while ($number > 0) {
            $number--;
            $name = chr(65 + ($number % 26)).$name;
            $number = intdiv($number, 26);
        }

        return $name;
    }

    private function xml(string $value): string
    {
        return htmlspecialchars($value, ENT_XML1 | ENT_QUOTES, 'UTF-8');
    }

    private function contentTypes(): string
    {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
            .'<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
            .'<Default Extension="xml" ContentType="application/xml"/>'
            .'<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>'
            .'<Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>'
            .'<Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/>'
            .'</Types>';
    }

    private function rootRelationships(): string
    {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            .'<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/>'
            .'</Relationships>';
    }

    private function workbook(): string
    {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" '
            .'xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
            .'<sheets><sheet name="Campaign Recipients" sheetId="1" r:id="rId1"/></sheets>'
            .'</workbook>';
    }

    private function workbookRelationships(): string
    {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            .'<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/>'
            .'<Relationship Id="rId2" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/>'
            .'</Relationships>';
    }

    private function styles(): string
    {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
            .'<fonts count="1"><font><sz val="11"/><name val="Calibri"/></font></fonts>'
            .'<fills count="1"><fill><patternFill patternType="none"/></fill></fills>'
            .'<borders count="1"><border/></borders>'
            .'<cellStyleXfs count="1"><xf/></cellStyleXfs>'
            .'<cellXfs count="1"><xf xfId="0"/></cellXfs>'
            .'</styleSheet>';
    }
}
