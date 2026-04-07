# New Enhancements Frontend Developer Guide

## Purpose

This document summarizes the frontend work added for the latest enhancement release.

It focuses on:

1. new screens
2. updated screens
3. new filters and service constants
4. how the new admin and customer flows behave in UI

## Scope Covered

This frontend guide covers:

1. support and help
2. suggestions
3. admin bank reset action
4. daily video delivery mode support
5. pin request export and product delivery update
6. withdraw export/import and status detail updates
7. user management export and team-count visibility
8. customer product delivery confirmation and bill download

## Main Frontend Files

### New Screens

1. [Suggestions.tsx](/home/fairland/Downloads/starmlm-laravel-production/starmlm-frontend-production/src/app/Panel/USER/Suggestions.tsx)
2. [SupportHelp.tsx](/home/fairland/Downloads/starmlm-laravel-production/starmlm-frontend-production/src/app/Panel/ADMIN/SupportHelp/SupportHelp.tsx)
3. [SuggestionManagement.tsx](/home/fairland/Downloads/starmlm-laravel-production/starmlm-frontend-production/src/app/Panel/ADMIN/SuggestionManagement/SuggestionManagement.tsx)
4. [ProductDeliveryFormModal.tsx](/home/fairland/Downloads/starmlm-laravel-production/starmlm-frontend-production/src/components/FormModals/ProductDeliveryModal/ProductDeliveryFormModal.tsx)

### Updated Screens

1. [ContactUs.tsx](/home/fairland/Downloads/starmlm-laravel-production/starmlm-frontend-production/src/app/Panel/USER/ContactUs.tsx)
2. [PinRequests.tsx](/home/fairland/Downloads/starmlm-laravel-production/starmlm-frontend-production/src/app/Panel/ADMIN/PinRequests/PinRequests.tsx)
3. [PinRequests.tsx](/home/fairland/Downloads/starmlm-laravel-production/starmlm-frontend-production/src/app/Panel/USER/PinRequests.tsx)
4. [WithDrawRequest.tsx](/home/fairland/Downloads/starmlm-laravel-production/starmlm-frontend-production/src/app/Panel/ADMIN/WithDrawRequest/WithDrawRequest.tsx)
5. [UserManagement.tsx](/home/fairland/Downloads/starmlm-laravel-production/starmlm-frontend-production/src/app/Panel/ADMIN/UserManagement/UserManagement.tsx)
6. [DailyVideoForm.tsx](/home/fairland/Downloads/starmlm-laravel-production/starmlm-frontend-production/src/components/FormModals/DailyVideoModal/DailyVideoForm.tsx)
7. [DailyVideos.tsx](/home/fairland/Downloads/starmlm-laravel-production/starmlm-frontend-production/src/app/Panel/ADMIN/DailyVideos/DailyVideos.tsx)
8. [DailyVideoWatch.tsx](/home/fairland/Downloads/starmlm-laravel-production/starmlm-frontend-production/src/app/Panel/USER/DailyVideoWatch.tsx)

### Shared Config / Helpers

1. [services.ts](/home/fairland/Downloads/starmlm-laravel-production/starmlm-frontend-production/src/constants/services.ts)
2. [others.ts](/home/fairland/Downloads/starmlm-laravel-production/starmlm-frontend-production/src/constants/others.ts)
3. [FilterTab.tsx](/home/fairland/Downloads/starmlm-laravel-production/starmlm-frontend-production/src/components/FilterTab.tsx)
4. [useGetCall.ts](/home/fairland/Downloads/starmlm-laravel-production/starmlm-frontend-production/src/hooks/useGetCall.ts)
5. [SideBar.tsx](/home/fairland/Downloads/starmlm-laravel-production/starmlm-frontend-production/src/layout/SideBar/SideBar.tsx)
6. [AdminRoutes.tsx](/home/fairland/Downloads/starmlm-laravel-production/starmlm-frontend-production/src/routes/ROUTE-PATHS/AdminRoutes.tsx)
7. [NormalUserRoutes.tsx](/home/fairland/Downloads/starmlm-laravel-production/starmlm-frontend-production/src/routes/ROUTE-PATHS/NormalUserRoutes.tsx)

## UI Flows

### User: Support & Help

Screen:

1. [ContactUs.tsx](/home/fairland/Downloads/starmlm-laravel-production/starmlm-frontend-production/src/app/Panel/USER/ContactUs.tsx)

Current behavior:

1. page title changed from simple contact page to `Support & Help`
2. user sees FAQ-style repeated questions and answers first
3. bank/contact details still remain on the same page

API used:

1. `SERVICE.SUPPORT_HELP`
2. `SERVICE.GET_ADMIN_BANK_DETAILS`

### Admin: Support & Help

Screen:

1. [SupportHelp.tsx](/home/fairland/Downloads/starmlm-laravel-production/starmlm-frontend-production/src/app/Panel/ADMIN/SupportHelp/SupportHelp.tsx)

Current behavior:

1. create new help item
2. edit existing help item
3. activate/deactivate help item

### User: Suggestions

Screen:

1. [Suggestions.tsx](/home/fairland/Downloads/starmlm-laravel-production/starmlm-frontend-production/src/app/Panel/USER/Suggestions.tsx)

Current behavior:

1. shows available suggestion slots
2. user submits suggestion text
3. user sees admin response text and emoji reaction later

Important UI rule:

1. submit button becomes effectively blocked when available slots are `0`

### Admin: Suggestions

Screen:

1. [SuggestionManagement.tsx](/home/fairland/Downloads/starmlm-laravel-production/starmlm-frontend-production/src/app/Panel/ADMIN/SuggestionManagement/SuggestionManagement.tsx)

Current behavior:

1. list all suggestions
2. enter response text
3. choose emoji reaction
4. save reaction

## Admin Enhancements

### Pin Requests

Screen:

1. [PinRequests.tsx](/home/fairland/Downloads/starmlm-laravel-production/starmlm-frontend-production/src/app/Panel/ADMIN/PinRequests/PinRequests.tsx)

Added UI behavior:

1. Excel export button
2. product delivery status column
3. bill link display
4. product delivery modal action for activated requests
5. additional filter: `Product Delivery Status`

Modal:

1. [ProductDeliveryFormModal.tsx](/home/fairland/Downloads/starmlm-laravel-production/starmlm-frontend-production/src/components/FormModals/ProductDeliveryModal/ProductDeliveryFormModal.tsx)

Current fields:

1. `product_delivery_status`
2. `product_delivery_notes`
3. `bill_path`

Important note:

1. bill currently uses a path/URL input, not a dedicated document uploader

### Withdraw Requests

Screen:

1. [WithDrawRequest.tsx](/home/fairland/Downloads/starmlm-laravel-production/starmlm-frontend-production/src/app/Panel/ADMIN/WithDrawRequest/WithDrawRequest.tsx)

Added UI behavior:

1. export button now sends current filter/sort
2. import button accepts `.xlsx`, `.xls`, `.csv`
3. processing details prompt
4. completion details prompt
5. rejection details display now prefers richer backend fields
6. extra filters:
   - withdraw status
   - wallet type
   - date range

### User Management

Screen:

1. [UserManagement.tsx](/home/fairland/Downloads/starmlm-laravel-production/starmlm-frontend-production/src/app/Panel/ADMIN/UserManagement/UserManagement.tsx)

Added UI behavior:

1. export button
2. active-status filter
3. `Total Members` column
4. promoter level column is sortable
5. existing bank reset action remains available

## Customer Enhancements

### Customer Product Delivery / Bill

Screen:

1. [PinRequests.tsx](/home/fairland/Downloads/starmlm-laravel-production/starmlm-frontend-production/src/app/Panel/USER/PinRequests.tsx)

Added UI behavior:

1. activated pin rows now show product delivery section
2. product delivery badge visible to customer
3. delivery notes visible
4. `Download Bill` link shown when `bill_path` exists
5. customer can mark:
   - `Received`
   - `Not Received`

## Daily Video UI Enhancements

### Admin Daily Video Form

Files:

1. [DailyVideoForm.tsx](/home/fairland/Downloads/starmlm-laravel-production/starmlm-frontend-production/src/components/FormModals/DailyVideoModal/DailyVideoForm.tsx)
2. [DailyVideos.tsx](/home/fairland/Downloads/starmlm-laravel-production/starmlm-frontend-production/src/app/Panel/ADMIN/DailyVideos/DailyVideos.tsx)

Added UI behavior:

1. delivery mode select
2. priority field
3. display of delivery mode in listing

### User Daily Video Screen

File:

1. [DailyVideoWatch.tsx](/home/fairland/Downloads/starmlm-laravel-production/starmlm-frontend-production/src/app/Panel/USER/DailyVideoWatch.tsx)

Added UI behavior:

1. resolved daily-video delivery mode badge shown to the user

## Shared Frontend Technical Notes

### Service Constants Added

See [services.ts](/home/fairland/Downloads/starmlm-laravel-production/starmlm-frontend-production/src/constants/services.ts).

Important additions:

1. `SUPPORT_HELP`
2. `SUPPORT_HELP_STATUS_UPDATE`
3. `USER_SUGGESTIONS`
4. `USER_SUGGESTIONS_REACT`
5. `ADMIN_RESET_USER_BANK_DETAIL`
6. `PIN_REQUEST_EXPORT_EXCEL`
7. `PIN_REQUEST_PRODUCT_DELIVERY_UPDATE`
8. `CUSTOMER_DELIVERY_CONFIRMATION`
9. `WITHDRAW_IMPORT_EXCEL`
10. `USER_EXPORT_EXCEL`

### Options / Filters Added

See [others.ts](/home/fairland/Downloads/starmlm-laravel-production/starmlm-frontend-production/src/constants/others.ts).

Important additions:

1. `DAILY_VIDEO_DELIVERY_MODE`
2. `WITHDRAW_STATUS`
3. `WALLET_TYPE_FILTER`
4. `ACTIVE_STATUS`
5. `PRODUCT_DELIVERY_STATUS`
6. `CUSTOMER_DELIVERY_STATUS`
7. `PRODUCT_DELIVERY_MODAL`

### FilterTab Enhancements

See [FilterTab.tsx](/home/fairland/Downloads/starmlm-laravel-production/starmlm-frontend-production/src/components/FilterTab.tsx).

New filter tokens supported:

1. `PRODUCT_DELIVERY_STATUS`
2. `WITHDRAW_STATUS`
3. `WALLET_TYPE`
4. `ACTIVE_STATUS`

### Sorting Compatibility Fix

See [useGetCall.ts](/home/fairland/Downloads/starmlm-laravel-production/starmlm-frontend-production/src/hooks/useGetCall.ts).

Important change:

1. frontend now sends both:
   - `sortBy` / `sortDir`
   - `sort_column` / `sort_direction`

This was added so existing Laravel controllers can sort correctly without requiring large frontend rewrites.

## Route / Sidebar Changes

### User

Added route:

1. `/portal/user/suggestions`

Updated nav:

1. `Contact Us` label became `Support & Help`
2. `Suggestions` menu item added

### Admin

Added routes:

1. `/portal/admin/support-help`
2. `/portal/admin/suggestions`

Updated nav:

1. `Support & Help` admin menu added
2. `Suggestions` admin menu added

## UX / State Assumptions

1. suggestion slot count comes from backend `meta.available_slots`
2. product delivery modal uses query-param-based modal state like the rest of this app
3. file/document bill handling is currently string-path based
4. export buttons use `useGetCall(..., { exports: true })`
5. withdraw import uses native `fetch` with `FormData`

## QA Checklist

1. open user support page and verify FAQ answers appear
2. open user suggestions page and create suggestions until the limit is hit
3. react from admin suggestions page and confirm slot unlocks
4. export pin requests with filters
5. update product delivery status and confirm customer view updates
6. check bill link opens when `bill_path` is set
7. customer marks `Received` and `Not Received`
8. export withdraw requests using date and status filters
9. import withdraw Excel and verify row statuses change
10. export users and verify `Total Members` column

## Known Frontend Limitations

1. dedicated bill upload UI is not implemented yet
2. frontend build was not verified locally in this environment because dependencies are missing
3. some older screens still contain legacy labels or styles in unrelated areas, but the flows covered in this document are wired to the new backend behavior
