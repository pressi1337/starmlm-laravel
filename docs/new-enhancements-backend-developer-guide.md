# New Enhancements Backend Developer Guide

## Purpose

This document summarizes the recent additive backend enhancements implemented in this repository after the core level-income work.

The goal is to help future developers understand:

1. what was added
2. which files were changed
3. which database fields/tables now exist
4. which APIs are available
5. which business rules are currently enforced

These enhancements were implemented to avoid breaking production behavior while extending the admin and customer flows.

## Scope Covered

This backend guide covers:

1. support and help FAQ configuration
2. user suggestions with admin reactions
3. admin bank-detail reset so customer can refill
4. daily video fallback/default delivery
5. pin request lifecycle automation
6. pin request product delivery and bill tracking
7. withdraw request export/import and status details
8. user management export and total-team visibility

## Main Backend Files

### Controllers

1. [DailyVideoController.php](/home/fairland/Downloads/starmlm-laravel-production/app/Http/Controllers/V1/Api/DailyVideoController.php)
2. [UserPromoterController.php](/home/fairland/Downloads/starmlm-laravel-production/app/Http/Controllers/V1/Api/UserPromoterController.php)
3. [WithdrawController.php](/home/fairland/Downloads/starmlm-laravel-production/app/Http/Controllers/V1/Api/WithdrawController.php)
4. [ReferralController.php](/home/fairland/Downloads/starmlm-laravel-production/app/Http/Controllers/V1/Api/ReferralController.php)
5. [UserBankDetailController.php](/home/fairland/Downloads/starmlm-laravel-production/app/Http/Controllers/V1/Api/UserBankDetailController.php)
6. [SupportHelpController.php](/home/fairland/Downloads/starmlm-laravel-production/app/Http/Controllers/V1/Api/SupportHelpController.php)
7. [UserSuggestionController.php](/home/fairland/Downloads/starmlm-laravel-production/app/Http/Controllers/V1/Api/UserSuggestionController.php)

### Models

1. [DailyVideo.php](/home/fairland/Downloads/starmlm-laravel-production/app/Models/DailyVideo.php)
2. [UserPromoter.php](/home/fairland/Downloads/starmlm-laravel-production/app/Models/UserPromoter.php)
3. [WithdrawRequest.php](/home/fairland/Downloads/starmlm-laravel-production/app/Models/WithdrawRequest.php)
4. [SupportHelpItem.php](/home/fairland/Downloads/starmlm-laravel-production/app/Models/SupportHelpItem.php)
5. [UserSuggestion.php](/home/fairland/Downloads/starmlm-laravel-production/app/Models/UserSuggestion.php)

### Export / Import

1. [WithdrawRequestExport.php](/home/fairland/Downloads/starmlm-laravel-production/app/Exports/WithdrawRequestExport.php)
2. [PinRequestExport.php](/home/fairland/Downloads/starmlm-laravel-production/app/Exports/PinRequestExport.php)
3. [UserManagementExport.php](/home/fairland/Downloads/starmlm-laravel-production/app/Exports/UserManagementExport.php)
4. [WithdrawStatusImport.php](/home/fairland/Downloads/starmlm-laravel-production/app/Imports/WithdrawStatusImport.php)

### Routes

1. [api.php](/home/fairland/Downloads/starmlm-laravel-production/routes/api.php)
2. [console.php](/home/fairland/Downloads/starmlm-laravel-production/routes/console.php)

## Database Changes

### Daily Video Enhancements

Migration:

1. [2026_04_04_000005_add_delivery_mode_columns_to_daily_videos_table.php](/home/fairland/Downloads/starmlm-laravel-production/database/migrations/2026_04_04_000005_add_delivery_mode_columns_to_daily_videos_table.php)

Added columns:

1. `delivery_mode`
2. `priority`

Current modes:

1. `1 = Scheduled Daily`
2. `2 = Common Fallback`
3. `3 = New Joiner Default`

### Pin Request Lifecycle / Product Delivery

Migrations:

1. [2026_04_04_000006_add_lifecycle_columns_to_user_promoters_table.php](/home/fairland/Downloads/starmlm-laravel-production/database/migrations/2026_04_04_000006_add_lifecycle_columns_to_user_promoters_table.php)
2. [2026_04_04_000009_add_product_delivery_fields_to_user_promoters_table.php](/home/fairland/Downloads/starmlm-laravel-production/database/migrations/2026_04_04_000009_add_product_delivery_fields_to_user_promoters_table.php)

Added lifecycle columns:

1. `term_raised_at`
2. `terms_accepted_at`
3. `auto_deleted_at`
4. `deleted_reason`

Added product-delivery columns:

1. `product_delivery_status`
2. `product_delivery_notes`
3. `bill_path`
4. `product_delivery_updated_at`
5. `customer_delivery_status`
6. `customer_delivery_confirmed_at`

### Withdraw Admin Status Detail

Migration:

1. [2026_04_04_000010_add_admin_status_detail_fields_to_withdraw_requests_table.php](/home/fairland/Downloads/starmlm-laravel-production/database/migrations/2026_04_04_000010_add_admin_status_detail_fields_to_withdraw_requests_table.php)

Added columns:

1. `processing_details`
2. `completed_details`
3. `rejected_details`
4. `status_updated_at`
5. `status_updated_by`

### Support / Suggestions

Migrations:

1. [2026_04_04_000007_create_support_help_items_table.php](/home/fairland/Downloads/starmlm-laravel-production/database/migrations/2026_04_04_000007_create_support_help_items_table.php)
2. [2026_04_04_000008_create_user_suggestions_table.php](/home/fairland/Downloads/starmlm-laravel-production/database/migrations/2026_04_04_000008_create_user_suggestions_table.php)

Tables:

1. `support_help_items`
2. `user_suggestions`

## Business Rules

### Daily Video Resolution

Implemented in [DailyVideoController.php](/home/fairland/Downloads/starmlm-laravel-production/app/Http/Controllers/V1/Api/DailyVideoController.php).

Current resolution order:

1. If user joined today and a `New Joiner Default` video exists, serve that.
2. Else if a `Scheduled Daily` video exists for today, serve that.
3. Else if a `Common Fallback` video exists, serve that.
4. Else return no daily video.

### Pin Request Lifecycle

Implemented in [UserPromoterController.php](/home/fairland/Downloads/starmlm-laravel-production/app/Http/Controllers/V1/Api/UserPromoterController.php).

Current rules:

1. terms auto-raise after 10 minutes
2. untouched pending requests auto-delete after 3 days
3. current promoter access is preserved until the new pin is actually activated
4. admin pin generation requires terms to be accepted first

Scheduler helper:

1. command: `php artisan promoters:process-pending`
2. scheduled in [console.php](/home/fairland/Downloads/starmlm-laravel-production/routes/console.php)

### Suggestions Limit

Implemented in [UserSuggestionController.php](/home/fairland/Downloads/starmlm-laravel-production/app/Http/Controllers/V1/Api/UserSuggestionController.php).

Current rule:

1. a user can have at most `3` pending suggestions at one time
2. once admin reacts to one suggestion, one slot opens again

### Customer Bank Refill

Implemented in [UserBankDetailController.php](/home/fairland/Downloads/starmlm-laravel-production/app/Http/Controllers/V1/Api/UserBankDetailController.php).

Current behavior:

1. admin reset clears customer bank fields
2. `is_editable` becomes `0`
3. customer form becomes editable again and can be refilled

### Product Delivery / Bill Flow

Implemented in [UserPromoterController.php](/home/fairland/Downloads/starmlm-laravel-production/app/Http/Controllers/V1/Api/UserPromoterController.php).

Current behavior:

1. admin updates `product_delivery_status`
2. admin may add `product_delivery_notes`
3. admin may store a `bill_path`
4. customer can later confirm `Received` or `Not Received`

## API Summary

### Support & Help

Admin:

1. `PATCH /api/v1/support-help/status-update`
2. `POST /api/v1/support-help`
3. `PUT /api/v1/support-help/{id}`
4. `DELETE /api/v1/support-help/{id}`
5. `GET /api/v1/support-help/{id}`

Shared read:

1. `GET /api/v1/support-help`

### Suggestions

User:

1. `GET /api/v1/user-suggestions`
2. `POST /api/v1/user-suggestions`

Admin reaction:

1. `POST /api/v1/user-suggestions/react`

### Bank Reset

Admin:

1. `POST /api/v1/user-bank-detail/admin-reset`

Payload:

```json
{
  "user_id": 123
}
```

### Pin Requests

Admin:

1. `GET /api/v1/pin-requests/export/excel`
2. `POST /api/v1/pin-requests/product-delivery-status-update`

Customer:

1. `POST /api/v1/pin-requests/customer-delivery-confirmation`

Delivery update payload:

```json
{
  "id": 55,
  "product_delivery_status": 2,
  "product_delivery_notes": "Delivered through courier partner",
  "bill_path": "bills/promoter-55.pdf"
}
```

Customer confirmation payload:

```json
{
  "id": 55,
  "customer_delivery_status": 1
}
```

### Withdraw Requests

Admin:

1. `POST /api/v1/withdraw-status-update`
2. `GET /api/v1/withdraws/export/excel`
3. `POST /api/v1/withdraws/import/excel`

Status update payload:

```json
{
  "id": 77,
  "status": 1,
  "details": "Sent to finance team for bank processing"
}
```

Rejected payload:

```json
{
  "id": 77,
  "status": 3,
  "reason": "Bank details mismatch"
}
```

Excel import expectation:

1. column A: withdraw request id
2. column B: status text
3. column C: details

Accepted status text values:

1. `processing`
2. `completed`
3. `rejected`

### User Management

Admin:

1. `GET /api/v1/all-referrals`
2. `GET /api/v1/referrals/export/excel`

## Filtering And Sorting

### Pin Requests

Backend supports:

1. `level`
2. `status`
3. `product_delivery_status`
4. `gift_delivery_type`
5. `fromdate`
6. `todate`
7. search by user fields
8. sorting by `created_at`, `level`, `status`, `updated_at`, `activated_at`, `pin_generated_at`, `product_delivery_status`

### Withdraw Requests

Backend supports:

1. `status`
2. `wallet_type`
3. `fromdate`
4. `todate`
5. search by withdraw fields and user fields
6. sorting by `request_at`, `status`, `wallet_type`, `amount`, `created_at`

### User Management

Backend supports:

1. `current_promoter_level`
2. `is_active`
3. `fromdate`
4. `todate`
5. search by username/name/mobile
6. sorting by base user fields and computed `total_team_count`

Important note:

1. `total_team_count` sorting is currently done after loading the filtered user collection because the value is computed from the referral tree service, not stored directly in the users table.

## Export Notes

### Pin Request Export

Includes:

1. request id
2. username
3. mobile
4. upgrade level
5. request status
6. gift delivery type
7. product delivery status
8. customer delivery status
9. bill path
10. key dates

### Withdraw Export

Includes:

1. user details
2. bank details
3. request date
4. status
5. amount

Current export uses active filter/sort query from frontend.

### User Export

Includes:

1. user details
2. referrer
3. promoter level
4. total team count
5. direct referral count
6. joined date
7. active/inactive status

## Known Implementation Notes

1. `bill_path` is currently treated as a stored path or public URL string.
2. This release does not yet include a dedicated backend bill upload module distinct from the existing video upload flow.
3. Withdraw import updates status in bulk and prevents repeated wallet reversal by checking previous rejected state.
4. User-management export is intentionally restricted to admin/super-admin even though the route sits in the shared authenticated group.

## Recommended QA Checklist

1. add FAQ entries and verify they show in user help page
2. create 3 suggestions and confirm the 4th is blocked
3. react to 1 suggestion and confirm a new slot opens
4. clear bank details as admin and confirm customer can refill
5. export pin requests with filters applied
6. update product delivery status and confirm customer sees it
7. confirm customer `Received` / `Not Received` updates persist
8. export withdraw requests with filter and sort
9. import withdraw status Excel and verify detail fields
10. export users and verify `total_team_count`

## Rollout Notes

Before releasing:

1. run all new migrations
2. ensure Excel package is available in the target environment
3. test import/export with staging data
4. confirm bill paths are reachable from the customer portal
