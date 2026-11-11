# BDMS Database Schema Specification

This specification details all **31 tables** (3 existing Laravel baseline auth/session tables + 28 BDMS domain tables) designed for **Laravel 13**, **MySQL 8.4.3** (`utf8mb4`, `utf8mb4_unicode_ci`, InnoDB), and **SQLite `:memory:`** (`phpunit.xml` test compatibility).

- **Dual-Key Strategy**: Core operational entities pair a compact `BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY` (`id`) with a `UNIQUE` indexed human-readable business code (e.g., `DNR-1041`, `DON-4405`, `REQ-1044`, `CMP-308`, `APT-2041`).
- **Immutability & Archival Policy**: Clinical, screening, unit-provenance, fulfillment, and ledger tables do **not** use `SoftDeletes` (`ON DELETE RESTRICT`); accounts deactivate via `is_active` / `account_status`.
- **MySQL 8.4.3 & SQLite `:memory:` Compatibility Rule**:
  - `STORED` generated guard columns (`active_slot_guard`, `active_unit_guard`, `active_registration_guard`) + `UNIQUE` indexes AND table `CHECK` constraints compile cleanly via Laravel 13 `Schema::create(...)` on both **MySQL 8.4.3** and **SQLite 3.40+**.
  - In SQLite, `STORED` columns and inline `CHECK` constraints **must** be declared inside `CREATE TABLE` (`Schema::create(...)`), not appended via `ALTER TABLE` (`Schema::table(...)`).
  - Driver-specific concurrency (`->lockForUpdate()`) runs natively under MySQL 8.4.3 row locking and executes safely without errors inside SQLite `:memory:` transactions.

---

## Group 1: Users, Sessions & Role-Based Access Control

### 1.1 `users` *(extends existing Laravel migration)*

| Column | MySQL Type | Nullable | Default | Keys / Constraints | Notes |
|---|---|---|---|---|---|
| `id` | `BIGINT UNSIGNED` | NO | `AUTO_INCREMENT` | `PRIMARY KEY` | Internal user ID |
| `name` | `VARCHAR(150)` | NO | — | — | Display name |
| `email` | `VARCHAR(191)` | NO | — | `UNIQUE (email)` | Login identifier |
| `email_verified_at` | `TIMESTAMP` | YES | `NULL` | — | Verification timestamp |
| `password` | `VARCHAR(255)` | NO | — | — | Bcrypt/Argon2 hash |
| `is_active` | `TINYINT(1)` | NO | `1` | `INDEX` | Enables instant suspension |
| `last_login_at` | `TIMESTAMP` | YES | `NULL` | — | Last sign-in audit |
| `remember_token` | `VARCHAR(100)` | YES | `NULL` | — | Session remember token |
| `created_at` | `TIMESTAMP` | YES | `NULL` | — | Laravel timestamp |
| `updated_at` | `TIMESTAMP` | YES | `NULL` | — | Laravel timestamp |

### 1.2 `password_reset_tokens` *(existing Laravel baseline)*

| Column | MySQL Type | Nullable | Default | Keys / Constraints |
|---|---|---|---|---|
| `email` | `VARCHAR(191)` | NO | — | `PRIMARY KEY` |
| `token` | `VARCHAR(255)` | NO | — | — |
| `created_at` | `TIMESTAMP` | YES | `NULL` | — |

### 1.3 `sessions` *(existing Laravel baseline)*

| Column | MySQL Type | Nullable | Default | Keys / Constraints |
|---|---|---|---|---|
| `id` | `VARCHAR(191)` | NO | — | `PRIMARY KEY` |
| `user_id` | `BIGINT UNSIGNED` | YES | `NULL` | `INDEX (user_id)` |
| `ip_address` | `VARCHAR(45)` | YES | `NULL` | — |
| `user_agent` | `TEXT` | YES | `NULL` | — |
| `payload` | `LONGTEXT` | NO | — | — |
| `last_activity` | `INT` | NO | — | `INDEX (last_activity)` |

### 1.4 `roles`

| Column | MySQL Type | Nullable | Default | Keys / Constraints | Notes |
|---|---|---|---|---|---|
| `id` | `BIGINT UNSIGNED` | NO | `AUTO_INCREMENT` | `PRIMARY KEY` | Role ID |
| `name` | `VARCHAR(100)` | NO | — | `UNIQUE (name)` | e.g., `Blood Bank Staff` |
| `slug` | `VARCHAR(64)` | NO | — | `UNIQUE (slug)` | `admin`, `staff`, `lab_officer`, `hospital`, `donor` |
| `description` | `VARCHAR(255)` | YES | `NULL` | — | Role purpose |
| `created_at` | `TIMESTAMP` | YES | `NULL` | — | — |
| `updated_at` | `TIMESTAMP` | YES | `NULL` | — | — |

### 1.5 `permissions`

| Column | MySQL Type | Nullable | Default | Keys / Constraints | Notes |
|---|---|---|---|---|---|
| `id` | `BIGINT UNSIGNED` | NO | `AUTO_INCREMENT` | `PRIMARY KEY` | Permission ID |
| `name` | `VARCHAR(120)` | NO | — | — | Human-readable label |
| `slug` | `VARCHAR(100)` | NO | — | `UNIQUE (slug)` | e.g., `donors.view_medical`, `requests.override_compatibility` |
| `module` | `VARCHAR(64)` | NO | — | `INDEX (module)` | `inventory`, `donors`, `screening`, `requests`, `camps` |
| `description` | `VARCHAR(255)` | YES | `NULL` | — | — |
| `created_at` | `TIMESTAMP` | YES | `NULL` | — | — |
| `updated_at` | `TIMESTAMP` | YES | `NULL` | — | — |

### 1.6 `role_user`

| Column | MySQL Type | Nullable | Default | Keys / Constraints |
|---|---|---|---|---|
| `user_id` | `BIGINT UNSIGNED` | NO | — | `PRIMARY KEY (user_id, role_id)`, `FK → users(id) ON DELETE CASCADE` |
| `role_id` | `BIGINT UNSIGNED` | NO | — | `PRIMARY KEY (user_id, role_id)`, `FK → roles(id) ON DELETE RESTRICT` |
| `assigned_by_user_id` | `BIGINT UNSIGNED` | YES | `NULL` | `FK → users(id) ON DELETE SET NULL` |
| `assigned_at` | `TIMESTAMP` | NO | `CURRENT_TIMESTAMP` | — |

### 1.7 `permission_role`

| Column | MySQL Type | Nullable | Default | Keys / Constraints |
|---|---|---|---|---|
| `permission_id` | `BIGINT UNSIGNED` | NO | — | `PRIMARY KEY (permission_id, role_id)`, `FK → permissions(id) ON DELETE CASCADE` |
| `role_id` | `BIGINT UNSIGNED` | NO | — | `PRIMARY KEY (permission_id, role_id)`, `FK → roles(id) ON DELETE CASCADE` |
| `created_at` | `TIMESTAMP` | YES | `NULL` | — |

---

## Group 2: Donors, Sensitive Medical Profile & Eligibility Governance

### 2.1 `donors`

| Column | MySQL Type | Nullable | Default | Keys / Constraints | Notes |
|---|---|---|---|---|---|
| `id` | `BIGINT UNSIGNED` | NO | `AUTO_INCREMENT` | `PRIMARY KEY` | Surrogate PK |
| `donor_code` | `VARCHAR(32)` | NO | — | `UNIQUE (donor_code)` | e.g., `DNR-1041` |
| `user_id` | `BIGINT UNSIGNED` | YES | `NULL` | `UNIQUE (user_id)`, `FK → users(id) ON DELETE SET NULL` | Optional portal account link |
| `full_name` | `VARCHAR(150)` | NO | — | `INDEX (full_name)` | Directory search |
| `email` | `VARCHAR(191)` | NO | — | `UNIQUE (email)` | Donor email |
| `phone` | `VARCHAR(32)` | YES | `NULL` | `INDEX (phone)` | Nullable until `/register` onboarding completion; required before collection |
| `gender` | `ENUM('Female','Male','Other')` | YES | `NULL` | — | Required prior to collection |
| `date_of_birth` | `DATE` | YES | `NULL` | — | Required prior to collection (age `16`–`110`) |
| `blood_group` | `ENUM('A+','A-','B+','B-','AB+','AB-','O+','O-')` | YES | `NULL` | `INDEX (blood_group)` | Confirmed at clinical intake |
| `address` | `TEXT` | YES | `NULL` | — | Residential address |
| `emergency_contact` | `VARCHAR(150)` | YES | `NULL` | — | Emergency contact details |
| `availability_status` | `ENUM('Available','Temporarily Unavailable','Inactive')` | NO | `'Available'` | — | Outreach availability |
| `eligibility_status` | `ENUM('Eligible','Not Eligible','Pending Review','Unknown')` | NO | `'Pending Review'` | `INDEX (eligibility_status)` | Synchronized with current eligibility record |
| `account_status` | `ENUM('Active','Inactive')` | NO | `'Active'` | `INDEX (account_status)` | Administrative status |
| `last_donation_date` | `DATE` | YES | `NULL` | — | Latest `Completed` donation date |
| `next_eligible_date` | `DATE` | YES | `NULL` | — | Medical deferral lift date |
| `total_completed_donations` | `INT UNSIGNED` | NO | `0` | `CHECK (total_completed_donations >= 0)` | Lifetime completed count |
| `profile_completed` | `TINYINT(1)` | NO | `0` | — | Must equal `1` before completing a real donation |
| `registered_at` | `DATE` | NO | — | `INDEX (registered_at)` | `"New Donors This Month"` reporting |
| `created_at` | `TIMESTAMP` | YES | `NULL` | — | — |
| `updated_at` | `TIMESTAMP` | YES | `NULL` | — | — |

### 2.2 `donor_medical_profiles`

| Column | MySQL Type | Nullable | Default | Keys / Constraints | Notes |
|---|---|---|---|---|---|
| `id` | `BIGINT UNSIGNED` | NO | `AUTO_INCREMENT` | `PRIMARY KEY` | Isolated clinical table |
| `donor_id` | `BIGINT UNSIGNED` | NO | — | `UNIQUE (donor_id)`, `FK → donors(id) ON DELETE RESTRICT` | Strict 1:1 extension |
| `medical_notes` | `TEXT` | YES | `NULL` | — | Confidential clinician remarks |
| `last_hemoglobin_g_dl` | `DECIMAL(4,2)` | YES | `NULL` | `CHECK (last_hemoglobin_g_dl IS NULL OR last_hemoglobin_g_dl > 0)` | Pre-draw hemoglobin reading |
| `weight_kg` | `DECIMAL(5,2)` | YES | `NULL` | `CHECK (weight_kg IS NULL OR weight_kg > 0)` | Pre-draw weight |
| `deferral_notes` | `TEXT` | YES | `NULL` | — | Clinical deferral details |
| `updated_by_user_id` | `BIGINT UNSIGNED` | YES | `NULL` | `FK → users(id) ON DELETE SET NULL` | Last modifying officer |
| `created_at` | `TIMESTAMP` | YES | `NULL` | — | — |
| `updated_at` | `TIMESTAMP` | YES | `NULL` | — | — |

### 2.3 `donor_eligibility_records`

| Column | MySQL Type | Nullable | Default | Keys / Constraints | Notes |
|---|---|---|---|---|---|
| `id` | `BIGINT UNSIGNED` | NO | `AUTO_INCREMENT` | `PRIMARY KEY` | Eligibility history PK |
| `donor_id` | `BIGINT UNSIGNED` | NO | — | `FK → donors(id) ON DELETE RESTRICT`, `INDEX (donor_id, is_current)` | Donor reference |
| `eligibility_status` | `ENUM('Eligible','Not Eligible','Pending Review','Unknown')` | NO | — | — | Evaluated clearance outcome |
| `reason` | `TEXT` | NO | — | — | Explanation (`eligibilityNote`) |
| `clearance_source` | `VARCHAR(191)` | NO | — | — | Clinical record source |
| `reviewed_at` | `DATE` | YES | `NULL` | — | Review date |
| `next_eligible_date` | `DATE` | YES | `NULL` | — | Deferral lift date |
| `reviewed_by_user_id` | `BIGINT UNSIGNED` | YES | `NULL` | `FK → users(id) ON DELETE SET NULL` | Authorizing medical officer |
| `is_current` | `TINYINT(1)` | NO | `1` | — | Active status indicator |
| `created_at` | `TIMESTAMP` | YES | `NULL` | — | — |
| `updated_at` | `TIMESTAMP` | YES | `NULL` | — | — |

---

## Group 3: Blood Components, Configurable Screening Panels & Donations

### 3.1 `blood_components`

| Column | MySQL Type | Nullable | Default | Keys / Constraints | Notes |
|---|---|---|---|---|---|
| `id` | `BIGINT UNSIGNED` | NO | `AUTO_INCREMENT` | `PRIMARY KEY` | Component lookup PK |
| `code` | `VARCHAR(24)` | NO | — | `UNIQUE (code)` | `WB`, `PRBC`, `PLT`, `PLS`, `DRBC` |
| `name` | `VARCHAR(100)` | NO | — | `UNIQUE (name)` | `Whole Blood`, `Packed RBC`, `Platelets`, `Plasma`, `Double Red Cells` |
| `default_volume_ml` | `INT UNSIGNED` | NO | — | `CHECK (default_volume_ml > 0)` | Reference bag volume |
| `shelf_life_days` | `SMALLINT UNSIGNED` | NO | — | `CHECK (shelf_life_days > 0)` | Sets bag expiration window |
| `storage_requirements` | `VARCHAR(191)` | YES | `NULL` | — | Cold-chain temperature spec |
| `is_active` | `TINYINT(1)` | NO | `1` | — | — |
| `created_at` | `TIMESTAMP` | YES | `NULL` | — | — |
| `updated_at` | `TIMESTAMP` | YES | `NULL` | — | — |

### 3.2 `screening_test_types`

| Column | MySQL Type | Nullable | Default | Keys / Constraints | Notes |
|---|---|---|---|---|---|
| `id` | `BIGINT UNSIGNED` | NO | `AUTO_INCREMENT` | `PRIMARY KEY` | Test panel PK |
| `code` | `VARCHAR(32)` | NO | — | `UNIQUE (code)` | `HIV`, `HBV`, `HCV`, `SYPH`, `COMPAT` |
| `name` | `VARCHAR(100)` | NO | — | `UNIQUE (name)` | `HIV`, `Hepatitis B`, `Hepatitis C`, `Syphilis`, `Blood Group Compatibility` |
| `description` | `VARCHAR(255)` | YES | `NULL` | — | Panel purpose |
| `is_active` | `TINYINT(1)` | NO | `1` | — | Active catalog toggle |
| `created_at` | `TIMESTAMP` | YES | `NULL` | — | — |
| `updated_at` | `TIMESTAMP` | YES | `NULL` | — | — |

### 3.3 `component_screening_requirements`

| Column | MySQL Type | Nullable | Default | Keys / Constraints | Notes |
|---|---|---|---|---|---|
| `id` | `BIGINT UNSIGNED` | NO | `AUTO_INCREMENT` | `PRIMARY KEY` | Configurable requirement PK |
| `blood_component_id` | `BIGINT UNSIGNED` | NO | — | `FK → blood_components(id) ON DELETE RESTRICT`, `UNIQUE (blood_component_id, test_type_id)` | Target blood component |
| `test_type_id` | `BIGINT UNSIGNED` | NO | — | `FK → screening_test_types(id) ON DELETE RESTRICT`, `INDEX (test_type_id)` | Required screening assay |
| `is_required` | `TINYINT(1)` | NO | `1` | — | Controls release gate without code hardcoding |
| `is_active` | `TINYINT(1)` | NO | `1` | — | Active rule flag |
| `notes` | `VARCHAR(255)` | YES | `NULL` | — | Protocol note |
| `created_at` | `TIMESTAMP` | YES | `NULL` | — | — |
| `updated_at` | `TIMESTAMP` | YES | `NULL` | — | — |

### 3.4 `donations`

| Column | MySQL Type | Nullable | Default | Keys / Constraints | Notes |
|---|---|---|---|---|---|
| `id` | `BIGINT UNSIGNED` | NO | `AUTO_INCREMENT` | `PRIMARY KEY` | Surrogate PK |
| `donation_code` | `VARCHAR(32)` | NO | — | `UNIQUE (donation_code)` | e.g., `DON-4405` |
| `donor_id` | `BIGINT UNSIGNED` | NO | — | `FK → donors(id) ON DELETE RESTRICT`, `INDEX (donor_id, donation_date)` | Donor reference |
| `appointment_id` | `BIGINT UNSIGNED` | YES | `NULL` | `UNIQUE (appointment_id)`, `FK → donor_appointments(id) ON DELETE SET NULL` | Optional scheduled visit |
| `camp_id` | `BIGINT UNSIGNED` | YES | `NULL` | `FK → donation_camps(id) ON DELETE RESTRICT`, `INDEX (camp_id)` | Nullable camp location |
| `center_id` | `BIGINT UNSIGNED` | YES | `NULL` | `FK → donation_centers(id) ON DELETE RESTRICT`, `INDEX (center_id)` | Nullable center location |
| `collection_location_name` | `VARCHAR(191)` | NO | — | — | Display location snapshot |
| `recorded_by_user_id` | `BIGINT UNSIGNED` | YES | `NULL` | `FK → users(id) ON DELETE SET NULL` | Recording staff user |
| `staff_member_name` | `VARCHAR(150)` | NO | — | — | Phlebotomist / clinician name |
| `blood_group` | `ENUM('A+','A-','B+','B-','AB+','AB-','O+','O-')` | NO | — | `INDEX (blood_group)` | Donor blood group at draw |
| `blood_component_id` | `BIGINT UNSIGNED` | NO | — | `FK → blood_components(id) ON DELETE RESTRICT` | Primary collected component |
| `donation_date` | `DATE` | NO | — | `INDEX (donation_date)` | Collection date |
| `collected_at` | `DATETIME` | YES | `NULL` | — | Exact draw timestamp |
| `quantity_ml` | `SMALLINT UNSIGNED` | NO | — | `CHECK (quantity_ml BETWEEN 150 AND 800)` | Collection volume (`150`–`800` ml) |
| `collection_status` | `ENUM('Scheduled','Completed','Cancelled')` | NO | — | `INDEX (collection_status)` | Draw status |
| `screening_status` | `ENUM('Pending','Passed','Failed')` | NO | `'Pending'` | `INDEX (screening_status)` | Overall lab gate status |
| `collection_notes` | `TEXT` | YES | `NULL` | — | Seal/cold-chain bag note |
| `screening_notes` | `TEXT` | YES | `NULL` | — | Lab summary notes |
| `released_to_inventory_at` | `DATETIME` | YES | `NULL` | `INDEX (released_to_inventory_at)` | Set strictly upon authorized release |
| `approved_by_user_id` | `BIGINT UNSIGNED` | YES | `NULL` | `FK → users(id) ON DELETE SET NULL` | Authorizing officer |
| `created_at` | `TIMESTAMP` | YES | `NULL` | — | — |
| `updated_at` | `TIMESTAMP` | YES | `NULL` | — | — |

### 3.5 `screening_records`

| Column | MySQL Type | Nullable | Default | Keys / Constraints | Notes |
|---|---|---|---|---|---|
| `id` | `BIGINT UNSIGNED` | NO | `AUTO_INCREMENT` | `PRIMARY KEY` | Surrogate PK |
| `screening_code` | `VARCHAR(32)` | NO | — | `UNIQUE (screening_code)` | e.g., `SCR-7105` |
| `donation_id` | `BIGINT UNSIGNED` | NO | — | `FK → donations(id) ON DELETE RESTRICT`, `INDEX (donation_id, test_type_id)` | Linked donation |
| `test_type_id` | `BIGINT UNSIGNED` | NO | — | `FK → screening_test_types(id) ON DELETE RESTRICT`, `INDEX (test_type_id)` | Test panel |
| `test_date` | `DATE` | YES | `NULL` | `INDEX (test_date)` | Null while `Pending` |
| `screening_result` | `ENUM('Pending','Passed','Failed')` | NO | `'Pending'` | `INDEX (screening_result)` | Explicit manual entry |
| `is_confirmatory` | `TINYINT(1)` | NO | `0` | — | Confirmatory retest flag |
| `performed_by_user_id` | `BIGINT UNSIGNED` | YES | `NULL` | `FK → users(id) ON DELETE SET NULL` | Lab technician/officer |
| `lab_staff_name` | `VARCHAR(150)` | NO | — | — | Display staff label |
| `assay_method` | `VARCHAR(191)` | YES | `NULL` | — | Instrument/assay method |
| `specimen_tube_code` | `VARCHAR(64)` | YES | `NULL` | `INDEX (specimen_tube_code)` | Tube ID (`EDTA-K2 #T-9941A`) |
| `test_notes` | `TEXT` | YES | `NULL` | — | Analyzer/batch notes |
| `collected_at` | `DATETIME` | YES | `NULL` | — | Specimen collection timestamp |
| `logged_at` | `DATETIME` | YES | `NULL` | — | Lab accession timestamp |
| `result_recorded_at` | `DATETIME` | YES | `NULL` | — | Result verification timestamp |
| `created_at` | `TIMESTAMP` | YES | `NULL` | — | — |
| `updated_at` | `TIMESTAMP` | YES | `NULL` | — | — |

---

## Group 4: Blood Inventory, Serialized Blood Units & Transaction Ledger

### 4.1 `inventory_stocks`

| Column | MySQL Type | Nullable | Default | Keys / Constraints | Notes |
|---|---|---|---|---|---|
| `id` | `BIGINT UNSIGNED` | NO | `AUTO_INCREMENT` | `PRIMARY KEY` | Pessimistic lock anchor per `(group, component)` |
| `blood_group` | `ENUM('A+','A-','B+','B-','AB+','AB-','O+','O-')` | NO | — | `UNIQUE (blood_group, blood_component_id)` | Rolled up by `blood_group` for 8-group views |
| `blood_component_id` | `BIGINT UNSIGNED` | NO | — | `FK → blood_components(id) ON DELETE RESTRICT` | Component classification |
| `available_units` | `INT UNSIGNED` | NO | `0` | `CHECK (available_units >= 0)` | Available released stock |
| `reserved_units` | `INT UNSIGNED` | NO | `0` | `CHECK (reserved_units >= 0)` | Stock reserved for approved requests |
| `minimum_level` | `INT UNSIGNED` | NO | `20` | `CHECK (minimum_level >= 1)` | `low` / `critical` threshold |
| `target_capacity` | `INT UNSIGNED` | NO | `100` | `CHECK (target_capacity >= 1)` | Visual progress indicator denominator |
| `last_adjusted_at` | `DATETIME` | YES | `NULL` | — | Latest movement timestamp |
| `created_at` | `TIMESTAMP` | YES | `NULL` | — | — |
| `updated_at` | `TIMESTAMP` | YES | `NULL` | — | — |

### 4.2 `blood_units`

| Column | MySQL Type | Nullable | Default | Keys / Constraints | Notes |
|---|---|---|---|---|---|
| `id` | `BIGINT UNSIGNED` | NO | `AUTO_INCREMENT` | `PRIMARY KEY` | Serialized blood bag PK |
| `unit_code` | `VARCHAR(40)` | NO | — | `UNIQUE (unit_code)` | Bag identifier (e.g., `UNIT-2026-004405`) |
| `donation_id` | `BIGINT UNSIGNED` | NO | — | `FK → donations(id) ON DELETE RESTRICT`, `INDEX (donation_id)` | Provenance donation |
| `parent_unit_id` | `BIGINT UNSIGNED` | YES | `NULL` | `FK → blood_units(id) ON DELETE SET NULL`, `INDEX (parent_unit_id)` | Supports multi-component fractionation from a parent bag |
| `blood_group` | `ENUM('A+','A-','B+','B-','AB+','AB-','O+','O-')` | NO | — | `INDEX (blood_group, blood_component_id, unit_status, expires_at)` | Optimizes FIFO bag selection |
| `blood_component_id` | `BIGINT UNSIGNED` | NO | — | `FK → blood_components(id) ON DELETE RESTRICT` | Specific stored component |
| `volume_ml` | `SMALLINT UNSIGNED` | NO | — | `CHECK (volume_ml > 0)` | Unit bag volume |
| `storage_location` | `VARCHAR(120)` | YES | `NULL` | — | Refrigerator rack/bay |
| `unit_status` | `ENUM('Quarantined','Available','Reserved','Dispatched','Transfused','Expired','Discarded')` | NO | `'Quarantined'` | `INDEX (unit_status, expires_at)` | Only `'Available'` eligible for allocation |
| `collected_at` | `DATETIME` | NO | — | — | Draw time |
| `expires_at` | `DATETIME` | NO | — | — | Expiry boundary for FIFO & 7-day expiry alerts |
| `released_at` | `DATETIME` | YES | `NULL` | — | Authorized screening-release timestamp |
| `released_by_user_id` | `BIGINT UNSIGNED` | YES | `NULL` | `FK → users(id) ON DELETE SET NULL` | Authorizing officer |
| `created_at` | `TIMESTAMP` | YES | `NULL` | — | — |
| `updated_at` | `TIMESTAMP` | YES | `NULL` | — | — |

### 4.3 `inventory_transactions`

| Column | MySQL Type | Nullable | Default | Keys / Constraints | Notes |
|---|---|---|---|---|---|
| `id` | `BIGINT UNSIGNED` | NO | `AUTO_INCREMENT` | `PRIMARY KEY` | Ledger sequence PK |
| `transaction_code` | `VARCHAR(36)` | NO | — | `UNIQUE (transaction_code)` | e.g., `TXN-101` |
| `inventory_stock_id` | `BIGINT UNSIGNED` | NO | — | `FK → inventory_stocks(id) ON DELETE RESTRICT`, `INDEX (inventory_stock_id, occurred_at)` | Stock bucket affected |
| `blood_unit_id` | `BIGINT UNSIGNED` | YES | `NULL` | `FK → blood_units(id) ON DELETE RESTRICT`, `INDEX (blood_unit_id)` | Bag-level reference |
| `operation_type` | `ENUM('Stock In','Reserve','Release Reservation','Dispatch','Removed','Expired','Discarded','Manual Adjustment')` | NO | — | `INDEX (operation_type)` | Traceable action category |
| `units_delta` | `INT` | NO | — | `CHECK (units_delta <> 0)` | Signed quantity change |
| `available_before` | `INT UNSIGNED` | NO | — | — | Available units before |
| `available_after` | `INT UNSIGNED` | NO | — | `CHECK (available_after >= 0)` | Available units after |
| `reserved_before` | `INT UNSIGNED` | NO | — | — | Reserved units before |
| `reserved_after` | `INT UNSIGNED` | NO | — | `CHECK (reserved_after >= 0)` | Reserved units after |
| `reference_type` | `VARCHAR(64)` | YES | `NULL` | `INDEX (reference_type, reference_id)` | `donation`, `blood_request`, `fulfillment`, `manual` |
| `reference_id` | `BIGINT UNSIGNED` | YES | `NULL` | — | Related domain PK |
| `performed_by_user_id` | `BIGINT UNSIGNED` | YES | `NULL` | `FK → users(id) ON DELETE SET NULL` | Staff user |
| `actor_name` | `VARCHAR(150)` | NO | — | — | Actor snapshot |
| `description` | `TEXT` | NO | — | — | Required audit reason |
| `occurred_at` | `DATETIME` | NO | — | `INDEX (occurred_at)` | Movement timestamp |
| `created_at` | `TIMESTAMP` | YES | `NULL` | — | Immutable ledger (`updated_at` omitted) |

---

## Group 5: Hospitals, Compatibility Matrix, Blood Requests & Fulfillments

### 5.1 `hospitals`

| Column | MySQL Type | Nullable | Default | Keys / Constraints | Notes |
|---|---|---|---|---|---|
| `id` | `BIGINT UNSIGNED` | NO | `AUTO_INCREMENT` | `PRIMARY KEY` | Facility PK |
| `hospital_code` | `VARCHAR(32)` | NO | — | `UNIQUE (hospital_code)` | Facility license/code (`HSP-2049`) |
| `name` | `VARCHAR(191)` | NO | — | `INDEX (name)` | Hospital/clinic name |
| `address` | `TEXT` | YES | `NULL` | — | Street address |
| `city` | `VARCHAR(100)` | YES | `NULL` | `INDEX (city)` | City/district |
| `primary_contact_name` | `VARCHAR(150)` | YES | `NULL` | — | Primary coordinator |
| `primary_phone` | `VARCHAR(32)` | YES | `NULL` | — | Primary contact phone |
| `primary_email` | `VARCHAR(191)` | YES | `NULL` | — | Transfusion lab email |
| `verification_status` | `ENUM('Pending','Verified','Suspended')` | NO | `'Pending'` | `INDEX (verification_status)` | Must be `'Verified'` to submit requests |
| `verified_by_user_id` | `BIGINT UNSIGNED` | YES | `NULL` | `FK → users(id) ON DELETE SET NULL` | Verifying administrator |
| `verified_at` | `DATETIME` | YES | `NULL` | — | Verification timestamp |
| `is_active` | `TINYINT(1)` | NO | `1` | — | Operational toggle |
| `created_at` | `TIMESTAMP` | YES | `NULL` | — | — |
| `updated_at` | `TIMESTAMP` | YES | `NULL` | — | — |

### 5.2 `hospital_users`

| Column | MySQL Type | Nullable | Default | Keys / Constraints | Notes |
|---|---|---|---|---|---|
| `id` | `BIGINT UNSIGNED` | NO | `AUTO_INCREMENT` | `PRIMARY KEY` | Hospital account PK |
| `hospital_id` | `BIGINT UNSIGNED` | NO | — | `FK → hospitals(id) ON DELETE RESTRICT`, `INDEX (hospital_id)` | Facility link |
| `user_id` | `BIGINT UNSIGNED` | NO | — | `UNIQUE (user_id)`, `FK → users(id) ON DELETE CASCADE` | Portal user link |
| `contact_name` | `VARCHAR(150)` | NO | — | — | Coordinator name |
| `department` | `VARCHAR(150)` | YES | `NULL` | — | Ward/department |
| `job_title` | `VARCHAR(120)` | YES | `NULL` | — | Role title |
| `work_phone` | `VARCHAR(32)` | YES | `NULL` | — | Desk phone |
| `work_email` | `VARCHAR(191)` | YES | `NULL` | — | Facility email |
| `is_primary_contact` | `TINYINT(1)` | NO | `0` | — | Primary contact flag |
| `is_active` | `TINYINT(1)` | NO | `1` | — | — |
| `created_at` | `TIMESTAMP` | YES | `NULL` | — | — |
| `updated_at` | `TIMESTAMP` | YES | `NULL` | — | — |

### 5.3 `blood_compatibility_rules`

| Column | MySQL Type | Nullable | Default | Keys / Constraints | Notes |
|---|---|---|---|---|---|
| `id` | `BIGINT UNSIGNED` | NO | `AUTO_INCREMENT` | `PRIMARY KEY` | Rule PK |
| `recipient_blood_group` | `ENUM('A+','A-','B+','B-','AB+','AB-','O+','O-')` | NO | — | `UNIQUE (recipient_blood_group, donor_blood_group, blood_component_id)` | Requested/patient group |
| `donor_blood_group` | `ENUM('A+','A-','B+','B-','AB+','AB-','O+','O-')` | NO | — | `INDEX (donor_blood_group)` | Candidate unit group |
| `blood_component_id` | `BIGINT UNSIGNED` | NO | — | `FK → blood_components(id) ON DELETE RESTRICT` | Component-specific (RBC vs Plasma matrices differ!) |
| `compatibility_level` | `ENUM('Exact Match','Compatible Substitution','Emergency Only')` | NO | — | — | Controlled clinical compatibility tier |
| `requires_medical_override` | `TINYINT(1)` | NO | `0` | — | Triggers medical officer approval check on dispatch |
| `is_active` | `TINYINT(1)` | NO | `1` | — | Clinical matrix toggle |
| `notes` | `VARCHAR(255)` | YES | `NULL` | — | Clinical protocol reference |
| `created_at` | `TIMESTAMP` | YES | `NULL` | — | — |
| `updated_at` | `TIMESTAMP` | YES | `NULL` | — | — |

### 5.4 `blood_requests`

| Column | MySQL Type | Nullable | Default | Keys / Constraints | Notes |
|---|---|---|---|---|---|
| `id` | `BIGINT UNSIGNED` | NO | `AUTO_INCREMENT` | `PRIMARY KEY` | Request PK |
| `request_code` | `VARCHAR(32)` | NO | — | `UNIQUE (request_code)` | e.g., `REQ-1044` |
| `hospital_id` | `BIGINT UNSIGNED` | NO | — | `FK → hospitals(id) ON DELETE RESTRICT`, `INDEX (hospital_id, status)` | Verified requesting hospital |
| `requested_by_user_id` | `BIGINT UNSIGNED` | YES | `NULL` | `FK → users(id) ON DELETE SET NULL` | Requester user |
| `department_or_ward` | `VARCHAR(150)` | YES | `NULL` | — | Ward/theater |
| `contact_person_snapshot` | `VARCHAR(150)` | NO | — | — | Point-in-time contact |
| `contact_phone_snapshot` | `VARCHAR(32)` | NO | — | — | Point-in-time phone |
| `contact_email_snapshot` | `VARCHAR(191)` | NO | — | — | Point-in-time email |
| `blood_group` | `ENUM('A+','A-','B+','B-','AB+','AB-','O+','O-')` | NO | — | `INDEX (blood_group, priority, status)` | Requested ABO/Rh type |
| `blood_component_id` | `BIGINT UNSIGNED` | YES | `NULL` | `FK → blood_components(id) ON DELETE RESTRICT` | Optional specific component request |
| `requested_units` | `INT UNSIGNED` | NO | — | `CHECK (requested_units >= 1)` | Total ordered units |
| `reserved_units` | `INT UNSIGNED` | NO | `0` | `CHECK (reserved_units >= 0)` | Currently held reserved units |
| `fulfilled_units` | `INT UNSIGNED` | NO | `0` | `CHECK (fulfilled_units >= 0 AND fulfilled_units <= requested_units AND fulfilled_units + reserved_units <= requested_units)` | Prevents over-allocation & over-fulfillment |
| `priority` | `ENUM('Low','Normal','High','Critical')` | NO | — | `INDEX (priority)` | Triage level |
| `status` | `ENUM('Pending','Approved','Rejected','Partially Fulfilled','Fulfilled','Cancelled')` | NO | `'Pending'` | `INDEX (status, request_date)` | Order lifecycle state |
| `request_date` | `DATE` | NO | — | — | Order date |
| `required_date` | `DATE` | NO | — | `INDEX (required_date)` | Required-by deadline |
| `request_reason` | `TEXT` | NO | — | — | Clinical justification |
| `additional_notes` | `TEXT` | YES | `NULL` | — | Special handling remarks |
| `reviewed_by_user_id` | `BIGINT UNSIGNED` | YES | `NULL` | `FK → users(id) ON DELETE SET NULL` | Approving/rejecting staff |
| `reviewed_at` | `DATETIME` | YES | `NULL` | — | Review timestamp |
| `rejection_reason` | `TEXT` | YES | `NULL` | — | Mandatory on rejection |
| `cancelled_by_user_id` | `BIGINT UNSIGNED` | YES | `NULL` | `FK → users(id) ON DELETE SET NULL` | Closing user |
| `cancelled_at` | `DATETIME` | YES | `NULL` | — | Cancellation timestamp |
| `cancellation_reason` | `TEXT` | YES | `NULL` | — | Cancellation notes |
| `created_at` | `TIMESTAMP` | YES | `NULL` | — | — |
| `updated_at` | `TIMESTAMP` | YES | `NULL` | — | — |

### 5.5 `blood_request_status_histories`

| Column | MySQL Type | Nullable | Default | Keys / Constraints | Notes |
|---|---|---|---|---|---|
| `id` | `BIGINT UNSIGNED` | NO | `AUTO_INCREMENT` | `PRIMARY KEY` | Timeline PK |
| `blood_request_id` | `BIGINT UNSIGNED` | NO | — | `FK → blood_requests(id) ON DELETE RESTRICT`, `INDEX (blood_request_id, occurred_at)` | Linked request |
| `event_name` | `VARCHAR(120)` | NO | — | — | Event label |
| `previous_status` | `ENUM('Pending','Approved','Rejected','Partially Fulfilled','Fulfilled','Cancelled')` | YES | `NULL` | — | Prior status |
| `new_status` | `ENUM('Pending','Approved','Rejected','Partially Fulfilled','Fulfilled','Cancelled')` | NO | — | — | Resulting status |
| `units_affected` | `INT UNSIGNED` | NO | `0` | — | Units dispatched/reserved |
| `actor_user_id` | `BIGINT UNSIGNED` | YES | `NULL` | `FK → users(id) ON DELETE SET NULL` | Acting user |
| `actor_name` | `VARCHAR(150)` | NO | — | — | Display actor |
| `notes` | `TEXT` | YES | `NULL` | — | Remark |
| `occurred_at` | `DATETIME` | NO | — | — | Timestamp |

### 5.6 `request_fulfillments`

| Column | MySQL Type | Nullable | Default | Keys / Constraints | Notes |
|---|---|---|---|---|---|
| `id` | `BIGINT UNSIGNED` | NO | `AUTO_INCREMENT` | `PRIMARY KEY` | Dispatch batch PK |
| `fulfillment_code` | `VARCHAR(32)` | NO | — | `UNIQUE (fulfillment_code)` | e.g., `FUL-5001` |
| `blood_request_id` | `BIGINT UNSIGNED` | NO | — | `FK → blood_requests(id) ON DELETE RESTRICT`, `INDEX (blood_request_id, dispatch_date)` | Target request |
| `dispatched_by_user_id` | `BIGINT UNSIGNED` | YES | `NULL` | `FK → users(id) ON DELETE SET NULL` | Dispatching staff user |
| `dispatched_blood_group` | `ENUM('A+','A-','B+','B-','AB+','AB-','O+','O-')` | NO | — | — | Actual group sent |
| `blood_component_id` | `BIGINT UNSIGNED` | NO | — | `FK → blood_components(id) ON DELETE RESTRICT` | Actual component sent |
| `units_dispatched` | `INT UNSIGNED` | NO | — | `CHECK (units_dispatched >= 1)` | Quantity dispatched |
| `dispatch_date` | `DATE` | NO | — | — | Dispatch date |
| `dispatched_at` | `DATETIME` | NO | — | — | Courier departure time |
| `courier_reference` | `VARCHAR(64)` | YES | `NULL` | — | e.g., `#TR-208` |
| `delivery_status` | `ENUM('Dispatched','In Transit','Delivered','Returned')` | NO | `'Dispatched'` | `INDEX (delivery_status)` | Cold-chain delivery status |
| `delivered_at` | `DATETIME` | YES | `NULL` | — | Hospital delivery time |
| `received_by_name` | `VARCHAR(150)` | YES | `NULL` | — | Receiving technician |
| `notes` | `TEXT` | YES | `NULL` | — | Dispatch notes |
| `is_compatibility_override` | `TINYINT(1)` | NO | `0` | — | True when non-exact compatible blood issued under medical override |
| `override_approved_by_user_id` | `BIGINT UNSIGNED` | YES | `NULL` | `FK → users(id) ON DELETE SET NULL` | Authorizing medical officer |
| `override_approved_at` | `DATETIME` | YES | `NULL` | — | Approval timestamp |
| `override_reason` | `TEXT` | YES | `NULL` | — | Clinical override justification |
| `created_at` | `TIMESTAMP` | YES | `NULL` | — | — |
| `updated_at` | `TIMESTAMP` | YES | `NULL` | — | — |

**Table Check Constraint on `request_fulfillments`**:
```sql
CONSTRAINT chk_fulfillment_override_audit CHECK (
    is_compatibility_override = 0
    OR (
        override_approved_by_user_id IS NOT NULL
        AND override_approved_at IS NOT NULL
        AND override_reason IS NOT NULL
    )
)
```

### 5.7 `inventory_allocations`

| Column | MySQL Type | Nullable | Default | Keys / Constraints | Notes |
|---|---|---|---|---|---|
| `id` | `BIGINT UNSIGNED` | NO | `AUTO_INCREMENT` | `PRIMARY KEY` | Allocation PK |
| `allocation_code` | `VARCHAR(32)` | NO | — | `UNIQUE (allocation_code)` | e.g., `ALC-3001` |
| `blood_request_id` | `BIGINT UNSIGNED` | NO | — | `FK → blood_requests(id) ON DELETE RESTRICT`, `INDEX (blood_request_id, allocation_status)` | Target request |
| `inventory_stock_id` | `BIGINT UNSIGNED` | NO | — | `FK → inventory_stocks(id) ON DELETE RESTRICT`, `INDEX (inventory_stock_id)` | Source stock bucket |
| `blood_unit_id` | `BIGINT UNSIGNED` | NO | — | `FK → blood_units(id) ON DELETE RESTRICT`, `INDEX (blood_unit_id, allocation_status)` | Specific serialized bag |
| `fulfillment_id` | `BIGINT UNSIGNED` | YES | `NULL` | `FK → request_fulfillments(id) ON DELETE RESTRICT`, `INDEX (fulfillment_id)` | Linked dispatch batch |
| `units_allocated` | `INT UNSIGNED` | NO | `1` | `CHECK (units_allocated = 1)` | 1 allocation row per serialized unit |
| `allocation_status` | `ENUM('Reserved','Dispatched','Released','Cancelled')` | NO | `'Reserved'` | `INDEX (allocation_status)` | Distinguishes holds vs dispatches vs released |
| `active_unit_guard` | `TINYINT(1)` | YES | `GENERATED ALWAYS AS (CASE WHEN allocation_status IN ('Reserved','Dispatched') THEN 1 ELSE NULL END) STORED` | `UNIQUE (blood_unit_id, active_unit_guard)` | Engine lock preventing concurrent double-allocation of one bag |
| `allocated_by_user_id` | `BIGINT UNSIGNED` | YES | `NULL` | `FK → users(id) ON DELETE SET NULL` | Assigning staff user |
| `allocated_at` | `DATETIME` | NO | — | — | Hold timestamp |
| `released_at` | `DATETIME` | YES | `NULL` | — | Release timestamp |
| `dispatched_at` | `DATETIME` | YES | `NULL` | — | Dispatch timestamp |
| `notes` | `TEXT` | YES | `NULL` | — | Crossmatch remarks |
| `created_at` | `TIMESTAMP` | YES | `NULL` | — | — |
| `updated_at` | `TIMESTAMP` | YES | `NULL` | — | — |

---

## Group 6: Donation Centers, Camps, Registrations & Donor Appointments

### 6.1 `donation_centers`

| Column | MySQL Type | Nullable | Default | Keys / Constraints | Notes |
|---|---|---|---|---|---|
| `id` | `BIGINT UNSIGNED` | NO | `AUTO_INCREMENT` | `PRIMARY KEY` | Center PK |
| `center_code` | `VARCHAR(32)` | NO | — | `UNIQUE (center_code)` | `CTR-CENTRAL`, `CTR-WEST`, `CTR-SOUTH` |
| `name` | `VARCHAR(191)` | NO | — | — | Clinic display name |
| `address` | `VARCHAR(255)` | NO | — | — | Street address |
| `city` | `VARCHAR(100)` | NO | — | `INDEX (city)` | City/district |
| `operating_hours` | `VARCHAR(100)` | NO | — | — | Daily hours display |
| `default_slot_capacity` | `SMALLINT UNSIGNED` | NO | `3` | `CHECK (default_slot_capacity >= 1)` | Concurrent slots per time window |
| `is_active` | `TINYINT(1)` | NO | `1` | — | Active center toggle |
| `created_at` | `TIMESTAMP` | YES | `NULL` | — | — |
| `updated_at` | `TIMESTAMP` | YES | `NULL` | — | — |

### 6.2 `donation_camps`

| Column | MySQL Type | Nullable | Default | Keys / Constraints | Notes |
|---|---|---|---|---|---|
| `id` | `BIGINT UNSIGNED` | NO | `AUTO_INCREMENT` | `PRIMARY KEY` | Surrogate PK |
| `camp_code` | `VARCHAR(32)` | NO | — | `UNIQUE (camp_code)` | e.g., `CMP-308` |
| `name` | `VARCHAR(191)` | NO | — | `INDEX (name)` | Drive title |
| `description` | `TEXT` | NO | — | — | Drive overview |
| `organizer_name` | `VARCHAR(150)` | NO | — | — | Internal coordinator (omitted publicly) |
| `organizer_contact` | `VARCHAR(32)` | NO | — | — | Restricted staff phone |
| `organizer_email` | `VARCHAR(191)` | NO | — | — | Restricted staff email |
| `created_by_user_id` | `BIGINT UNSIGNED` | YES | `NULL` | `FK → users(id) ON DELETE SET NULL` | Creator user |
| `venue_name` | `VARCHAR(191)` | NO | — | — | Public venue |
| `full_address` | `VARCHAR(255)` | NO | — | — | Street address |
| `city` | `VARCHAR(100)` | NO | — | `INDEX (city)` | City filter |
| `location_notes` | `TEXT` | YES | `NULL` | — | Arrival guidance |
| `start_date` | `DATE` | NO | — | `INDEX (start_date, status)` | Start date |
| `end_date` | `DATE` | NO | — | `CHECK (end_date >= start_date)` | End date |
| `start_time` | `TIME` | NO | — | — | Daily opening time |
| `end_time` | `TIME` | NO | — | `CHECK (end_date > start_date OR end_time > start_time)` | Daily closing time |
| `capacity` | `INT UNSIGNED` | NO | — | `CHECK (capacity >= 1)` | Total active donor capacity (primary drive cap) |
| `registration_deadline` | `DATE` | NO | — | `CHECK (registration_deadline <= start_date)` | Advance registration cutoff |
| `allow_walk_in` | `TINYINT(1)` | NO | `1` | — | Ongoing walk-in flag |
| `donor_requirements` | `TEXT` | NO | — | — | Readiness checklist |
| `additional_notes` | `TEXT` | YES | `NULL` | — | Extra notes |
| `status` | `ENUM('Draft','Upcoming','Ongoing','Completed','Cancelled')` | NO | `'Draft'` | `INDEX (status)` | Public views strictly filter `Upcoming`/`Ongoing` |
| `created_at` | `TIMESTAMP` | YES | `NULL` | — | — |
| `updated_at` | `TIMESTAMP` | YES | `NULL` | — | — |

### 6.3 `camp_registrations`

| Column | MySQL Type | Nullable | Default | Keys / Constraints | Notes |
|---|---|---|---|---|---|
| `id` | `BIGINT UNSIGNED` | NO | `AUTO_INCREMENT` | `PRIMARY KEY` | Registration PK |
| `registration_code` | `VARCHAR(32)` | NO | — | `UNIQUE (registration_code)` | e.g., `REG-801` |
| `camp_id` | `BIGINT UNSIGNED` | NO | — | `FK → donation_camps(id) ON DELETE RESTRICT`, `INDEX (camp_id, attendance_status)` | Linked camp |
| `donor_id` | `BIGINT UNSIGNED` | NO | — | `FK → donors(id) ON DELETE RESTRICT`, `INDEX (donor_id)` | Registered donor |
| `appointment_id` | `BIGINT UNSIGNED` | YES | `NULL` | `UNIQUE (appointment_id)`, `FK → donor_appointments(id) ON DELETE SET NULL` | Optional linked portal booking |
| `registered_by_user_id` | `BIGINT UNSIGNED` | YES | `NULL` | `FK → users(id) ON DELETE SET NULL` | Actor |
| `registration_date` | `DATE` | NO | — | — | Registration log date |
| `is_walk_in` | `TINYINT(1)` | NO | `0` | — | Walk-in intake flag |
| `attendance_status` | `ENUM('Registered','Confirmed','Attended','No-show','Cancelled')` | NO | `'Registered'` | — | `'Cancelled'` releases slot & keeps history |
| `active_registration_guard` | `TINYINT(1)` | YES | `GENERATED ALWAYS AS (CASE WHEN attendance_status <> 'Cancelled' THEN 1 ELSE NULL END) STORED` | `UNIQUE (camp_id, donor_id, active_registration_guard)` | Blocks duplicate active registration while preserving cancelled history & allowing new re-registration rows |
| `registered_at` | `DATETIME` | NO | — | — | Registration timestamp |
| `checked_in_at` | `DATETIME` | YES | `NULL` | — | Check-in timestamp |
| `cancelled_at` | `DATETIME` | YES | `NULL` | — | Cancellation timestamp |
| `created_at` | `TIMESTAMP` | YES | `NULL` | — | — |
| `updated_at` | `TIMESTAMP` | YES | `NULL` | — | — |

### 6.4 `camp_status_histories`

| Column | MySQL Type | Nullable | Default | Keys / Constraints | Notes |
|---|---|---|---|---|---|
| `id` | `BIGINT UNSIGNED` | NO | `AUTO_INCREMENT` | `PRIMARY KEY` | Audit PK |
| `camp_id` | `BIGINT UNSIGNED` | NO | — | `FK → donation_camps(id) ON DELETE RESTRICT`, `INDEX (camp_id, occurred_at)` | Linked camp |
| `event_name` | `VARCHAR(120)` | NO | — | — | Event name |
| `previous_status` | `ENUM('Draft','Upcoming','Ongoing','Completed','Cancelled')` | YES | `NULL` | — | Prior status |
| `new_status` | `ENUM('Draft','Upcoming','Ongoing','Completed','Cancelled')` | YES | `NULL` | — | Target status |
| `actor_user_id` | `BIGINT UNSIGNED` | YES | `NULL` | `FK → users(id) ON DELETE SET NULL` | Acting user |
| `actor_name` | `VARCHAR(150)` | NO | — | — | Display actor |
| `notes` | `TEXT` | YES | `NULL` | — | Remark |
| `occurred_at` | `DATETIME` | NO | — | — | Event time |

### 6.5 `donor_appointments`

| Column | MySQL Type | Nullable | Default | Keys / Constraints | Notes |
|---|---|---|---|---|---|
| `id` | `BIGINT UNSIGNED` | NO | `AUTO_INCREMENT` | `PRIMARY KEY` | Appointment PK |
| `appointment_code` | `VARCHAR(32)` | NO | — | `UNIQUE (appointment_code)` | e.g., `APT-2041` |
| `donor_id` | `BIGINT UNSIGNED` | NO | — | `FK → donors(id) ON DELETE RESTRICT`, `INDEX (donor_id, status, appointment_date)` | Booking donor |
| `donation_component_id` | `BIGINT UNSIGNED` | NO | — | `FK → blood_components(id) ON DELETE RESTRICT` | Donation type |
| `donation_center_id` | `BIGINT UNSIGNED` | YES | `NULL` | `FK → donation_centers(id) ON DELETE RESTRICT`, `INDEX (donation_center_id, appointment_date, slot_time)` | Exclusive with `donation_camp_id` |
| `donation_camp_id` | `BIGINT UNSIGNED` | YES | `NULL` | `FK → donation_camps(id) ON DELETE RESTRICT`, `INDEX (donation_camp_id, appointment_date, slot_time)` | Exclusive with `donation_center_id` |
| `appointment_date` | `DATE` | NO | — | — | Scheduled date |
| `slot_time` | `TIME` | NO | — | — | Scheduled window (`NOT NULL` to keep composite unique guard valid) |
| `active_slot_guard` | `TINYINT(1)` | YES | `GENERATED ALWAYS AS (CASE WHEN status <> 'Cancelled' THEN 1 ELSE NULL END) STORED` | `UNIQUE (donor_id, appointment_date, slot_time, active_slot_guard)` | Engine lock preventing duplicate active donor slot |
| `status` | `ENUM('Pending','Confirmed','Completed','Cancelled')` | NO | `'Pending'` | `INDEX (status)` | Booking status |
| `notes` | `VARCHAR(500)` | YES | `NULL` | — | Optional notes |
| `prep_summary` | `TEXT` | YES | `NULL` | — | Pre-visit instructions |
| `cancelled_at` | `DATETIME` | YES | `NULL` | — | Cancellation timestamp |
| `cancellation_reason` | `VARCHAR(255)` | YES | `NULL` | — | Optional cancellation note |
| `created_at` | `TIMESTAMP` | YES | `NULL` | — | — |
| `updated_at` | `TIMESTAMP` | YES | `NULL` | — | — |

**Table Check Constraint on `donor_appointments`**:
```sql
CONSTRAINT chk_appointment_single_target CHECK (
    (donation_center_id IS NOT NULL AND donation_camp_id IS NULL)
    OR
    (donation_center_id IS NULL AND donation_camp_id IS NOT NULL)
)
```

### 6.6 `appointment_status_histories`

| Column | MySQL Type | Nullable | Default | Keys / Constraints | Notes |
|---|---|---|---|---|---|
| `id` | `BIGINT UNSIGNED` | NO | `AUTO_INCREMENT` | `PRIMARY KEY` | Audit PK |
| `appointment_id` | `BIGINT UNSIGNED` | NO | — | `FK → donor_appointments(id) ON DELETE RESTRICT`, `INDEX (appointment_id, occurred_at)` | Linked appointment |
| `event_name` | `VARCHAR(120)` | NO | — | — | Status event |
| `previous_status` | `ENUM('Pending','Confirmed','Completed','Cancelled')` | YES | `NULL` | — | Prior status |
| `new_status` | `ENUM('Pending','Confirmed','Completed','Cancelled')` | NO | — | — | Target status |
| `actor_user_id` | `BIGINT UNSIGNED` | YES | `NULL` | `FK → users(id) ON DELETE SET NULL` | Acting user |
| `notes` | `TEXT` | YES | `NULL` | — | Remark |
| `occurred_at` | `DATETIME` | NO | — | — | Timestamp |
