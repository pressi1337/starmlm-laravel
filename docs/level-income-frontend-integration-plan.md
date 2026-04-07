# Level Income Frontend Integration Plan

## Scope

This document maps the new backend `Level Income Matrix` feature to the embedded frontend project located at:

- `starmlm-frontend-production/`

The goal is to make the frontend ready for the new release without breaking current production UI flows.

## Frontend Areas Reviewed

The current frontend already contains the main screens we need:

1. user referral screen
2. user dashboard
3. user earnings history
4. admin promotion/referral settings screen
5. admin and user route configuration
6. sidebar navigation
7. shared service endpoint config

## Key Frontend Files

### API and routing

1. `starmlm-frontend-production/src/constants/services.ts`
2. `starmlm-frontend-production/src/routes/ROUTE-PATHS/AdminRoutes.tsx`
3. `starmlm-frontend-production/src/routes/ROUTE-PATHS/NormalUserRoutes.tsx`
4. `starmlm-frontend-production/src/layout/SideBar/SideBar.tsx`

### User-facing screens

1. `starmlm-frontend-production/src/app/Panel/USER/ReferralTree.tsx`
2. `starmlm-frontend-production/src/app/Panel/USER/EarningsHistory.tsx`
3. `starmlm-frontend-production/src/app/Panel/USER/UserDashboard/UserDashboard.tsx`
4. `starmlm-frontend-production/src/app/Panel/USER/UserDashboard.tsx`

### Admin-facing screens

1. `starmlm-frontend-production/src/app/Panel/ADMIN/ReferralSettings/ReferralSettings.tsx`

## Current Frontend State

### 1. User referral page

Current behavior in `ReferralTree.tsx`:

1. loads direct referrals from `SERVICE.REFERRALS`
2. uses dashboard data for totals
3. shows hardcoded promoter labels/colors for only a few levels
4. does not yet use the new backend team-summary endpoint

This is the primary screen that should surface:

1. direct IDs
2. team count by depth
3. total team count
4. dynamic promoter labels

### 2. Earnings history page

Current behavior in `EarningsHistory.tsx`:

1. maps earning types `1..6`
2. does not know about the new backend `EARNING_TYPE_LEVEL_INCOME = 7`
3. wallet badge logic is tied to old earning types only

This screen must be updated so level income appears clearly in history.

### 3. Admin referral settings page

Current behavior in `ReferralSettings.tsx`:

1. is built for scratch setup only
2. has fixed tabs for promoter levels `0..4`
3. cannot support unlimited promoter levels
4. edits `scratch-setup`, not level-income rules

This means the current admin page cannot be reused as-is for the new matrix.

### 4. Routes and menu

Current route/menu setup:

1. admin has `referral-settings`
2. user has `referrals`, `earnings-history`, `withdraw-requests`
3. no route exists yet for `level-income-rules`

## Backend Endpoints Already Added

The backend now exposes these new endpoints:

1. `GET /v1/level-income-rules`
2. `POST /v1/level-income-rules`
3. `GET /v1/level-income-rules/{id}`
4. `PUT /v1/level-income-rules/{id}`
5. `DELETE /v1/level-income-rules/{id}`
6. `PATCH /v1/level-income-rules/status-update`
7. `GET /v1/referrals/team-summary`

The frontend should integrate with these endpoints rather than trying to force the new feature into the existing scratch-setup UI.

## Recommended Frontend Plan

### Phase 1: Service Layer Updates

Update `src/constants/services.ts` with new entries:

1. `LEVEL_INCOME_RULES: "level-income-rules"`
2. `LEVEL_INCOME_RULES_STATUS_UPDATE: "level-income-rules/status-update"`
3. `REFERRAL_TEAM_SUMMARY: "referrals/team-summary"`

This should be done first so the UI screens can fetch the new backend endpoints cleanly.

### Phase 2: User Referral Screen Upgrade

Update `src/app/Panel/USER/ReferralTree.tsx` to use both:

1. `SERVICE.REFERRALS` for existing direct referral list compatibility
2. `SERVICE.REFERRAL_TEAM_SUMMARY` for new summary data

Recommended UI changes:

1. keep the direct referral list already shown today
2. add a new team-summary section
3. show count cards like:
   - direct promoters
   - total team count
   - depth 1 count
   - configured depth count
4. add a compact table or chip list for `team_counts_by_depth`

Important:

Do not remove the current direct-referral list in the first frontend release.

### Phase 3: Dynamic Promoter Label Handling

Replace hardcoded level mappings in `ReferralTree.tsx` with a reusable helper:

1. `0 => Promoter`
2. `1 => Promoter Level 1`
3. `N => Promoter Level N`
4. null => `Trainee` or `Unknown`, based on existing UX decision

This is needed because promoter levels are now unlimited.

The color mapping should also become range-based or generated, not fixed only for `0..5`.

### Phase 4: Earnings History Update

Update `src/app/Panel/USER/EarningsHistory.tsx` to support:

1. new `earning_type = 7`
2. new title like `Level Income`
3. optional description support from backend history row

Recommended wallet badge logic:

1. do not infer wallet only from old earning-type mapping
2. at minimum, treat level income as `Cash` for release 1 because backend defaults to main wallet unless configured otherwise

### Phase 5: Admin Matrix Management Screen

Do not overload the current `ReferralSettings.tsx` scratch screen.

Recommended approach:

1. keep current `ReferralSettings.tsx` for scratch setup
2. create a new admin screen for level income matrix, for example:
   - `src/app/Panel/ADMIN/LevelIncomeSettings/LevelIncomeSettings.tsx`
3. add a new admin route
4. add a new sidebar menu item

Recommended admin UI behavior:

1. list matrix rows in a table
2. filters:
   - promoter level
   - referral depth
   - trigger type
   - wallet type
   - active/inactive
3. allow create, edit, delete, activate/deactivate
4. avoid fixed tabs for promoter levels
5. support unlimited rows via table + modal form

This is a better fit for the backend rule model than the existing tabbed scratch-range UI.

### Phase 6: Admin Navigation

Update:

1. `src/routes/ROUTE-PATHS/AdminRoutes.tsx`
2. `src/layout/SideBar/SideBar.tsx`

Add:

1. route for `level-income-settings`
2. sidebar item like `Level Income Matrix`

Keep the current `Promotion Settings` item for scratch setup unless business wants a rename later.

### Phase 7: User Dashboard Enhancement

Optional but recommended:

Update dashboard-related user screens to surface:

1. total team count
2. direct promoters count
3. current promoter label using dynamic formatting

This can use `SERVICE.REFERRAL_TEAM_SUMMARY` and existing `SERVICE.USER_DASHBOARD`.

## Production-Safe Frontend Rules

The first frontend release should follow these rules:

1. do not remove existing pages
2. do not rename old routes unless necessary
3. do not replace scratch setup UI with level income UI
4. keep old referral list visible
5. add new summary sections rather than rewriting everything

## Recommended Delivery Order

1. add new service constants
2. add dynamic promoter label helper
3. update earnings history for level income type
4. enhance referral page with team summary
5. add admin matrix page
6. add route and sidebar entry
7. test both admin and user flows

## Frontend Test Checklist

### User tests

1. referral page still lists direct referrals
2. referral page shows total team count and depth counts
3. users with promoter level greater than 4 display correctly
4. earnings history shows `Level Income` rows

### Admin tests

1. admin can list matrix rules
2. admin can create new promoter level/depth rules
3. admin can manage higher promoter levels like 5, 10, and 25
4. admin can activate/deactivate rules

## Final Recommendation

Frontend should integrate the new feature by:

1. enhancing the user referral page
2. extending earnings history
3. introducing a separate admin matrix-management page

That is the cleanest way to support unlimited promoter levels and unlimited referral depth without disturbing the current scratch and referral UI.
