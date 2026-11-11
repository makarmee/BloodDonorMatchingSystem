# BDMS Database Schema Specification

This specification details all **29 tables** (3 existing Laravel baseline auth/session tables + 26 BDMS domain tables) designed for **Laravel 13** and **MySQL 8.0+** (`utf8mb4`, `utf8mb4_unicode_ci`, InnoDB).

- **Dual-Key Strategy**: Every core entity uses a surrogate `BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY` (`id`) for compact B+Tree joins and foreign keys, paired with a `UNIQUE` indexed human-readable business identifier (e.g., `DNR-1041`, `DON-4405`, `REQ-1044`, `CMP-308`, `APT-2041`) matching existing BDMS routes and UI references.
- **Immutability & Audit Policy**: Clinical/ledger tables (`donations`, `screening_records`, `inventory_transactions`, `request_fulfillments`, `*_status_histories`) do **not** use `softDeletes` and prohibit hard deletion once referenced (`ON DELETE RESTRICT`). Account deactivation uses explicit state fields (`is_active`, `account_status`).

---

## Group 1: Users, Sessions & Role-Based Access Control

### 1.1 `users` *(extends existing Laravel migration)*

| Column | MySQL Type | Nullable | Default | Keys / Constraints | Notes |
|---|---|---|---|---|---|
| `id` | `BIGINT UNSIGNED` | NO | `AUTO_INCREMENT` | `PRIMARY KEY` | Internal user ID |
| `name` | `VARCHAR(150)` | NO | — | — | Display name |
| `email` | `VARCHAR(191)` | NO | — | `UNIQUE (email)` | Login identifier |
| `email_verified_at` | `TIMESTAMP` | YES | `NULL` | — | Email verification timestamp |
| `password` | `VARCHAR(255)` | NO | — | — | Bcrypt/Argon2 hash |
| `is_active` | `TINYINT(1)` | NO | `1` | `INDEX` | Enables instant account suspension |
| `last_login_at` | `TIMESTAMP` | YES | `NULL` | — | Audit sign-in timestamp |
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
| `name` | `VARCHAR(120)` | NO | — | — | Human-readable action |
| `slug` | `VARCHAR(100)` | NO | — | `UNIQUE (slug)` | e.g., `donors.view_medical`, `inventory.adjust` |
| `module` | `VARCHAR(64)` | NO | — | `INDEX (module)` | e.g., `inventory`, `donors`, `screening`, `requests` |
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
| `user_id` | `BIGINT UNSIGNED` | YES | `NULL` | `UNIQUE (user_id)`, `FK → users(id) ON DELETE SET NULL` | Optional portal login link |
| `full_name` | `VARCHAR(150)` | NO | — | `INDEX (full_name)` | Supports directory search |
| `email` | `VARCHAR(191)` | NO | — | `UNIQUE (email)` | Donor email |
| `phone` | `VARCHAR(32)` | YES | `NULL` | `INDEX (phone)` | Nullable for incomplete `/register` self-signups; required at clinical intake |
| `gender` | `ENUM('Female','Male','Other')` | YES | `NULL` | — | Required in staff form |
| `date_of_birth` | `DATE` | YES | `NULL` | — | Nullable only until self-registered donor completes profile |
| `blood_group` | `ENUM('A+','A-','B+','B-','AB+','AB-','O+','O-')` | YES | `NULL` | `INDEX (blood_group)` | Confirmed at intake / optional on `/register` |
| `address` | `TEXT` | YES | `NULL` | — | Mailing address |
| `emergency_contact` | `VARCHAR(150)` | YES | `NULL` | — | Emergency contact name & phone |
| `availability_status` | `ENUM('Available','Temporarily Unavailable','Inactive')` | NO | `'Available'` | — | Donor outreach preference |
| `eligibility_status` | `ENUM('Eligible','Not Eligible','Pending Review','Unknown')` | NO | `'Pending Review'` | `INDEX (eligibility_status)` | Synchronized with current eligibility record |
| `account_status` | `ENUM('Active','Inactive')` | NO | `'Active'` | `INDEX (account_status)` | Administrative account state |
| `last_donation_date` | `DATE` | YES | `NULL` | — | Derived/cached date of latest `Completed` donation |
| `next_eligible_date` | `DATE` | YES | `NULL` | — | Explicit deferral/cooldown date from medical officer |
| `total_completed_donations` | `INT UNSIGNED` | NO | `0` | `CHECK (total_completed_donations >= 0)` | Completed visit count |
| `profile_completed` | `TINYINT(1)` | NO | `0` | — | Indicates full intake contact/DOB completion |
| `registered_at` | `DATE` | NO | — | `INDEX (registered_at)` | Supports `"New Donors This Month"` reporting |
| `created_at` | `TIMESTAMP` | YES | `NULL` | — | — |
| `updated_at` | `TIMESTAMP` | YES | `NULL` | — | — |

### 2.2 `donor_medical_profiles`

| Column | MySQL Type | Nullable | Default | Keys / Constraints | Notes |
|---|---|---|---|---|---|
| `id` | `BIGINT UNSIGNED` | NO | `AUTO_INCREMENT` | `PRIMARY KEY` | Separated sensitive table |
| `donor_id` | `BIGINT UNSIGNED` | NO | — | `UNIQUE (donor_id)`, `FK → donors(id) ON DELETE RESTRICT` | Strict 1:1 clinical extension |
| `medical_notes` | `TEXT` | YES | `NULL` | — | Confidential clinician notes (restricted from public/donor views) |
| `last_hemoglobin_g_dl` | `DECIMAL(4,2)` | YES | `NULL` | `CHECK (last_hemoglobin_g_dl IS NULL OR last_hemoglobin_g_dl > 0)` | e.g., `13.90` g/dL |
| `weight_kg` | `DECIMAL(5,2)` | YES | `NULL` | `CHECK (weight_kg IS NULL OR weight_kg > 0)` | Screening weight check |
| `deferral_notes` | `TEXT` | YES | `NULL` | — | Specific clinical deferral explanation |
| `updated_by_user_id` | `BIGINT UNSIGNED` | YES | `NULL` | `FK → users(id) ON DELETE SET NULL` | Last clinician modifying notes |
| `created_at` | `TIMESTAMP` | YES | `NULL` | — | — |
| `updated_at` | `TIMESTAMP` | YES | `NULL` | — | — |

### 2.3 `donor_eligibility_records`

| Column | MySQL Type | Nullable | Default | Keys / Constraints | Notes |
|---|---|---|---|---|---|
| `id` | `BIGINT UNSIGNED` | NO | `AUTO_INCREMENT` | `PRIMARY KEY` | Eligibility audit event |
| `donor_id` | `BIGINT UNSIGNED` | NO | — | `FK → donors(id) ON DELETE RESTRICT`, `INDEX (donor_id, is_current)` | Donor reference |
| `eligibility_status` | `ENUM('Eligible','Not Eligible','Pending Review','Unknown')` | NO | — | — | Reviewed clearance outcome |
| `reason` | `TEXT` | NO | — | — | Explicit reason text (`eligibilityNote`) |
| `clearance_source` | `VARCHAR(191)` | NO | — | — | e.g., `Verified intake screening log` |
| `reviewed_at` | `DATE` | YES | `NULL` | — | `lastReviewedDate` |
| `next_eligible_date` | `DATE` | YES | `NULL` | — | Deferral lift date if temporary |
| `reviewed_by_user_id` | `BIGINT UNSIGNED` | YES | `NULL` | `FK → users(id) ON DELETE SET NULL` | Authorizing officer |
| `is_current` | `TINYINT(1)` | NO | `1` | — | Latest active status marker |
| `created_at` | `TIMESTAMP` | YES | `NULL` | — | — |
| `updated_at` | `TIMESTAMP` | YES | `NULL` | — | — |

---

## Group 3: Blood Components, Donations & Laboratory Screening

### 3.1 `blood_components`

| Column | MySQL Type | Nullable | Default | Keys / Constraints | Notes |
|---|---|---|---|---|---|
| `id` | `BIGINT UNSIGNED` | NO | `AUTO_INCREMENT` | `PRIMARY KEY` | Component lookup PK |
| `code` | `VARCHAR(24)` | NO | — | `UNIQUE (code)` | `WB`, `PRBC`, `PLT`, `PLS`, `DRBC` |
| `name` | `VARCHAR(100)` | NO | — | `UNIQUE (name)` | `Whole Blood`, `Packed RBC`, `Platelets`, `Plasma`, `Double Red Cells` |
| `default_volume_ml` | `INT UNSIGNED` | NO | — | `CHECK (default_volume_ml > 0)` | Standard unit volume (e.g., `450`, `250`, `600`) |
| `shelf_life_days` | `SMALLINT UNSIGNED` | NO | — | `CHECK (shelf_life_days > 0)` | Drives `expires_at` & 7-day FIFO expiry alerts |
| `storage_requirements` | `VARCHAR(191)` | YES | `NULL` | — | Cold-chain handling note |
| `is_active` | `TINYINT(1)` | NO | `1` | — | — |
| `created_at` | `TIMESTAMP` | YES | `NULL` | — | — |
| `updated_at` | `TIMESTAMP` | YES | `NULL` | — | — |

### 3.2 `donations`

| Column | MySQL Type | Nullable | Default | Keys / Constraints | Notes |
|---|---|---|---|---|---|
| `id` | `BIGINT UNSIGNED` | NO | `AUTO_INCREMENT` | `PRIMARY KEY` | Surrogate PK |
| `donation_code` | `VARCHAR(32)` | NO | — | `UNIQUE (donation_code)` | e.g., `DON-4405` |
| `donor_id` | `BIGINT UNSIGNED` | NO | — | `FK → donors(id) ON DELETE RESTRICT`, `INDEX (donor_id, donation_date)` | Donor reference |
| `appointment_id` | `BIGINT UNSIGNED` | YES | `NULL` | `UNIQUE (appointment_id)`, `FK → donor_appointments(id) ON DELETE SET NULL` | Optional booking origin |
| `camp_id` | `BIGINT UNSIGNED` | YES | `NULL` | `FK → donation_camps(id) ON DELETE RESTRICT`, `INDEX (camp_id)` | Nullable camp location |
| `center_id` | `BIGINT UNSIGNED` | YES | `NULL` | `FK → donation_centers(id) ON DELETE RESTRICT`, `INDEX (center_id)` | Nullable center location |
| `collection_location_name` | `VARCHAR(191)` | NO | — | — | Display location snapshot (supports legacy/ad-hoc drive names) |
| `recorded_by_user_id` | `BIGINT UNSIGNED` | YES | `NULL` | `FK → users(id) ON DELETE SET NULL` | Staff user recording collection |
| `staff_member_name` | `VARCHAR(150)` | NO | — | — | Phlebotomist / clinician name |
| `blood_group` | `ENUM('A+','A-','B+','B-','AB+','AB-','O+','O-')` | NO | — | `INDEX (blood_group)` | Donor verified blood group |
| `blood_component_id` | `BIGINT UNSIGNED` | NO | — | `FK → blood_components(id) ON DELETE RESTRICT` | Donation component type |
| `donation_date` | `DATE` | NO | — | `INDEX (donation_date)` | Collection date |
| `collected_at` | `DATETIME` | YES | `NULL` | — | Exact draw timestamp |
| `quantity_ml` | `SMALLINT UNSIGNED` | NO | — | `CHECK (quantity_ml BETWEEN 150 AND 800)` | Matches `donations.js` volume bounds |
| `collection_status` | `ENUM('Scheduled','Completed','Cancelled')` | NO | — | `INDEX (collection_status)` | Collection session state |
| `screening_status` | `ENUM('Pending','Passed','Failed')` | NO | `'Pending'` | `INDEX (screening_status)` | Explicit overall lab gate state |
| `collection_notes` | `TEXT` | YES | `NULL` | — | e.g., bag seal `#CB-918` |
| `screening_notes` | `TEXT` | YES | `NULL` | — | Lab summary notes |
| `released_to_inventory_at` | `DATETIME` | YES | `NULL` | `INDEX (released_to_inventory_at)` | Set only when cleared for stock release |
| `approved_by_user_id` | `BIGINT UNSIGNED` | YES | `NULL` | `FK → users(id) ON DELETE SET NULL` | Medical/lab officer authorizing release |
| `created_at` | `TIMESTAMP` | YES | `NULL` | — | — |
| `updated_at` | `TIMESTAMP` | YES | `NULL` | — | — |

### 3.3 `screening_test_types`

| Column | MySQL Type | Nullable | Default | Keys / Constraints | Notes |
|---|---|---|---|---|---|
| `id` | `BIGINT UNSIGNED` | NO | `AUTO_INCREMENT` | `PRIMARY KEY` | Panel ID |
| `code` | `VARCHAR(32)` | NO | — | `UNIQUE (code)` | `HIV`, `HBV`, `HCV`, `SYPH`, `COMPAT` |
| `name` | `VARCHAR(100)` | NO | — | `UNIQUE (name)` | Matches `ScreeningController::testTypeOptions()` |
| `is_mandatory_for_release` | `TINYINT(1)` | NO | `1` | — | Whether test must be `Passed` prior to unit release |
| `description` | `VARCHAR(255)` | YES | `NULL` | — | Panel scope |
| `is_active` | `TINYINT(1)` | NO | `1` | — | — |
| `created_at` | `TIMESTAMP` | YES | `NULL` | — | — |
| `updated_at` | `TIMESTAMP` | YES | `NULL` | — | — |

### 3.4 `screening_records`

| Column | MySQL Type | Nullable | Default | Keys / Constraints | Notes |
|---|---|---|---|---|---|
| `id` | `BIGINT UNSIGNED` | NO | `AUTO_INCREMENT` | `PRIMARY KEY` | Surrogate PK |
| `screening_code` | `VARCHAR(32)` | NO | — | `UNIQUE (screening_code)` | e.g., `SCR-7105` |
| `donation_id` | `BIGINT UNSIGNED` | NO | — | `FK → donations(id) ON DELETE RESTRICT`, `INDEX (donation_id, test_type_id)` | Multiple tests per donation allowed |
| `test_type_id` | `BIGINT UNSIGNED` | NO | — | `FK → screening_test_types(id) ON DELETE RESTRICT`, `INDEX (test_type_id)` | Panel type reference |
| `test_date` | `DATE` | YES | `NULL` | `INDEX (test_date)` | Nullable while `Pending` |
| `screening_result` | `ENUM('Pending','Passed','Failed')` | NO | `'Pending'` | `INDEX (screening_result)` | Explicit manual result |
| `is_confirmatory` | `TINYINT(1)` | NO | `0` | — | Distinguishes initial vs confirmatory repeat panel |
| `performed_by_user_id` | `BIGINT UNSIGNED` | YES | `NULL` | `FK → users(id) ON DELETE SET NULL` | Lab technician/medical officer |
| `lab_staff_name` | `VARCHAR(150)` | NO | — | — | Display actor (e.g., `Dr. Priya Nair (Serology)`) |
| `assay_method` | `VARCHAR(191)` | YES | `NULL` | — | Analyzer/method name |
| `specimen_tube_code` | `VARCHAR(64)` | YES | `NULL` | `INDEX (specimen_tube_code)` | e.g., `EDTA-K2 #T-9941A` |
| `test_notes` | `TEXT` | YES | `NULL` | — | Batch & lab notes |
| `collected_at` | `DATETIME` | YES | `NULL` | — | Specimen collection timestamp |
| `logged_at` | `DATETIME` | YES | `NULL` | — | Lab accession timestamp |
| `result_recorded_at` | `DATETIME` | YES | `NULL` | — | Final verification timestamp |
| `created_at` | `TIMESTAMP` | YES | `NULL` | — | — |
| `updated_at` | `TIMESTAMP` | YES | `NULL` | — | — |

---

## Group 4: Blood Inventory, Individual Blood Units & Transaction Ledger

### 4.1 `inventory_stocks`

| Column | MySQL Type | Nullable | Default | Keys / Constraints | Notes |
|---|---|---|---|---|---|
| `id` | `BIGINT UNSIGNED` | NO | `AUTO_INCREMENT` | `PRIMARY KEY` | Pessimistic lock anchor per stock bucket |
| `blood_group` | `ENUM('A+','A-','B+','B-','AB+','AB-','O+','O-')` | NO | — | `UNIQUE (blood_group, blood_component_id)` | ABO/Rh type |
| `blood_component_id` | `BIGINT UNSIGNED` | NO | — | `FK → blood_components(id) ON DELETE RESTRICT` | Component classification |
| `available_units` | `INT UNSIGNED` | NO | `0` | `CHECK (available_units >= 0)` | Ready for allocation/crossmatch |
| `reserved_units` | `INT UNSIGNED` | NO | `0` | `CHECK (reserved_units >= 0)` | Held for approved requests |
| `minimum_level` | `INT UNSIGNED` | NO | `20` | `CHECK (minimum_level >= 1)` | Drives `low` (`<= min`) & `critical` (`<= floor(min*0.6)`) |
| `target_capacity` | `INT UNSIGNED` | NO | `100` | `CHECK (target_capacity >= 1)` | Visual stock progress bar denominator |
| `last_adjusted_at` | `DATETIME` | YES | `NULL` | — | Latest movement timestamp |
| `created_at` | `TIMESTAMP` | YES | `NULL` | — | — |
| `updated_at` | `TIMESTAMP` | YES | `NULL` | — | — |

### 4.2 `blood_units`

| Column | MySQL Type | Nullable | Default | Keys / Constraints | Notes |
|---|---|---|---|---|---|
| `id` | `BIGINT UNSIGNED` | NO | `AUTO_INCREMENT` | `PRIMARY KEY` | Individual unit/bag PK |
| `unit_code` | `VARCHAR(40)` | NO | — | `UNIQUE (unit_code)` | Traceable bag barcode |
| `donation_id` | `BIGINT UNSIGNED` | NO | — | `FK → donations(id) ON DELETE RESTRICT`, `INDEX (donation_id)` | Origin donation provenance |
| `blood_group` | `ENUM('A+','A-','B+','B-','AB+','AB-','O+','O-')` | NO | — | `INDEX (blood_group, blood_component_id, unit_status, expires_at)` | Optimizes FIFO unit locking |
| `blood_component_id` | `BIGINT UNSIGNED` | NO | — | `FK → blood_components(id) ON DELETE RESTRICT` | Component bag type |
| `volume_ml` | `SMALLINT UNSIGNED` | NO | — | `CHECK (volume_ml > 0)` | Unit bag volume |
| `storage_location` | `VARCHAR(120)` | YES | `NULL` | — | Cold-chain rack / refrigerator bay |
| `unit_status` | `ENUM('Quarantined','Available','Reserved','Dispatched','Transfused','Expired','Discarded')` | NO | `'Quarantined'` | `INDEX (unit_status, expires_at)` | Only `'Available'` eligible for allocation |
| `collected_at` | `DATETIME` | NO | — | — | Collection time |
| `expires_at` | `DATETIME` | NO | — | — | Expiry boundary for FIFO & 7d alerts |
| `released_at` | `DATETIME` | YES | `NULL` | — | Screened & cleared timestamp |
| `released_by_user_id` | `BIGINT UNSIGNED` | YES | `NULL` | `FK → users(id) ON DELETE SET NULL` | Officer releasing from quarantine |
| `created_at` | `TIMESTAMP` | YES | `NULL` | — | — |
| `updated_at` | `TIMESTAMP` | YES | `NULL` | — | — |

### 4.3 `inventory_transactions`

| Column | MySQL Type | Nullable | Default | Keys / Constraints | Notes |
|---|---|---|---|---|---|
| `id` | `BIGINT UNSIGNED` | NO | `AUTO_INCREMENT` | `PRIMARY KEY` | Append-only audit sequence |
| `transaction_code` | `VARCHAR(36)` | NO | — | `UNIQUE (transaction_code)` | e.g., `TXN-101` |
| `inventory_stock_id` | `BIGINT UNSIGNED` | NO | — | `FK → inventory_stocks(id) ON DELETE RESTRICT`, `INDEX (inventory_stock_id, occurred_at)` | Affected stock bucket |
| `blood_unit_id` | `BIGINT UNSIGNED` | YES | `NULL` | `FK → blood_units(id) ON DELETE RESTRICT`, `INDEX (blood_unit_id)` | Specific unit bag if applicable |
| `operation_type` | `ENUM('Stock In','Reserve','Release Reservation','Dispatch','Removed','Expired','Discarded','Manual Adjustment')` | NO | — | `INDEX (operation_type)` | Supports both `InventoryController` operations and lifecycle flows |
| `units_delta` | `INT` | NO | — | `CHECK (units_delta <> 0)` | Signed count (`+N` or `-N`) |
| `available_before` | `INT UNSIGNED` | NO | — | — | Snapshot before change |
| `available_after` | `INT UNSIGNED` | NO | — | `CHECK (available_after >= 0)` | Snapshot after change |
| `reserved_before` | `INT UNSIGNED` | NO | — | — | Reserved snapshot before change |
| `reserved_after` | `INT UNSIGNED` | NO | — | `CHECK (reserved_after >= 0)` | Reserved snapshot after change |
| `reference_type` | `VARCHAR(64)` | YES | `NULL` | `INDEX (reference_type, reference_id)` | `donation`, `blood_request`, `fulfillment`, `manual` |
| `reference_id` | `BIGINT UNSIGNED` | YES | `NULL` | — | Related domain PK |
| `performed_by_user_id` | `BIGINT UNSIGNED` | YES | `NULL` | `FK → users(id) ON DELETE SET NULL` | Acting staff user |
| `actor_name` | `VARCHAR(150)` | NO | — | — | Actor label at event time |
| `description` | `TEXT` | NO | — | — | Required audit justification (e.g., `#DB-2611`, `#REQ-1042`) |
| `occurred_at` | `DATETIME` | NO | — | `INDEX (occurred_at)` | Audit timestamp |
| `created_at` | `TIMESTAMP` | YES | `NULL` | — | Immutable (`updated_at` intentionally omitted) |

---

## Group 5: Hospitals, Blood Requests, Allocations & Fulfillments

### 5.1 `hospitals`

| Column | MySQL Type | Nullable | Default | Keys / Constraints | Notes |
|---|---|---|---|---|---|
| `id` | `BIGINT UNSIGNED` | NO | `AUTO_INCREMENT` | `PRIMARY KEY` | Normalized hospital PK |
| `hospital_code` | `VARCHAR(32)` | NO | — | `UNIQUE (hospital_code)` | Facility license/code (e.g., `HSP-2049`) |
| `name` | `VARCHAR(191)` | NO | — | `INDEX (name)` | e.g., `Metro General Trauma Center` |
| `address` | `TEXT` | YES | `NULL` | — | Street address |
| `city` | `VARCHAR(100)` | YES | `NULL` | `INDEX (city)` | City/district |
| `primary_contact_name` | `VARCHAR(150)` | YES | `NULL` | — | Default coordinator |
| `primary_phone` | `VARCHAR(32)` | YES | `NULL` | — | Dispatch phone |
| `primary_email` | `VARCHAR(191)` | YES | `NULL` | — | Transfusion lab email |
| `verification_status` | `ENUM('Pending','Verified','Suspended')` | NO | `'Pending'` | `INDEX (verification_status)` | Protects against unverified hospital accounts |
| `is_active` | `TINYINT(1)` | NO | `1` | — | Operational toggle |
| `created_at` | `TIMESTAMP` | YES | `NULL` | — | — |
| `updated_at` | `TIMESTAMP` | YES | `NULL` | — | — |

### 5.2 `hospital_users`

| Column | MySQL Type | Nullable | Default | Keys / Constraints | Notes |
|---|---|---|---|---|---|
| `id` | `BIGINT UNSIGNED` | NO | `AUTO_INCREMENT` | `PRIMARY KEY` | Liaison profile PK |
| `hospital_id` | `BIGINT UNSIGNED` | NO | — | `FK → hospitals(id) ON DELETE RESTRICT`, `INDEX (hospital_id)` | Linked hospital facility |
| `user_id` | `BIGINT UNSIGNED` | NO | — | `UNIQUE (user_id)`, `FK → users(id) ON DELETE CASCADE` | Login user |
| `contact_name` | `VARCHAR(150)` | NO | — | — | Clinician / requester name |
| `department` | `VARCHAR(150)` | YES | `NULL` | — | e.g., `ICU Trauma Unit — Ward 3B` |
| `job_title` | `VARCHAR(120)` | YES | `NULL` | — | Coordinator / Physician |
| `work_phone` | `VARCHAR(32)` | YES | `NULL` | — | Direct desk phone |
| `work_email` | `VARCHAR(191)` | YES | `NULL` | — | Facility email |
| `is_primary_contact` | `TINYINT(1)` | NO | `0` | — | Designates main facility contact |
| `is_active` | `TINYINT(1)` | NO | `1` | — | — |
| `created_at` | `TIMESTAMP` | YES | `NULL` | — | — |
| `updated_at` | `TIMESTAMP` | YES | `NULL` | — | — |

### 5.3 `blood_requests`

| Column | MySQL Type | Nullable | Default | Keys / Constraints | Notes |
|---|---|---|---|---|---|
| `id` | `BIGINT UNSIGNED` | NO | `AUTO_INCREMENT` | `PRIMARY KEY` | Surrogate PK |
| `request_code` | `VARCHAR(32)` | NO | — | `UNIQUE (request_code)` | e.g., `REQ-1044` |
| `hospital_id` | `BIGINT UNSIGNED` | NO | — | `FK → hospitals(id) ON DELETE RESTRICT`, `INDEX (hospital_id, status)` | Requesting hospital |
| `requested_by_user_id` | `BIGINT UNSIGNED` | YES | `NULL` | `FK → users(id) ON DELETE SET NULL` | Requester account if portal-submitted |
| `department_or_ward` | `VARCHAR(150)` | YES | `NULL` | — | Specific ward/theater note |
| `contact_person_snapshot` | `VARCHAR(150)` | NO | — | — | Point-in-time contact for order |
| `contact_phone_snapshot` | `VARCHAR(32)` | NO | — | — | Point-in-time contact phone |
| `contact_email_snapshot` | `VARCHAR(191)` | NO | — | — | Point-in-time contact email |
| `blood_group` | `ENUM('A+','A-','B+','B-','AB+','AB-','O+','O-')` | NO | — | `INDEX (blood_group, priority, status)` | Requested blood group |
| `blood_component_id` | `BIGINT UNSIGNED` | YES | `NULL` | `FK → blood_components(id) ON DELETE RESTRICT` | Nullable if request doesn't lock a specific component |
| `requested_units` | `INT UNSIGNED` | NO | — | `CHECK (requested_units >= 1)` | Total ordered units |
| `reserved_units` | `INT UNSIGNED` | NO | `0` | `CHECK (reserved_units >= 0)` | Currently held in `Reserved` allocations |
| `fulfilled_units` | `INT UNSIGNED` | NO | `0` | `CHECK (fulfilled_units >= 0 AND fulfilled_units <= requested_units AND fulfilled_units + reserved_units <= requested_units)` | Prevents over-allocation and over-fulfillment |
| `priority` | `ENUM('Low','Normal','High','Critical')` | NO | — | `INDEX (priority)` | Triage level |
| `status` | `ENUM('Pending','Approved','Rejected','Partially Fulfilled','Fulfilled','Cancelled')` | NO | `'Pending'` | `INDEX (status, request_date)` | Request lifecycle status |
| `request_date` | `DATE` | NO | — | — | Submission date |
| `required_date` | `DATE` | NO | — | `INDEX (required_date)` | Target fulfillment deadline |
| `request_reason` | `TEXT` | NO | — | — | Clinical justification |
| `additional_notes` | `TEXT` | YES | `NULL` | — | Special handling (e.g., CMV-negative, leukoreduced) |
| `reviewed_by_user_id` | `BIGINT UNSIGNED` | YES | `NULL` | `FK → users(id) ON DELETE SET NULL` | Staff approving/rejecting |
| `reviewed_at` | `DATETIME` | YES | `NULL` | — | Review timestamp |
| `rejection_reason` | `TEXT` | YES | `NULL` | — | Required when `status = 'Rejected'` |
| `cancelled_by_user_id` | `BIGINT UNSIGNED` | YES | `NULL` | `FK → users(id) ON DELETE SET NULL` | Actor closing request |
| `cancelled_at` | `DATETIME` | YES | `NULL` | — | Cancellation timestamp |
| `cancellation_reason` | `TEXT` | YES | `NULL` | — | Cancellation explanation |
| `created_at` | `TIMESTAMP` | YES | `NULL` | — | — |
| `updated_at` | `TIMESTAMP` | YES | `NULL` | — | — |

### 5.4 `blood_request_status_histories`

| Column | MySQL Type | Nullable | Default | Keys / Constraints | Notes |
|---|---|---|---|---|---|
| `id` | `BIGINT UNSIGNED` | NO | `AUTO_INCREMENT` | `PRIMARY KEY` | Timeline PK |
| `blood_request_id` | `BIGINT UNSIGNED` | NO | — | `FK → blood_requests(id) ON DELETE RESTRICT`, `INDEX (blood_request_id, occurred_at)` | Target request |
| `event_name` | `VARCHAR(120)` | NO | — | — | e.g., `Request Approved`, `Partial Dispatch Recorded` |
| `previous_status` | `ENUM('Pending','Approved','Rejected','Partially Fulfilled','Fulfilled','Cancelled')` | YES | `NULL` | — | `NULL` on initial creation |
| `new_status` | `ENUM('Pending','Approved','Rejected','Partially Fulfilled','Fulfilled','Cancelled')` | NO | — | — | Resulting status |
| `units_affected` | `INT UNSIGNED` | NO | `0` | — | Units dispatched/reserved in event |
| `actor_user_id` | `BIGINT UNSIGNED` | YES | `NULL` | `FK → users(id) ON DELETE SET NULL` | Acting user |
| `actor_name` | `VARCHAR(150)` | NO | — | — | Display actor snapshot |
| `notes` | `TEXT` | YES | `NULL` | — | Audit remark |
| `occurred_at` | `DATETIME` | NO | — | — | Event time |

### 5.5 `request_fulfillments`

| Column | MySQL Type | Nullable | Default | Keys / Constraints | Notes |
|---|---|---|---|---|---|
| `id` | `BIGINT UNSIGNED` | NO | `AUTO_INCREMENT` | `PRIMARY KEY` | Physical dispatch batch PK |
| `fulfillment_code` | `VARCHAR(32)` | NO | — | `UNIQUE (fulfillment_code)` | e.g., `FUL-5001` |
| `blood_request_id` | `BIGINT UNSIGNED` | NO | — | `FK → blood_requests(id) ON DELETE RESTRICT`, `INDEX (blood_request_id, dispatch_date)` | Target request |
| `dispatched_by_user_id` | `BIGINT UNSIGNED` | YES | `NULL` | `FK → users(id) ON DELETE SET NULL` | Blood bank dispatcher |
| `dispatched_blood_group` | `ENUM('A+','A-','B+','B-','AB+','AB-','O+','O-')` | NO | — | — | Allows cross-compatible emergency substitution (e.g., `O-`) |
| `blood_component_id` | `BIGINT UNSIGNED` | NO | — | `FK → blood_components(id) ON DELETE RESTRICT` | Component dispatched |
| `units_dispatched` | `INT UNSIGNED` | NO | — | `CHECK (units_dispatched >= 1)` | Number of bags in batch |
| `dispatch_date` | `DATE` | NO | — | — | Matches dispatch form date |
| `dispatched_at` | `DATETIME` | NO | — | — | Cold-chain departure time |
| `courier_reference` | `VARCHAR(64)` | YES | `NULL` | — | e.g., `#TR-208` |
| `delivery_status` | `ENUM('Dispatched','In Transit','Delivered','Returned')` | NO | `'Dispatched'` | `INDEX (delivery_status)` | Logistics state |
| `delivered_at` | `DATETIME` | YES | `NULL` | — | Hospital sign-off timestamp |
| `received_by_name` | `VARCHAR(150)` | YES | `NULL` | — | Hospital receiver |
| `notes` | `TEXT` | YES | `NULL` | — | Dispatch notes |
| `created_at` | `TIMESTAMP` | YES | `NULL` | — | — |
| `updated_at` | `TIMESTAMP` | YES | `NULL` | — | — |

### 5.6 `inventory_allocations`

| Column | MySQL Type | Nullable | Default | Keys / Constraints | Notes |
|---|---|---|---|---|---|
| `id` | `BIGINT UNSIGNED` | NO | `AUTO_INCREMENT` | `PRIMARY KEY` | Allocation / hold PK |
| `allocation_code` | `VARCHAR(32)` | NO | — | `UNIQUE (allocation_code)` | e.g., `ALC-3001` |
| `blood_request_id` | `BIGINT UNSIGNED` | NO | — | `FK → blood_requests(id) ON DELETE RESTRICT`, `INDEX (blood_request_id, allocation_status)` | Linked hospital order |
| `inventory_stock_id` | `BIGINT UNSIGNED` | NO | — | `FK → inventory_stocks(id) ON DELETE RESTRICT`, `INDEX (inventory_stock_id)` | Source stock bucket |
| `blood_unit_id` | `BIGINT UNSIGNED` | NO | — | `FK → blood_units(id) ON DELETE RESTRICT`, `INDEX (blood_unit_id, allocation_status)` | Specific blood bag allocated |
| `fulfillment_id` | `BIGINT UNSIGNED` | YES | `NULL` | `FK → request_fulfillments(id) ON DELETE RESTRICT`, `INDEX (fulfillment_id)` | Populated once dispatched in a fulfillment batch |
| `units_allocated` | `INT UNSIGNED` | NO | `1` | `CHECK (units_allocated = 1)` | 1 row = 1 specific reserved/dispatched `blood_unit` bag |
| `allocation_status` | `ENUM('Reserved','Dispatched','Released','Cancelled')` | NO | `'Reserved'` | `INDEX (allocation_status)` | Tracks hold vs dispatch vs release |
| `active_unit_guard` | `TINYINT(1)` | YES | `GENERATED ALWAYS AS (CASE WHEN allocation_status IN ('Reserved','Dispatched') THEN 1 ELSE NULL END) STORED` | `UNIQUE (blood_unit_id, active_unit_guard)` | DB guard preventing one blood bag from being simultaneously allocated/dispatched to two requests |
| `allocated_by_user_id` | `BIGINT UNSIGNED` | YES | `NULL` | `FK → users(id) ON DELETE SET NULL` | Assigning staff member |
| `allocated_at` | `DATETIME` | NO | — | — | Hold creation timestamp |
| `released_at` | `DATETIME` | YES | `NULL` | — | When reservation returned to available stock |
| `dispatched_at` | `DATETIME` | YES | `NULL` | — | When physically transferred out |
| `notes` | `TEXT` | YES | `NULL` | — | Crossmatch / hold remarks |
| `created_at` | `TIMESTAMP` | YES | `NULL` | — | — |
| `updated_at` | `TIMESTAMP` | YES | `NULL` | — | — |

---

## Group 6: Donation Centers, Camps, Registrations & Appointments

### 6.1 `donation_centers`

| Column | MySQL Type | Nullable | Default | Keys / Constraints | Notes |
|---|---|---|---|---|---|
| `id` | `BIGINT UNSIGNED` | NO | `AUTO_INCREMENT` | `PRIMARY KEY` | Center PK |
| `center_code` | `VARCHAR(32)` | NO | — | `UNIQUE (center_code)` | e.g., `CTR-CENTRAL`, `CTR-WEST`, `CTR-SOUTH` |
| `name` | `VARCHAR(191)` | NO | — | — | Fixed center name |
| `address` | `VARCHAR(255)` | NO | — | — | Street address |
| `city` | `VARCHAR(100)` | NO | — | `INDEX (city)` | District/city |
| `operating_hours` | `VARCHAR(100)` | NO | — | — | e.g., `08:00 AM – 05:00 PM` |
| `default_slot_capacity` | `SMALLINT UNSIGNED` | NO | `3` | `CHECK (default_slot_capacity >= 1)` | Default concurrent chairs per time slot (`sampleTimeSlots()`) |
| `is_active` | `TINYINT(1)` | NO | `1` | — | Active center toggle |
| `created_at` | `TIMESTAMP` | YES | `NULL` | — | — |
| `updated_at` | `TIMESTAMP` | YES | `NULL` | — | — |

### 6.2 `donation_camps`

| Column | MySQL Type | Nullable | Default | Keys / Constraints | Notes |
|---|---|---|---|---|---|
| `id` | `BIGINT UNSIGNED` | NO | `AUTO_INCREMENT` | `PRIMARY KEY` | Surrogate PK |
| `camp_code` | `VARCHAR(32)` | NO | — | `UNIQUE (camp_code)` | e.g., `CMP-308` |
| `name` | `VARCHAR(191)` | NO | — | `INDEX (name)` | Public drive title |
| `description` | `TEXT` | NO | — | — | Public summary |
| `organizer_name` | `VARCHAR(150)` | NO | — | — | Staff/community lead (withheld on public API/views) |
| `organizer_contact` | `VARCHAR(32)` | NO | — | — | Staff contact phone (restricted) |
| `organizer_email` | `VARCHAR(191)` | NO | — | — | Staff contact email (restricted) |
| `created_by_user_id` | `BIGINT UNSIGNED` | YES | `NULL` | `FK → users(id) ON DELETE SET NULL` | Creator user |
| `venue_name` | `VARCHAR(191)` | NO | — | — | Public venue title |
| `full_address` | `VARCHAR(255)` | NO | — | — | Street address |
| `city` | `VARCHAR(100)` | NO | — | `INDEX (city)` | Filterable city/district |
| `location_notes` | `TEXT` | YES | `NULL` | — | Parking/entrance guidance |
| `start_date` | `DATE` | NO | — | `INDEX (start_date, status)` | Drive start date |
| `end_date` | `DATE` | NO | — | `CHECK (end_date >= start_date)` | Must be on/after start date |
| `start_time` | `TIME` | NO | — | — | Daily opening time |
| `end_time` | `TIME` | NO | — | `CHECK (end_date > start_date OR end_time > start_time)` | End after start when single-day |
| `capacity` | `INT UNSIGNED` | NO | — | `CHECK (capacity >= 1)` | Maximum active donor capacity |
| `registration_deadline` | `DATE` | NO | — | `CHECK (registration_deadline <= start_date)` | Cannot close after camp starts |
| `allow_walk_in` | `TINYINT(1)` | NO | `1` | — | Controls ongoing drive intake & public badge |
| `donor_requirements` | `TEXT` | NO | — | — | Intake readiness requirements |
| `additional_notes` | `TEXT` | YES | `NULL` | — | Extra operational notes |
| `status` | `ENUM('Draft','Upcoming','Ongoing','Completed','Cancelled')` | NO | `'Draft'` | `INDEX (status)` | Public views strictly filter `Upcoming`/`Ongoing` |
| `created_at` | `TIMESTAMP` | YES | `NULL` | — | — |
| `updated_at` | `TIMESTAMP` | YES | `NULL` | — | — |

### 6.3 `camp_registrations`

| Column | MySQL Type | Nullable | Default | Keys / Constraints | Notes |
|---|---|---|---|---|---|
| `id` | `BIGINT UNSIGNED` | NO | `AUTO_INCREMENT` | `PRIMARY KEY` | Registration PK |
| `registration_code` | `VARCHAR(32)` | NO | — | `UNIQUE (registration_code)` | e.g., `REG-801` |
| `camp_id` | `BIGINT UNSIGNED` | NO | — | `FK → donation_camps(id) ON DELETE RESTRICT`, `UNIQUE (camp_id, donor_id)`, `INDEX (camp_id, attendance_status)` | Prevents duplicate donor registration per camp |
| `donor_id` | `BIGINT UNSIGNED` | NO | — | `FK → donors(id) ON DELETE RESTRICT`, `INDEX (donor_id)` | Registered donor |
| `appointment_id` | `BIGINT UNSIGNED` | YES | `NULL` | `UNIQUE (appointment_id)`, `FK → donor_appointments(id) ON DELETE SET NULL` | Linked portal booking (`APT-2041` ↔ `REG-801`) |
| `registered_by_user_id` | `BIGINT UNSIGNED` | YES | `NULL` | `FK → users(id) ON DELETE SET NULL` | Staff or donor self-service |
| `registration_date` | `DATE` | NO | — | — | Registration log date |
| `is_walk_in` | `TINYINT(1)` | NO | `0` | — | Walk-in check-in flag |
| `attendance_status` | `ENUM('Registered','Confirmed','Attended','No-show','Cancelled')` | NO | `'Registered'` | — | `'Cancelled'` excluded from consumed capacity |
| `registered_at` | `DATETIME` | NO | — | — | Registration timestamp |
| `checked_in_at` | `DATETIME` | YES | `NULL` | — | Timestamp when donor arrives (`Attended`) |
| `cancelled_at` | `DATETIME` | YES | `NULL` | — | Cancellation timestamp |
| `created_at` | `TIMESTAMP` | YES | `NULL` | — | — |
| `updated_at` | `TIMESTAMP` | YES | `NULL` | — | — |

### 6.4 `camp_status_histories`

| Column | MySQL Type | Nullable | Default | Keys / Constraints | Notes |
|---|---|---|---|---|---|
| `id` | `BIGINT UNSIGNED` | NO | `AUTO_INCREMENT` | `PRIMARY KEY` | Audit PK |
| `camp_id` | `BIGINT UNSIGNED` | NO | — | `FK → donation_camps(id) ON DELETE RESTRICT`, `INDEX (camp_id, occurred_at)` | Linked camp |
| `event_name` | `VARCHAR(120)` | NO | — | — | e.g., `Camp Created`, `Donor Registered`, `Camp Started` |
| `previous_status` | `ENUM('Draft','Upcoming','Ongoing','Completed','Cancelled')` | YES | `NULL` | — | Null if status unchanged |
| `new_status` | `ENUM('Draft','Upcoming','Ongoing','Completed','Cancelled')` | YES | `NULL` | — | Target status if changed |
| `actor_user_id` | `BIGINT UNSIGNED` | YES | `NULL` | `FK → users(id) ON DELETE SET NULL` | Staff actor |
| `actor_name` | `VARCHAR(150)` | NO | — | — | Display actor snapshot |
| `notes` | `TEXT` | YES | `NULL` | — | Audit remark |
| `occurred_at` | `DATETIME` | NO | — | — | Timestamp |

### 6.5 `donor_appointments`

| Column | MySQL Type | Nullable | Default | Keys / Constraints | Notes |
|---|---|---|---|---|---|
| `id` | `BIGINT UNSIGNED` | NO | `AUTO_INCREMENT` | `PRIMARY KEY` | Appointment PK |
| `appointment_code` | `VARCHAR(32)` | NO | — | `UNIQUE (appointment_code)` | e.g., `APT-2041` |
| `donor_id` | `BIGINT UNSIGNED` | NO | — | `FK → donors(id) ON DELETE RESTRICT`, `INDEX (donor_id, status, appointment_date)` | Booking donor |
| `donation_component_id` | `BIGINT UNSIGNED` | NO | — | `FK → blood_components(id) ON DELETE RESTRICT` | Scheduled donation type |
| `donation_center_id` | `BIGINT UNSIGNED` | YES | `NULL` | `FK → donation_centers(id) ON DELETE RESTRICT`, `INDEX (donation_center_id, appointment_date, slot_time)` | Mutually exclusive with `donation_camp_id` |
| `donation_camp_id` | `BIGINT UNSIGNED` | YES | `NULL` | `FK → donation_camps(id) ON DELETE RESTRICT`, `INDEX (donation_camp_id, appointment_date, slot_time)` | Mutually exclusive with `donation_center_id` |
| `appointment_date` | `DATE` | NO | — | — | Visit date |
| `slot_time` | `TIME` | NO | — | — | Time slot (e.g., `09:00:00`, `10:30:00`) |
| `active_slot_guard` | `TINYINT(1)` | YES | `GENERATED ALWAYS AS (CASE WHEN status <> 'Cancelled' THEN 1 ELSE NULL END) STORED` | `UNIQUE (donor_id, appointment_date, slot_time, active_slot_guard)` | Enforces zero duplicate active donor slots in MySQL 8 |
| `status` | `ENUM('Pending','Confirmed','Completed','Cancelled')` | NO | `'Pending'` | `INDEX (status)` | Booking lifecycle state |
| `notes` | `VARCHAR(500)` | YES | `NULL` | — | Optional donor note |
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
| `event_name` | `VARCHAR(120)` | NO | — | — | e.g., `Appointment Requested`, `Confirmed`, `Cancelled` |
| `previous_status` | `ENUM('Pending','Confirmed','Completed','Cancelled')` | YES | `NULL` | — | Prior state |
| `new_status` | `ENUM('Pending','Confirmed','Completed','Cancelled')` | NO | — | — | New state |
| `actor_user_id` | `BIGINT UNSIGNED` | YES | `NULL` | `FK → users(id) ON DELETE SET NULL` | Donor or staff actor |
| `notes` | `TEXT` | YES | `NULL` | — | Context note |
| `occurred_at` | `DATETIME` | NO | — | — | Timestamp |

