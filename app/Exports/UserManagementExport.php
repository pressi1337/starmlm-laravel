<?php

namespace App\Exports;

use App\Models\User;
use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;

class UserManagementExport implements FromCollection, WithHeadings, WithMapping, ShouldAutoSize
{
    public function __construct(protected $rows)
    {
    }

    public function collection()
    {
        return $this->rows;
    }

    public function headings(): array
    {
        return [
            'User ID',
            'Username',
            'Full Name',
            'Mobile',
            'Referrer',
            'Promoter Level',
            'Total Team Members',
            'Direct Referrals',
            'Joined Date',
            'Status',
        ];
    }

    public function map($row): array
    {
        return [
            $row->id,
            $row->username,
            trim(($row->first_name ?? '') . ' ' . ($row->last_name ?? '')),
            $row->mobile,
            $row->referrer?->username ?? 'N/A',
            User::promoterLevelLabel($row->current_promoter_level),
            $row->total_team_count ?? 0,
            $row->direct_referrals_count ?? 0,
            optional($row->created_at)?->format('d-m-Y h:i A'),
            $row->is_active ? 'Active' : 'Inactive',
        ];
    }
}
