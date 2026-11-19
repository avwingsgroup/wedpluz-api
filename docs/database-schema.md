# Database schema

PostgreSQL 15+ (developed on 18). The schema is defined entirely by the migrations in `database/migrations/`; this document explains it and lists every table. The **table reference** in §5 is generated from a freshly migrated database, so it matches the migrations exactly.

```bash
php artisan migrate            # create/upgrade the schema
php artisan db:seed            # reference data + bootstrap super admin
```

## 1. Conventions

| Convention | Detail |
|---|---|
| Primary keys | Prefixed ULIDs in `varchar(40)` (`usr_01J…`, `evt_…`, `ord_…`). Time-sortable, self-describing. |
| Money | `bigint` minor units (kobo) plus a `currency` char(3) column where relevant. Never floats. |
| Timestamps | `timestamptz`, stored in UTC. |
| Enums | `varchar` columns validated by application enums (`app/Enums`); a few critical ones also have check constraints. New values never need a type migration. |
| Soft deletes | `deleted_at` on user-generated content that moderation or support may need to restore (users, events, messages, statuses, reviews, works). |
| JSON | `jsonb` for flexible, non-relational detail (provider payloads, settings values, audit before/after). |
| Polymorphism | `*_type` columns hold morph-map aliases (`order`, `user`, `wallet_transaction`, …), never class names. |
| Extensions | `pg_trgm` (fuzzy user/vendor search). Event search uses a generated `tsvector` column with a GIN index. |

## 2. Domains

| Domain | Tables |
|---|---|
| Identity & accounts | `users`, `user_roles`, `social_accounts`, `otp_codes`, `user_privacy_settings`, `notification_preferences`, `device_tokens`, `interests`, `user_interests`, `identity_verifications`, `account_deletion_requests`, `blocks`, `user_activities` |
| Tokens | `personal_access_tokens` (Sanctum, string morph ids, device metadata), `refresh_tokens` (hashed, family + rotation) |
| Admin & platform | `admin_users`, `admin_roles`, `admin_role_permissions`, `admin_user_roles`, `audit_logs`, `security_logs`, `platform_settings`, `banners`, `cms_pages`, `cms_page_versions`, `support_tickets`, `support_messages`, `system_alerts`, `export_jobs` |
| Infrastructure | `uploads`, `media`, `idempotency_keys`, `webhook_events`, `integration_logs`, Laravel `jobs`/`failed_jobs`/`cache` |
| Vendors | `vendor_categories`, `vendor_profiles`, `vendor_verification_documents`, `vendor_works`, `work_likes`, `vendor_reviews`, `vendor_promos`, `saved_vendors` |
| Events | `event_categories`, `events`, `event_schedules`, `sub_events`, `ticket_types`, `saved_events`, `event_requests`, `promo_codes`, `hall_layouts`, `planner_menus` |
| Checkout & tickets | `checkout_quotes`, `inventory_holds`, `orders`, `order_lines`, `order_food_lines`, `seat_reservations`, `payments`, `tickets`, `check_ins`, `refunds`, `ticket_transfers`, `disputes` |
| Collaboration | `event_managers`, `event_vendor_assignments`, `event_tasks`, `event_task_assignees`, `event_task_attachments`, `activity_logs`, `event_promotions`, `promoter_enrollments`, `promoter_commissions` |
| Communities | `communities`, `community_members`, `community_invites`, `status_posts`, status views/likes, `live_sessions`, `live_requests` |
| Messaging | `conversations`, `conversation_participants`, `messages`, `message_reactions`, `message_stars` |
| Moderation | `content_reports`, `moderation_removals` |
| Notifications | `notifications`, `notification_templates`, `notification_campaigns`, `notification_deliveries`, `email_suppressions` |
| Finance | `wallets`, `ledger_entries`, `wallet_transactions`, `banks`, `bank_accounts`, `commission_rules`, `settlements`, `payouts`, `payout_settlements`, `reconciliation_records` |
| Subscriptions | `plans`, `subscriptions`, `subscription_payments` |
| Planner | `planner_chats`, `planner_chat_messages`, `planner_generations` |

## 3. Core relationships

```
users ─┬─< events (organizer_user_id) ─┬─< event_schedules
       │                               ├─< ticket_types ─< order_lines
       │                               ├─< sub_events
       │                               ├─< event_managers >── users
       │                               ├─< event_vendor_assignments >── vendor_profiles
       │                               ├─< event_tasks ─< event_task_assignees
       │                               └── communities (0..1 per event)
       ├─< orders ─┬─< order_lines / order_food_lines / seat_reservations
       │           ├─< payments (payable = order | wallet_transaction | subscription_payment)
       │           └─< tickets ─┬─< check_ins
       │                        ├─< refunds
       │                        └─< ticket_transfers
       ├── wallets ─< wallet_transactions        ledger_entries (account strings, transaction_id groups)
       ├─< bank_accounts
       ├── vendor_profiles ─< vendor_works / vendor_reviews / vendor_promos
       ├─< community_members >── communities ─< status_posts / live_sessions
       ├─< conversation_participants >── conversations ─< messages
       ├─< subscriptions >── plans
       └─< notifications / notification_deliveries / device_tokens
settlements (organizer, event) ─< payout_settlements >── payouts
```

## 4. Integrity rules enforced by the database

These are deliberately enforced in PostgreSQL, not only in code, because they protect money or inventory under concurrency:

| Rule | Mechanism |
|---|---|
| A seat can be held or sold once per schedule | partial unique `(schedule_id, seat_id)` on `seat_reservations` where status is `held` or `confirmed` |
| Wallet balances never go negative | check `cached_balance >= 0` on `wallets` |
| Ledger is append-only and balanced per transaction | service-level check + no update/delete paths; entries grouped by `transaction_id` |
| A payout cannot be approved by its requester | check constraint `approved_by_admin_id <> requested_by_admin_id` |
| A webhook event is processed once | unique `(provider, provider_event_id)` on `webhook_events` |
| An idempotency key is used once per user + endpoint | unique `(user_id, endpoint, key)` on `idempotency_keys` |
| One pending deletion request per user | partial unique index on `account_deletion_requests` |
| One direct conversation per pair of users | unique `direct_key` on `conversations` |
| A message client id is used once per sender | unique `(sender_user_id, client_id)` on `messages` |
| One community per event | partial unique `communities.event_id` |
| Ticket types are never oversold | check `sold <= quantity` on `ticket_types` |
| Ledger amounts are positive and directional | checks `amount > 0` and `direction in (debit, credit)` on `ledger_entries` |
| Usernames are unique case-insensitively | unique index on `lower(username)` for live accounts |
| One notification delivery per dedupe key | unique `dedupe_key` on `notification_deliveries` |

## 5. Table reference

Generated from the migrated schema. Laravel framework tables (`migrations`, `jobs`, `job_batches`, `failed_jobs`, `cache`, `cache_locks`, `sessions`, `password_reset_tokens`) are omitted.

### `account_deletion_requests`

| Column | Type | Null | Default |
|---|---|---|---|
| id | varchar(40) |  |  |
| user_id | varchar(40) |  |  |
| reason | varchar(1000) |  |  |
| status | varchar(20) |  | `'pending'` |
| requested_at | timestamptz |  |  |
| scheduled_for | timestamptz |  |  |
| decided_by_admin_id | varchar(40) | yes |  |
| decided_at | timestamptz | yes |  |
| completed_at | timestamptz | yes |  |
| created_at | timestamptz | yes |  |
| updated_at | timestamptz | yes |  |

- FK `FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE`
- UNIQUE `(user_id) WHERE ((status)::text = 'pending'::text)`
- INDEX `(status, scheduled_for)`

### `activity_logs`

| Column | Type | Null | Default |
|---|---|---|---|
| id | varchar(40) |  |  |
| event_id | varchar(40) |  |  |
| actor_user_id | varchar(40) | yes |  |
| actor_role | varchar(20) |  |  |
| action | varchar(60) |  |  |
| description | varchar(500) |  |  |
| metadata | jsonb | yes |  |
| created_at | timestamptz |  | `CURRENT_TIMESTAMP` |

- FK `FOREIGN KEY (event_id) REFERENCES events(id) ON DELETE CASCADE`
- INDEX `(event_id, created_at)`

### `admin_role_permissions`

| Column | Type | Null | Default |
|---|---|---|---|
| role_id | varchar(40) |  |  |
| permission_key | varchar(60) |  |  |

- FK `FOREIGN KEY (role_id) REFERENCES admin_roles(id) ON DELETE CASCADE`

### `admin_roles`

| Column | Type | Null | Default |
|---|---|---|---|
| id | varchar(40) |  |  |
| name | varchar(100) |  |  |
| slug | varchar(100) |  |  |
| description | varchar(500) |  | `''` |
| is_system | boolean |  | `false` |
| created_at | timestamptz | yes |  |
| updated_at | timestamptz | yes |  |

- UNIQUE `(slug)`

### `admin_user_roles`

| Column | Type | Null | Default |
|---|---|---|---|
| admin_user_id | varchar(40) |  |  |
| role_id | varchar(40) |  |  |

- FK `FOREIGN KEY (admin_user_id) REFERENCES admin_users(id) ON DELETE CASCADE`
- FK `FOREIGN KEY (role_id) REFERENCES admin_roles(id) ON DELETE CASCADE`

### `admin_users`

| Column | Type | Null | Default |
|---|---|---|---|
| id | varchar(40) |  |  |
| first_name | varchar(100) |  |  |
| last_name | varchar(100) |  |  |
| email | varchar(255) |  |  |
| password | varchar(255) | yes |  |
| two_factor_secret | text | yes |  |
| two_factor_enabled | boolean |  | `false` |
| two_factor_confirmed_at | timestamptz | yes |  |
| two_factor_last_used_step | bigint | yes |  |
| avatar_url | varchar(1024) | yes |  |
| status | varchar(20) |  | `'invited'` |
| invite_token_hash | varchar(64) | yes |  |
| invite_expires_at | timestamptz | yes |  |
| invited_by_admin_id | varchar(40) | yes |  |
| last_login_at | timestamptz | yes |  |
| created_at | timestamptz | yes |  |
| updated_at | timestamptz | yes |  |

- UNIQUE `(lower((email)::text))`

### `audit_logs`

| Column | Type | Null | Default |
|---|---|---|---|
| id | varchar(40) |  |  |
| admin_user_id | varchar(40) | yes |  |
| action | varchar(100) |  |  |
| module | varchar(60) |  |  |
| target_type | varchar(60) | yes |  |
| target_id | varchar(40) | yes |  |
| target_label | varchar(255) | yes |  |
| before | jsonb | yes |  |
| after | jsonb | yes |  |
| ip | varchar(45) | yes |  |
| user_agent | varchar(512) | yes |  |
| correlation_id | varchar(64) | yes |  |
| created_at | timestamptz |  | `CURRENT_TIMESTAMP` |

- INDEX `(admin_user_id, created_at)`
- INDEX `(module, created_at)`
- INDEX `(target_type, target_id)`

### `bank_accounts`

| Column | Type | Null | Default |
|---|---|---|---|
| id | varchar(40) |  |  |
| user_id | varchar(40) |  |  |
| bank_code | varchar(20) |  |  |
| bank_name | varchar(200) |  |  |
| account_number_encrypted | text |  |  |
| account_number_hash | varchar(64) |  |  |
| account_last4 | varchar(4) |  |  |
| account_name | varchar(200) |  |  |
| is_default | boolean |  | `false` |
| provider_recipient_code | varchar(191) | yes |  |
| verified_at | timestamptz | yes |  |
| created_at | timestamptz | yes |  |
| updated_at | timestamptz | yes |  |
| deleted_at | timestamptz | yes |  |

- FK `FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE`
- UNIQUE `(user_id, bank_code, account_number_hash) WHERE (deleted_at IS NULL)`

### `banks`

| Column | Type | Null | Default |
|---|---|---|---|
| code | varchar(20) |  |  |
| name | varchar(200) |  |  |
| provider_codes | jsonb | yes |  |
| is_active | boolean |  | `true` |
| created_at | timestamptz | yes |  |
| updated_at | timestamptz | yes |  |

### `banners`

| Column | Type | Null | Default |
|---|---|---|---|
| id | varchar(40) |  |  |
| title | varchar(200) |  |  |
| subtitle | varchar(500) | yes |  |
| image_url | varchar(1024) | yes |  |
| cta_label | varchar(60) | yes |  |
| cta_url | varchar(1024) | yes |  |
| placement | varchar(30) |  | `'home_hero'` |
| audience | varchar(20) |  | `'all'` |
| starts_at | timestamptz | yes |  |
| ends_at | timestamptz | yes |  |
| is_active | boolean |  | `true` |
| priority | integer |  | `0` |
| impressions | bigint |  | `'0'` |
| clicks | bigint |  | `'0'` |
| created_at | timestamptz | yes |  |
| updated_at | timestamptz | yes |  |

- INDEX `(placement, is_active)`

### `blocks`

| Column | Type | Null | Default |
|---|---|---|---|
| blocker_user_id | varchar(40) |  |  |
| blocked_user_id | varchar(40) |  |  |
| created_at | timestamptz |  | `CURRENT_TIMESTAMP` |

- FK `FOREIGN KEY (blocked_user_id) REFERENCES users(id) ON DELETE CASCADE`
- FK `FOREIGN KEY (blocker_user_id) REFERENCES users(id) ON DELETE CASCADE`
- INDEX `(blocked_user_id)`

### `check_ins`

| Column | Type | Null | Default |
|---|---|---|---|
| id | varchar(40) |  |  |
| ticket_id | varchar(40) | yes |  |
| event_id | varchar(40) |  |  |
| code_scanned | varchar(512) |  |  |
| result | varchar(30) |  |  |
| method | varchar(10) |  |  |
| device | varchar(255) | yes |  |
| scanned_by_user_id | varchar(40) |  |  |
| created_at | timestamptz |  | `CURRENT_TIMESTAMP` |

- FK `FOREIGN KEY (event_id) REFERENCES events(id) ON DELETE CASCADE`
- INDEX `(event_id, created_at)`
- INDEX `(result, created_at)`

### `checkout_quotes`

| Column | Type | Null | Default |
|---|---|---|---|
| id | varchar(40) |  |  |
| user_id | varchar(40) |  |  |
| event_id | varchar(40) |  |  |
| lines | jsonb |  |  |
| food_lines | jsonb |  |  |
| promo_code_id | varchar(40) | yes |  |
| promo_code | varchar(40) | yes |  |
| referral_enrollment_id | varchar(40) | yes |  |
| subtotal | bigint |  |  |
| ticket_subtotal | bigint |  |  |
| service_fee | bigint |  |  |
| discount | bigint |  |  |
| total | bigint |  |  |
| currency | character |  |  |
| requires_seat_selection | boolean |  | `false` |
| requires_menu_selection | boolean |  | `false` |
| expires_at | timestamptz |  |  |
| consumed_at | timestamptz | yes |  |
| released_at | timestamptz | yes |  |
| created_at | timestamptz | yes |  |
| updated_at | timestamptz | yes |  |

- FK `FOREIGN KEY (event_id) REFERENCES events(id) ON DELETE CASCADE`
- FK `FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE`
- INDEX `(user_id, created_at)`

### `cms_page_versions`

| Column | Type | Null | Default |
|---|---|---|---|
| id | bigint |  | `nextval('cms_page_versions_id_seq')` |
| cms_page_id | varchar(40) |  |  |
| title | varchar(200) |  |  |
| content | text |  |  |
| updated_by_admin_id | varchar(40) | yes |  |
| created_at | timestamptz |  | `CURRENT_TIMESTAMP` |

- FK `FOREIGN KEY (cms_page_id) REFERENCES cms_pages(id) ON DELETE CASCADE`

### `cms_pages`

| Column | Type | Null | Default |
|---|---|---|---|
| id | varchar(40) |  |  |
| slug | varchar(30) |  |  |
| title | varchar(200) |  |  |
| content | text |  |  |
| updated_by_admin_id | varchar(40) | yes |  |
| created_at | timestamptz | yes |  |
| updated_at | timestamptz | yes |  |

- UNIQUE `(slug)`

### `commission_rules`

| Column | Type | Null | Default |
|---|---|---|---|
| id | varchar(40) |  |  |
| scope | varchar(20) |  |  |
| scope_id | varchar(40) | yes |  |
| scope_label | varchar(200) |  |  |
| percent | numeric |  |  |
| fixed_fee | bigint |  | `'0'` |
| currency | character |  | `'NGN'` |
| effective_from | date |  |  |
| updated_by_admin_id | varchar(40) | yes |  |
| is_active | boolean |  | `true` |
| created_at | timestamptz | yes |  |
| updated_at | timestamptz | yes |  |

- UNIQUE `(scope, COALESCE(scope_id, ''::character varying)) WHERE is_active`

### `communities`

| Column | Type | Null | Default |
|---|---|---|---|
| id | varchar(40) |  |  |
| event_id | varchar(40) | yes |  |
| owner_user_id | varchar(40) |  |  |
| name | varchar(200) |  |  |
| description | varchar(2000) | yes |  |
| avatar_url | varchar(1024) | yes |  |
| permissions | jsonb |  |  |
| invite_code | varchar(20) |  |  |
| status | varchar(20) |  | `'active'` |
| archive_at | timestamptz | yes |  |
| created_at | timestamptz | yes |  |
| updated_at | timestamptz | yes |  |

- FK `FOREIGN KEY (event_id) REFERENCES events(id) ON DELETE SET NULL`
- FK `FOREIGN KEY (owner_user_id) REFERENCES users(id) ON DELETE CASCADE`
- UNIQUE `(event_id) WHERE (event_id IS NOT NULL)`
- UNIQUE `(invite_code)`
- INDEX `(status)`

### `community_invites`

| Column | Type | Null | Default |
|---|---|---|---|
| id | varchar(40) |  |  |
| community_id | varchar(40) |  |  |
| invited_by_user_id | varchar(40) |  |  |
| user_id | varchar(40) | yes |  |
| email | varchar(255) | yes |  |
| status | varchar(20) |  | `'pending'` |
| created_at | timestamptz | yes |  |
| updated_at | timestamptz | yes |  |

- FK `FOREIGN KEY (community_id) REFERENCES communities(id) ON DELETE CASCADE`

### `community_members`

| Column | Type | Null | Default |
|---|---|---|---|
| community_id | varchar(40) |  |  |
| user_id | varchar(40) |  |  |
| role | varchar(20) |  | `'member'` |
| status | varchar(20) |  | `'active'` |
| is_muted | boolean |  | `false` |
| is_pinned | boolean |  | `false` |
| is_archived | boolean |  | `false` |
| joined_at | timestamptz |  | `CURRENT_TIMESTAMP` |

- FK `FOREIGN KEY (community_id) REFERENCES communities(id) ON DELETE CASCADE`
- FK `FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE`
- INDEX `(user_id)`

### `content_reports`

| Column | Type | Null | Default |
|---|---|---|---|
| id | varchar(40) |  |  |
| target_type | varchar(20) |  |  |
| target_id | varchar(40) |  |  |
| reporter_user_id | varchar(40) |  |  |
| reported_user_id | varchar(40) | yes |  |
| reason | varchar(40) |  |  |
| details | varchar(2000) | yes |  |
| status | varchar(20) |  | `'open'` |
| priority | varchar(10) |  | `'low'` |
| assigned_admin_id | varchar(40) | yes |  |
| target_preview | jsonb | yes |  |
| resolution | jsonb | yes |  |
| created_at | timestamptz | yes |  |
| updated_at | timestamptz | yes |  |

- FK `FOREIGN KEY (reporter_user_id) REFERENCES users(id) ON DELETE CASCADE`
- INDEX `(reported_user_id)`
- UNIQUE `(reporter_user_id, target_type, target_id)`
- INDEX `(status, priority, created_at)`
- INDEX `(target_type, target_id)`

### `conversation_participants`

| Column | Type | Null | Default |
|---|---|---|---|
| conversation_id | varchar(40) |  |  |
| user_id | varchar(40) |  |  |
| last_read_message_id | varchar(40) | yes |  |
| last_read_at | timestamptz | yes |  |
| unread_count | integer |  | `0` |
| is_pinned | boolean |  | `false` |
| is_muted | boolean |  | `false` |
| is_archived | boolean |  | `false` |
| is_request | boolean |  | `false` |
| request_declined_at | timestamptz | yes |  |
| cleared_at | timestamptz | yes |  |
| deleted_at | timestamptz | yes |  |
| created_at | timestamptz | yes |  |
| updated_at | timestamptz | yes |  |

- FK `FOREIGN KEY (conversation_id) REFERENCES conversations(id) ON DELETE CASCADE`
- FK `FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE`
- INDEX `(user_id, deleted_at)`

### `conversations`

| Column | Type | Null | Default |
|---|---|---|---|
| id | varchar(40) |  |  |
| type | varchar(20) |  |  |
| community_id | varchar(40) | yes |  |
| direct_key | varchar(100) | yes |  |
| last_message_id | varchar(40) | yes |  |
| last_message_at | timestamptz | yes |  |
| created_at | timestamptz | yes |  |
| updated_at | timestamptz | yes |  |

- FK `FOREIGN KEY (community_id) REFERENCES communities(id) ON DELETE CASCADE`
- UNIQUE `(community_id)`
- UNIQUE `(direct_key)`

### `device_tokens`

| Column | Type | Null | Default |
|---|---|---|---|
| id | bigint |  | `nextval('device_tokens_id_seq')` |
| user_id | varchar(40) |  |  |
| platform | varchar(10) |  |  |
| token | varchar(512) |  |  |
| last_seen_at | timestamptz | yes |  |
| created_at | timestamptz | yes |  |
| updated_at | timestamptz | yes |  |

- FK `FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE`
- UNIQUE `(token)`
- INDEX `(user_id)`

### `disputes`

| Column | Type | Null | Default |
|---|---|---|---|
| id | varchar(40) |  |  |
| order_id | varchar(40) |  |  |
| raised_by_user_id | varchar(40) | yes |  |
| type | varchar(20) |  | `'other'` |
| status | varchar(30) |  | `'open'` |
| description | text |  |  |
| assigned_admin_id | varchar(40) | yes |  |
| provider_dispute_id | varchar(191) | yes |  |
| notes | jsonb | yes |  |
| created_at | timestamptz | yes |  |
| updated_at | timestamptz | yes |  |

- FK `FOREIGN KEY (order_id) REFERENCES orders(id)`
- UNIQUE `(provider_dispute_id)`
- INDEX `(status, created_at)`

### `email_suppressions`

| Column | Type | Null | Default |
|---|---|---|---|
| email | varchar(255) |  |  |
| reason | varchar(30) |  |  |
| created_at | timestamptz | yes |  |
| updated_at | timestamptz | yes |  |

### `event_categories`

| Column | Type | Null | Default |
|---|---|---|---|
| id | varchar(40) |  |  |
| name | varchar(120) |  |  |
| slug | varchar(120) |  |  |
| icon | varchar(60) |  | `'calendar'` |
| is_active | boolean |  | `true` |
| requires_review | boolean |  | `false` |
| sort_order | smallint |  | `'0'` |
| created_at | timestamptz | yes |  |
| updated_at | timestamptz | yes |  |

- UNIQUE `(slug)`

### `event_managers`

| Column | Type | Null | Default |
|---|---|---|---|
| id | varchar(40) |  |  |
| event_id | varchar(40) |  |  |
| user_id | varchar(40) |  |  |
| invited_by_user_id | varchar(40) | yes |  |
| access | varchar(10) |  | `'partial'` |
| permissions | jsonb |  |  |
| status | varchar(20) |  | `'pending'` |
| invited_at | timestamptz |  |  |
| responded_at | timestamptz | yes |  |
| created_at | timestamptz | yes |  |
| updated_at | timestamptz | yes |  |

- FK `FOREIGN KEY (event_id) REFERENCES events(id) ON DELETE CASCADE`
- FK `FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE`
- UNIQUE `(event_id, user_id) WHERE ((status)::text <> ALL ((ARRAY['declined'::character varying, 'removed'::character varying])::text[]))`
- INDEX `(user_id, status)`

### `event_promotions`

| Column | Type | Null | Default |
|---|---|---|---|
| event_id | varchar(40) |  |  |
| enabled | boolean |  | `false` |
| commission_percent | numeric |  | `'10'` |
| target_signups | integer | yes |  |
| promoter_limit | integer | yes |  |
| created_at | timestamptz | yes |  |
| updated_at | timestamptz | yes |  |

- CHECK `CHECK (((commission_percent >= (1)::numeric) AND (commission_percent <= (50)::numeric)))`
- FK `FOREIGN KEY (event_id) REFERENCES events(id) ON DELETE CASCADE`

### `event_requests`

| Column | Type | Null | Default |
|---|---|---|---|
| id | varchar(40) |  |  |
| event_id | varchar(40) |  |  |
| user_id | varchar(40) |  |  |
| first_name | varchar(100) |  |  |
| last_name | varchar(100) |  |  |
| status | varchar(20) |  | `'pending'` |
| decided_by_user_id | varchar(40) | yes |  |
| decided_at | timestamptz | yes |  |
| created_at | timestamptz | yes |  |
| updated_at | timestamptz | yes |  |

- FK `FOREIGN KEY (event_id) REFERENCES events(id) ON DELETE CASCADE`
- FK `FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE`
- UNIQUE `(event_id, user_id) WHERE ((status)::text = ANY ((ARRAY['pending'::character varying, 'approved'::character varying])::text[]))`
- INDEX `(event_id, status)`

### `event_schedules`

| Column | Type | Null | Default |
|---|---|---|---|
| id | varchar(40) |  |  |
| event_id | varchar(40) |  |  |
| label | varchar(120) | yes |  |
| starts_at | timestamptz |  |  |
| ends_at | timestamptz | yes |  |
| attendance_mode | varchar(10) |  | `'physical'` |
| venue | jsonb | yes |  |
| online | jsonb | yes |  |
| city | varchar(120) | yes |  |
| lat | numeric | yes |  |
| lng | numeric | yes |  |
| hall_layout_id | varchar(40) | yes |  |
| menu_id | varchar(40) | yes |  |
| sort_order | smallint |  | `'0'` |
| created_at | timestamptz | yes |  |
| updated_at | timestamptz | yes |  |

- FK `FOREIGN KEY (event_id) REFERENCES events(id) ON DELETE CASCADE`
- FK `FOREIGN KEY (hall_layout_id) REFERENCES hall_layouts(id) ON DELETE SET NULL`
- FK `FOREIGN KEY (menu_id) REFERENCES planner_menus(id) ON DELETE SET NULL`
- INDEX `(event_id, starts_at)`
- INDEX `(starts_at)`

### `event_task_assignees`

| Column | Type | Null | Default |
|---|---|---|---|
| task_id | varchar(40) |  |  |
| user_id | varchar(40) |  |  |

- FK `FOREIGN KEY (task_id) REFERENCES event_tasks(id) ON DELETE CASCADE`
- FK `FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE`
- INDEX `(user_id)`

### `event_task_attachments`

| Column | Type | Null | Default |
|---|---|---|---|
| id | varchar(40) |  |  |
| task_id | varchar(40) |  |  |
| upload_id | varchar(40) | yes |  |
| name | varchar(255) |  |  |
| url | varchar(2048) |  |  |
| created_at | timestamptz | yes |  |
| updated_at | timestamptz | yes |  |

- FK `FOREIGN KEY (task_id) REFERENCES event_tasks(id) ON DELETE CASCADE`

### `event_tasks`

| Column | Type | Null | Default |
|---|---|---|---|
| id | varchar(40) |  |  |
| event_id | varchar(40) |  |  |
| title | varchar(200) |  |  |
| description | text | yes |  |
| status | varchar(20) |  | `'todo'` |
| due_at | timestamptz | yes |  |
| created_by_user_id | varchar(40) |  |  |
| completed_at | timestamptz | yes |  |
| created_at | timestamptz | yes |  |
| updated_at | timestamptz | yes |  |

- FK `FOREIGN KEY (event_id) REFERENCES events(id) ON DELETE CASCADE`
- INDEX `(event_id, created_at)`

### `event_vendor_assignments`

| Column | Type | Null | Default |
|---|---|---|---|
| id | varchar(40) |  |  |
| event_id | varchar(40) |  |  |
| vendor_profile_id | varchar(40) |  |  |
| invited_by_user_id | varchar(40) | yes |  |
| role | varchar(120) | yes |  |
| status | varchar(20) |  | `'pending'` |
| invited_at | timestamptz |  |  |
| responded_at | timestamptz | yes |  |
| created_at | timestamptz | yes |  |
| updated_at | timestamptz | yes |  |

- FK `FOREIGN KEY (event_id) REFERENCES events(id) ON DELETE CASCADE`
- FK `FOREIGN KEY (vendor_profile_id) REFERENCES vendor_profiles(id) ON DELETE CASCADE`
- UNIQUE `(event_id, vendor_profile_id) WHERE ((status)::text <> ALL ((ARRAY['declined'::character varying, 'removed'::character varying])::text[]))`

### `events`

| Column | Type | Null | Default |
|---|---|---|---|
| id | varchar(40) |  |  |
| organizer_user_id | varchar(40) |  |  |
| category_id | varchar(40) | yes |  |
| slug | varchar(220) |  |  |
| title | varchar(200) |  |  |
| description | text | yes |  |
| cover_url | varchar(1024) | yes |  |
| gallery | jsonb | yes |  |
| tags | jsonb | yes |  |
| visibility | varchar(10) |  | `'public'` |
| status | varchar(20) |  | `'draft'` |
| approval_required | boolean |  | `false` |
| guest_limit | integer | yes |  |
| allow_guest_invites | boolean |  | `false` |
| max_additional_guests | smallint |  | `'0'` |
| is_adult_only | boolean |  | `false` |
| is_featured | boolean |  | `false` |
| is_free | boolean |  | `false` |
| always_refundable | boolean |  | `false` |
| allow_ticket_transfer | boolean |  | `true` |
| community_enabled | boolean |  | `true` |
| currency | character |  | `'NGN'` |
| organizer_info | jsonb | yes |  |
| hall_layout_id | varchar(40) | yes |  |
| menu_id | varchar(40) | yes |  |
| starts_at | timestamptz | yes |  |
| ends_at | timestamptz | yes |  |
| city | varchar(120) | yes |  |
| attendance_mode | varchar(10) |  | `'physical'` |
| min_price | bigint |  | `'0'` |
| tickets_sold | integer |  | `0` |
| saves_count | integer |  | `0` |
| rejection_reason | varchar(1000) | yes |  |
| cancellation_reason | varchar(1000) | yes |  |
| suspended_reason | varchar(1000) | yes |  |
| status_before_suspension | varchar(20) | yes |  |
| submitted_at | timestamptz | yes |  |
| reviewed_by_admin_id | varchar(40) | yes |  |
| reviewed_at | timestamptz | yes |  |
| published_at | timestamptz | yes |  |
| completed_at | timestamptz | yes |  |
| cancelled_at | timestamptz | yes |  |
| source | varchar(10) |  | `'web'` |
| created_at | timestamptz | yes |  |
| updated_at | timestamptz | yes |  |
| deleted_at | timestamptz | yes |  |
| search_vector | tsvector | yes |  |

- FK `FOREIGN KEY (category_id) REFERENCES event_categories(id) ON DELETE SET NULL`
- FK `FOREIGN KEY (organizer_user_id) REFERENCES users(id) ON DELETE CASCADE`
- FK `FOREIGN KEY (reviewed_by_admin_id) REFERENCES admin_users(id) ON DELETE SET NULL`
- INDEX `(category_id)`
- INDEX `(organizer_user_id)`
- INDEX `(search_vector)`
- UNIQUE `(slug)`
- INDEX `(status, starts_at)`
- INDEX `(status, visibility, starts_at)`
- INDEX `(title gin_trgm_ops)`

### `export_jobs`

| Column | Type | Null | Default |
|---|---|---|---|
| id | varchar(40) |  |  |
| admin_user_id | varchar(40) |  |  |
| report_key | varchar(60) |  |  |
| query | jsonb |  |  |
| format | varchar(10) |  |  |
| status | varchar(20) |  | `'queued'` |
| file_path | varchar(1024) | yes |  |
| error | text | yes |  |
| expires_at | timestamptz | yes |  |
| created_at | timestamptz | yes |  |
| updated_at | timestamptz | yes |  |

- FK `FOREIGN KEY (admin_user_id) REFERENCES admin_users(id) ON DELETE CASCADE`

### `hall_layouts`

| Column | Type | Null | Default |
|---|---|---|---|
| id | varchar(40) |  |  |
| user_id | varchar(40) |  |  |
| name | varchar(200) |  |  |
| thumbnail_url | varchar(1024) | yes |  |
| width | integer |  | `1000` |
| height | integer |  | `800` |
| total_seats | integer |  | `0` |
| reserved_seats | integer |  | `0` |
| tables | jsonb |  |  |
| fixtures | jsonb |  |  |
| event_id | varchar(40) | yes |  |
| created_at | timestamptz | yes |  |
| updated_at | timestamptz | yes |  |
| deleted_at | timestamptz | yes |  |

- FK `FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE`
- INDEX `(user_id)`

### `idempotency_keys`

| Column | Type | Null | Default |
|---|---|---|---|
| id | bigint |  | `nextval('idempotency_keys_id_seq')` |
| key | varchar(191) |  |  |
| user_id | varchar(40) |  |  |
| endpoint | varchar(191) |  |  |
| request_hash | varchar(64) |  |  |
| response_status | smallint | yes |  |
| response_body | jsonb | yes |  |
| locked_until | timestamptz | yes |  |
| completed_at | timestamptz | yes |  |
| created_at | timestamptz | yes |  |
| updated_at | timestamptz | yes |  |

- INDEX `(created_at)`
- UNIQUE `(user_id, endpoint, key)`

### `identity_verifications`

| Column | Type | Null | Default |
|---|---|---|---|
| id | varchar(40) |  |  |
| user_id | varchar(40) |  |  |
| id_type | varchar(30) |  |  |
| id_number_encrypted | text |  |  |
| id_number_last4 | varchar(4) | yes |  |
| selfie_upload_id | varchar(40) | yes |  |
| provider | varchar(30) |  |  |
| provider_reference | varchar(191) | yes |  |
| status | varchar(20) |  | `'pending'` |
| match_score | numeric | yes |  |
| failure_reason | varchar(500) | yes |  |
| submitted_at | timestamptz |  |  |
| reviewed_at | timestamptz | yes |  |
| media_purged_at | timestamptz | yes |  |
| created_at | timestamptz | yes |  |
| updated_at | timestamptz | yes |  |

- FK `FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE`
- INDEX `(provider_reference)`
- INDEX `(user_id, created_at)`

### `integration_logs`

| Column | Type | Null | Default |
|---|---|---|---|
| id | bigint |  | `nextval('integration_logs_id_seq')` |
| provider | varchar(30) |  |  |
| operation | varchar(60) |  |  |
| method | varchar(10) | yes |  |
| endpoint | varchar(512) | yes |  |
| status_code | smallint | yes |  |
| success | boolean |  | `false` |
| latency_ms | integer |  | `0` |
| request | jsonb | yes |  |
| response | jsonb | yes |  |
| error | text | yes |  |
| correlation_id | varchar(64) | yes |  |
| created_at | timestamptz |  | `CURRENT_TIMESTAMP` |

- INDEX `(provider, created_at)`

### `interests`

| Column | Type | Null | Default |
|---|---|---|---|
| slug | varchar(60) |  |  |
| label | varchar(120) |  |  |
| sort_order | smallint |  | `'0'` |
| is_active | boolean |  | `true` |
| created_at | timestamptz | yes |  |
| updated_at | timestamptz | yes |  |

### `inventory_holds`

| Column | Type | Null | Default |
|---|---|---|---|
| id | bigint |  | `nextval('inventory_holds_id_seq')` |
| quote_id | varchar(40) |  |  |
| order_id | varchar(40) | yes |  |
| ticket_type_id | varchar(40) |  |  |
| quantity | integer |  |  |
| expires_at | timestamptz |  |  |
| released_at | timestamptz | yes |  |
| converted_at | timestamptz | yes |  |
| created_at | timestamptz | yes |  |
| updated_at | timestamptz | yes |  |

- FK `FOREIGN KEY (quote_id) REFERENCES checkout_quotes(id) ON DELETE CASCADE`
- FK `FOREIGN KEY (ticket_type_id) REFERENCES ticket_types(id) ON DELETE CASCADE`
- INDEX `(quote_id)`
- INDEX `(ticket_type_id, expires_at)`

### `ledger_entries`

| Column | Type | Null | Default |
|---|---|---|---|
| id | bigint |  | `nextval('ledger_entries_id_seq')` |
| transaction_id | varchar(40) |  |  |
| account | varchar(120) |  |  |
| direction | varchar(6) |  |  |
| amount | bigint |  |  |
| currency | character |  |  |
| reference_type | varchar(60) | yes |  |
| reference_id | varchar(40) | yes |  |
| description | varchar(255) | yes |  |
| created_at | timestamptz |  | `CURRENT_TIMESTAMP` |

- CHECK `CHECK ((amount > 0))`
- CHECK `CHECK (((direction)::text = ANY ((ARRAY['debit'::character varying, 'credit'::character varying])::text[])))`
- INDEX `(account, created_at)`
- INDEX `(reference_type, reference_id)`
- INDEX `(transaction_id)`

### `live_requests`

| Column | Type | Null | Default |
|---|---|---|---|
| id | varchar(40) |  |  |
| live_session_id | varchar(40) |  |  |
| user_id | varchar(40) |  |  |
| status | varchar(20) |  | `'pending'` |
| created_at | timestamptz | yes |  |
| updated_at | timestamptz | yes |  |

- FK `FOREIGN KEY (live_session_id) REFERENCES live_sessions(id) ON DELETE CASCADE`
- UNIQUE `(live_session_id, user_id)`

### `live_sessions`

| Column | Type | Null | Default |
|---|---|---|---|
| id | varchar(40) |  |  |
| community_id | varchar(40) |  |  |
| host_user_id | varchar(40) |  |  |
| title | varchar(200) | yes |  |
| status | varchar(20) |  | `'live'` |
| provider | varchar(20) | yes |  |
| provider_room_id | varchar(191) | yes |  |
| audio_enabled | boolean |  | `true` |
| video_enabled | boolean |  | `true` |
| viewers_count | integer |  | `0` |
| started_at | timestamptz | yes |  |
| ended_at | timestamptz | yes |  |
| created_at | timestamptz | yes |  |
| updated_at | timestamptz | yes |  |

- FK `FOREIGN KEY (community_id) REFERENCES communities(id) ON DELETE CASCADE`
- UNIQUE `(community_id) WHERE ((status)::text = 'live'::text)`

### `media`

| Column | Type | Null | Default |
|---|---|---|---|
| id | varchar(40) |  |  |
| owner_type | varchar(60) |  |  |
| owner_id | varchar(40) |  |  |
| upload_id | varchar(40) | yes |  |
| type | varchar(20) |  |  |
| url | varchar(2048) |  |  |
| thumbnail_url | varchar(2048) | yes |  |
| name | varchar(255) | yes |  |
| size_bytes | bigint | yes |  |
| width | integer | yes |  |
| height | integer | yes |  |
| duration_seconds | integer | yes |  |
| waveform | jsonb | yes |  |
| provider | varchar(20) | yes |  |
| provider_public_id | varchar(512) | yes |  |
| sort_order | smallint |  | `'0'` |
| created_at | timestamptz | yes |  |
| updated_at | timestamptz | yes |  |

- INDEX `(owner_type, owner_id)`

### `message_reactions`

| Column | Type | Null | Default |
|---|---|---|---|
| message_id | varchar(40) |  |  |
| user_id | varchar(40) |  |  |
| emoji | varchar(32) |  |  |
| created_at | timestamptz |  | `CURRENT_TIMESTAMP` |

- FK `FOREIGN KEY (message_id) REFERENCES messages(id) ON DELETE CASCADE`
- FK `FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE`

### `message_stars`

| Column | Type | Null | Default |
|---|---|---|---|
| message_id | varchar(40) |  |  |
| user_id | varchar(40) |  |  |
| created_at | timestamptz |  | `CURRENT_TIMESTAMP` |

- FK `FOREIGN KEY (message_id) REFERENCES messages(id) ON DELETE CASCADE`
- FK `FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE`

### `messages`

| Column | Type | Null | Default |
|---|---|---|---|
| id | varchar(40) |  |  |
| conversation_id | varchar(40) |  |  |
| sender_user_id | varchar(40) |  |  |
| client_id | varchar(64) |  |  |
| type | varchar(20) |  | `'text'` |
| body | text | yes |  |
| reply_to_id | varchar(40) | yes |  |
| event_id | varchar(40) | yes |  |
| is_pinned | boolean |  | `false` |
| deleted_by | varchar(10) |  | `'none'` |
| deleted_at | timestamptz | yes |  |
| created_at | timestamptz | yes |  |
| updated_at | timestamptz | yes |  |

- FK `FOREIGN KEY (conversation_id) REFERENCES conversations(id) ON DELETE CASCADE`
- FK `FOREIGN KEY (sender_user_id) REFERENCES users(id) ON DELETE CASCADE`
- INDEX `(conversation_id, created_at)`
- UNIQUE `(sender_user_id, client_id)`

### `moderation_removals`

| Column | Type | Null | Default |
|---|---|---|---|
| id | varchar(40) |  |  |
| target_type | varchar(20) |  |  |
| target_id | varchar(40) |  |  |
| report_id | varchar(40) | yes |  |
| admin_user_id | varchar(40) |  |  |
| note | varchar(2000) |  |  |
| restored_at | timestamptz | yes |  |
| created_at | timestamptz | yes |  |
| updated_at | timestamptz | yes |  |

- INDEX `(target_type, target_id)`

### `notification_campaigns`

| Column | Type | Null | Default |
|---|---|---|---|
| id | varchar(40) |  |  |
| name | varchar(200) |  |  |
| channels | jsonb |  |  |
| audience | jsonb |  |  |
| template_id | varchar(40) | yes |  |
| subject | varchar(255) | yes |  |
| body | text |  |  |
| status | varchar(20) |  | `'draft'` |
| scheduled_at | timestamptz | yes |  |
| sent_at | timestamptz | yes |  |
| stats | jsonb |  |  |
| created_by_admin_id | varchar(40) |  |  |
| created_at | timestamptz | yes |  |
| updated_at | timestamptz | yes |  |

- INDEX `(status, scheduled_at)`

### `notification_deliveries`

| Column | Type | Null | Default |
|---|---|---|---|
| id | varchar(40) |  |  |
| campaign_id | varchar(40) | yes |  |
| notification_id | varchar(40) | yes |  |
| user_id | varchar(40) | yes |  |
| channel | varchar(20) |  |  |
| recipient | varchar(255) | yes |  |
| template_key | varchar(60) | yes |  |
| dedupe_key | varchar(191) | yes |  |
| provider | varchar(30) | yes |  |
| provider_message_id | varchar(191) | yes |  |
| status | varchar(20) |  | `'queued'` |
| attempts | smallint |  | `'0'` |
| error | text | yes |  |
| sent_at | timestamptz | yes |  |
| delivered_at | timestamptz | yes |  |
| opened_at | timestamptz | yes |  |
| clicked_at | timestamptz | yes |  |
| created_at | timestamptz | yes |  |
| updated_at | timestamptz | yes |  |

- INDEX `(campaign_id, channel, status)`
- UNIQUE `(dedupe_key)`
- INDEX `(provider, provider_message_id)`
- INDEX `(user_id, created_at)`

### `notification_preferences`

| Column | Type | Null | Default |
|---|---|---|---|
| user_id | varchar(40) |  |  |
| channels | jsonb |  |  |
| topics | jsonb |  |  |
| created_at | timestamptz | yes |  |
| updated_at | timestamptz | yes |  |

- FK `FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE`

### `notification_templates`

| Column | Type | Null | Default |
|---|---|---|---|
| id | varchar(40) |  |  |
| key | varchar(60) |  |  |
| name | varchar(200) |  |  |
| channel | varchar(20) |  |  |
| subject | varchar(255) | yes |  |
| body | text |  |  |
| variables | jsonb |  |  |
| is_active | boolean |  | `true` |
| whatsapp_template_name | varchar(120) | yes |  |
| created_at | timestamptz | yes |  |
| updated_at | timestamptz | yes |  |

- UNIQUE `(key, channel)`

### `notifications`

| Column | Type | Null | Default |
|---|---|---|---|
| id | varchar(40) |  |  |
| user_id | varchar(40) |  |  |
| type | varchar(40) |  |  |
| actor_user_id | varchar(40) | yes |  |
| title | varchar(200) |  |  |
| body | varchar(1000) |  |  |
| segments | jsonb |  |  |
| action | varchar(20) |  | `'none'` |
| action_state | varchar(20) | yes |  |
| data | jsonb |  |  |
| link | varchar(1024) | yes |  |
| is_request | boolean |  | `false` |
| subject_type | varchar(60) | yes |  |
| subject_id | varchar(40) | yes |  |
| read_at | timestamptz | yes |  |
| created_at | timestamptz | yes |  |
| updated_at | timestamptz | yes |  |

- FK `FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE`
- INDEX `(user_id, is_request, created_at)`
- INDEX `(user_id, read_at, created_at)`

### `order_food_lines`

| Column | Type | Null | Default |
|---|---|---|---|
| id | bigint |  | `nextval('order_food_lines_id_seq')` |
| order_id | varchar(40) |  |  |
| menu_item_id | varchar(40) |  |  |
| name_snapshot | varchar(200) |  |  |
| unit_price | bigint |  |  |
| quantity | integer |  |  |
| subtotal | bigint |  |  |

- FK `FOREIGN KEY (order_id) REFERENCES orders(id) ON DELETE CASCADE`

### `order_lines`

| Column | Type | Null | Default |
|---|---|---|---|
| id | bigint |  | `nextval('order_lines_id_seq')` |
| order_id | varchar(40) |  |  |
| ticket_type_id | varchar(40) |  |  |
| name_snapshot | varchar(120) |  |  |
| unit_price | bigint |  |  |
| quantity | integer |  |  |
| subtotal | bigint |  |  |

- FK `FOREIGN KEY (order_id) REFERENCES orders(id) ON DELETE CASCADE`
- FK `FOREIGN KEY (ticket_type_id) REFERENCES ticket_types(id)`

### `orders`

| Column | Type | Null | Default |
|---|---|---|---|
| id | varchar(40) |  |  |
| reference | varchar(30) |  |  |
| user_id | varchar(40) |  |  |
| event_id | varchar(40) |  |  |
| quote_id | varchar(40) | yes |  |
| status | varchar(20) |  | `'pending'` |
| payment_method | varchar(20) |  |  |
| provider | varchar(20) |  | `'none'` |
| attendee | jsonb |  |  |
| additional_guests | jsonb |  |  |
| subtotal | bigint |  |  |
| ticket_subtotal | bigint |  |  |
| service_fee | bigint |  |  |
| discount | bigint |  |  |
| total | bigint |  |  |
| refunded_amount | bigint |  | `'0'` |
| currency | character |  |  |
| promo_code_id | varchar(40) | yes |  |
| commission_rule_snapshot | jsonb | yes |  |
| promoter_enrollment_id | varchar(40) | yes |  |
| idempotency_key | varchar(191) | yes |  |
| return_url | varchar(2048) | yes |  |
| source | varchar(10) |  | `'web'` |
| cancellation_reason | varchar(60) | yes |  |
| cancellation_note | varchar(1000) | yes |  |
| paid_at | timestamptz | yes |  |
| expires_at | timestamptz | yes |  |
| cancelled_at | timestamptz | yes |  |
| created_at | timestamptz | yes |  |
| updated_at | timestamptz | yes |  |

- FK `FOREIGN KEY (event_id) REFERENCES events(id)`
- FK `FOREIGN KEY (user_id) REFERENCES users(id)`
- INDEX `(event_id, status)`
- UNIQUE `(reference)`
- INDEX `(status, expires_at)`
- INDEX `(user_id, created_at)`
- UNIQUE `(user_id, idempotency_key)`

### `otp_codes`

| Column | Type | Null | Default |
|---|---|---|---|
| id | bigint |  | `nextval('otp_codes_id_seq')` |
| email | varchar(255) |  |  |
| user_id | varchar(40) | yes |  |
| purpose | varchar(30) |  |  |
| code_hash | varchar(255) |  |  |
| attempts | smallint |  | `'0'` |
| expires_at | timestamptz |  |  |
| consumed_at | timestamptz | yes |  |
| verified_at | timestamptz | yes |  |
| created_at | timestamptz | yes |  |
| updated_at | timestamptz | yes |  |

- INDEX `(email, purpose)`

### `payments`

| Column | Type | Null | Default |
|---|---|---|---|
| id | varchar(40) |  |  |
| payable_type | varchar(60) |  |  |
| payable_id | varchar(40) |  |  |
| user_id | varchar(40) | yes |  |
| provider | varchar(20) |  |  |
| provider_reference | varchar(191) |  |  |
| provider_transaction_id | varchar(191) | yes |  |
| purpose | varchar(20) |  |  |
| amount | bigint |  |  |
| fee | bigint |  | `'0'` |
| refunded_amount | bigint |  | `'0'` |
| currency | character |  |  |
| status | varchar(20) |  | `'initialized'` |
| reconciliation_status | varchar(20) |  | `'pending'` |
| reconciliation_note | varchar(1000) | yes |  |
| authorization_url | varchar(2048) | yes |  |
| client_params | jsonb | yes |  |
| raw_payload | jsonb | yes |  |
| failure_reason | varchar(500) | yes |  |
| verified_at | timestamptz | yes |  |
| settled_at | timestamptz | yes |  |
| created_at | timestamptz | yes |  |
| updated_at | timestamptz | yes |  |

- INDEX `(payable_type, payable_id)`
- UNIQUE `(provider, provider_reference)`
- INDEX `(reconciliation_status, created_at)`
- INDEX `(status, created_at)`

### `payout_settlements`

| Column | Type | Null | Default |
|---|---|---|---|
| payout_id | varchar(40) |  |  |
| settlement_id | varchar(40) |  |  |

- FK `FOREIGN KEY (payout_id) REFERENCES payouts(id) ON DELETE CASCADE`
- FK `FOREIGN KEY (settlement_id) REFERENCES settlements(id) ON DELETE CASCADE`

### `payouts`

| Column | Type | Null | Default |
|---|---|---|---|
| id | varchar(40) |  |  |
| reference | varchar(30) |  |  |
| organizer_user_id | varchar(40) |  |  |
| bank_account_snapshot | jsonb |  |  |
| amount | bigint |  |  |
| currency | character |  |  |
| provider | varchar(20) |  |  |
| provider_reference | varchar(191) | yes |  |
| status | varchar(20) |  | `'requested'` |
| requested_by_admin_id | varchar(40) | yes |  |
| approved_by_admin_id | varchar(40) | yes |  |
| rejection_reason | varchar(1000) | yes |  |
| failure_reason | varchar(1000) | yes |  |
| approved_at | timestamptz | yes |  |
| completed_at | timestamptz | yes |  |
| created_at | timestamptz | yes |  |
| updated_at | timestamptz | yes |  |

- CHECK `CHECK (((approved_by_admin_id IS NULL) OR ((approved_by_admin_id)::text <> (requested_by_admin_id)::text)))`
- FK `FOREIGN KEY (organizer_user_id) REFERENCES users(id)`
- UNIQUE `(reference)`
- INDEX `(status, created_at)`

### `personal_access_tokens`

| Column | Type | Null | Default |
|---|---|---|---|
| id | varchar(40) |  |  |
| tokenable_type | varchar(60) |  |  |
| tokenable_id | varchar(40) |  |  |
| name | text |  |  |
| token | varchar(64) |  |  |
| abilities | text | yes |  |
| ip_address | varchar(45) | yes |  |
| user_agent | varchar(512) | yes |  |
| platform | varchar(10) | yes |  |
| location | varchar(120) | yes |  |
| last_used_at | timestamptz | yes |  |
| expires_at | timestamptz | yes |  |
| created_at | timestamptz | yes |  |
| updated_at | timestamptz | yes |  |

- INDEX `(expires_at)`
- UNIQUE `(token)`
- INDEX `(tokenable_type, tokenable_id)`

### `planner_chat_messages`

| Column | Type | Null | Default |
|---|---|---|---|
| id | varchar(40) |  |  |
| chat_id | varchar(40) |  |  |
| role | varchar(20) |  |  |
| content | text |  |  |
| attachments | jsonb | yes |  |
| generation_id | varchar(40) | yes |  |
| input_tokens | integer | yes |  |
| output_tokens | integer | yes |  |
| created_at | timestamptz |  | `CURRENT_TIMESTAMP` |

- FK `FOREIGN KEY (chat_id) REFERENCES planner_chats(id) ON DELETE CASCADE`
- INDEX `(chat_id, created_at)`

### `planner_chats`

| Column | Type | Null | Default |
|---|---|---|---|
| id | varchar(40) |  |  |
| user_id | varchar(40) |  |  |
| title | varchar(200) |  |  |
| created_at | timestamptz | yes |  |
| updated_at | timestamptz | yes |  |
| deleted_at | timestamptz | yes |  |

- FK `FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE`
- INDEX `(user_id, updated_at)`

### `planner_generations`

| Column | Type | Null | Default |
|---|---|---|---|
| id | varchar(40) |  |  |
| user_id | varchar(40) |  |  |
| type | varchar(30) |  |  |
| prompt | text |  |  |
| name | varchar(200) | yes |  |
| status | varchar(20) |  | `'queued'` |
| result_urls | jsonb |  |  |
| provider | varchar(30) | yes |  |
| cost_units | integer |  | `0` |
| event_id | varchar(40) | yes |  |
| error | text | yes |  |
| created_at | timestamptz | yes |  |
| updated_at | timestamptz | yes |  |
| deleted_at | timestamptz | yes |  |

- FK `FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE`
- INDEX `(user_id, created_at)`

### `planner_menus`

| Column | Type | Null | Default |
|---|---|---|---|
| id | varchar(40) |  |  |
| user_id | varchar(40) |  |  |
| name | varchar(200) |  |  |
| sections | jsonb |  |  |
| currency | character |  | `'NGN'` |
| event_id | varchar(40) | yes |  |
| created_at | timestamptz | yes |  |
| updated_at | timestamptz | yes |  |
| deleted_at | timestamptz | yes |  |

- FK `FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE`
- INDEX `(user_id)`

### `plans`

| Column | Type | Null | Default |
|---|---|---|---|
| id | varchar(40) |  |  |
| tier | varchar(20) |  |  |
| name | varchar(60) |  |  |
| tagline | varchar(200) |  |  |
| price_monthly | bigint |  |  |
| price_yearly | bigint |  |  |
| currency | character |  | `'NGN'` |
| features | jsonb |  |  |
| limits | jsonb | yes |  |
| is_popular | boolean |  | `false` |
| inherits_from | varchar(40) | yes |  |
| sort_order | smallint |  | `'0'` |
| created_at | timestamptz | yes |  |
| updated_at | timestamptz | yes |  |

- UNIQUE `(tier)`

### `platform_settings`

| Column | Type | Null | Default |
|---|---|---|---|
| key | varchar(60) |  |  |
| value | jsonb |  |  |
| updated_by_admin_id | varchar(40) | yes |  |
| created_at | timestamptz | yes |  |
| updated_at | timestamptz | yes |  |

### `promo_codes`

| Column | Type | Null | Default |
|---|---|---|---|
| id | varchar(40) |  |  |
| code | varchar(40) |  |  |
| event_id | varchar(40) | yes |  |
| type | varchar(10) |  |  |
| value | bigint |  |  |
| max_uses | integer | yes |  |
| used_count | integer |  | `0` |
| starts_at | timestamptz | yes |  |
| ends_at | timestamptz | yes |  |
| applies_to_ticket_type_ids | jsonb | yes |  |
| is_active | boolean |  | `true` |
| created_at | timestamptz | yes |  |
| updated_at | timestamptz | yes |  |

- FK `FOREIGN KEY (event_id) REFERENCES events(id) ON DELETE CASCADE`
- UNIQUE `(event_id, upper((code)::text)) WHERE (event_id IS NOT NULL)`
- UNIQUE `(upper((code)::text)) WHERE (event_id IS NULL)`

### `promoter_commissions`

| Column | Type | Null | Default |
|---|---|---|---|
| id | varchar(40) |  |  |
| enrollment_id | varchar(40) |  |  |
| order_id | varchar(40) |  |  |
| amount | bigint |  |  |
| currency | character |  |  |
| status | varchar(20) |  | `'pending'` |
| credited_at | timestamptz | yes |  |
| created_at | timestamptz | yes |  |
| updated_at | timestamptz | yes |  |

- FK `FOREIGN KEY (enrollment_id) REFERENCES promoter_enrollments(id) ON DELETE CASCADE`
- FK `FOREIGN KEY (order_id) REFERENCES orders(id) ON DELETE CASCADE`
- INDEX `(enrollment_id, status)`
- UNIQUE `(order_id)`

### `promoter_enrollments`

| Column | Type | Null | Default |
|---|---|---|---|
| id | varchar(40) |  |  |
| event_id | varchar(40) |  |  |
| user_id | varchar(40) |  |  |
| share_code | varchar(20) |  |  |
| status | varchar(20) |  | `'active'` |
| joined_at | timestamptz |  |  |
| created_at | timestamptz | yes |  |
| updated_at | timestamptz | yes |  |

- FK `FOREIGN KEY (event_id) REFERENCES events(id) ON DELETE CASCADE`
- FK `FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE`
- UNIQUE `(event_id, user_id)`
- UNIQUE `(share_code)`

### `reconciliation_records`

| Column | Type | Null | Default |
|---|---|---|---|
| id | varchar(40) |  |  |
| payment_id | varchar(40) | yes |  |
| provider | varchar(20) |  |  |
| provider_report_line | jsonb | yes |  |
| status | varchar(20) |  |  |
| note | varchar(1000) | yes |  |
| resolved_by_admin_id | varchar(40) | yes |  |
| created_at | timestamptz | yes |  |
| updated_at | timestamptz | yes |  |

- INDEX `(payment_id)`

### `refresh_tokens`

| Column | Type | Null | Default |
|---|---|---|---|
| id | varchar(40) |  |  |
| user_id | varchar(40) |  |  |
| token_hash | varchar(64) |  |  |
| family_id | varchar(40) |  |  |
| access_token_id | varchar(40) | yes |  |
| expires_at | timestamptz |  |  |
| revoked_at | timestamptz | yes |  |
| replaced_by_id | varchar(40) | yes |  |
| device_name | varchar(255) | yes |  |
| ip_address | varchar(45) | yes |  |
| user_agent | varchar(512) | yes |  |
| platform | varchar(10) | yes |  |
| last_used_at | timestamptz | yes |  |
| created_at | timestamptz | yes |  |
| updated_at | timestamptz | yes |  |

- FK `FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE`
- INDEX `(access_token_id)`
- INDEX `(family_id)`
- UNIQUE `(token_hash)`

### `refunds`

| Column | Type | Null | Default |
|---|---|---|---|
| id | varchar(40) |  |  |
| reference | varchar(30) |  |  |
| order_id | varchar(40) |  |  |
| payment_id | varchar(40) | yes |  |
| amount | bigint |  |  |
| fee_portion | bigint |  | `'0'` |
| currency | character |  |  |
| reason | varchar(1000) |  |  |
| status | varchar(20) |  | `'requested'` |
| provider | varchar(20) |  |  |
| provider_reference | varchar(191) | yes |  |
| requested_by_user_id | varchar(40) | yes |  |
| requested_by_admin_id | varchar(40) | yes |  |
| decided_by_admin_id | varchar(40) | yes |  |
| ticket_id | varchar(40) | yes |  |
| failure_reason | varchar(500) | yes |  |
| needs_attention | boolean |  | `false` |
| decided_at | timestamptz | yes |  |
| completed_at | timestamptz | yes |  |
| created_at | timestamptz | yes |  |
| updated_at | timestamptz | yes |  |

- CHECK `CHECK ((amount > 0))`
- FK `FOREIGN KEY (order_id) REFERENCES orders(id)`
- INDEX `(order_id)`
- INDEX `(provider_reference)`
- UNIQUE `(reference)`
- INDEX `(status, created_at)`

### `saved_events`

| Column | Type | Null | Default |
|---|---|---|---|
| user_id | varchar(40) |  |  |
| event_id | varchar(40) |  |  |
| created_at | timestamptz |  | `CURRENT_TIMESTAMP` |

- FK `FOREIGN KEY (event_id) REFERENCES events(id) ON DELETE CASCADE`
- FK `FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE`

### `saved_vendors`

| Column | Type | Null | Default |
|---|---|---|---|
| user_id | varchar(40) |  |  |
| vendor_profile_id | varchar(40) |  |  |
| created_at | timestamptz |  | `CURRENT_TIMESTAMP` |

- FK `FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE`
- FK `FOREIGN KEY (vendor_profile_id) REFERENCES vendor_profiles(id) ON DELETE CASCADE`

### `seat_reservations`

| Column | Type | Null | Default |
|---|---|---|---|
| id | varchar(40) |  |  |
| order_id | varchar(40) |  |  |
| schedule_id | varchar(40) |  |  |
| seat_id | varchar(80) |  |  |
| table_label | varchar(60) |  |  |
| seat_label | varchar(60) |  |  |
| status | varchar(20) |  | `'held'` |
| created_at | timestamptz | yes |  |
| updated_at | timestamptz | yes |  |

- FK `FOREIGN KEY (order_id) REFERENCES orders(id) ON DELETE CASCADE`
- FK `FOREIGN KEY (schedule_id) REFERENCES event_schedules(id) ON DELETE CASCADE`
- UNIQUE `(schedule_id, seat_id) WHERE ((status)::text = ANY ((ARRAY['held'::character varying, 'confirmed'::character varying])::text[]))`
- INDEX `(order_id)`

### `security_logs`

| Column | Type | Null | Default |
|---|---|---|---|
| id | varchar(40) |  |  |
| admin_user_id | varchar(40) | yes |  |
| email_attempted | varchar(255) | yes |  |
| event | varchar(40) |  |  |
| ip | varchar(45) | yes |  |
| location | varchar(120) | yes |  |
| user_agent | varchar(512) | yes |  |
| context | jsonb | yes |  |
| created_at | timestamptz |  | `CURRENT_TIMESTAMP` |

- INDEX `(created_at)`
- INDEX `(event, created_at)`

### `settlements`

| Column | Type | Null | Default |
|---|---|---|---|
| id | varchar(40) |  |  |
| reference | varchar(30) |  |  |
| organizer_user_id | varchar(40) |  |  |
| event_id | varchar(40) |  |  |
| gross_sales | bigint |  | `'0'` |
| refunds | bigint |  | `'0'` |
| platform_commission | bigint |  | `'0'` |
| gateway_fees | bigint |  | `'0'` |
| promoter_commissions | bigint |  | `'0'` |
| adjustments | bigint |  | `'0'` |
| net_payable | bigint |  | `'0'` |
| currency | character |  |  |
| status | varchar(20) |  | `'pending'` |
| hold_reason | varchar(1000) | yes |  |
| pending_reason | varchar(500) | yes |  |
| failure_reason | varchar(1000) | yes |  |
| eligible_at | timestamptz |  |  |
| paid_at | timestamptz | yes |  |
| payout_id | varchar(40) | yes |  |
| created_at | timestamptz | yes |  |
| updated_at | timestamptz | yes |  |

- FK `FOREIGN KEY (event_id) REFERENCES events(id)`
- FK `FOREIGN KEY (organizer_user_id) REFERENCES users(id)`
- FK `FOREIGN KEY (payout_id) REFERENCES payouts(id) ON DELETE SET NULL`
- UNIQUE `(event_id, organizer_user_id)`
- UNIQUE `(reference)`
- INDEX `(status, eligible_at)`

### `social_accounts`

| Column | Type | Null | Default |
|---|---|---|---|
| id | bigint |  | `nextval('social_accounts_id_seq')` |
| user_id | varchar(40) |  |  |
| provider | varchar(20) |  |  |
| provider_user_id | varchar(191) |  |  |
| email | varchar(255) | yes |  |
| created_at | timestamptz | yes |  |
| updated_at | timestamptz | yes |  |

- FK `FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE`
- UNIQUE `(provider, provider_user_id)`

### `status_likes`

| Column | Type | Null | Default |
|---|---|---|---|
| status_id | varchar(40) |  |  |
| user_id | varchar(40) |  |  |
| created_at | timestamptz |  | `CURRENT_TIMESTAMP` |

- FK `FOREIGN KEY (status_id) REFERENCES status_posts(id) ON DELETE CASCADE`
- FK `FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE`

### `status_posts`

| Column | Type | Null | Default |
|---|---|---|---|
| id | varchar(40) |  |  |
| community_id | varchar(40) |  |  |
| author_user_id | varchar(40) |  |  |
| media_url | varchar(2048) |  |  |
| media_type | varchar(10) |  |  |
| upload_id | varchar(40) | yes |  |
| caption | varchar(500) | yes |  |
| likes_count | integer |  | `0` |
| views_count | integer |  | `0` |
| expires_at | timestamptz |  |  |
| media_purged_at | timestamptz | yes |  |
| created_at | timestamptz | yes |  |
| updated_at | timestamptz | yes |  |
| deleted_at | timestamptz | yes |  |

- FK `FOREIGN KEY (author_user_id) REFERENCES users(id) ON DELETE CASCADE`
- FK `FOREIGN KEY (community_id) REFERENCES communities(id) ON DELETE CASCADE`
- INDEX `(community_id, expires_at)`

### `status_views`

| Column | Type | Null | Default |
|---|---|---|---|
| status_id | varchar(40) |  |  |
| user_id | varchar(40) |  |  |
| created_at | timestamptz |  | `CURRENT_TIMESTAMP` |

- FK `FOREIGN KEY (status_id) REFERENCES status_posts(id) ON DELETE CASCADE`
- FK `FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE`

### `sub_events`

| Column | Type | Null | Default |
|---|---|---|---|
| id | varchar(40) |  |  |
| event_id | varchar(40) |  |  |
| schedule_id | varchar(40) | yes |  |
| title | varchar(200) |  |  |
| description | text | yes |  |
| cover_url | varchar(1024) | yes |  |
| starts_at | timestamptz |  |  |
| ends_at | timestamptz | yes |  |
| requires_approval | boolean |  | `false` |
| is_included_in_bundle | boolean |  | `true` |
| sort_order | smallint |  | `'0'` |
| created_at | timestamptz | yes |  |
| updated_at | timestamptz | yes |  |

- FK `FOREIGN KEY (event_id) REFERENCES events(id) ON DELETE CASCADE`
- FK `FOREIGN KEY (schedule_id) REFERENCES event_schedules(id) ON DELETE SET NULL`

### `subscription_payments`

| Column | Type | Null | Default |
|---|---|---|---|
| id | varchar(40) |  |  |
| reference | varchar(30) |  |  |
| user_id | varchar(40) |  |  |
| subscription_id | varchar(40) | yes |  |
| plan_id | varchar(40) |  |  |
| interval | varchar(10) |  |  |
| payment_id | varchar(40) | yes |  |
| payment_method | varchar(20) |  |  |
| amount | bigint |  |  |
| currency | character |  |  |
| status | varchar(20) |  | `'pending'` |
| idempotency_key | varchar(191) | yes |  |
| paid_at | timestamptz | yes |  |
| receipt_url | varchar(2048) | yes |  |
| created_at | timestamptz | yes |  |
| updated_at | timestamptz | yes |  |

- FK `FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE`
- UNIQUE `(reference)`
- INDEX `(user_id, created_at)`

### `subscriptions`

| Column | Type | Null | Default |
|---|---|---|---|
| id | varchar(40) |  |  |
| user_id | varchar(40) |  |  |
| plan_id | varchar(40) |  |  |
| interval | varchar(10) |  |  |
| status | varchar(20) |  |  |
| pending_plan_id | varchar(40) | yes |  |
| pending_interval | varchar(10) | yes |  |
| started_at | timestamptz |  |  |
| renews_at | timestamptz | yes |  |
| cancelled_at | timestamptz | yes |  |
| past_due_at | timestamptz | yes |  |
| provider | varchar(20) | yes |  |
| provider_subscription_id | varchar(191) | yes |  |
| created_at | timestamptz | yes |  |
| updated_at | timestamptz | yes |  |

- FK `FOREIGN KEY (plan_id) REFERENCES plans(id)`
- FK `FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE`
- UNIQUE `(user_id) WHERE ((status)::text = ANY ((ARRAY['active'::character varying, 'trialing'::character varying, 'past_due'::character varying])::text[]))`

### `support_messages`

| Column | Type | Null | Default |
|---|---|---|---|
| id | varchar(40) |  |  |
| ticket_id | varchar(40) |  |  |
| author_user_id | varchar(40) | yes |  |
| author_admin_id | varchar(40) | yes |  |
| body | text |  |  |
| created_at | timestamptz |  | `CURRENT_TIMESTAMP` |

- FK `FOREIGN KEY (ticket_id) REFERENCES support_tickets(id) ON DELETE CASCADE`
- INDEX `(ticket_id, created_at)`

### `support_tickets`

| Column | Type | Null | Default |
|---|---|---|---|
| id | varchar(40) |  |  |
| reference | varchar(20) |  |  |
| user_id | varchar(40) | yes |  |
| subject | varchar(200) |  |  |
| category | varchar(20) |  |  |
| status | varchar(20) |  | `'open'` |
| priority | varchar(20) |  | `'medium'` |
| assigned_admin_id | varchar(40) | yes |  |
| source | varchar(10) |  | `'web'` |
| created_at | timestamptz | yes |  |
| updated_at | timestamptz | yes |  |

- FK `FOREIGN KEY (assigned_admin_id) REFERENCES admin_users(id) ON DELETE SET NULL`
- FK `FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL`
- UNIQUE `(reference)`
- INDEX `(status, created_at)`
- INDEX `(user_id)`

### `system_alerts`

| Column | Type | Null | Default |
|---|---|---|---|
| id | varchar(40) |  |  |
| severity | varchar(20) |  |  |
| title | varchar(200) |  |  |
| description | varchar(1000) |  |  |
| link | varchar(512) | yes |  |
| dedupe_key | varchar(191) | yes |  |
| resolved_at | timestamptz | yes |  |
| created_at | timestamptz | yes |  |
| updated_at | timestamptz | yes |  |

- UNIQUE `(dedupe_key)`
- INDEX `(resolved_at, created_at)`

### `ticket_transfers`

| Column | Type | Null | Default |
|---|---|---|---|
| id | varchar(40) |  |  |
| ticket_id | varchar(40) |  |  |
| from_user_id | varchar(40) |  |  |
| recipient_email | varchar(255) |  |  |
| message | varchar(500) | yes |  |
| new_ticket_id | varchar(40) | yes |  |
| status | varchar(20) |  | `'completed'` |
| created_at | timestamptz | yes |  |
| updated_at | timestamptz | yes |  |

- FK `FOREIGN KEY (ticket_id) REFERENCES tickets(id) ON DELETE CASCADE`

### `ticket_types`

| Column | Type | Null | Default |
|---|---|---|---|
| id | varchar(40) |  |  |
| event_id | varchar(40) |  |  |
| schedule_id | varchar(40) | yes |  |
| name | varchar(120) |  |  |
| description | varchar(1000) | yes |  |
| price | bigint |  | `'0'` |
| currency | character |  | `'NGN'` |
| quantity | integer |  |  |
| sold | integer |  | `0` |
| sales_start_at | timestamptz | yes |  |
| sales_end_at | timestamptz | yes |  |
| per_order_limit | smallint |  | `'10'` |
| seat_selection | boolean |  | `false` |
| menu_selection | boolean |  | `false` |
| is_early_bird | boolean |  | `false` |
| is_active | boolean |  | `true` |
| sort_order | smallint |  | `'0'` |
| created_at | timestamptz | yes |  |
| updated_at | timestamptz | yes |  |
| deleted_at | timestamptz | yes |  |

- CHECK `CHECK ((price >= 0))`
- CHECK `CHECK ((sold <= quantity))`
- FK `FOREIGN KEY (event_id) REFERENCES events(id) ON DELETE CASCADE`
- FK `FOREIGN KEY (schedule_id) REFERENCES event_schedules(id) ON DELETE SET NULL`
- INDEX `(event_id)`

### `tickets`

| Column | Type | Null | Default |
|---|---|---|---|
| id | varchar(40) |  |  |
| code | varchar(20) |  |  |
| order_id | varchar(40) |  |  |
| event_id | varchar(40) |  |  |
| schedule_id | varchar(40) | yes |  |
| ticket_type_id | varchar(40) |  |  |
| holder_user_id | varchar(40) | yes |  |
| holder_name | varchar(200) |  |  |
| holder_email | varchar(255) | yes |  |
| is_additional_guest | boolean |  | `false` |
| qr_secret_version | smallint |  | `'1'` |
| status | varchar(20) |  | `'valid'` |
| checked_in_at | timestamptz | yes |  |
| checked_in_by_user_id | varchar(40) | yes |  |
| seat_reservation_id | varchar(40) | yes |  |
| transferred_to_ticket_id | varchar(40) | yes |  |
| transferred_to_user_id | varchar(40) | yes |  |
| created_at | timestamptz | yes |  |
| updated_at | timestamptz | yes |  |

- FK `FOREIGN KEY (event_id) REFERENCES events(id)`
- FK `FOREIGN KEY (order_id) REFERENCES orders(id) ON DELETE CASCADE`
- FK `FOREIGN KEY (ticket_type_id) REFERENCES ticket_types(id)`
- UNIQUE `(code)`
- INDEX `(event_id, status)`
- INDEX `(holder_user_id, created_at)`
- INDEX `(order_id)`

### `uploads`

| Column | Type | Null | Default |
|---|---|---|---|
| id | varchar(40) |  |  |
| owner_user_id | varchar(40) | yes |  |
| owner_admin_id | varchar(40) | yes |  |
| purpose | varchar(30) |  |  |
| provider | varchar(20) |  |  |
| provider_public_id | varchar(512) | yes |  |
| resource_type | varchar(20) | yes |  |
| disk_path | varchar(1024) | yes |  |
| url | varchar(2048) | yes |  |
| thumbnail_url | varchar(2048) | yes |  |
| original_name | varchar(255) | yes |  |
| mime_type | varchar(120) |  |  |
| size_bytes | bigint |  |  |
| width | integer | yes |  |
| height | integer | yes |  |
| duration_seconds | integer | yes |  |
| attached_at | timestamptz | yes |  |
| expires_at | timestamptz | yes |  |
| created_at | timestamptz | yes |  |
| updated_at | timestamptz | yes |  |

- INDEX `(attached_at, expires_at)`
- INDEX `(owner_user_id)`

### `user_activities`

| Column | Type | Null | Default |
|---|---|---|---|
| id | varchar(40) |  |  |
| user_id | varchar(40) |  |  |
| type | varchar(60) |  |  |
| description | varchar(500) |  |  |
| source | varchar(10) |  | `'web'` |
| metadata | jsonb | yes |  |
| created_at | timestamptz |  | `CURRENT_TIMESTAMP` |

- FK `FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE`
- INDEX `(user_id, created_at)`

### `user_interests`

| Column | Type | Null | Default |
|---|---|---|---|
| user_id | varchar(40) |  |  |
| interest_slug | varchar(60) |  |  |

- FK `FOREIGN KEY (interest_slug) REFERENCES interests(slug) ON DELETE CASCADE`
- FK `FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE`

### `user_privacy_settings`

| Column | Type | Null | Default |
|---|---|---|---|
| user_id | varchar(40) |  |  |
| profile_visibility | varchar(20) |  | `'public'` |
| show_email | boolean |  | `false` |
| show_phone | boolean |  | `false` |
| allow_message_requests | boolean |  | `true` |
| allow_tagging | boolean |  | `true` |
| discoverable_by_search | boolean |  | `true` |
| created_at | timestamptz | yes |  |
| updated_at | timestamptz | yes |  |

- FK `FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE`

### `user_roles`

| Column | Type | Null | Default |
|---|---|---|---|
| user_id | varchar(40) |  |  |
| role | varchar(20) |  |  |
| created_at | timestamptz |  | `CURRENT_TIMESTAMP` |

- FK `FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE`

### `users`

| Column | Type | Null | Default |
|---|---|---|---|
| id | varchar(40) |  |  |
| first_name | varchar(100) |  |  |
| last_name | varchar(100) |  |  |
| display_name | varchar(200) |  |  |
| username | varchar(30) | yes |  |
| email | varchar(255) |  |  |
| phone | varchar(20) | yes |  |
| date_of_birth | date | yes |  |
| password | varchar(255) | yes |  |
| email_verified_at | timestamptz | yes |  |
| phone_verified_at | timestamptz | yes |  |
| identity_status | varchar(20) |  | `'unverified'` |
| status | varchar(20) |  | `'active'` |
| plan | varchar(20) |  | `'free'` |
| locale | varchar(10) |  | `'en'` |
| city | varchar(120) | yes |  |
| country_code | character | yes |  |
| avatar_url | varchar(1024) | yes |  |
| bio | varchar(500) | yes |  |
| suspended_reason | varchar(1000) | yes |  |
| suspended_at | timestamptz | yes |  |
| signup_source | varchar(10) |  | `'web'` |
| is_vendor | boolean |  | `false` |
| profile_completed | boolean |  | `false` |
| interests_completed | boolean |  | `false` |
| last_seen_at | timestamptz | yes |  |
| anonymized_at | timestamptz | yes |  |
| created_at | timestamptz | yes |  |
| updated_at | timestamptz | yes |  |
| deleted_at | timestamptz | yes |  |

- CHECK `CHECK (((status)::text = ANY ((ARRAY['active'::character varying, 'suspended'::character varying, 'deactivated'::character varying, 'pending_deletion'::character varying])::text[])))`
- INDEX `(created_at)`
- UNIQUE `(lower((email)::text)) WHERE (deleted_at IS NULL)`
- INDEX `(((((((first_name)::text \|\| ' '::text) \|\| (last_name)::text) \|\| ' '::text) \|\| (COALESCE(username, ''::character varying))::text)) gin_trgm_ops)`
- INDEX `(status, created_at)`
- UNIQUE `(lower((username)::text)) WHERE ((deleted_at IS NULL) AND (username IS NOT NULL))`

### `vendor_categories`

| Column | Type | Null | Default |
|---|---|---|---|
| id | varchar(40) |  |  |
| name | varchar(120) |  |  |
| slug | varchar(120) |  |  |
| sort_order | smallint |  | `'0'` |
| is_active | boolean |  | `true` |
| created_at | timestamptz | yes |  |
| updated_at | timestamptz | yes |  |

- UNIQUE `(slug)`

### `vendor_profiles`

| Column | Type | Null | Default |
|---|---|---|---|
| id | varchar(40) |  |  |
| user_id | varchar(40) |  |  |
| business_name | varchar(200) |  |  |
| slug | varchar(220) |  |  |
| category_id | varchar(40) | yes |  |
| location | varchar(200) |  |  |
| description | text |  |  |
| avatar_url | varchar(1024) | yes |  |
| cover_url | varchar(1024) | yes |  |
| contact_email | varchar(255) | yes |  |
| contact_phone | varchar(20) | yes |  |
| contact_email_verified_at | timestamptz | yes |  |
| website | varchar(512) | yes |  |
| socials | jsonb | yes |  |
| status | varchar(30) |  | `'pending_verification'` |
| availability | varchar(20) |  | `'available'` |
| rating_avg | numeric |  | `'0'` |
| ratings_count | integer |  | `0` |
| likes_count | integer |  | `0` |
| works_count | integer |  | `0` |
| is_featured | boolean |  | `false` |
| rejection_reason | varchar(1000) | yes |  |
| suspended_reason | varchar(1000) | yes |  |
| verified_at | timestamptz | yes |  |
| created_at | timestamptz | yes |  |
| updated_at | timestamptz | yes |  |
| deleted_at | timestamptz | yes |  |

- FK `FOREIGN KEY (category_id) REFERENCES vendor_categories(id) ON DELETE SET NULL`
- FK `FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE`
- INDEX `(business_name gin_trgm_ops)`
- UNIQUE `(slug)`
- INDEX `(status, rating_avg)`
- UNIQUE `(user_id)`

### `vendor_promos`

| Column | Type | Null | Default |
|---|---|---|---|
| id | varchar(40) |  |  |
| vendor_profile_id | varchar(40) |  |  |
| title | varchar(200) |  |  |
| description | varchar(1000) |  |  |
| discount_percent | numeric | yes |  |
| valid_until | timestamptz | yes |  |
| cover_url | varchar(1024) | yes |  |
| created_at | timestamptz | yes |  |
| updated_at | timestamptz | yes |  |

- FK `FOREIGN KEY (vendor_profile_id) REFERENCES vendor_profiles(id) ON DELETE CASCADE`

### `vendor_reviews`

| Column | Type | Null | Default |
|---|---|---|---|
| id | varchar(40) |  |  |
| vendor_profile_id | varchar(40) |  |  |
| user_id | varchar(40) |  |  |
| rating | smallint |  |  |
| comment | varchar(1000) |  |  |
| created_at | timestamptz | yes |  |
| updated_at | timestamptz | yes |  |
| deleted_at | timestamptz | yes |  |

- CHECK `CHECK (((rating >= 1) AND (rating <= 5)))`
- FK `FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE`
- FK `FOREIGN KEY (vendor_profile_id) REFERENCES vendor_profiles(id) ON DELETE CASCADE`
- UNIQUE `(vendor_profile_id, user_id) WHERE (deleted_at IS NULL)`

### `vendor_verification_documents`

| Column | Type | Null | Default |
|---|---|---|---|
| id | varchar(40) |  |  |
| vendor_profile_id | varchar(40) | yes |  |
| user_id | varchar(40) |  |  |
| type | varchar(60) |  |  |
| upload_id | varchar(40) | yes |  |
| status | varchar(20) |  | `'pending'` |
| created_at | timestamptz | yes |  |
| updated_at | timestamptz | yes |  |

- FK `FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE`

### `vendor_works`

| Column | Type | Null | Default |
|---|---|---|---|
| id | varchar(40) |  |  |
| vendor_profile_id | varchar(40) |  |  |
| title | varchar(200) |  |  |
| description | text |  |  |
| likes_count | integer |  | `0` |
| created_at | timestamptz | yes |  |
| updated_at | timestamptz | yes |  |
| deleted_at | timestamptz | yes |  |

- FK `FOREIGN KEY (vendor_profile_id) REFERENCES vendor_profiles(id) ON DELETE CASCADE`
- INDEX `(vendor_profile_id, created_at)`

### `wallet_transactions`

| Column | Type | Null | Default |
|---|---|---|---|
| id | varchar(40) |  |  |
| reference | varchar(30) |  |  |
| wallet_id | varchar(40) |  |  |
| type | varchar(30) |  |  |
| direction | varchar(6) |  |  |
| amount | bigint |  |  |
| fee | bigint |  | `'0'` |
| currency | character |  |  |
| status | varchar(20) |  | `'pending'` |
| description | varchar(255) |  |  |
| counterparty | varchar(255) | yes |  |
| provider | varchar(20) | yes |  |
| provider_reference | varchar(191) | yes |  |
| bank_account_id | varchar(40) | yes |  |
| related_type | varchar(60) | yes |  |
| related_id | varchar(40) | yes |  |
| metadata | jsonb | yes |  |
| idempotency_key | varchar(191) | yes |  |
| failure_reason | varchar(500) | yes |  |
| completed_at | timestamptz | yes |  |
| created_at | timestamptz | yes |  |
| updated_at | timestamptz | yes |  |

- FK `FOREIGN KEY (wallet_id) REFERENCES wallets(id) ON DELETE CASCADE`
- INDEX `(provider_reference)`
- UNIQUE `(reference)`
- INDEX `(type, status)`
- INDEX `(wallet_id, created_at)`
- UNIQUE `(wallet_id, idempotency_key)`

### `wallets`

| Column | Type | Null | Default |
|---|---|---|---|
| id | varchar(40) |  |  |
| user_id | varchar(40) |  |  |
| currency | character |  | `'NGN'` |
| is_active | boolean |  | `false` |
| pin_hash | varchar(255) | yes |  |
| pin_failed_attempts | smallint |  | `'0'` |
| pin_locked_until | timestamptz | yes |  |
| cached_balance | bigint |  | `'0'` |
| cached_pending_balance | bigint |  | `'0'` |
| daily_withdrawal_limit | bigint |  | `'50000000'` |
| activated_at | timestamptz | yes |  |
| created_at | timestamptz | yes |  |
| updated_at | timestamptz | yes |  |

- CHECK `CHECK ((cached_balance >= 0))`
- FK `FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE`
- UNIQUE `(user_id)`

### `webhook_events`

| Column | Type | Null | Default |
|---|---|---|---|
| id | varchar(40) |  |  |
| provider | varchar(30) |  |  |
| provider_event_id | varchar(191) |  |  |
| type | varchar(100) | yes |  |
| signature_valid | boolean |  | `false` |
| payload | jsonb |  |  |
| status | varchar(20) |  | `'received'` |
| attempts | smallint |  | `'0'` |
| processed_at | timestamptz | yes |  |
| error | text | yes |  |
| created_at | timestamptz | yes |  |
| updated_at | timestamptz | yes |  |

- UNIQUE `(provider, provider_event_id)`
- INDEX `(status, created_at)`

### `work_likes`

| Column | Type | Null | Default |
|---|---|---|---|
| user_id | varchar(40) |  |  |
| work_id | varchar(40) |  |  |
| created_at | timestamptz |  | `CURRENT_TIMESTAMP` |

- FK `FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE`
- FK `FOREIGN KEY (work_id) REFERENCES vendor_works(id) ON DELETE CASCADE`

