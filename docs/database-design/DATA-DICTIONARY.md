# BDMS Data Dictionary

This Data Dictionary documents the domain purpose, sensitivity classification, important columns, human-readable business code patterns, and exact enumeration values across all **29 tables** of the Blood Donation Management System (BDMS).

---

## 1. Human-Readable Business Identifiers

Every operational record carries a surrogate `BIGINT UNSIGNED` primary key (`id`) plus an indexed `UNIQUE` business reference code aligned with existing BDMS URLs and views:

| Table | Code Column | Observed / Target Pattern | Examples in BDMS |
|---|---|---|---|
| `donors` | `donor_code` | `DNR-XXXX` | `DNR-1041` – `DNR-1048` |
| `donations` | `donation_code` | `DON-XXXX` | `DON-4012` – `DON-4405` |
| `screening_records` | `screening_code` | `SCR-XXXX` | `SCR-7096` – `SCR-7105` |
| `blood_units` | `unit_code` | `UNIT-YYYY-XXXXXX` | `UNIT-2026-004405` *(new unit tracking ID)* |
| `inventory_transactions` | `transaction_code` | `TXN-XXXX` | `TXN-101` – `TXN-106` *(replaces integer sample IDs `101`–`106`)* |
| `hospitals` | `hospital_code` | `HSP-XXXX` | `HSP-2049` (`auth/register.blade.php` placeholder) |
| `blood_requests` | `request_code` | `REQ-XXXX` | `REQ-1035` – `REQ-1044` |
| `inventory_allocations` | `allocation_code` | `ALC-XXXX` | `ALC-3001` |
| `request_fulfillments` | `fulfillment_code` | `FUL-XXXX` | `FUL-5001` *(courier refs like `#TR-208` stored separately)* |
| `donation_centers` | `center_code` | `CTR-*` | `CTR-CENTRAL`, `CTR-WEST`, `CTR-SOUTH` |
| `donation_camps` | `camp_code` | `CMP-XXX` | `CMP-301` – `CMP-308` |
| `camp_registrations` | `registration_code` | `REG-XXX` | `REG-731` – `REG-805` |
| `donor_appointments` | `appointment_code` | `APT-XXXX` | `APT-1962` – `APT-2041` |

---

## 2. Data Classification & Visibility Levels

| Classification | Meaning | Accessible To |
|---|---|---|
| **Public-Safe (Projected)** | Non-identifying supply or published drive schedule metrics (with staff PII & donor rosters stripped). | Public Portal (`/`, `/blood-stock`, `/blood-camps`, `/blood-camps/{camp}`) |
| **Donor-Self Scoped** | Donor profile (contact masked on portal header), own appointments, eligibility decision summary, and safe donation history. | Authenticated Donor (`user_id` match) + Authorized Staff |
| **Hospital-Self Scoped** | Own facility details, request queue, fulfillment delivery tracking, and aggregate supply availability. | Linked `hospital_users` + Authorized Staff |
| **Staff / Operational** | Donor directory, donation logs, request review queues, camp attendance rosters, inventory reservations. | Blood Bank Staff & Administrators |
| **Clinical-Restricted (PHI)** | Clinical deferral notes, hemoglobin/weight measurements, serology test assay notes, medical history. | Authorized Medical / Lab / Staff Roles Only (`donors.view_medical`, `screening.manage`) |
| **Auth / Security** | Credentials, password reset tokens, session payloads, RBAC permission graphs. | Authentication Subsystem / Admin Only |

---

## 3. Master Catalog of Allowed Statuses & Enumerations

### 3.1 Blood Groups (All Inventory, Donors, Donations, Requests, Fulfillments)
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
| `donors.gender` | `'Female'`, `'Male'`, `'Other'` | `NULL` *(until profile complete)* | Staff form requires non-null |
| `donors.availability_status` | `'Available'`, `'Temporarily Unavailable'`, `'Inactive'` | `'Available'` | Outreach readiness toggle |
| `donors.eligibility_status` & `donor_eligibility_records.eligibility_status` | `'Eligible'`, `'Not Eligible'`, `'Pending Review'`, `'Unknown'` | `'Pending Review'` | `Unknown` used when sample/unverified record lacks clinical status |
| `donors.account_status` | `'Active'`, `'Inactive'` | `'Active'` | Administrative deactivation without deleting history |
| `donations.collection_status` | `'Scheduled'`, `'Completed'`, `'Cancelled'` | — | Physical draw outcome |
| `donations.screening_status` | `'Pending'`, `'Passed'`, `'Failed'` | `'Pending'` | Gate status aggregated from lab panels; must be `Passed` before release |
| `screening_records.screening_result` | `'Pending'`, `'Passed'`, `'Failed'` | `'Pending'` | Never auto-defaulted to `Passed` |
| `screening_test_types.name` | `'HIV'`, `'Hepatitis B'`, `'Hepatitis C'`, `'Syphilis'`, `'Blood Group Compatibility'` | — | Extensible catalog table |
| `blood_units.unit_status` | `'Quarantined'`, `'Available'`, `'Reserved'`, `'Dispatched'`, `'Transfused'`, `'Expired'`, `'Discarded'` | `'Quarantined'` | Strictly controls allocatable bags |
| `inventory_transactions.operation_type` | `'Stock In'`, `'Reserve'`, `'Release Reservation'`, `'Dispatch'`, `'Removed'`, `'Expired'`, `'Discarded'`, `'Manual Adjustment'` | — | Traces every `+N`/`-N` movement |
| `hospitals.verification_status` | `'Pending'`, `'Verified'`, `'Suspended'` | `'Pending'` | Supports admin vetting of `/register` hospital accounts |
| `blood_requests.priority` | `'Low'`, `'Normal'`, `'High'`, `'Critical'` | — | Triage sorting |
| `blood_requests.status` | `'Pending'`, `'Approved'`, `'Rejected'`, `'Partially Fulfilled'`, `'Fulfilled'`, `'Cancelled'` | `'Pending'` | `Rejected` and `Cancelled` are terminal |
| `request_fulfillments.delivery_status` | `'Dispatched'`, `'In Transit'`, `'Delivered'`, `'Returned'` | `'Dispatched'` | Cold-chain courier tracking |
| `inventory_allocations.allocation_status` | `'Reserved'`, `'Dispatched'`, `'Released'`, `'Cancelled'` | `'Reserved'` | Reservation hold vs physical dispatch vs returned |
| `donation_camps.status` | `'Draft'`, `'Upcoming'`, `'Ongoing'`, `'Completed'`, `'Cancelled'` | `'Draft'` | Public pages show `Upcoming` & `Ongoing` only |
| `camp_registrations.attendance_status` | `'Registered'`, `'Confirmed'`, `'Attended'`, `'No-show'`, `'Cancelled'` | `'Registered'` | Only non-cancelled statuses consume capacity |
| `donor_appointments.status` | `'Pending'`, `'Confirmed'`, `'Completed'`, `'Cancelled'` | `'Pending'` | Upcoming badge checks `Pending`/`Confirmed` & `date >= today` |

*Note on Derived Inventory Stock Status:* Consistent with `InventoryController::resolveStatus()`, `inventory_stocks` does **not** store a redundant `status` column that can drift out of sync. Stock status is always computed deterministically from `(available_units, minimum_level)`:
- `critical` when `available_units <= floor(minimum_level * 0.6)`
- `low` when `available_units <= minimum_level`
- `normal` when `available_units > minimum_level`

---

## 4. Table-by-Table Purpose & Key Column Guide

### 4.1 Identity & RBAC (`users`, `password_reset_tokens`, `sessions`, `roles`, `permissions`, `role_user`, `permission_role`)
- **`users`**: Authentication credentials (`email`, `password`), account activity (`is_active`, `last_login_at`). Deliberately omits donor medical attributes or hospital facility attributes so auth logic stays decoupled from domain profiles. *(Auth / Security)*
- **`roles` & `permissions`**: Role definitions (`admin`, `staff`, `lab_officer`, `hospital`, `donor`) and granular capabilities (e.g., `inventory.adjust`, `donors.view_medical`, `screening.record`, `requests.fulfill`). *(Auth / Security)*
- **`role_user` & `permission_role`**: Join tables assigning multi-role capabilities without schema duplication. *(Auth / Security)*

### 4.2 Donors & Clinical Governance (`donors`, `donor_medical_profiles`, `donor_eligibility_records`)
- **`donors`**: Core demographic/contact donor profile (`donor_code`, `full_name`, `email`, `phone`, `gender`, `date_of_birth`, `blood_group`), link to optional portal login (`user_id`), administrative readiness (`availability_status`, `account_status`), and cached summary fields (`eligibility_status`, `last_donation_date`, `next_eligible_date`, `total_completed_donations`) optimized for directory filtering and portal headers. *(Donor-Self / Staff)*
- **`donor_medical_profiles`**: Isolated 1:1 clinical extension holding confidential medical notes (`medical_notes`), biometric measurements (`last_hemoglobin_g_dl`, `weight_kg`), and specific deferral descriptions (`deferral_notes`). Queries for Public Portal or standard Donor Portal overviews never join this table. *(Clinical-Restricted)*
- **`donor_eligibility_records`**: Immutable/historical record of medical clearance decisions (`eligibility_status`, `reason`, `clearance_source`, `reviewed_at`, `next_eligible_date`, `reviewed_by_user_id`), preserving audit accountability for why a donor was cleared or deferred. *(Donor-Self summary / Clinical-Restricted)*

### 4.3 Collections & Lab Screening (`blood_components`, `donations`, `screening_test_types`, `screening_records`)
- **`blood_components`**: Reference table defining collection components, standard target volumes (`quantity_ml` baseline), and shelf lives (`shelf_life_days`) needed for unit expiration tracking. *(Public-Safe reference / Staff)*
- **`donations`**: Individual blood draw event (`donation_code`) tying a `donor_id` to collection time, component type, location (`camp_id`, `center_id`, `collection_location_name`), optional scheduled `appointment_id`, volume collected (`quantity_ml`), `collection_status`, `screening_status`, and manual inventory release approval (`released_to_inventory_at`, `approved_by_user_id`). *(Staff / Donor-Self safe subset)*
- **`screening_test_types`**: Controlled lookup of infectious disease and compatibility assays (`HIV`, `Hepatitis B`, `Hepatitis C`, `Syphilis`, `Blood Group Compatibility`). *(Staff)*
- **`screening_records`**: Individual laboratory assay result (`screening_code`) linked to a single `donation_id` and `test_type_id`. Tracks `specimen_tube_code`, `assay_method`, `test_date`, `screening_result`, `is_confirmatory`, `lab_staff_name`, and specimen lifecycle timestamps (`collected_at`, `logged_at`, `result_recorded_at`). *(Clinical-Restricted)*

### 4.4 Inventory & Provenance (`inventory_stocks`, `blood_units`, `inventory_transactions`)
- **`inventory_stocks`**: Real-time aggregate balances (`available_units`, `reserved_units`) and thresholds (`minimum_level`, `target_capacity`) keyed by `(blood_group, blood_component_id)`. Serves both fast dashboard/public aggregation queries and `SELECT ... FOR UPDATE` row-level concurrency locks. *(Public-Safe `available_units` only; Staff full view including `reserved_units`)*
- **`blood_units`**: Serialized blood bag inventory table (`unit_code`), guaranteeing full bag-level traceability back to `donations.id`, shelf-life monitoring (`expires_at`), cold-chain storage bay (`storage_location`), and strict lifecycle state (`Quarantined` through `Dispatched` / `Expired`). *(Staff)*
- **`inventory_transactions`**: Append-only financial-grade stock ledger recording every stock delta (`units_delta`), exact before/after balances (`available_before/after`, `reserved_before/after`), related domain reference (`reference_type`, `reference_id`), `performed_by_user_id`, and justification `description`. *(Staff Audit)*

### 4.5 Hospitals & Request Fulfillment (`hospitals`, `hospital_users`, `blood_requests`, `blood_request_status_histories`, `request_fulfillments`, `inventory_allocations`)
- **`hospitals`**: Normalized hospital directory (`hospital_code`, `name`, `address`, `city`, `primary_contact_name/phone/email`, `verification_status`). Removes duplicated hospital contact fields from individual blood requests. *(Hospital-Self / Staff)*
- **`hospital_users`**: Maps user login accounts (`users`) to partner hospitals with ward/department (`department`), role title (`job_title`), and primary contact designation. *(Auth / Hospital-Self / Staff)*
- **`blood_requests`**: Clinical demand order (`request_code`) tracking requested vs reserved vs fulfilled units (`requested_units`, `reserved_units`, `fulfilled_units`), urgency (`priority`), dates (`request_date`, `required_date`), snapshot requester contact at order submission, and closure metadata (`rejection_reason`, `cancellation_reason`). *(Hospital-Self / Staff)*
- **`blood_request_status_histories`**: Audit timeline of every submission, approval, partial dispatch, completion, rejection, or cancellation event on a request (`units_affected`, `actor_name`, `notes`, `occurred_at`). *(Hospital-Self / Staff)*
- **`request_fulfillments`**: Physical cold-chain dispatch record (`fulfillment_code`) capturing actual dispatched group (`dispatched_blood_group`), component, quantity (`units_dispatched`), courier ID (`courier_reference`), and hospital receipt status (`delivery_status`, `delivered_at`, `received_by_name`). *(Hospital-Self / Staff)*
- **`inventory_allocations`**: Granular link tying specific `blood_units` (`blood_unit_id`) and `inventory_stocks` (`inventory_stock_id`) to a `blood_request_id` (`Reserved` while awaiting dispatch, transitioning to `Dispatched` + linked `fulfillment_id` upon courier handoff, or `Released`/`Cancelled` if unassigned). *(Staff)*

### 4.6 Camps, Centers & Donor Appointments (`donation_centers`, `donation_camps`, `camp_registrations`, `camp_status_histories`, `donor_appointments`, `appointment_status_histories`)
- **`donation_centers`**: Fixed permanent clinic facilities (`CTR-CENTRAL`, `CTR-WEST`, `CTR-SOUTH`) with operating hours and per-timeslot concurrency capacity (`default_slot_capacity`). *(Public-Safe / Donor-Self / Staff)*
- **`donation_camps`**: Scheduled mobile/community drives (`CMP-*`) containing public schedule/venue metadata (`venue_name`, `full_address`, `city`, `start_date`, `end_date`, `start_time`, `end_time`, `capacity`, `registration_deadline`, `allow_walk_in`, `donor_requirements`) plus internal coordinator fields (`organizer_name/contact/email`) omitted from public queries. *(Public-Safe subset when `Upcoming`/`Ongoing`; full fields Staff-only)*
- **`camp_registrations`**: Donor enrollment & event-day check-in ledger (`REG-*`) linking `camp_id`, `donor_id`, optional portal `appointment_id`, `is_walk_in`, and `attendance_status`. *(Staff / Donor-Self presence check only)*
- **`camp_status_histories`**: Audit trail of camp schedule publications, openings, walk-in registrations/events, completions, and cancellations. *(Staff)*
- **`donor_appointments`**: Donor scheduling record (`APT-*`) mutually exclusively bound to either a `donation_center_id` or a `donation_camp_id` (`appointment_date`, `slot_time`, `status`, `notes`, `prep_summary`), protected by a database-enforced active-slot uniqueness guard. *(Donor-Self / Staff)*
- **`appointment_status_histories`**: Longitudinal record of donor booking confirmations, check-in completions, and cancellations. *(Donor-Self / Staff)*

