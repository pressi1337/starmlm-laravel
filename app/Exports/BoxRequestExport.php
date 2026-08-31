<?php

namespace App\Exports;

use App\Exports\Concerns\PreservesNumericIdentifiers;
use App\Models\PromoterBoxRequest;
use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithStyles;
use Maatwebsite\Excel\Concerns\WithCustomValueBinder;
use PhpOffice\PhpSpreadsheet\Cell\DefaultValueBinder;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

/**
 * Excel export for the admin Plan Product (box requests) page.
 *
 * The controller applies exactly the same filter/search/sort scope as the
 * on-screen list, so the file always matches what the admin is looking at.
 */
class BoxRequestExport extends DefaultValueBinder implements FromCollection, WithHeadings, WithMapping, ShouldAutoSize, WithStyles, WithCustomValueBinder
{
    use PreservesNumericIdentifiers;

    protected $rows;

    public function __construct($rows)
    {
        $this->rows = $rows;
    }

    public function collection()
    {
        return $this->rows;
    }

    public function headings(): array
    {
        return [
            'Request ID',
            'Username',
            'Full Name',
            'Customer ID',
            'Mobile',
            'Level',
            'Quantity',
            'Delivery Type',
            'Delivery Address',
            'Contact Number',
            'Status',
            'Requested Date',
            'Sent Date',
            'Dispatch Method',
            'Dispatch Details',
            'Delivered Date',
            'Not Received Date',
        ];
    }

    /** Same "-" placeholder the screen shows for a date that hasn't happened. */
    private function date($value): string
    {
        if (empty($value)) {
            return '-';
        }

        return date('d-m-Y h:i A', strtotime((string) $value));
    }

    public function map($row): array
    {
        $levels = [
            0 => 'Promoter',
            1 => 'Promoter Level 1',
            2 => 'Promoter Level 2',
            3 => 'Promoter Level 3',
            4 => 'Promoter Level 4',
        ];

        $deliveryTypes = [
            PromoterBoxRequest::DELIVERY_TYPE_PICKUP   => 'Pickup',
            PromoterBoxRequest::DELIVERY_TYPE_DELIVERY => 'Delivery',
        ];

        return [
            $row->id,
            $row->user->username ?? '',
            trim(($row->user->first_name ?? '') . ' ' . ($row->user->last_name ?? '')),
            $row->user->customer_id ?? '',
            $row->user->mobile ?? '',
            $levels[(int) $row->level] ?? '',
            (int) $row->quantity,
            $deliveryTypes[(int) $row->delivery_type] ?? '-',
            $row->delivery_address ?: '-',
            $row->contact_number ?: '-',
            $row->statusLabel(),
            // Legacy rows written before requested_at existed fall back to
            // created_at, matching what the listing shows.
            $this->date($row->requested_at ?: $row->created_at),
            $this->date($row->sent_at),
            $row->dispatchLabel() ?: '-',
            $row->dispatchSummary() ?: '-',
            $this->date($row->delivered_at),
            $this->date($row->not_received_at),
        ];
    }

    public function styles(Worksheet $sheet)
    {
        return [
            1 => [
                'font' => ['bold' => true],
                'fill' => [
                    'fillType'   => Fill::FILL_SOLID,
                    'startColor' => ['rgb' => 'D9EAD3'],
                ],
            ],
            // Force-text: both look numeric to Excel and lose leading zeros.
            'D' => ['numberFormat' => ['formatCode' => '@']],
            'E' => ['numberFormat' => ['formatCode' => '@']],
            'J' => ['numberFormat' => ['formatCode' => '@']],
        ];
    }
}
