# Level Income Technical Specification

## Feature Summary

Introduce a new configuration-driven Level Income Matrix where payout depends on:

1. ancestor promoter level
2. referral depth from the triggering user
3. configured payout rule

The design must support:

1. unlimited promoter levels
2. unlimited referral depth
3. production-safe release with no regression in current features

## Release 1 Defaults

The current implementation follows these defaults:

1. trigger event: promoter activation
2. default wallet target: main wallet
3. payout source traversal: ancestor chain from the activated promoter user
4. existing scratch and promotion earnings remain unchanged

## Core Design Principles

1. configuration over hardcoding
2. additive rollout over invasive rewrite
3. clear audit trail for all generated income
4. isolated service layer for payout distribution

## Proposed Tables

### `level_income_rules`

Suggested columns:

1. `id`
2. `promoter_level` integer
3. `referral_depth` integer
4. `amount` decimal(10,2)
5. `trigger_type` tinyInteger or integer
6. `wallet_type` tinyInteger or integer
7. `is_active` tinyInteger default 1
8. `is_deleted` tinyInteger default 0
9. `created_by`
10. `updated_by`
11. timestamps

Suggested uniqueness:

- one active logical rule per `promoter_level + referral_depth + trigger_type + wallet_type`

### Additive fields for `earning_histories`

Suggested new fields:

1. `source_user_id`
2. `referral_depth`
3. `beneficiary_promoter_level`
4. `trigger_type`
5. `income_rule_id`
6. `reference_id`

If modifying `earning_histories` feels too risky, a separate `level_income_histories` table can be created and linked to `earning_histories`.

## Required Schema Adjustments

The following columns should be migrated from `tinyInteger` to `integer`:

1. `users.current_promoter_level`
2. `user_promoters.level`

This is required for unlimited promoter levels.

## Service Layer Plan

### 1. `ReferralTreeService`

Responsibilities:

1. get direct referrals
2. get descendants up to any depth
3. get ancestors up to any depth
4. count members grouped by depth
5. count total team members across all configured depths

Recommended source:

- `users.referred_by`

### 2. `LevelIncomeRuleService`

Responsibilities:

1. fetch active rule for `(promoter_level, referral_depth, trigger_type, wallet_type)`
2. validate duplicate or overlapping rule creation
3. expose matrix for admin display

### 3. `LevelIncomePayoutService`

Responsibilities:

1. accept a trigger event
2. walk up the ancestor chain
3. determine depth number for each ancestor
4. read ancestor promoter level
5. load rule
6. create earning records
7. update beneficiary wallet
8. prevent duplicate payout for the same business event

## Trigger Strategy

This must be confirmed before coding.

Possible trigger sources:

1. referral signup
2. promoter activation
3. promotion earning confirmation
4. scratch-card claim
5. another business event

Recommended implementation pattern:

Wrap the payout engine behind a clear service entry like:

- `distributeLevelIncome($triggerType, $sourceUser, $referenceModel, $context = [])`

This keeps the trigger point flexible without scattering logic across controllers.

## Wallet Strategy

This must also be confirmed before coding.

Possible wallet destinations:

1. main wallet
2. scratch wallet
3. grow wallet
4. dedicated level-income wallet

Recommended short-term choice:

- reuse an existing wallet only if business confirms it
- otherwise add a dedicated wallet to avoid mixing accounting semantics

## API Plan

### Admin APIs

1. `GET /v1/level-income-rules`
2. `POST /v1/level-income-rules`
3. `GET /v1/level-income-rules/{id}`
4. `PUT /v1/level-income-rules/{id}`
5. `PATCH /v1/level-income-rules/status-update`
6. `DELETE /v1/level-income-rules/{id}`

### User/Admin Reporting APIs

1. `GET /v1/referrals/team-summary`
2. optional `GET /v1/level-income-histories`

### Reporting response example

```json
{
  "success": true,
  "data": {
    "direct_referrals": [
      {
        "id": 101,
        "username": "user101",
        "current_promoter_level": 2
      }
    ],
    "team_counts_by_depth": [
      { "depth": 1, "count": 12 },
      { "depth": 2, "count": 44 },
      { "depth": 3, "count": 123 }
    ],
    "total_team_count": 179
  }
}
```

This fits the requirement to show direct IDs while still showing overall team-member counts.

## Backward Compatibility Rules

1. do not remove or repurpose existing endpoints in first release
2. do not modify scratch setup tables for level income
3. do not replace promotion earning rules in first release unless separately approved
4. do not change withdraw rules
5. do not change referral creation behavior

## Testing Requirements

### Unit/Service Tests

1. ancestor traversal for depth 1, 2, 7, and 20
2. rule lookup for high promoter levels
3. duplicate payout protection
4. no payout when no active rule exists

### Feature Tests

1. team summary endpoint with direct-only list
2. admin rule CRUD
3. payout trigger integration
4. earning-history trace creation
5. unchanged existing referral listing
6. unchanged scratch claim flow
7. unchanged promotion earning flow

## Rollout Notes

### Recommended rollout process

1. deploy schema changes first
2. deploy code with feature disabled
3. add admin rule data
4. run staging/UAT with deep-chain test data
5. enable feature after business confirmation

### Rollback strategy

1. disable feature flag
2. leave additive schema in place
3. stop new level-income payouts
4. do not roll back old working features

## Open Questions

These must be finalized before implementation:

1. exact trigger event
2. exact wallet destination
3. whether promoter level `0` should also be allowed in matrix rules
4. whether only activated promoters are eligible
5. whether team summary should count only active users or all referred users
