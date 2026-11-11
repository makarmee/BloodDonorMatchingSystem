# BDMS Data Dictionary

This Data Dictionary documents the domain purpose, sensitivity classification, key columns, human-readable business code patterns, and exact enumeration values across all **31 tables** (3 existing Laravel baseline auth/session tables + 28 BDMS domain tables) of the Blood Donation Management System (BDMS).

---

## 1. Human-Readable Business Identifiers

Every operational record carries a surrogate `BIGINT UNSIGNED` primary key (`id`) plus an indexed `UNIQUE` business reference code aligned with existing BDMS URLs and views:

| Table | Code Column | Observed / Target Pattern | Examples in BDMS |
|---|---|---|---|
| `donors` | `donor_code` | `DNR-XXXX` | `DNR-1041` – `DNR-1048` |
| `donations` | `donation_code` | `DON-XXXX` | `DON-4012` – `DON-4405` |
| `screening_records` | `screening_code` | `SCR-XXXX` | `SCR-7096` – `SCR-7105` |
| `blood_units` | `unit_code` | `UNIT-YYYY-XXXXXX` | `UNIT-2026-004405` *(serialized unit tracking ID)* |
| `inventory_transactions` | `transaction_code` | `TXN-XXXX` | `TXN-101` – `TXN-106` *(replaces integer sample IDs `101`–`106`)* |
| `hospitals` | `hospital_code` | `HSP-XXXX` | `HSP-2049` (`auth/register.blade.php` placeholder) |
| `blood_requests` | `request_code` | `REQ-XXXX` | `REQ-1035` – `REQ-1044` |
| `inventory_allocations` | `allocation_code` | `ALC-XXXX` | `ALC-3001` |
| `request_fulfillments` | `fulfillment_code` | `FUL-XXXX` | `FUL-5001` *(courier refs like `#TR-208` stored separately)* |
| `donation_centers` | `center_code` | `CTR-*` | `CTR-CENTRAL`, `CTR-WEST`, `CTR-SOUTH` |
| `donation_camps` | `camp_code` | `CMP-XXX` | `CMP-301` – `CMP-308` |
| `camp_registrations` | `registration_code` | `REG-XXX` | `REG-731` – `REG-805` |
| `donor_appointments` | `appointment_code` | `APT-XXXX` | `APT-1962` – `APT-2041` |

*(Reference catalogs `blood_components.code` and `screening_test_types.code` also carry compact unique uppercase codes such as `WB`/`PRBC`/`PLT`/`PLS`/`DRBC` and `HIV`/`HBV`/`HCV`/`SYPH`/`COMPAT`.)*

---

## 2. Data Classification & Visibility Levels

| Classification | Meaning | Accessible To |
|---|---|---|
| **Public-Safe (Projected)** | Non-identifying aggregate supply rollups (grouped by the 8 ABO/Rh blood groups) or published drive schedule metrics (with staff PII & donor rosters stripped). | Public Portal (`/`, `/blood-stock`, `/blood-camps`, `/blood-camps/{camp}`) |
| **Donor-Self Scoped** | Donor profile (contact masked on portal header), own appointments, eligibility decision summary, and safe donation history. | Authenticated Donor (`user_id` match) + Authorized Staff |
| **Hospital-Self Scoped** | Own facility details (`verification_status` checked before submission), request queue, fulfillment delivery tracking, and aggregate supply availability. | Linked `hospital_users` + Authorized Staff |
| **Staff / Operational** | Donor directory, donation logs, component inventory balances, request review queues, camp attendance rosters, inventory reservations. | Blood Bank Staff & Administrators |
| **Clinical-Restricted (PHI & Governance)** | Clinical deferral notes, hemoglobin/weight measurements, serology test assay notes, compatibility override matrices, component screening gate policies. | Authorized Medical / Lab / Staff Roles (`donors.view_medical`, `screening.manage`, `requests.override_compatibility`) |
| **Auth / Security** | Credentials, password reset tokens, session payloads, RBAC permission graphs. | Authentication Subsystem / Admin Only |

---

## 3. Master Catalog of Allowed Statuses & Enumerations

### 3.1 Blood Groups (All Donors, Donations, Inventory, Rules, Requests, Fulfillments)
- Allowed Values: `'A+'`, `'A-'`, `'B+'`, `'B-'`, `'AB+'`, `'AB-'`, `'O+'`, `'O-'`

### 3.2 Blood Component Types (`blood_components`)
Synchronized across `DonationsController`, `ScreeningController`, `DonorPortalController`, `DashboardController`, and `BloodRequestsController` notes:
- `WB` — `'Whole Blood'` (`default_volume_ml: 450`, `shelf_life_days: 35`)
- `PRBC` — `'Packed RBC'` (`default_volume_ml: 300`, `shelf_life_days: 42`)
- `PLT` — `'Platelets'` (`default_volume_ml: 250`, `shelf_life_days: 5`)
- `PLS` — `'Plasma'` (`default_volume_ml: 600`, `shelf_life_days: 365`)
- `DRBC` — `'Double Red Cells'` (`default_volume_ml: 360`, `shelf_life_days: 42`)

### 3.3 Core Entity Statuses & Enums

| Table.Column | Allowed Values | Default | Notes |
|---|---|---|---|
| `donors.gender` | `'Female'`, `'Male'`, `'Other'` | `NULL` *(until intake complete)* | Required prior to physical donation (`profile_completed = 1`) |
| `donors.availability_status` | `'Available'`, `'Temporarily Unavailable'`, `'Inactive'` | `'Available'` | Outreach readiness toggle |
| `donors.eligibility_status` & `donor_eligibility_records.eligibility_status` | `'Eligible'`, `'Not Eligible'`, `'Pending Review'`, `'Unknown'` | `'Pending Review'` | Self-registered donors begin `'Pending Review'` until medical review |
| `donors.account_status` | `'Active'`, `'Inactive'` | `'Active'` | Administrative deactivation without deleting history |
| `donations.collection_status` | `'Scheduled'`, `'Completed'`, `'Cancelled'` | — | Physical draw outcome (`Completed` requires donor `profile_completed = 1` & `Eligible`) |
| `donations.screening_status` | `'Pending'`, `'Passed'`, `'Failed'` | `'Pending'` | Gate status aggregated from mandatory component panels |
| `screening_records.screening_result` | `'Pending'`, `'Passed'`, `'Failed'` | `'Pending'` | Explicitly entered by lab staff; never auto-defaulted to `Passed` |
| `screening_test_types.name` | `'HIV'`, `'Hepatitis B'`, `'Hepatitis C'`, `'Syphilis'`, `'Blood Group Compatibility'` | — | Extensible catalog table; mandatory rules moved to `component_screening_requirements` |
| `blood_units.unit_status` | `'Quarantined'`, `'Available'`, `'Reserved'`, `'Dispatched'`, `'Transfused'`, `'Expired'`, `'Discarded'` | `'Quarantined'` | Strictly controls allocatable bags |
| `inventory_transactions.operation_type` | `'Stock In'`, `'Reserve'`, `'Release Reservation'`, `'Dispatch'`, `'Removed'`, `'Expired'`, `'Discarded'`, `'Manual Adjustment'` | — | Traces every `+N`/`-N` movement |
| `hospitals.verification_status` | `'Pending'`, `'Verified'`, `'Suspended'` | `'Pending'` | Public `/register` hospital signups start `'Pending'`; must be `'Verified'` to submit requests |
| `blood_compatibility_rules.compatibility_level` | `'Exact Match'`, `'Compatible Substitution'`, `'Emergency Only'` | — | Component-specific substitution policy per `(recipient_blood_group, donor_blood_group, blood_component_id)` |
| `blood_requests.priority` | `'Low'`, `'Normal'`, `'High'`, `'Critical'` | — | Triage sorting; emergency override allowed under policy & audit |
| `blood_requests.status` | `'Pending'`, `'Approved'`, `'Rejected'`, `'Partially Fulfilled'`, `'Fulfilled'`, `'Cancelled'` | `'Pending'` | `Rejected` and `Cancelled` are terminal |
| `request_fulfillments.delivery_status` | `'Dispatched'`, `'In Transit'`, `'Delivered'`, `'Returned'` | `'Dispatched'` | Cold-chain courier tracking |
| `inventory_allocations.allocation_status` | `'Reserved'`, `'Dispatched'`, `'Released'`, `'Cancelled'` | `'Reserved'` | Protected by `active_unit_guard` when `'Reserved'` or `'Dispatched'` |
| `donation_camps.status` | `'Draft'`, `'Upcoming'`, `'Ongoing'`, `'Completed'`, `'Cancelled'` | `'Draft'` | Public pages show `Upcoming` & `Ongoing` only |
| `camp_registrations.attendance_status` | `'Registered'`, `'Confirmed'`, `'Attended'`, `'No-show'`, `'Cancelled'` | `'Registered'` | Protected by `active_registration_guard`; `'Cancelled'` preserves history & permits re-registration |
| `donor_appointments.status` | `'Pending'`, `'Confirmed'`, `'Completed'`, `'Cancelled'` | `'Pending'` | Protected by `active_slot_guard` with non-null `slot_time` |

*Note on Derived Inventory Stock Status:* Consistent with `InventoryController::resolveStatus()`, `inventory_stocks` does **not** store a redundant `status` column that can drift out of sync. Stock status is always computed deterministically from `(available_units, minimum_level)` — per component or rolled up across all components per ABO/Rh group:
- `critical` when `available_units <= floor(minimum_level * 0.6)`
- `low` when `available_units <= minimum_level`
- `normal` when `available_units > minimum_level`

---

## 4. Table-by-Table Purpose & Key Column Guide (All 31 Tables)

### 4.1 Identity, Sessions & RBAC (7 Tables)
- **`users`**: Authentication credentials (`email`, `password`), account activity (`is_active`, `last_login_at`). Deliberately omits donor medical attributes or hospital facility attributes so auth stays decoupled from domain profiles. *(Auth / Security)*
- **`password_reset_tokens`**: Standard Laravel password recovery token store keyed by `email`. *(Auth / Security)*
- **`sessions`**: Standard Laravel database session driver table (`SESSION_DRIVER=database`), tracking optional `user_id`, IP/user-agent, and serialized session payload. *(Auth / Security)*
- **`roles`**: Named system roles (`admin`, `staff`, `lab_officer`, `hospital`, `donor`) governing access control. *(Auth / Security)*
- **`permissions`**: Granular capabilities including `inventory.adjust`, `donors.view_medical`, `screening.record`, `screening.release_inventory`, `requests.fulfill`, `requests.override_compatibility`, and `hospitals.verify`. *(Auth / Security)*
- **`role_user`**: Many-to-many assignment binding users to one or more roles with `assigned_by_user_id` audit. *(Auth / Security)*
- **`permission_role`**: Many-to-many join mapping permissions to roles. *(Auth / Security)*

### 4.2 Donors & Clinical Governance (3 Tables)
- **`donors`**: Core demographic/contact donor profile (`donor_code`, `full_name`, `email`, nullable `phone`, `gender`, `date_of_birth`, `blood_group`, `address`, `emergency_contact`), optional portal link (`user_id`), readiness/account flags (`availability_status`, `account_status`, `profile_completed`), and cached eligibility/donation summaries (`eligibility_status`, `last_donation_date`, `next_eligible_date`, `total_completed_donations`). Supports lightweight self-registration (`profile_completed = 0`) while blocking completed blood draws until clinical intake fills required fields (`profile_completed = 1`). *(Donor-Self / Staff)*
- **`donor_medical_profiles`**: Isolated 1:1 clinical extension holding confidential medical notes (`medical_notes`), biometric measurements (`last_hemoglobin_g_dl`, `weight_kg`), and deferral notes (`deferral_notes`). Queries for Public Portal or standard Donor Portal views never join this table. *(Clinical-Restricted)*
- **`donor_eligibility_records`**: Historical audit log of medical clearance evaluations (`eligibility_status`, `reason`, `clearance_source`, `reviewed_at`, `next_eligible_date`, `reviewed_by_user_id`, `is_current`). *(Donor-Self summary / Clinical-Restricted)*

### 4.3 Blood Components, Configurable Screening Matrix & Donations (5 Tables)
- **`blood_components`**: Reference table defining blood components (`WB`, `PRBC`, `PLT`, `PLS`, `DRBC`), target draw volumes (`default_volume_ml`), shelf lives (`shelf_life_days`), and cold-chain storage specs (`storage_requirements`). *(Public-Safe reference / Staff)*
- **`screening_test_types`**: Controlled catalog of infectious disease and compatibility assays (`HIV`, `Hepatitis B`, `Hepatitis C`, `Syphilis`, `Blood Group Compatibility`). Global `is_mandatory_for_release` is intentionally omitted here because release panel rules vary by blood component. *(Staff / Clinical-Restricted)*
- **`component_screening_requirements`**: Pivot rule table (`UNIQUE (blood_component_id, test_type_id)`) governing which assays are mandatory (`is_required`) and enabled (`is_active`) before a donation of a given component can exit quarantine into available stock. Eliminates frontend/backend hardcoding of clinical panels. *(Clinical-Restricted)*
- **`donations`**: Individual blood collection event (`donation_code`) linking `donor_id`, `blood_component_id`, collection location (`camp_id`, `center_id`, `collection_location_name`), optional `appointment_id`, volume (`quantity_ml`), `collection_status`, `screening_status`, and authorized inventory release audit (`released_to_inventory_at`, `approved_by_user_id`). *(Staff / Donor-Self safe subset)*
- **`screening_records`**: Individual laboratory assay execution (`screening_code`) linked to `donation_id` and `test_type_id`. Tracks `specimen_tube_code`, `assay_method`, `test_date`, `screening_result`, `is_confirmatory`, `lab_staff_name`, and specimen timestamps (`collected_at`, `logged_at`, `result_recorded_at`). *(Clinical-Restricted)*

### 4.4 Inventory, Units & Transaction Ledger (3 Tables)
- **`inventory_stocks`**: Real-time aggregate balances (`available_units`, `reserved_units`) and thresholds (`minimum_level`, `target_capacity`) keyed by `(blood_group, blood_component_id)`. Enables O(1) lock targets (`SELECT ... FOR UPDATE`), component-level inventory views, and exact `SUM(...) GROUP BY blood_group` rollups for the 8-group overview and Public Portal. *(Public-Safe rollup of `available_units` only; Staff full view)*
- **`blood_units`**: Serialized blood bag inventory table (`unit_code`), linking `donation_id`, `blood_component_id`, `volume_ml`, `storage_location`, `expires_at`, `released_at`, `released_by_user_id`, and strict lifecycle `unit_status` (`Quarantined` through `Dispatched` / `Expired`). Includes nullable self-FK `parent_unit_id -> blood_units(id)` so today's `1 donation -> 1 unit` workflow can scale cleanly to multi-component fractionation (`1 Whole Blood -> 1 PRBC + 1 Plasma + 1 Platelets`) without altering the schema. *(Staff)*
- **`inventory_transactions`**: Append-only financial-grade stock ledger recording every stock delta (`units_delta <> 0`), exact before/after balances (`available_before/after`, `reserved_before/after`), domain reference (`reference_type`, `reference_id`), `performed_by_user_id`, `actor_name`, and mandatory justification `description`. *(Staff Audit)*

### 4.5 Hospitals, Compatibility Matrix, Blood Requests & Fulfillments (7 Tables)
- **`hospitals`**: Normalized hospital facility directory (`hospital_code`, `name`, `address`, `city`, `primary_contact_name/phone/email`), governed by `verification_status` (`Pending` / `Verified` / `Suspended`) plus audit fields `verified_by_user_id` and `verified_at`. Blocks unverified self-registered hospital signups from submitting real orders. *(Hospital-Self / Staff)*
- **`hospital_users`**: Maps portal user accounts (`users`) to partner hospitals with ward/department (`department`), role title (`job_title`), and primary contact designation. *(Auth / Hospital-Self / Staff)*
- **`blood_compatibility_rules`**: Component-aware ABO/Rh clinical matching matrix keyed by `UNIQUE (recipient_blood_group, donor_blood_group, blood_component_id)` (`blood_component_id` `NOT NULL` because Packed RBC compatibility and Plasma compatibility follow inverse donor-recipient rules). Classifies pairs as `'Exact Match'`, `'Compatible Substitution'`, or `'Emergency Only'` and sets `requires_medical_override`. *(Clinical-Restricted)*
- **`blood_requests`**: Clinical demand order (`request_code`) tracking requested vs reserved vs fulfilled units (`requested_units`, `reserved_units`, `fulfilled_units`), `blood_group`, optional `blood_component_id` (nullable so both existing 8-group requests and component-specific requests work seamlessly), `priority`, `status`, dates (`request_date`, `required_date`), snapshot requester contact at submission, and rejection/cancellation metadata. *(Hospital-Self / Staff)*
- **`blood_request_status_histories`**: Immutable timeline of every submission, approval, partial dispatch, completion, rejection, or cancellation event on a request (`units_affected`, `actor_name`, `notes`, `occurred_at`). *(Hospital-Self / Staff)*
- **`request_fulfillments`**: Physical cold-chain dispatch record (`fulfillment_code`) capturing actual `dispatched_blood_group`, `blood_component_id`, `units_dispatched`, `courier_reference`, hospital receipt (`delivery_status`, `delivered_at`, `received_by_name`), and full medical-override audit columns (`is_compatibility_override`, `override_approved_by_user_id`, `override_approved_at`, `override_reason`) enforced by `chk_fulfillment_override_audit`. *(Hospital-Self / Staff)*
- **`inventory_allocations`**: Granular link binding specific `blood_units` (`blood_unit_id`) and `inventory_stocks` (`inventory_stock_id`) to a `blood_request_id` (`Reserved`, `Dispatched`, `Released`, `Cancelled`), protected by generated column `active_unit_guard` (`UNIQUE (blood_unit_id, active_unit_guard)`). *(Staff)*

### 4.6 Centers, Camps & Donor Appointments (6 Tables)
- **`donation_centers`**: Fixed clinic facilities (`CTR-CENTRAL`, `CTR-WEST`, `CTR-SOUTH`) with operating hours and per-timeslot concurrency limits (`default_slot_capacity`, default `3`). *(Public-Safe / Donor-Self / Staff)*
- **`donation_camps`**: Mobile/community blood drives (`CMP-*`) storing public schedule/venue details (`venue_name`, `full_address`, `city`, `start_date`, `end_date`, `start_time`, `end_time`, total event `capacity`, `registration_deadline`, `allow_walk_in`, `donor_requirements`) plus restricted coordinator contacts (`organizer_name/contact/email`) omitted from public queries. *(Public-Safe subset when `Upcoming`/`Ongoing`; full fields Staff-only)*
- **`camp_registrations`**: Donor enrollment and check-in ledger (`REG-*`) linking `camp_id`, `donor_id`, optional portal `appointment_id`, `is_walk_in`, `attendance_status`, `registered_at`, `checked_in_at`, and `cancelled_at`. Uses `active_registration_guard` (`UNIQUE (camp_id, donor_id, active_registration_guard)`) so cancelled registration history rows remain intact while permitting donor re-registration without creating duplicate active rows. *(Staff / Donor-Self presence check only)*
- **`camp_status_histories`**: Audit timeline of camp publication, opening, walk-ins, completions, and cancellations. *(Staff)*
- **`donor_appointments`**: Donor scheduling record (`APT-*`) bound exclusively to either a `donation_center_id` or a `donation_camp_id` (`chk_appointment_single_target`), with `appointment_date`, non-null `slot_time`, `status`, and generated `active_slot_guard` preventing duplicate active bookings at the same timestamp while allowing rebooking after cancellation. *(Donor-Self / Staff)*
- **`appointment_status_histories`**: Longitudinal record of donor booking requests, confirmations, completions, and cancellations. *(Donor-Self / Staff)*
