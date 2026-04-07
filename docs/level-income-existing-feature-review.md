# Existing Feature Review For Level Income Release

## Purpose

This document lists the currently working product areas that must be protected while introducing the new Level Income Matrix feature.

The goal is to help future developers understand what already exists and what must not regress during implementation.

## Product Areas Present In Current Repo

### 1. Authentication

Covered by:

- `app/Http/Controllers/Auth/JwtAuthController.php`
- `routes/auth.php`

Current responsibilities:

1. register
2. login
3. auth user fetch
4. logout
5. change password
6. update personal details
7. delete account

Release safety note:

Level Income should not modify auth flow.

### 2. Daily Video Feature

Covered by:

- `app/Http/Controllers/V1/Api/DailyVideoController.php`

Current responsibilities:

1. admin CRUD for daily videos
2. status update
3. today video fetch
4. watched tracking
5. today status response

Release safety note:

Do not change daily video earning or watching flow as part of the first Level Income release unless explicitly required.

### 3. Training Video Feature

Covered by:

- `app/Http/Controllers/V1/Api/TrainingVideoController.php`
- `app/Http/Controllers/V1/Api/TrainingQuizController.php`
- `app/Http/Controllers/V1/Api/UserTrainingController.php`

Current responsibilities:

1. training video setup
2. quiz setup
3. assignment to users
4. completion progression

Release safety note:

No direct dependency on Level Income has been identified.

### 4. Promotion Video And Quiz Earning

Covered by:

- `app/Http/Controllers/V1/Api/PromotionVideoController.php`
- `app/Models/UserPromoterSession.php`

Current responsibilities:

1. serving promoter videos by session
2. quiz evaluation
3. session submission
4. wallet earning updates

Known risk:

Promotion earning logic currently contains hardcoded promoter-level mappings. This should not be casually changed during Level Income release unless the business explicitly wants both systems migrated together.

### 5. Referral Feature

Covered by:

- `app/Http/Controllers/V1/Api/ReferralController.php`
- `app/Models/User.php`

Current responsibilities:

1. create referred users
2. list direct referrals
3. edit referred users
4. show referral data

Current active relationship source:

- `users.referred_by`

Release safety note:

This is the primary place where the new tree traversal logic will connect, but direct referral behavior must remain unchanged.

### 6. Promoter Feature

Covered by:

- `app/Http/Controllers/V1/Api/UserPromoterController.php`
- `app/Models/UserPromoter.php`

Current responsibilities:

1. promoter request creation
2. term raised
3. term accepted
4. PIN generation
5. PIN rejection
6. promoter activation
7. dashboard summary
8. scratch-card listing and scratch update

Known risk:

Current flow assumes promoter levels up to 4 in validation and several code paths.

Release safety note:

The field type can be upgraded safely, but promoter activation behavior should remain the same unless Level Income specifically depends on it.

### 7. Scratch Setup And Scratch Earning

Covered by:

- `app/Http/Controllers/V1/Api/ScratchSetupController.php`
- `app/Models/ReferralScratchLevel.php`
- `app/Models/ReferralScratchRange.php`
- `app/Models/ScratchCard.php`

Current responsibilities:

1. admin setup of promoter-level scratch rules
2. range-based amount selection
3. scratch-card creation during promoter activation
4. scratch-card claim and earning-history creation

Release safety note:

This feature is already config-driven and should remain separate from Level Income unless there is an explicit merge plan.

### 8. Withdraw Feature

Covered by:

- `app/Http/Controllers/V1/Api/WithdrawController.php`
- `app/Models/WithdrawRequest.php`
- `app/Exports/WithdrawRequestExport.php`

Current responsibilities:

1. withdraw request creation
2. admin status updates
3. wallet restriction enforcement
4. export
5. history retrieval

Known risk:

Export currently uses hardcoded promoter labels up to level 4.

Release safety note:

Export should be updated only in a backward-compatible way.

### 9. Dashboard Features

Covered by:

- `app/Http/Controllers/V1/Api/UserPromoterController.php`
- `app/Http/Controllers/V1/Api/AdminDashboardController.php`

Current responsibilities:

1. user wallet summary
2. user promoter level summary
3. referral totals
4. admin aggregate counts

Release safety note:

New team-depth statistics can be added, but existing fields should stay unchanged.

## Existing Database Areas Relevant To New Release

### Existing tables directly related

1. `users`
2. `user_promoters`
3. `user_referrals`
4. `referral_scratch_levels`
5. `referral_scratch_ranges`
6. `scratch_cards`
7. `earning_histories`
8. `withdraw_requests`

### Immediate schema risks

1. `users.current_promoter_level` is `tinyInteger`
2. `user_promoters.level` is `tinyInteger`
3. some code assumes promoter values `0..4`

## Release Boundaries

### Must Stay Unchanged In First Release

1. referral creation workflow
2. promoter PIN workflow
3. scratch-card generation rules
4. daily video flow
5. training progression
6. withdraw timing and minimum amount rules
7. login/register behavior

### Can Be Added Safely

1. new level-income rule tables
2. new level-income service layer
3. new reporting endpoint for team depth
4. additive earning-history metadata
5. dynamic promoter label formatting

## Recommended Development Rule

When implementing this release, developers should ask:

1. Is this change additive?
2. Does it alter an existing wallet flow?
3. Does it change current API response shape?
4. Does it introduce hidden impact on promotion or scratch earnings?

If the answer to `2`, `3`, or `4` is yes, the change should be reviewed before merge.
