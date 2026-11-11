# BDMS Database Design Decisions, Resolution of Open Questions & Compatibility Review

This document records the architectural rationale, final resolutions to all **8 outstanding domain questions**, security and concurrency verification, **MySQL 8.4.3** / **SQLite `:memory:`** compatibility engineering notes, and remaining clinical/product sign-offs required before migration implementation.

---

## 1. Key Architectural Decisions (Final 31-Table Architecture)

### Decision 1: Surrogate `BIGINT` Primary Keys + Unique Business Reference Codes
- **Choice**: Use `BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY` (`id`) on all operational tables while storing human-readable codes (`donor_code = 'DNR-1041'`, `request_code = 'REQ-1044'`, `camp_code = 'CMP-308'`) as indexed `UNIQUE VARCHAR(32)` columns.
- **Alternative Considered**: String natural primary keys (`DNR-1041`) or UUID primary keys across foreign keys.
- **Why Chosen**: InnoDB clusters rows on the PK and duplicates the PK inside every secondary index. Compact 8-byte `BIGINT UNSIGNED` keys minimize index bloat across `blood_units`, `inventory_allocations`, and `inventory_transactions`, shield foreign keys from code-prefix changes, and pair cleanly with Laravel `getRouteKeyName()` route binding (`/donors/DNR-1041`).

### Decision 2: Component-Granular Dual-Layer Inventory (`inventory_stocks` + `blood_units` + `inventory_transactions`)
- **Choice**: Track component-level stock balances (`inventory_stocks` keyed by `(blood_group, blood_component_id)`), serialized bags (`blood_units` with optional self-referential `parent_unit_id`), and an immutable append-only ledger (`inventory_transactions`).
- **Alternative Considered**: Flat 8-row `blood_group` counter table lacking unit serialization or component separation.
- **Why Chosen**: Flat counters cannot prevent unscreened quarantine stock from entering circulation, cannot track bag expiration (`7`-day alerts in `DashboardController`), cannot support FIFO bag picking or recalls back to `DON-xxxx`, and cannot track individual components (`Whole Blood`, `Packed RBC`, `Platelets`, `Plasma`, `Double Red Cells`). Storing `(blood_group, blood_component_id)` rows supports both component-level custody and deterministic `SUM(...) GROUP BY blood_group` SQL rollups for 8-group views and the Public Portal.

### Decision 3: Separating `inventory_allocations` (Holds & Unit Mapping) from `request_fulfillments` (Dispatches)
- **Choice**: Track unit-level holds (`inventory_allocations`: `'Reserved'`, `'Dispatched'`, `'Released'`, `'Cancelled'`) separately from delivery batches (`request_fulfillments` with courier code `#TR-208`, delivery tracking, and compatibility override audit columns).
- **Alternative Considered**: Decrementing inventory counters immediately upon request approval without serialized hold rows.
- **Why Chosen**: Orders in `Approved` status (`REQ-1038`) reserve bags pending courier departure, while `Partially Fulfilled` orders (`REQ-1043`) span multiple dispatches. Explicit allocation rows allow clean release back to `Available` stock when an order is rejected or cancelled (`REQ-1036`).

### Decision 4: Normalizing `hospitals`, `hospital_users` & Facility Verification Gate
- **Choice**: Extract partner facilities into `hospitals` (with `verification_status`, `verified_by_user_id`, `verified_at`) and login coordinators into `hospital_users`, while preserving point-in-time requester snapshots on `blood_requests`.
- **Alternative Considered**: Storing hospital names, addresses, and emails redundantly on every `blood_requests` row.
- **Why Chosen**: Eliminates Third Normal Form (3NF) update anomalies, enforces admin verification before external hospitals place orders, and keeps historical dispatch/cold-chain logs intact if hospital staff later change.

### Decision 5: Separating Sensitive Clinical & Auth Data (`users` / `donors` / `donor_medical_profiles` / `donor_eligibility_records`)
- **Choice**: Separate login auth (`users` + RBAC tables), demographic/portal profiles (`donors`), sensitive clinical measurements & clinician notes (`donor_medical_profiles`), and longitudinal clearance outcomes (`donor_eligibility_records`).
- **Alternative Considered**: Storing biometrics, deferral notes, and login credentials on one monolithic `donors` table.
- **Why Chosen**: Supports walk-in donors without portal credentials (`user_id = NULL`), ensures Public Portal pages never touch PHI tables, keeps confidential notes hidden from standard Donor Portal views, and protects clinical records behind `donors.view_medical`.

### Decision 6: Engine-Enforced Generated Partial-Unique Guards (`STORED`)
- **Choice**: Prevent concurrent double-booked active donor appointments (`active_slot_guard`), duplicate active camp registrations (`active_registration_guard`), duplicate active donor eligibility rows (`current_record_guard`), and double-allocated blood units (`active_unit_guard`) using `GENERATED ALWAYS AS (...) STORED` columns combined with `UNIQUE` indexes.
- **Alternative Considered**: Application-only `EXISTS` checks or complex MySQL triggers.
- **Why Chosen**: Eliminates TOCTOU race conditions at the storage engine level while letting `'Cancelled'`, `'Released'`, or `is_current = 0` historical rows evaluate to `NULL` (preserving full history without blocking re-registration, re-booking, re-allocation, or new eligibility evaluations).

### Decision 7: Status-Based Archival & `ON DELETE RESTRICT` Over Global `SoftDeletes`
- **Choice**: Disallow hard deletion on regulated clinical/operational records (`ON DELETE RESTRICT`) and avoid `deleted_at` on `donations`, `screening_records`, `blood_units`, `inventory_transactions`, `request_fulfillments`, and `*_status_histories`, using lifecycle statuses (`account_status = 'Inactive'`, `'Cancelled'`, `is_active = 0`) instead.
- **Why Chosen**: Preserves complete regulatory audit trails and avoids `UNIQUE` index collisions on `email` or business codes (`*_code`) after soft-deletion.

### Decision 8: Component-Configurable Screening & Compatibility Matrices in Database Tables
- **Choice**: Parameterize per-component release screening rules (`component_screening_requirements`, seeded immediately with all 5 mandatory baseline tests across all components) and component-specific ABO/Rh compatibility substitution rules (`blood_compatibility_rules`) in database tables rather than frontend/service hardcoding.
- **Why Chosen**: Blood components invert compatibility (e.g., `O-` vs `AB` plasma) and require component-appropriate assay gating. Modeling both matrices declaratively lets medical directors govern policies safely with full auditability.

### Decision 9: Single Authoritative Source of Truth for Donor Eligibility (`donor_eligibility_records`)
- **Choice**: Designate `donor_eligibility_records` (`is_current = 1`, guarded by `current_record_guard` + `UNIQUE (donor_id, current_record_guard)`) as the single authoritative source of truth for donor eligibility, while maintaining `donors.eligibility_status` and `donors.next_eligible_date` strictly as read-only system-synchronized projections updated exclusively by `DonorEligibilityService` inside `DB::transaction()`.
- **Alternative Considered**: Allowing direct edits to `donors.eligibility_status` alongside separate inserts into `donor_eligibility_records`, or joining `donor_eligibility_records` dynamically on every directory filter query.
- **Why Chosen**: Maintaining two independently mutable copies of current eligibility causes silent drift in safety-critical donation clearance. Locking `donors FOR UPDATE`, retiring `is_current = 0`, inserting the authoritative `is_current = 1` row, and updating the read-only cached columns in one atomic transaction eliminates drift while preserving `O(1)` directory indexing.

---

## 2. Final Resolution of the Eight Outstanding Questions

All **8 domain ambiguities** identified in the initial design review have been evaluated against existing controllers, views, and JS modules and are resolved as follows:

### Question 1: Component-Level Inventory Granularity vs. 8-Group Totals
- **Existing Behavior**: `InventoryController`, `PublicPortalController`, and `BloodRequestsController` display and submit orders strictly across the 8 ABO/Rh blood groups (`A+`..`O-`), whereas `DonationsController`, `ScreeningController`, `DonorPortalController`, `DashboardController`, and sample request notes (`REQ-1044`) track explicit components (`Whole Blood`, `Packed RBC`, `Platelets`, `Plasma`, `Double Red Cells`).
- **Alternatives Considered**:
  1. Collapse inventory purely to 8 blood groups and discard component granularity.
  2. Persist component-level stock in `inventory_stocks` keyed by `(blood_group, blood_component_id)` while deriving 8-group summaries via SQL aggregation.
- **Selected Decision**: **Option 2 — Persist per `(blood_group, blood_component_id)` with SQL 8-group rollups.** `inventory_stocks` and `blood_units` store both `blood_group` and `blood_component_id`, while `blood_requests.blood_component_id` stays nullable (`BIGINT UNSIGNED NULL`) so existing 8-group request workflows and future component-specific requests work seamlessly side by side.
- **Why Chosen**: Storing `blood_group` alone makes per-component expiration tracking (`Platelets` `5` days vs `Plasma` `365` days) and transfusion matching impossible. Aggregating up to 8 groups preserves all current Public Portal and Inventory overview cards without rework.
- **Schema / Doc Impact**: `inventory_stocks` (`UNIQUE (blood_group, blood_component_id)`), `blood_requests.blood_component_id` (`NULL`), `request_fulfillments.blood_component_id` (`NOT NULL`).

### Question 2: Emergency Blood Compatibility Dispatch & Authorized Medical Override
- **Existing Behavior**: `requests/show.blade.php` and `requests.js` let staff choose any dispatch blood group (for cross-group emergency dispatch like `O-`) with no compatibility validation or clinical override tracking.
- **Alternatives Considered**:
  1. Allow arbitrary unvalidated cross-group dispatches from UI dropdowns.
  2. Restrict to exact group match only (blocking life-saving `O-` emergency substitutions).
  3. Enforce a component-specific ABO/Rh compatibility lookup table plus explicit medical override accountability on emergencies.
- **Selected Decision**: **Option 3 — Add component-specific `blood_compatibility_rules` + audited medical override columns on `request_fulfillments`.**
- **Why Chosen**: ABO/Rh compatibility differs fundamentally by component (e.g., universal RBC donor `O-` vs universal plasma donor `AB`), so `blood_compatibility_rules.blood_component_id` is strictly `NOT NULL`. Dispatches whose `(requested_blood_group, dispatched_blood_group, blood_component_id)` pair fails lookup abort cleanly; `'Emergency Only'` / override-flagged pairs require RBAC permission `requests.override_compatibility` plus `override_approved_by_user_id`, `override_approved_at`, and `override_reason` enforced by database constraint `chk_fulfillment_override_audit`.
- **Schema / Doc Impact**: New table `blood_compatibility_rules`; added `is_compatibility_override`, `override_approved_by_user_id`, `override_approved_at`, `override_reason`, and `chk_fulfillment_override_audit` on `request_fulfillments`; added `requests.override_compatibility` permission example.

### Question 3: Cancelled Camp Registration History vs. Re-Registration
- **Existing Behavior**: `camps.js` (`evaluateRegistrationEligibility()`) blocks any `donorId` present in `registrations`—even if `attendanceStatus === 'Cancelled'`—whereas `DonorPortalController::buildDonorCamps()` excludes `'Cancelled'` rows when calculating `donorAlreadyRegistered`. Meanwhile, `camp_registrations` in the initial schema had an unconditional `UNIQUE (camp_id, donor_id)` index.
- **Alternatives Considered**:
  1. Keep `UNIQUE (camp_id, donor_id)` and mutate cancelled rows in place (erasing cancellation audit timestamps and history like `REG-805`).
  2. Drop uniqueness altogether (creating race-condition active duplicates).
  3. Replace unconditional uniqueness with a generated partial-unique active guard preserving cancelled rows and permitting fresh re-registration.
- **Selected Decision**: **Option 3 — Generated `active_registration_guard` (`CASE WHEN attendance_status <> 'Cancelled' THEN 1 ELSE NULL END`) + `UNIQUE (camp_id, donor_id, active_registration_guard)` + `cancelled_at`.**
- **Why Chosen**: Preserves immutable cancelled registration history rows (`REG-805`), removes the mismatch between `camps.js` and `DonorPortalController`, prevents concurrent duplicate active registrations at the database layer, and allows a donor who cancelled to re-register cleanly with a new registration row (`REG-806`) as long as camp capacity remains.
- **Schema / Doc Impact**: Removed `UNIQUE (camp_id, donor_id)` from `camp_registrations`; added `active_registration_guard` (`STORED`), `UNIQUE (camp_id, donor_id, active_registration_guard)`, and `cancelled_at`.

### Question 4: Mandatory Screening Panel Gate Before Unit Release
- **Existing Behavior**: `ScreeningController` currently uses **five standard laboratory tests** (`HIV`, `Hepatitis B`, `Hepatitis C`, `Syphilis`, `Blood Group Compatibility`) and renders sample screening rows per donation (`DON-4403`, `DON-4405`). The initial schema placed a single global boolean on `screening_test_types`.
- **Alternatives Considered**:
  1. Hardcode the 5 test names in PHP services.
  2. Keep a global boolean on `screening_test_types`.
  3. Preserve `component_screening_requirements` and seed all 5 standard tests as active and required across every blood component.
- **Selected Decision**: **Option 3 — Preserve `component_screening_requirements` and seed all five existing BDMS tests (`HIV`, `Hepatitis B`, `Hepatitis C`, `Syphilis`, `Blood Group Compatibility`) as `is_required = 1, is_active = 1` across every blood component for the baseline workflow.**
- **Why Chosen**: Enforces the approved rule that **all five mandatory screening tests must pass before any unit can exit `'Quarantined'` inventory** immediately out of the box—without leaving the initial 5-test rule dependent on future clinical approval—while keeping the table extensible for future medically approved component-specific policies. Releasing a unit requires `'Passed'` on every required test, zero `'Pending'` or `'Failed'` records on the donation, and explicit officer approval (`approved_by_user_id` + `released_to_inventory_at`).
- **Schema / Doc Impact**: `component_screening_requirements` + baseline 5-test seeder specification documented in `SCHEMA.md`, `DATA-DICTIONARY.md`, and `BUSINESS-RULES.md`.

### Question 5: Unit Yield Mapping (`quantity_ml` vs. Multi-Component Splitting)
- **Existing Behavior**: `donations` collects volume (`150`–`800` ml), whereas inventory and requests track integer `units`.
- **Alternatives Considered**:
  1. Enforce a strict 1:1 `UNIQUE (donation_id)` foreign key on `blood_units` (making fractionation require breaking migrations later).
  2. Enforce multi-component splitting immediately on every draw.
- **Selected Decision**: **Option 1+ — Maintain `1 donation -> 1 unit` as the standard collection workflow while supporting `1:N` multi-component fractionation via `blood_units.parent_unit_id` (nullable self-FK).**
- **Why Chosen**: Current UI workflows (`DonationsController`, `ScreeningController`) treat one donation as producing one primary unit (`parent_unit_id = NULL`). Allowing non-unique `donation_id` plus nullable `parent_unit_id -> blood_units(id)` gives immediate compatibility today while enabling `Whole Blood -> PRBC + Plasma + Platelets` splitting tomorrow with zero schema breaking changes.
- **Schema / Doc Impact**: Added `parent_unit_id` (`FK -> blood_units(id) ON DELETE SET NULL`, `INDEX`) on `blood_units`.

### Question 6: Self-Registered Donor Profile Completion & Appointment Booking Gate
- **Existing Behavior**: `auth/register.blade.php` collects only `name`, `email`, and `password` (with `blood_group` and `date_of_birth` optional), whereas staff `donors/_form.blade.php` and `donors.js` require all **8 core profile fields**: `full_name`, `email`, `phone`, `gender`, `date_of_birth`, `blood_group`, `address`, and `emergency_contact`.
- **Alternatives Considered**:
  1. Block self-registration unless all 8 demographic/emergency fields are filled upfront at `/register`.
  2. Allow `profile_completed = 0` accounts to book appointments with only frontend UI warnings.
  3. Keep lightweight `/register` (`profile_completed = 0`) and Donor Portal browsing accessible, but strictly block appointment creation via backend validation until `profile_completed = 1` (and require verified `'Eligible'` status before physical donation).
- **Selected Decision**: **Option 3 — Donor Portal viewing stays accessible when `profile_completed = 0`, while backend validation strictly requires `profile_completed = 1` (all 8 required fields populated) before booking any `donor_appointments` record; physical donation additionally requires verified `'Eligible'` clearance.**
- **Why Chosen**: Preserves low-friction onboarding on `/register` while enforcing the approved rule that a donor must complete all required profile fields (`full_name`, `email`, `phone`, `gender`, `date_of_birth`, `blood_group`, `address`, `emergency_contact`) before booking an appointment. Backend `FormRequest` and transactional service locks guarantee incomplete profiles cannot bypass frontend warnings.
- **Schema / Doc Impact**: Retained nullable onboarding fields + `profile_completed` (`DEFAULT 0`) on `donors`; explicitly documented all 8 required fields and backend booking block across `SCHEMA.md`, `DATA-DICTIONARY.md`, `BUSINESS-RULES.md`, and `ERD.md`.

### Question 7: Self-Registered Hospital Verification Gate
- **Existing Behavior**: `auth/register.blade.php` allows public signup with `role = 'hospital'` (`organization_name`, optional `facility_code`).
- **Alternatives Considered**:
  1. Auto-approve public hospital signups immediately.
  2. Remove hospital self-registration entirely.
  3. Accept signup into `hospitals.verification_status = 'Pending'` and require administrator vetting prior to submitting blood requests.
- **Selected Decision**: **Option 3 — Public hospital signups start with `verification_status = 'Pending'` (with `verified_by_user_id`, `verified_at` audit columns) and cannot submit live `blood_requests` until verified (`verification_status = 'Verified'` and `is_active = 1`).**
- **Why Chosen**: Preserves partner onboarding convenience in `auth/register.blade.php` while closing the security gap of unvetted external accounts submitting emergency hospital blood orders.
- **Schema / Doc Impact**: Added `verified_by_user_id` (`FK -> users(id) ON DELETE SET NULL`) and `verified_at` (`DATETIME NULL`) to `hospitals`.

### Question 8: Camp vs. Fixed Center Slot Capacity Model
- **Existing Behavior**: `DonorPortalController::sampleTimeSlots()` checks per-slot capacity `3` across fixed centers (`09:00 AM`..`03:30 PM`), whereas `donation_camps` only defines total event `capacity` without per-slot capacities.
- **Alternatives Considered**:
  1. Force mobile drives into rigid clinic slot quotas that conflict with overall drive `capacity`.
  2. Allow nullable `donor_appointments.slot_time` for camps (which would create a MySQL `NULL != NULL` bypass on `UNIQUE (donor_id, appointment_date, slot_time, active_slot_guard)`!).
  3. Enforce fixed centers per `(donation_center_id, appointment_date, slot_time)` (`default_slot_capacity`), enforce camps primarily by total `donation_camps.capacity`, and keep `donor_appointments.slot_time` `NOT NULL`.
- **Selected Decision**: **Option 3 — Camp capacity is enforced primarily against total `donation_camps.capacity`; fixed centers enforce `default_slot_capacity` (`3`); `donor_appointments.slot_time` stays `NOT NULL`.**
- **Why Chosen**: Accurately reflects mobile camp throughput while keeping `uq_donor_active_slot` leak-proof across MySQL and SQLite (`NULL` never neutralizes composite unique indexes).
- **Schema / Doc Impact**: `donation_camps.capacity` authoritative total active limit; `donation_centers.default_slot_capacity` (`3`) per slot; `donor_appointments.slot_time` `NOT NULL` + `chk_appointment_single_target`.

---

## 3. Technical, Security & MySQL 8.4.3 / SQLite Compatibility Review

### 3.1 Security & Privacy Verification
- **PHI Isolation**: `donor_medical_profiles` (hemoglobin, weight, clinical/deferral notes) and detailed serology results (`screening_records`) are decoupled from `donors` and `users`, gated behind permissions (`donors.view_medical`, `screening.record`, `screening.release_inventory`).
- **Public Portal Safety**: Public routes query only `inventory_stocks` aggregate `available_units` (excluding `reserved_units`) and published `donation_camps` (`Upcoming`/`Ongoing` only, omitting `organizer_name`, `organizer_contact`, `organizer_email`, and `camp_registrations`).
- **RBAC Enforcement**: Administrative actions (`hospitals.verify`, `requests.override_compatibility`, `screening.release_inventory`, `inventory.adjust`) map to explicit permission slugs rather than coarse role checks alone.

### 3.2 Concurrency & Integrity Verification
- **No Unscreened Stock Release**: `blood_units` originate in `'Quarantined'` state and transition to `'Available'` only inside an atomic `DB::transaction()` verifying `collection_status = 'Completed'`, zero `'Failed'`/`'Pending'` screens, `'Passed'` results for all required panels in `component_screening_requirements` (seeded initially with all 5 active assays across every component), and explicit officer approval (`approved_by_user_id` + `released_to_inventory_at`).
- **Single-Source Eligibility Synchronization**: `donor_eligibility_records.current_record_guard` enforces a unique active (`is_current = 1`) record per donor; `DonorEligibilityService` atomically locks `donors`, demotes old records (`is_current = 0`), inserts the authoritative record, and synchronizes read-only `donors.eligibility_status` / `next_eligible_date`.
- **Deadlock-Free Locking Order**: Fulfillment transactions consistently acquire row locks in deterministic order: `blood_requests` -> `inventory_stocks` -> FIFO `blood_units` / `inventory_allocations`.
- **Table Check Constraints Enforced at Engine Level**:
  - `blood_requests`: `requested_units >= 1`, `fulfilled_units >= 0`, `fulfilled_units <= requested_units`, `fulfilled_units + reserved_units <= requested_units`.
  - `request_fulfillments`: `chk_fulfillment_override_audit` ensures every override batch carries `override_approved_by_user_id`, `override_approved_at`, and `override_reason`.
  - `donor_appointments`: `chk_appointment_single_target` guarantees XOR target (`donation_center_id` vs `donation_camp_id`).
  - `donation_camps`: date/time ordering (`end_date >= start_date`, `end_date > start_date OR end_time > start_time`, `registration_deadline <= start_date`, `capacity >= 1`).

### 3.3 MySQL 8.4.3 & PHPUnit SQLite `:memory:` Compatibility
- **Inspected Environment**:
  - Production database configured in `.env` / `config/database.php`: **MySQL `8.4.3`** (`blood_donor_system`, `utf8mb4` / `utf8mb4_unicode_ci`, `strict => true`), currently holding 9 baseline Laravel tables (`users`, `password_reset_tokens`, `sessions`, `cache`, `cache_locks`, `jobs`, `job_batches`, `failed_jobs`, `migrations`).
  - Test runner configured in `phpunit.xml`: **SQLite `:memory:`** (`SQLite 3.40.0` via PHP `8.3` PDO).
- **Verified Laravel 13 Grammar Behavior**:
  - `STORED` generated columns (`->storedAs(...)`) + `->unique(...)` + `->enum(...)` + `CHECK` constraints compile and execute cleanly on **both** MySQL `8.4.3` and SQLite `3.40.0` when defined **inside `Schema::create(...)`**.
  - **Critical Rule**: Do **not** add `STORED` generated columns or table `CHECK` constraints via `Schema::table(...)` (`ALTER TABLE`), because SQLite rejects `ALTER TABLE ADD COLUMN ... STORED`.
  - `->lockForUpdate()` emits native `FOR UPDATE` locks under MySQL `8.4.3` InnoDB and degrades safely to transaction serialization without SQL errors on SQLite `:memory:`.
  - `ENUM` columns on SQLite compile as `VARCHAR` + inline `CHECK ("col" in (...))`, guaranteeing identical domain value enforcement in PHPUnit tests and production MySQL `8.4.3`.

---

## 4. Remaining Decisions Requiring Explicit Human or Clinical Approval

While the relational architecture is complete and implementation-ready, the following clinical policy thresholds and product scope items intentionally await sign-off from medical leadership and stakeholders before populating production seeders or changing existing frontend screens:

1. **Per-Component Clinical Compatibility Matrix (`blood_compatibility_rules`)**:
   - Medical Director / Transfusion Committee must formally approve the exact donor-to-recipient pairs allowed as `'Compatible Substitution'` vs `'Emergency Only'` (requiring medical officer override) separately for `Whole Blood`, `Packed RBC`, `Platelets`, `Plasma`, and `Double Red Cells` before seeding production rules.
2. **Future Expansions Beyond the Approved Five-Panel Screening Baseline (`component_screening_requirements`)**:
   - The baseline requirement of passing all **5 existing BDMS assays (`HIV`, `Hepatitis B`, `Hepatitis C`, `Syphilis`, `Blood Group Compatibility`)** across all components is **already finalized and seeded as mandatory out of the box**. Future Lab Director sign-off is required only when introducing additional region-specific infectious assays or specialized component-level panel expansions later.
3. **Component Fractionation Yield & Volume Losses (`blood_units` with `parent_unit_id`)**:
   - Clinical lab SOP must define acceptable derivative bag volume ranges (`volume_ml` sums vs centrifuge separation loss) when splitting a `Whole Blood` draw into multiple component bags.
4. **UI Component Adoption Timing & 8-Group Threshold Semantics**:
   - Product sign-off on when Inventory, Blood Requests, and Public Portal views expand from 8-group rollups to per-component filtering, plus whether 8-group `minimum_level` status indicators sum component thresholds or track a Whole-Blood-equivalent floor.
5. **Hospital Verification Operational Criteria (`hospitals.verification_status`)**:
   - Administration sign-off on acceptable regulatory facility codes (`HSP-*`), documentary proof requirements, and turnaround SLA before external `/register` hospitals are promoted from `'Pending'` to `'Verified'`.

---

## 5. Cross-Document Validation Methodology & Tooling Note

- **Validation Scope**: All 5 design artifacts (`ERD.md`, `SCHEMA.md`, `DATA-DICTIONARY.md`, `BUSINESS-RULES.md`, `DESIGN-DECISIONS.md`) are cross-checked structurally for exact 31-table parity, consistent foreign keys, consistent enum sets, consistent generated partial-unique guards, and absence of superseded 29-table/unconditional-unique references.
- **Prior Script Failure Explanation**: An earlier validation attempt run via `node -e "..."` exited with code `1` strictly due to PowerShell shell-quoting (`"..."` / backtick interpolation inside the inline command string causing a Node `SyntaxError`), **not** a schema defect. Subsequent validation runs pipe scripts cleanly over standard input (`@' ... '@ | node`) so zero shell interpolation interferes with Markdown/SQL string scanning.
