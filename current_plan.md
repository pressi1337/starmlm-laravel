# Level Income Matrix Release Plan

## Objective

Build a new release feature for a config-driven `Level Income Matrix` with:

- unlimited promoter levels
- unlimited referral depths
- production-safe rollout
- no regression in existing working features
- clear developer documentation for future maintenance

This document is a planning artifact only. No feature code is included here.

## Follow-Up Release Notes

After the initial Level Income release work, the next production-safe enhancement scope was added and implemented in code with these directions:

1. training flow reduced from 7 days to 5 days
2. quiz selection labels standardized to `English` and `தமிழ்`
3. quiz default timing standardized to 55 seconds
4. user referral screen enhanced with:
   - My Promotor
   - direct-only emphasis
   - total team count
   - ceiling limit
5. admin customer listing enhanced with a full-width team-tree modal
6. promotion video delivery redesigned away from strict `today + session + order` lookup into tracked user-specific assignment history with replay fallback

These changes are additive and intended to preserve existing production behavior as much as possible while making promotion delivery more flexible for new uploads.

## Production-Safety Principle

This product is already running in production, so the Level Income feature must be introduced as an additive release, not a rewrite.

The implementation must follow these rules:

1. Do not break any existing API contract unless a new versioned endpoint is introduced.
2. Do not change the meaning of current earnings, scratch, promoter, or withdraw flows unless explicitly approved.
3. Add the new feature behind configuration and isolated services.
4. Keep old logic working until the new feature is fully tested and enabled.
5. Prefer new tables and additive columns over risky rewrites.

## Existing Working Features Reviewed

The current repo already contains these working domains:

1. Authentication and user profile management
2. Daily video flow
3. Training video assignment and completion
4. Promotion video and quiz earning flow
5. Referral user creation and direct referral listing
6. Promoter request, PIN approval, and activation flow
7. Scratch setup and scratch-card earning flow
8. Withdraw request flow for multiple wallets
9. Admin dashboard and admin setup APIs
10. Bank detail management
11. Video upload support

## Existing Constraints Found

These places currently assume fixed promoter limits:

1. `users.current_promoter_level` uses `tinyInteger`
2. `user_promoters.level` uses `tinyInteger`
3. promoter request validation uses `max:4`
4. export labels stop at promoter level 4
5. promotion earning logic uses hardcoded level mappings
6. some dashboard/referral queries only work on direct referrals

These are important because unlimited levels and unlimited depth cannot be added safely without removing those fixed assumptions.

## Feature Interpretation From `image.png`

The requested feature is:

1. a level-income matrix
2. currently represented visually with 7 referral depths
3. currently represented visually with promoter columns up to level 4
4. final implementation must not be capped at 7 depths
5. final implementation must not be capped at promoter level 4
6. direct IDs should remain visible
7. overall team counts should be available across configured levels/depth

## Recommended Design Direction

Implement the feature as a fully configuration-driven release:

1. promoter levels are stored as dynamic integer values
2. referral depth is stored as dynamic integer values
3. payout amount is read from setup tables, not hardcoded in controllers
4. traversal logic is centralized in a reusable referral-tree service
5. payouts are recorded with strong traceability in earning history
6. existing features remain untouched unless they explicitly opt into the new engine

## Safe Rollout Strategy

### Phase 1: Foundation

1. Add new DB structures for level-income configuration
2. Add new DB structures or additive fields for payout traceability
3. Change promoter-level DB columns from `tinyInteger` to `integer`
4. Do not replace old earning logic yet

### Phase 2: Read-Only Tree and Reporting

1. Build a referral-tree service using existing `users.referred_by`
2. Add methods for:
   - direct children
   - descendants by depth
   - ancestor chain by depth
   - team counts by level
3. Expose reporting-only APIs first if needed

### Phase 3: Admin Configuration

1. Add admin CRUD for the level-income matrix
2. Allow any promoter level number
3. Allow any referral depth number
4. Keep rules inactive by default until validated

### Phase 4: Payout Engine

1. Add a dedicated service for level-income distribution
2. Trigger it only from the approved business event
3. Write earning history records for every distributed level income
4. Keep this engine isolated from scratch and promotion earning logic

### Phase 5: Controlled Activation

1. Add feature flag or config switch
2. Run UAT with matrix setup data
3. Enable only after business validation

## Non-Regression Boundaries

The following existing features must remain behaviorally unchanged unless separately approved:

1. Daily video earning rules
2. Training flow and training assignments
3. Promotion video session flow
4. Existing scratch-card generation logic
5. Existing scratch wallet behavior
6. Existing withdraw rules and timing restrictions
7. Existing auth and user onboarding
8. Existing referral creation behavior

## Recommended Data Model

### 1. Level Income Rule Table

Create a new table for rules, for example:

- `level_income_rules`

Recommended columns:

- `id`
- `promoter_level`
- `referral_depth`
- `amount`
- `wallet_type`
- `trigger_type`
- `is_active`
- `is_deleted`
- `created_by`
- `updated_by`
- timestamps

This structure gives unlimited promoter levels and unlimited referral depth without changing code each time.

### 2. Level Income Earning Trace

Either extend `earning_histories` or add a dedicated linkage table.

Recommended additive fields if extending `earning_histories`:

- `source_user_id`
- `beneficiary_user_id` or reuse `user_id`
- `referral_depth`
- `beneficiary_promoter_level`
- `trigger_type`
- `income_rule_id`
- `reference_id`

This is needed for auditability in production.

## Recommended Source of Truth for Referral Tree

Use `users.referred_by` as the primary genealogy source for now.

Reason:

1. referral creation already writes to `users.referred_by`
2. current direct referral listing already depends on it
3. `user_referrals` exists but does not appear to be the active source of truth

Unless there is a confirmed business need, we should not switch the active tree source during this release.

## API Plan

### Keep Existing APIs Stable

Existing endpoints should continue to behave as they do now.

### Additive Endpoints

Recommended new endpoints:

1. level income setup endpoints for admin
2. referral team summary endpoint
3. optional level-income history endpoint

Possible examples:

- `GET /v1/level-income-rules`
- `POST /v1/level-income-rules`
- `PUT /v1/level-income-rules/{id}`
- `PATCH /v1/level-income-rules/status-update`
- `GET /v1/referrals/team-summary`

## Business Decisions Required Before Development

These items must be confirmed before coding starts:

1. What event triggers level income?
   - referral signup
   - promoter activation
   - promotion earning
   - scratch earning
   - some other event

2. Which wallet receives level income?
   - main wallet
   - scratch wallet
   - grow wallet
   - a new wallet

3. Should payout happen only if ancestor promoter level is equal to or higher than some threshold?

4. Should inactive or non-activated promoters receive level income?

5. Should the matrix allow duplicate entries for the same `(promoter_level, referral_depth, trigger_type)` combination?

My recommendation is:

1. one active rule per `(promoter_level, referral_depth, trigger_type)`
2. payout only to activated promoters
3. use config-driven depth with no code cap

## Implementation Checklist

1. create migration for unlimited integer promoter levels
2. create migration for level-income rule table
3. create model for level-income rules
4. create admin controller and routes for rule management
5. create referral-tree service
6. create level-income payout service
7. extend earning history for traceability
8. add team summary API
9. replace hardcoded promoter-label formatting with dynamic formatting
10. review any validations that still assume max promoter level 4
11. write tests
12. prepare rollout notes and rollback notes

## Testing Strategy

Before production release, test these cases:

1. promoter level 5, 10, and 25 can be stored safely
2. referral depth 8, 15, and 30 can be configured safely
3. direct referral listing still returns the same data as before
4. existing scratch-card behavior is unchanged
5. existing promotion earning behavior is unchanged unless intentionally refactored
6. payout engine gives correct beneficiaries for deep chains
7. no duplicate level-income entries are generated for the same event
8. withdraw history/export still works for higher promoter levels
9. dashboards do not fail when promoter levels exceed 4

## Deliverables For Developers

This release planning package should include:

1. this main plan
2. an existing-feature impact review
3. a technical specification for the new release

Related files:

1. `current_plan.md`
2. `docs/level-income-existing-feature-review.md`
3. `docs/level-income-technical-spec.md`

## Final Recommendation

Proceed with a strictly additive implementation:

1. new config tables
2. new service layer
3. new reporting endpoints
4. minimal controlled changes to shared promoter-level fields
5. no silent behavior changes to current earning engines

That is the safest path for a production system.

## Release 1 Implementation Assumption

The first implementation of this feature is designed with these defaults:

1. Level income is triggered on promoter activation.
2. Default payout wallet is the existing main wallet unless rule setup says otherwise.
3. Existing scratch and promotion earning flows remain active and are not replaced by the level-income engine.
4. Team summary is exposed through a separate reporting endpoint.

## Additional Release Notes

1. Daily video delivery now supports:
   - `Scheduled Daily`
   - `Common Fallback`
   - `New Joiner Default`
2. User daily video resolution now follows:
   - joining-day default video
   - date-matched scheduled video
   - common fallback video
3. Pin request lifecycle now supports:
   - terms auto-raise after 10 minutes
   - untouched pending requests auto-delete after 3 days
4. Upgrade requests no longer replace the currently active promoter access until final pin activation.
5. Lifecycle automation command added:
   - `php artisan promoters:process-pending`

## Additional Release Notes 2

1. Support and Help now supports admin-configured repeated questions and answers for the user portal.
2. Suggestions now allows users to submit ideas with a rolling limit of 3 pending suggestions until admin reacts.
3. Admin can react to suggestions using text, emoji, or both.
4. Admin can now clear a customer's bank account details and reopen the bank form so the customer can refill it again.
