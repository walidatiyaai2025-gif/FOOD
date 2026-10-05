<?php

namespace App\Services;

use RuntimeException;
use ZipArchive;

final class ReportExportService
{
    /**
     * @param  array<string, mixed>  $report
     * @return array{content:string,mime:string,extension:string}
     */
    public function build(array $report, string $format, string $locale): array
    {
        return match ($format) {
            'xlsx' => [
                'content' => $this->xlsx($report, $locale),
                'mime' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
                'extension' => 'xlsx',
            ],
            'docx' => [
                'content' => $this->docx($report, $locale),
                'mime' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
                'extension' => 'docx',
            ],
            'pdf' => [
                'content' => $this->pdf($report, $locale),
                'mime' => 'application/pdf',
                'extension' => 'pdf',
            ],
            default => throw new RuntimeException('Unsupported export format.'),
        };
    }

    public function sanitizeSpreadsheetValue(mixed $value): mixed
    {
        if (! is_string($value)) {
            return $value;
        }

        $trimmed = ltrim($value);

        return preg_match('/^[=+\-@]/u', $trimmed) === 1 ? chr(39).$value : $value;
    }

    /** @param array<string, mixed> $report */
    public function filename(array $report, string $extension): string
    {
        $filters = (array) ($report['filters'] ?? []);
        $from = preg_replace('/[^0-9-]/', '', (string) ($filters['from'] ?? 'period')) ?: 'period';
        $to = preg_replace('/[^0-9-]/', '', (string) ($filters['to'] ?? 'period')) ?: 'period';
        $type = preg_replace('/[^a-z0-9_-]/i', '-', (string) ($report['report'] ?? 'report')) ?: 'report';

        return sprintf('foodex-%s-%s-to-%s-%s.%s', $type, $from, $to, gmdate('Ymd-His'), $extension);
    }

    /** @param array<string, mixed> $report */
    private function xlsx(array $report, string $locale): string
    {
        $rows = $this->tabularRows($report, $locale);
        $headerRow = $this->headerRowIndex($report);
        $columnCount = max(1, count((array) ($report['columns'] ?? [])));
        $sheetRows = [];

        foreach ($rows as $rowIndex => $row) {
            $cells = [];
            foreach (array_values($row) as $columnIndex => $value) {
                $reference = $this->columnLetter($columnIndex + 1).($rowIndex + 1);
                if (is_int($value) || is_float($value)) {
                    $cells[] = '<c r="'.$reference.'" t="n"><v>'.$value.'</v></c>';

                    continue;
                }

                $safe = $this->xml((string) $this->sanitizeSpreadsheetValue($value));
                $cells[] = '<c r="'.$reference.'" t="inlineStr"><is><t xml:space="preserve">'.$safe.'</t></is></c>';
            }

            $sheetRows[] = '<row r="'.($rowIndex + 1).'">'.implode('', $cells).'</row>';
        }

        $lastColumn = $this->columnLetter($columnCount);
        $sheet = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
            .'<sheetViews><sheetView workbookViewId="0" rightToLeft="'.($locale === 'ar' ? '1' : '0').'">'
            .'<pane ySplit="'.$headerRow.'" topLeftCell="A'.($headerRow + 1).'" activePane="bottomLeft" state="frozen"/>'
            .'</sheetView></sheetViews>'
            .'<sheetData>'.implode('', $sheetRows).'</sheetData>'
            .'<autoFilter ref="A'.$headerRow.':'.$lastColumn.$headerRow.'"/>'
            .'</worksheet>';

        return $this->zip([
            '[Content_Types].xml' => '<?xml version="1.0" encoding="UTF-8"?>'
                .'<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
                .'<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
                .'<Default Extension="xml" ContentType="application/xml"/>'
                .'<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>'
                .'<Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>'
                .'<Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/>'
                .'</Types>',
            '_rels/.rels' => '<?xml version="1.0" encoding="UTF-8"?>'
                .'<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
                .'<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/>'
                .'</Relationships>',
            'xl/workbook.xml' => '<?xml version="1.0" encoding="UTF-8"?>'
                .'<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" '
                .'xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
                .'<sheets><sheet name="FOODEX Report" sheetId="1" r:id="rId1"/></sheets></workbook>',
            'xl/_rels/workbook.xml.rels' => '<?xml version="1.0" encoding="UTF-8"?>'
                .'<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
                .'<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/>'
                .'<Relationship Id="rId2" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/>'
                .'</Relationships>',
            'xl/styles.xml' => '<?xml version="1.0" encoding="UTF-8"?>'
                .'<styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
                .'<fonts count="1"><font><sz val="11"/><name val="Aptos"/></font></fonts>'
                .'<fills count="1"><fill><patternFill patternType="none"/></fill></fills>'
                .'<borders count="1"><border/></borders>'
                .'<cellStyleXfs count="1"><xf/></cellStyleXfs>'
                .'<cellXfs count="1"><xf xfId="0"/></cellXfs>'
                .'</styleSheet>',
            'xl/worksheets/sheet1.xml' => $sheet,
        ]);
    }

    /** @param array<string, mixed> $report */
    private function docx(array $report, string $locale): string
    {
        $rtl = $locale === 'ar';
        $paragraphs = [];

        foreach ($this->summaryLines($report, $locale) as $line) {
            $paragraphs[] = $this->wordParagraph($line, $rtl);
        }

        $columns = (array) ($report['columns'] ?? []);
        $tableRows = [];
        $tableRows[] = '<w:tr>'.implode('', array_map(
            fn (string $column): string => $this->wordCell((string) __("reports.columns.{$column}"), $rtl, true),
            $columns,
        )).'</w:tr>';

        foreach ((array) ($report['rows'] ?? []) as $row) {
            $tableRows[] = '<w:tr>'.implode('', array_map(
                fn (string $column): string => $this->wordCell((string) ($row[$column] ?? ''), $rtl),
                $columns,
            )).'</w:tr>';
        }

        $document = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<w:document xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main" '
            .'xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
            .'<w:body>'.implode('', $paragraphs)
            .'<w:tbl><w:tblPr><w:tblW w:w="0" w:type="auto"/>'
            .($rtl ? '<w:bidiVisual/>' : '').'</w:tblPr>'.implode('', $tableRows).'</w:tbl>'
            .'<w:sectPr><w:footerReference w:type="default" r:id="rId1"/>'
            .'<w:pgSz w:w="11906" w:h="16838"/><w:pgMar w:top="900" w:right="900" w:bottom="900" w:left="900"/>'
            .'</w:sectPr></w:body></w:document>';

        $footer = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<w:ftr xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main">'
            .'<w:p><w:pPr><w:jc w:val="center"/></w:pPr><w:r><w:t>FOODEX · </w:t></w:r>'
            .'<w:fldSimple w:instr="PAGE"><w:r><w:t>1</w:t></w:r></w:fldSimple></w:p></w:ftr>';

        return $this->zip([
            '[Content_Types].xml' => '<?xml version="1.0" encoding="UTF-8"?>'
                .'<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
                .'<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
                .'<Default Extension="xml" ContentType="application/xml"/>'
                .'<Override PartName="/word/document.xml" ContentType="application/vnd.openxmlformats-officedocument.wordprocessingml.document.main+xml"/>'
                .'<Override PartName="/word/footer1.xml" ContentType="application/vnd.openxmlformats-officedocument.wordprocessingml.footer+xml"/>'
                .'</Types>',
            '_rels/.rels' => '<?xml version="1.0" encoding="UTF-8"?>'
                .'<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
                .'<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="word/document.xml"/>'
                .'</Relationships>',
            'word/document.xml' => $document,
            'word/_rels/document.xml.rels' => '<?xml version="1.0" encoding="UTF-8"?>'
                .'<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
                .'<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/footer" Target="footer1.xml"/>'
                .'</Relationships>',
            'word/footer1.xml' => $footer,
        ]);
    }

    /** @param array<string, mixed> $report */
    private function pdf(array $report, string $locale): string
    {
        $rtl = $locale === 'ar';
        $pdf = app(PdfDocumentFactory::class)->create($rtl, 10, 12, 10, 9);
        $pdf->SetTitle($rtl ? 'تقرير FOODEX' : 'FOODEX Report');
        $pdf->AddPage();

        $align = $rtl ? 'right' : 'left';
        $dir = $rtl ? 'rtl' : 'ltr';
        $html = '<div dir="'.$dir.'" style="text-align:'.$align.';font-family:dejavusans;">';
        foreach ($this->summaryLines($report, $locale) as $line) {
            $html .= '<div style="margin-bottom:4px;">'.$this->html((string) $line).'</div>';
        }

        $columns = (array) ($report['columns'] ?? []);
        $html .= '<br><table border="1" cellpadding="4" cellspacing="0" style="width:100%;border-collapse:collapse;"><thead><tr>';
        foreach ($columns as $column) {
            $html .= '<th style="font-weight:bold;background-color:#f3f5f4;">'.$this->html((string) __("reports.columns.{$column}")).'</th>';
        }
        $html .= '</tr></thead><tbody>';
        foreach ((array) ($report['rows'] ?? []) as $row) {
            $html .= '<tr>';
            foreach ($columns as $column) {
                $html .= '<td>'.$this->html((string) ($row[$column] ?? '')).'</td>';
            }
            $html .= '</tr>';
        }
        $html .= '</tbody></table></div>';

        $pdf->writeHTML($html, true, false, true, false, '');
        $content = $pdf->Output('', 'S');
        if (! str_starts_with($content, '%PDF-')) {
            throw new RuntimeException('Unable to generate Unicode PDF report.');
        }

        return $content;
    }

    private function html(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    /** @param array<string, mixed> $report */
    private function summaryLines(array $report, string $locale): array
    {
        $filters = (array) ($report['filters'] ?? []);
        $lines = [
            'FOODEX',
            (string) __("reports.families.{$report['report']}"),
            (string) __('reports.generated_at').': '.(string) ($report['generated_at'] ?? ''),
            (string) __('reports.period').': '.(string) ($filters['from'] ?? '').' → '.(string) ($filters['to'] ?? ''),
        ];

        foreach ((array) ($report['kpis'] ?? []) as $key => $value) {
            $lines[] = (string) __("reports.kpis.{$key}").': '.$value;
        }

        $lines[] = $locale === 'ar' ? 'FOODEX · تقرير إداري' : 'FOODEX · Management Report';

        return $lines;
    }

    /** @param array<string, mixed> $report */
    private function tabularRows(array $report, string $locale): array
    {
        $rows = [];
        foreach ($this->summaryLines($report, $locale) as $line) {
            $rows[] = [$line];
        }

        $rows[] = [];
        $columns = (array) ($report['columns'] ?? []);
        $rows[] = array_map(
            fn (string $column): string => (string) __("reports.columns.{$column}"),
            $columns,
        );

        foreach ((array) ($report['rows'] ?? []) as $row) {
            $rows[] = array_map(
                fn (string $column): mixed => $this->sanitizeSpreadsheetValue($row[$column] ?? ''),
                $columns,
            );
        }

        return $rows;
    }

    /** @param array<string, mixed> $report */
    private function headerRowIndex(array $report): int
    {
        return count($this->summaryLines($report, app()->getLocale())) + 2;
    }

    private function wordParagraph(string $text, bool $rtl): string
    {
        return '<w:p><w:pPr>'.($rtl ? '<w:bidi/>' : '').'</w:pPr><w:r><w:t xml:space="preserve">'
            .$this->xml($text).'</w:t></w:r></w:p>';
    }

    private function wordCell(string $text, bool $rtl, bool $bold = false): string
    {
        return '<w:tc><w:tcPr><w:tcW w:w="0" w:type="auto"/></w:tcPr><w:p><w:pPr>'
            .($rtl ? '<w:bidi/><w:jc w:val="right"/>' : '')
            .'</w:pPr><w:r>'.($bold ? '<w:rPr><w:b/></w:rPr>' : '')
            .'<w:t xml:space="preserve">'.$this->xml($text).'</w:t></w:r></w:p></w:tc>';
    }

    /** @param array<string, string> $files */
    private function zip(array $files): string
    {
        if (! class_exists(ZipArchive::class)) {
            throw new RuntimeException('ZIP support is required for Office exports.');
        }

        $path = tempnam(sys_get_temp_dir(), 'foodex-report-');
        if ($path === false) {
            throw new RuntimeException('Unable to create export workspace.');
        }

        $zip = new ZipArchive;
        if ($zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            @unlink($path);
            throw new RuntimeException('Unable to create export archive.');
        }

        foreach ($files as $name => $content) {
            $zip->addFromString($name, $content);
        }
        $zip->close();

        $content = file_get_contents($path);
        @unlink($path);

        if ($content === false) {
            throw new RuntimeException('Unable to read generated export.');
        }

        return $content;
    }

    private function xml(string $value): string
    {
        return htmlspecialchars($value, ENT_XML1 | ENT_QUOTES, 'UTF-8');
    }

    private function columnLetter(int $number): string
    {
        $letters = '';
        while ($number > 0) {
            $number--;
            $letters = chr(65 + ($number % 26)).$letters;
            $number = intdiv($number, 26);
        }

        return $letters;
    }
}
