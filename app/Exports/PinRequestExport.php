<?php

namespace App\Exports;

use App\Exports\Concerns\PreservesNumericIdentifiers;
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
 * Excel export for the admin Pin Requests page. Filtered scope (search,
 * status, level, fromdate/todate) is applied by the controller — including
 * the sub-admin "level 0/1 only" restriction.
 */
class PinRequestExport extends DefaultValueBinder implements FromCollection, WithHeadings, WithMapping, ShouldAutoSize, WithStyles, WithCustomValueBinder
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
            'Mobile',
            'Level',
            'PIN',
            'Status',
            'Request Date',
            'Pin Generated',
            'Activated At',
            'Pick Date',
            'Delivery Address',
        ];
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
        $statuses = [
            0 => 'Pending Review',
            1 => 'Pin Generated',
            2 => 'Pin Activated',
            3 => 'Rejected',
        ];

        return [
            $row->id,
            $row->user->username ?? '',
            trim(($row->user->first_name ?? '') . ' ' . ($row->user->last_name ?? '')),
            $row->user->mobile ?? '',
            $levels[$row->level] ?? '',
            $row->pin ?? '',
            $statuses[$row->status] ?? 'Unknown',
            $row->created_at ? $row->created_at->format('d-m-Y h:i A') : '-',
            $row->pin_generated_at ? date('d-m-Y h:i A', strtotime($row->pin_generated_at)) : '-',
            $row->activated_at ? date('d-m-Y h:i A', strtotime($row->activated_at)) : '-',
            $row->direct_pick_date ?? '-',
            $row->gift_delivery_address ?? '-',
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
            // Force-text on Mobile and PIN — both look numeric to Excel.
            'D' => ['numberFormat' => ['formatCode' => '@']],
            'F' => ['numberFormat' => ['formatCode' => '@']],
        ];
    }
}
