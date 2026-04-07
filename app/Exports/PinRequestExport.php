<?php

namespace App\Exports;

use App\Models\UserPromoter;
use App\Models\User;
use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;

class PinRequestExport implements FromCollection, WithHeadings, WithMapping, ShouldAutoSize
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
            'Request ID',
            'Username',
            'Mobile',
            'Upgrade Level',
            'Request Status',
            'Gift Delivery Type',
            'Product Delivery Status',
            'Customer Delivery Status',
            'Bill Path',
            'Request Date',
            'Pin Generated Date',
            'Activated Date',
        ];
    }

    public function map($row): array
    {
        return [
            $row->id,
            $row->user->username ?? 'N/A',
            $row->user->mobile ?? 'N/A',
            User::promoterLevelLabel($row->level),
            $this->pinStatusLabel($row->status),
            $row->gift_delivery_type == UserPromoter::GIFT_DELIVERY_TYPE_DELIVERY ? 'Courier' : ($row->gift_delivery_type == UserPromoter::GIFT_DELIVERY_TYPE_PICKUP ? 'Direct' : 'N/A'),
            $this->productDeliveryStatusLabel($row->product_delivery_status),
            $this->customerDeliveryStatusLabel($row->customer_delivery_status),
            $row->bill_path ?? '',
            optional($row->created_at)?->format('d-m-Y h:i A'),
            optional($row->pin_generated_at)?->format('d-m-Y h:i A'),
            optional($row->activated_at)?->format('d-m-Y h:i A'),
        ];
    }

    private function pinStatusLabel($status): string
    {
        return match ((int) $status) {
            1 => 'Approved',
            2 => 'Activated',
            3 => 'Rejected',
            4 => 'Auto Deleted',
            default => 'Pending',
        };
    }

    private function productDeliveryStatusLabel($status): string
    {
        return match ((int) $status) {
            1 => 'Processing',
            2 => 'Delivered',
            3 => 'Not Delivered',
            default => 'Pending',
        };
    }

    private function customerDeliveryStatusLabel($status): string
    {
        return match ((int) $status) {
            1 => 'Received',
            2 => 'Not Received',
            default => 'Pending',
        };
    }
}
