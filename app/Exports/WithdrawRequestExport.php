<?php

namespace App\Exports;

use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithStyles;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

class WithdrawRequestExport implements FromCollection, WithHeadings, WithMapping, ShouldAutoSize, WithStyles
{
    protected $withdrawRequests;

    public function __construct($withdrawRequests)
    {
        $this->withdrawRequests = $withdrawRequests;
    }

    public function collection()
    {
        return $this->withdrawRequests;
    }

    public function headings(): array
    {
        return [
            'Request ID',
            'Username',
            'Full Name',
            'Mobile',
            'Address',
            'Promoter Level',
            'Bank Name',
            'Account Number',
            'IFSC Code',
            'Branch Name',
            'Request Date',
            'Status',
            'Amount',
        ];
    }

    public function map($withdrawRequest): array
    {
        $statuses = [
            0 => 'Pending',
            1 => 'Processing',
            2 => 'Completed',
            3 => 'Rejected'
        ];
        $promoter_levels = [
            0 => 'Promoter',
            1 => 'Promoter1',
            2 => 'Promoter2',
            3 => 'Promoter3',
            4 => 'Promoter4'
        ];

        return [
            $withdrawRequest->id,
            $withdrawRequest->user->username ?? 'N/A',
            ($withdrawRequest->user->first_name ?? '') . ' ' . ($withdrawRequest->user->last_name ?? ''),
            $withdrawRequest->user->mobile ?? 'N/A',
            $this->formatAddress($withdrawRequest->user),
            $promoter_levels[$withdrawRequest->user->current_promoter_level] ?? 'Unknown',
            $withdrawRequest->bankDetail->bank_name ?? 'N/A',
            $withdrawRequest->bankDetail->acc_no ?? 'N/A',
            $withdrawRequest->bankDetail->ifsc_code ?? 'N/A',
            $withdrawRequest->bankDetail->branch_name ?? 'N/A',
            $withdrawRequest->request_at ? date('d-m-Y h:i A', strtotime($withdrawRequest->request_at)) : '-',
            $statuses[$withdrawRequest->status] ?? 'Unknown',
            number_format($withdrawRequest->amount, 2),
        ];
    }

    protected function formatAddress($user)
    {
        $address = [];
        if (!empty($user->address)) $address[] = $user->address;
        if (!empty($user->city)) $address[] = $user->city;
        if (!empty($user->district)) $address[] = $user->district;
        if (!empty($user->state)) $address[] = $user->state;
        if (!empty($user->pin_code)) $address[] = $user->pin_code;
        
        return !empty($address) ? implode(', ', $address) : 'N/A';
    }

    public function styles(Worksheet $sheet)
    {
        return [
            // Style the first row as bold text
            1 => [
                'font' => ['bold' => true],
                'fill' => [
                    'fillType' => \PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID,
                    'startColor' => ['rgb' => 'D9EAD3']
                ]
            ],
            // Set text format for the amount column
            'M' => [
                'numberFormat' => [
                    'formatCode' => '0.00'
                ]
            ]
        ];
    }
}
