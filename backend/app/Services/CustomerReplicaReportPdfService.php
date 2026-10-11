<?php

namespace App\Services;

use InvalidArgumentException;
use RuntimeException;
use TCPDF;
use Throwable;

final class CustomerReplicaReportPdfService
{
    public function __construct(private readonly PdfDocumentFactory $factory) {}

    /** @param array<string,mixed> $report */
    public function render(array $report): string
    {
        $type = (string) ($report['report'] ?? '');
        $orientation = $type === CustomerReplicaReportService::ACCOUNT_STATEMENT ? 'P' : 'L';
        $pdf = $this->factory->create(false, 3, 3, 3, 8, $orientation);
        $pdf->SetTitle((string) $this->title($type));
        $pdf->SetSubject('FOODEX Customer Report');
        $pdf->SetAutoPageBreak(false, 0);
        $pdf->setPrintHeader(false);
        $pdf->setPrintFooter(false);

        match ($type) {
            CustomerReplicaReportService::ACCOUNT_STATEMENT => $this->accountStatement($pdf, $report),
            CustomerReplicaReportService::ITEM_DETAIL => $this->itemDetail($pdf, $report),
            CustomerReplicaReportService::ITEM_SUMMARY => $this->itemSummary($pdf, $report),
            default => throw new InvalidArgumentException('Unsupported customer replica report.'),
        };

        $this->stampFooters($pdf, $type);
        $content = (string) $pdf->Output('', 'S');

        if (! str_starts_with($content, '%PDF-')) {
            throw new RuntimeException('Unable to generate customer replica PDF.');
        }

        return $content;
    }

    /** @param array<string,mixed> $report */
    private function accountStatement(TCPDF $pdf, array $report): void
    {
        $pdf->AddPage('P', 'A4');
        $y = $this->accountHeader($pdf, $report);
        $this->accountColumnHeader($pdf, $y);
        $y += 14;

        $openingDebit = (float) ($report['opening_debit'] ?? 0);
        $openingCredit = (float) ($report['opening_credit'] ?? 0);
        $this->accountRow($pdf, $y, [
            'date' => (string) $report['from'],
            'movement_number' => '0',
            'document_type' => '',
            'description' => 'رصيد اول الفتره',
            'debit' => 0,
            'credit' => 0,
            'balance_debit' => $openingDebit,
            'balance_credit' => $openingCredit,
        ], true);
        $y += 9;

        foreach ((array) ($report['rows'] ?? []) as $row) {
            if ($y > 266) {
                $pdf->AddPage('P', 'A4');
                $y = $this->accountHeader($pdf, $report);
                $this->accountColumnHeader($pdf, $y);
                $y += 14;
            }

            $this->accountRow($pdf, $y, (array) $row);
            $y += 10;
        }

        if ($y > 263) {
            $pdf->AddPage('P', 'A4');
            $y = $this->accountHeader($pdf, $report);
            $this->accountColumnHeader($pdf, $y);
            $y += 14;
        }

        $this->accountTotals($pdf, $y, $report);
    }

    /** @param array<string,mixed> $report */
    private function itemDetail(TCPDF $pdf, array $report): void
    {
        $pdf->AddPage('L', 'A4');
        $y = $this->detailHeader($pdf, $report);
        $this->detailColumnHeader($pdf, $y);
        $y += 10;

        foreach ((array) ($report['rows'] ?? []) as $row) {
            if ($y > 188) {
                $pdf->AddPage('L', 'A4');
                $y = $this->detailHeader($pdf, $report);
                $this->detailColumnHeader($pdf, $y);
                $y += 10;
            }

            $this->detailRow($pdf, $y, (array) $row);
            $y += 9;
        }

        if ($y > 188) {
            $pdf->AddPage('L', 'A4');
            $y = $this->detailHeader($pdf, $report);
            $this->detailColumnHeader($pdf, $y);
            $y += 10;
        }

        $this->detailTotal($pdf, $y, (float) ($report['total'] ?? 0));
    }

    /** @param array<string,mixed> $report */
    private function itemSummary(TCPDF $pdf, array $report): void
    {
        $pdf->AddPage('L', 'A4');
        $y = $this->summaryHeader($pdf, $report);
        $this->summaryColumnHeader($pdf, $y);
        $y += 10;
        $this->cell($pdf, 4, $y, 289, 8, (string) $report['customer_name'], true, 'R', 8.5, true, [251, 250, 238]);
        $y += 8;

        foreach ((array) ($report['rows'] ?? []) as $row) {
            if ($y > 187) {
                $pdf->AddPage('L', 'A4');
                $y = $this->summaryHeader($pdf, $report);
                $this->summaryColumnHeader($pdf, $y);
                $y += 10;
                $this->cell($pdf, 4, $y, 289, 8, (string) $report['customer_name'], true, 'R', 8.5, true, [251, 250, 238]);
                $y += 8;
            }

            $this->summaryRow($pdf, $y, (array) $row);
            $y += 9;
        }

        if ($y > 188) {
            $pdf->AddPage('L', 'A4');
            $y = $this->summaryHeader($pdf, $report);
            $this->summaryColumnHeader($pdf, $y);
            $y += 10;
        }

        $this->summaryTotal($pdf, $y, (float) ($report['total'] ?? 0));
    }

    /** @param array<string,mixed> $report */
    private function accountHeader(TCPDF $pdf, array $report): float
    {
        $this->logo($pdf, 146, 5, 58, 20);
        $this->cell($pdf, 64, 16, 82, 16, 'كشف حساب عميل', true, 'C', 11, true, [224, 224, 224], [20, 20, 20], 1.2);
        $this->cell($pdf, 8, 27, 55, 6, 'الادارة التجاريه', false, 'C', 8, true, null, [24, 45, 125], 0);
        $this->printStamp($pdf, 4, 4, 76);

        $this->cell($pdf, 4, 36, 70, 9, 'From Date      '.(string) $report['from'].'      من تاريخ', true, 'C', 8, true);
        $this->cell($pdf, 4, 45, 70, 9, 'To Date        '.(string) $report['to'].'      الي تاريخ', true, 'C', 8, true);
        $this->cell($pdf, 113, 36, 93, 9, 'Code                     '.(string) $report['customer_code'].'       كود العميل :', true, 'C', 8, true);
        $this->cell($pdf, 113, 45, 93, 9, 'Acc Name              '.(string) $report['customer_name'].'       العميل :', true, 'C', 8, true);

        return 59;
    }

    private function accountColumnHeader(TCPDF $pdf, float $y): void
    {
        $cyan = [73, 183, 198];
        $ink = [30, 47, 126];
        $this->cell($pdf, 3, $y, 40, 7, 'الرصيد', true, 'C', 8, true, $cyan, $ink);
        $this->cell($pdf, 43, $y, 40, 7, 'قيمة الحركة', true, 'C', 8, true, $cyan, $ink);
        $this->cell($pdf, 83, $y, 53, 14, 'وصف الحركة', true, 'C', 8, true, $cyan, $ink);
        $this->cell($pdf, 136, $y, 28, 14, 'نوع المستند', true, 'C', 8, true, $cyan, $ink);
        $this->cell($pdf, 164, $y, 20, 14, 'رقم الحركة', true, 'C', 8, true, $cyan, $ink);
        $this->cell($pdf, 184, $y, 23, 14, 'التاريخ', true, 'C', 8, true, $cyan, $ink);

        $this->cell($pdf, 3, $y + 7, 20, 7, 'دائن', true, 'C', 7.5, true, $cyan, $ink);
        $this->cell($pdf, 23, $y + 7, 20, 7, 'مدين', true, 'C', 7.5, true, $cyan, $ink);
        $this->cell($pdf, 43, $y + 7, 20, 7, 'دائن', true, 'C', 7.5, true, $cyan, $ink);
        $this->cell($pdf, 63, $y + 7, 20, 7, 'مدين', true, 'C', 7.5, true, $cyan, $ink);
    }

    /** @param array<string,mixed> $row */
    private function accountRow(TCPDF $pdf, float $y, array $row, bool $opening = false): void
    {
        $fill = $opening ? [220, 220, 220] : null;
        $balanceCredit = (float) ($row['balance_credit'] ?? 0);
        $balanceDebit = (float) ($row['balance_debit'] ?? 0);
        $zeroBalance = abs($balanceCredit) < 0.0005 && abs($balanceDebit) < 0.0005;
        $this->cell($pdf, 3, $y, 20, 9, $zeroBalance ? $this->money(0) : $this->moneyOrBlank($balanceCredit), true, 'C', 7.3, false, $fill);
        $this->cell($pdf, 23, $y, 20, 9, $zeroBalance ? $this->money(0) : $this->moneyOrBlank($balanceDebit), true, 'C', 7.3, false, $fill);
        $this->cell($pdf, 43, $y, 20, 9, $this->moneyOrBlank((float) ($row['credit'] ?? 0)), true, 'C', 7.3, false, $fill);
        $this->cell($pdf, 63, $y, 20, 9, $this->moneyOrBlank((float) ($row['debit'] ?? 0)), true, 'C', 7.3, false, $fill);
        $this->cell($pdf, 83, $y, 53, 9, (string) ($row['description'] ?? ''), true, 'R', 6.8, false, $fill);
        $this->cell($pdf, 136, $y, 28, 9, (string) ($row['document_type'] ?? ''), true, 'C', 7, false, $fill);
        $this->cell($pdf, 164, $y, 20, 9, (string) ($row['movement_number'] ?? ''), true, 'C', 7.3, false, $fill);
        $this->cell($pdf, 184, $y, 23, 9, (string) ($row['date'] ?? ''), true, 'C', 7.1, $opening, $fill);
    }

    /** @param array<string,mixed> $report */
    private function accountTotals(TCPDF $pdf, float $y, array $report): void
    {
        $this->cell($pdf, 3, $y, 20, 9, $this->moneyOrBlank((float) ($report['closing_credit'] ?? 0)), false, 'C', 8.2, true);
        $this->cell($pdf, 23, $y, 20, 9, $this->moneyOrBlank((float) ($report['closing_debit'] ?? 0)), false, 'C', 8.2, true);
        $this->cell($pdf, 43, $y, 20, 9, $this->money((float) ($report['total_credit'] ?? 0)), false, 'C', 8.2, true);
        $this->cell($pdf, 63, $y, 20, 9, $this->money((float) ($report['total_debit'] ?? 0)), false, 'C', 8.2, true);
        $this->cell($pdf, 83, $y, 101, 9, 'اجمالي حركة الفترة', false, 'C', 8.5, true);
        $direction = (float) ($report['closing_debit'] ?? 0) > 0 ? 'مدين' : ((float) ($report['closing_credit'] ?? 0) > 0 ? 'دائن' : '');
        $this->cell($pdf, 23, $y + 8, 60, 7, $direction, false, 'C', 8, true);
    }

    /** @param array<string,mixed> $report */
    private function detailHeader(TCPDF $pdf, array $report): float
    {
        $this->logo($pdf, 235, 5, 56, 20);
        $this->cell($pdf, 99, 7, 102, 13, 'مسحوبات عميل تفصيلي بالاصناف', false, 'C', 12, true, null, [33, 47, 133], 0);
        $this->cell($pdf, 4, 6, 63, 8, (string) $report['from'].'     من تاريخ', true, 'C', 8, true);
        $this->cell($pdf, 4, 14, 63, 8, (string) $report['to'].'     الي تاريخ', true, 'C', 8, true);
        $this->printStamp($pdf, 4, 24, 80);

        return 35;
    }

    private function detailColumnHeader(TCPDF $pdf, float $y): void
    {
        $cyan = [124, 186, 232];
        $ink = [27, 53, 120];
        $columns = [
            [4, 25, 'ملاحظات'],
            [29, 30, 'البائع'],
            [59, 22, 'صافي القيمة'],
            [81, 16, 'السعر'],
            [97, 14, 'الكمية'],
            [111, 18, 'كود الصنف'],
            [129, 66, 'الصنف'],
            [195, 36, 'العميل'],
            [231, 22, 'نوع الحركة'],
            [253, 18, 'رقم الحركة'],
            [271, 22, 'تاريخ الفاتورة'],
        ];

        foreach ($columns as [$x, $w, $label]) {
            $this->cell($pdf, (float) $x, $y, (float) $w, 10, (string) $label, true, 'C', 7.2, true, $cyan, $ink);
        }
    }

    /** @param array<string,mixed> $row */
    private function detailRow(TCPDF $pdf, float $y, array $row): void
    {
        $value = (float) ($row['net_value'] ?? 0);
        $cells = [
            [4, 25, (string) ($row['notes'] ?? ''), 'C'],
            [29, 30, (string) ($row['seller'] ?? ''), 'C'],
            [59, 22, $value < 0 ? '('.$this->money(abs($value)).')' : $this->money($value), 'C'],
            [81, 16, number_format((float) ($row['price'] ?? 0), 1, '.', ''), 'C'],
            [97, 14, (float) ($row['quantity'] ?? 0) < 0 ? number_format(abs((float) $row['quantity']), 1, '.', '').'-' : number_format((float) ($row['quantity'] ?? 0), 1, '.', ''), 'C'],
            [111, 18, (string) ($row['item_code'] ?? ''), 'C'],
            [129, 66, (string) ($row['item'] ?? ''), 'R'],
            [195, 36, (string) ($row['customer'] ?? ''), 'R'],
            [231, 22, (string) ($row['movement_type'] ?? ''), 'C'],
            [253, 18, (string) ($row['movement_number'] ?? ''), 'C'],
            [271, 22, (string) ($row['date'] ?? ''), 'C'],
        ];

        foreach ($cells as [$x, $w, $text, $align]) {
            $this->cell($pdf, (float) $x, $y, (float) $w, 9, (string) $text, false, (string) $align, 6.9);
        }
    }

    private function detailTotal(TCPDF $pdf, float $y, float $total): void
    {
        $this->cell($pdf, 59, $y, 22, 9, $this->money($total), false, 'C', 8.2, true);
        $this->cell($pdf, 81, $y, 114, 9, 'الاجمالي', false, 'C', 8.2, true);
    }

    /** @param array<string,mixed> $report */
    private function summaryHeader(TCPDF $pdf, array $report): float
    {
        $this->logo($pdf, 235, 5, 56, 20);
        $this->cell($pdf, 99, 7, 102, 13, 'مسحوبات عميل بالاصناف', false, 'C', 12, true, null, [33, 47, 133], 0);
        $this->cell($pdf, 4, 6, 63, 8, (string) $report['from'].'     من تاريخ', true, 'C', 8, true);
        $this->cell($pdf, 4, 14, 63, 8, (string) $report['to'].'     الي تاريخ', true, 'C', 8, true);
        $this->printStamp($pdf, 215, 199, 78);

        return 35;
    }

    private function summaryColumnHeader(TCPDF $pdf, float $y): void
    {
        $blue = [140, 197, 239];
        $ink = [27, 53, 120];
        $columns = [
            [4, 28, 'صافي القيمة'],
            [32, 24, 'الوحده'],
            [56, 24, 'صافي المباع'],
            [80, 20, 'مرتجعات'],
            [100, 20, 'مبيعات'],
            [120, 40, 'المجموعة'],
            [160, 110, 'الصنف'],
            [270, 23, 'الكود'],
        ];
        foreach ($columns as [$x, $w, $label]) {
            $this->cell($pdf, (float) $x, $y, (float) $w, 10, (string) $label, true, 'C', 7.4, true, $blue, $ink);
        }
    }

    /** @param array<string,mixed> $row */
    private function summaryRow(TCPDF $pdf, float $y, array $row): void
    {
        $cells = [
            [4, 28, number_format((float) ($row['net_value'] ?? 0), 1, '.', ''), 'C'],
            [32, 24, (string) ($row['unit'] ?? ''), 'C'],
            [56, 24, $this->quantity((float) ($row['net_sold'] ?? 0)), 'C'],
            [80, 20, $this->quantity((float) ($row['returns'] ?? 0)), 'C'],
            [100, 20, $this->quantity((float) ($row['sales'] ?? 0)), 'C'],
            [120, 40, (string) ($row['category'] ?? ''), 'R'],
            [160, 110, (string) ($row['item'] ?? ''), 'R'],
            [270, 23, (string) ($row['item_code'] ?? ''), 'C'],
        ];

        foreach ($cells as [$x, $w, $text, $align]) {
            $this->cell($pdf, (float) $x, $y, (float) $w, 9, (string) $text, false, (string) $align, 7);
        }
    }

    private function summaryTotal(TCPDF $pdf, float $y, float $total): void
    {
        $yellow = [255, 252, 151];
        $this->cell($pdf, 4, $y, 28, 9, $this->money($total), true, 'C', 8.3, true, $yellow);
        $this->cell($pdf, 32, $y, 261, 9, 'الاجمالي', true, 'C', 8.4, true, $yellow);
    }

    private function stampFooters(TCPDF $pdf, string $type): void
    {
        $pages = $pdf->getNumPages();
        $landscape = $type !== CustomerReplicaReportService::ACCOUNT_STATEMENT;
        $pageWidth = $landscape ? 297.0 : 210.0;
        $footerY = $landscape ? 202.5 : 289.0;
        $design = $type === CustomerReplicaReportService::ITEM_DETAIL
            ? 'Desigend by : WWW.Softarkan.Com'
            : 'Design By www.Softarkan.com';

        for ($page = 1; $page <= $pages; $page++) {
            $pdf->setPage($page);
            $this->cell($pdf, 5, $footerY, 80, 5, $design, false, 'L', 6.2, true, null, [0, 0, 0], 0);
            $this->cell($pdf, ($pageWidth / 2) - 20, $footerY, 40, 5, (string) $page, false, 'C', 6.2, false, null, [0, 0, 0], 0);
            $this->cell($pdf, $pageWidth - 55, $footerY, 50, 5, 'Page '.$page.' of '.$pages, false, 'R', 6.2, false, null, [0, 0, 0], 0);
        }
    }

    private function printStamp(TCPDF $pdf, float $x, float $y, float $w): void
    {
        $stamp = now()->format('Y/m/d').'   '.now()->format('h:i:sA').'   وقت الطباعة';
        $this->cell($pdf, $x, $y, $w, 5, $stamp, false, 'L', 6.2, false, null, [0, 0, 0], 0);
    }

    private function logo(TCPDF $pdf, float $x, float $y, float $w, float $h): void
    {
        $path = public_path('brand/foodex-economical-group.webp');
        if (is_file($path)) {
            try {
                $pdf->Image($path, $x, $y, $w, $h, '', '', '', true, 300);

                return;
            } catch (Throwable) {
                // Fall through to the textual lock-up only when the runtime
                // cannot decode WebP; the bundled production asset is preferred.
            }
        }

        $this->cell($pdf, $x, $y, $w, $h * .55, 'FOODEX', true, 'C', 18, true, [246, 255, 246], [32, 115, 57], 0);
        $this->cell($pdf, $x, $y + ($h * .55), $w, $h * .35, 'Economical Group', false, 'C', 9, false, null, [32, 115, 57], 0);
    }

    /** @param array<int,int>|null $fill
     * @param  array<int,int>  $text
     */
    private function cell(
        TCPDF $pdf,
        float $x,
        float $y,
        float $w,
        float $h,
        string $text,
        bool $border,
        string $align = 'C',
        float $fontSize = 8,
        bool $bold = false,
        ?array $fill = null,
        array $textColor = [20, 20, 20],
        float $lineWidth = 0.35,
    ): void {
        $pdf->setRTL(false);
        $pdf->SetFont('dejavusans', $bold ? 'B' : '', $fontSize);
        $pdf->SetTextColor($textColor[0], $textColor[1], $textColor[2]);
        $pdf->SetDrawColor(30, 30, 30);
        $pdf->SetLineWidth($lineWidth);
        if ($fill !== null) {
            $pdf->SetFillColor($fill[0], $fill[1], $fill[2]);
        }
        $pdf->SetXY($x, $y);
        $pdf->MultiCell(
            $w,
            $h,
            $text,
            $border ? 1 : 0,
            $align,
            $fill !== null,
            1,
            $x,
            $y,
            true,
            0,
            false,
            true,
            $h,
            'M',
        );
    }

    private function money(float $value): string
    {
        return number_format($value, 2, '.', ',');
    }

    private function moneyOrBlank(float $value): string
    {
        return abs($value) < 0.0005 ? '' : $this->money($value);
    }

    private function quantity(float $value): string
    {
        return abs($value - round($value)) < 0.0005
            ? number_format($value, 0, '.', '')
            : rtrim(rtrim(number_format($value, 3, '.', ''), '0'), '.');
    }

    private function title(string $type): string
    {
        return match ($type) {
            CustomerReplicaReportService::ACCOUNT_STATEMENT => 'كشف حساب عميل',
            CustomerReplicaReportService::ITEM_DETAIL => 'مسحوبات عميل تفصيلي بالاصناف',
            CustomerReplicaReportService::ITEM_SUMMARY => 'مسحوبات عميل بالاصناف',
            default => 'FOODEX',
        };
    }
}
