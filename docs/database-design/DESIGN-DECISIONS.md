# BDMS Database Design Decisions, Trade-offs & Open Questions

This document captures the architectural rationale, alternative approaches considered, security protections, and unresolved domain ambiguities identified by inspecting every BDMS controller, view, route, and JavaScript module.

---

## 1. Key Architectural Decisions

### Decision 1: Surrogate `BIGINT` Primary Keys + Unique Business Reference Codes
- **Choice**: Use `BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY` (`id`) on all entities while storing human-readable codes (`donor_code = 'DNR-1041'`, `request_code = 'REQ-1044'`, `camp_code = 'CMP-308'`) as indexed `UNIQUE VARCHAR(32)` columns.
- **Alternative Considered**: Using string business codes (`DNR-1041`) directly as primary keys across foreign keys, or UUID primary keys.
- **Why Chosen**: InnoDB clusters rows on the primary key and stores the PK inside every secondary index. Compact 8-byte `BIGINT` keys minimize index bloat, speed joins across `blood_units`, `inventory_allocations`, and `inventory_transactions`, and shield foreign keys from formatting changes while Laravel route model binding (`getRouteKeyName()` -> `'donor_code'`) preserves `/donors/DNR-1041` URLs cleanly.

### Decision 2: Dual-Layer Inventory Model (`inventory_stocks` + `blood_units` + `inventory_transactions`)
- **Choice**: Track aggregated stock balances (`inventory_stocks` keyed by `(blood_group, blood_component_id)`), serialized individual blood bags (`blood_units`), and an immutable append-only movement ledger (`inventory_transactions`).
- **Alternative Considered**: Storing only `available` and `reserved` integer counters per blood group (like the current frontend `InventoryController` preview).
- **Why Chosen**: A simple quantity table cannot enforce donation-to-bag provenance, prevent unscreened/quarantined units from entering stock, track per-bag expiration dates for 7-day expiry alerts (`DashboardController`), perform FIFO allocation, or trace recalls back to `DON-xxxx`. Meanwhile, keeping `inventory_stocks` alongside `blood_units` provides an `O(1)` read summary for the Public Portal and acts as the single deterministic row lock target (`SELECT ... FOR UPDATE`) to serialize concurrent reservations and dispatches cleanly.

### Decision 3: Separating `inventory_allocations` (Holds & Unit Mapping) from `request_fulfillments` (Dispatches)
- **Choice**: Maintain `inventory_allocations` (bag-level `Reserved` / `Dispatched` / `Released` states) separately from `request_fulfillments` (delivery batches with courier code `#TR-208` and delivery sign-off).
- **Alternative Considered**: Decrementing inventory immediately on request approval without an intermediate allocation table.
- **Why Chosen**: Approved orders such as `REQ-1038` (*"B+ units reserved pending courier departure"*) hold stock before physical dispatch, and partially fulfilled orders (`REQ-1043`) need multiple dispatch batches over time. Keeping `inventory_allocations` distinguishes unallocated `available_units`, held `reserved_units`, and physically couriered `Dispatched` units—and cleanly releases holds back to available stock if a request is cancelled (`REQ-1036`).

### Decision 4: Normalizing `hospitals` & `hospital_users` Out of `blood_requests`
- **Choice**: Extract `hospitals` and `hospital_users` out of flat request rows, while retaining lightweight point-in-time requester snapshots (`contact_person_snapshot`, `contact_phone_snapshot`) on `blood_requests`.
- **Alternative Considered**: Leaving `hospitalName`, `hospitalAddress`, `contactPerson`, and `email` directly on `blood_requests`.
- **Why Chosen**: Repeating facility data across requests violates Third Normal Form (3NF) and creates update anomalies when hospital addresses or transfusion lab emails change. Retaining explicit contact snapshot columns on the order ensures historical cold-chain audit logs remain accurate even if a hospital coordinator later changes jobs.

### Decision 5: Separating Sensitive Medical & Auth Data (`users` / `donors` / `donor_medical_profiles` / `donor_eligibility_records`)
- **Choice**: Split authentication (`users` + RBAC tables), demographic/portal donor data (`donors`), confidential medical notes & biometrics (`donor_medical_profiles`), and audited eligibility decisions (`donor_eligibility_records`).
- **Alternative Considered**: Storing `medicalNotes`, login credentials, and portal fields in one monolithic `donors` table.
- **Why Chosen**: Supports BDMS privacy boundaries strictly. Staff walk-in donors (`donors.user_id = NULL`) can donate without portal login credentials; public pages never touch clinical tables; Donor Portal queries mask contacts and omit clinician `medical_notes`; and only users holding `donors.view_medical` permission query `donor_medical_profiles`.

### Decision 6: Generated-Column Partial Unique Guards in MySQL 8
- **Choice**: Enforce zero double-booked active donor appointments (`active_slot_guard` on `donor_appointments`) and zero double-allocated blood bags (`active_unit_guard` on `inventory_allocations`) using MySQL 8 `GENERATED ALWAYS AS (...) STORED` columns combined with `UNIQUE` indexes.
- **Alternative Considered**: Relying purely on application-level `EXISTS` queries or complex MySQL triggers.
- **Why Chosen**: Application-level checks alone suffer from TOCTOU race conditions under concurrent requests, whereas triggers complicate Laravel testing and migrations. Generated columns leverage MySQL's `UNIQUE` index semantics (`NULL` values allowed multiple times) natively, allowing cancelled slots or released units to be re-allocated cleanly while mathematically blocking active conflicts at the engine level.

### Decision 7: Status-Based Archival & `ON DELETE RESTRICT` Over Unchecked `SoftDeletes`
- **Choice**: Prohibit physical deletion on operational history (`ON DELETE RESTRICT`) and avoid `deleted_at` on regulated clinical/audit tables (`donations`, `screening_records`, `blood_units`, `inventory_transactions`, `request_fulfillments`, `*_status_histories`), using explicit lifecycle states (`account_status = 'Inactive'`, `is_active = 0`, `'Cancelled'`) instead.
- **Alternative Considered**: Adding Laravel `SoftDeletes` (`deleted_at`) globally across all tables.
- **Why Chosen**: Clinical blood-bank records are legally auditable and referenced by downstream provenance chains; soft-deleting a `donations` or `donors` row breaks MySQL `UNIQUE` constraints on `email`/`donor_code` on re-registration and risks orphaned inventory trace queries. Deactivation flags preserve referential integrity cleanly.

### Decision 8: Optional Profile Fields on `donors` Until Intake Completion
- **Choice**: Make `donors.phone`, `gender`, `date_of_birth`, `blood_group`, `address`, and `emergency_contact` nullable at the database level paired with `profile_completed` (`TINYINT(1)`), while staff `FormRequest` validation strictly enforces them for clinical intake.
- **Alternative Considered**: Enforcing `NOT NULL` on all `donors` columns immediately at `auth/register`.
- **Why Chosen**: Inspection of `auth/register.blade.php` revealed that public self-registration requires only `name`, `email`, and `password` (leaving `blood_group` and `date_of_birth` optional, and omitting `phone`, `gender`, and `address`), whereas staff `donors/_form.blade.php` requires all contact and demographic fields. This design supports both onboarding paths cleanly without failing DB inserts.

---

## 2. Schema Review & Validation Summary

- **Normalization (3NF/BCNF)**: Facility attributes (`hospitals`, `donation_centers`), lookup taxonomies (`blood_components`, `screening_test_types`, `roles`, `permissions`), and longitudinal event histories (`*_status_histories`, `inventory_transactions`, `donor_eligibility_records`) are cleanly separated with zero repeating groups or transitive dependencies.
- **Foreign Key Consistency**: All surrogate PKs (`BIGINT UNSIGNED`) match child FK signedness (`UNSIGNED`) across every table for MySQL 8 InnoDB compatibility. `SET NULL` is used strictly on optional user/actor/appointment references; `RESTRICT` protects financial/clinical parent records.
- **Coverage of Existing Frontend Workflows**:
  - Inventory adjustments, status thresholds, and history logs -> `inventory_stocks`, `blood_units`, `inventory_transactions`.
  - Donor registration, clinical review, medical notes, and portal masked summaries -> `donors`, `donor_medical_profiles`, `donor_eligibility_records`.
  - Multi-panel lab screening per donation -> `screening_test_types`, `screening_records`.
  - Partial/full request fulfillment & rejection/cancellation -> `hospitals`, `blood_requests`, `inventory_allocations`, `request_fulfillments`, `blood_request_status_histories`.
  - Camps, walk-ins, attendance updates, donor appointments -> `donation_centers`, `donation_camps`, `camp_registrations`, `camp_status_histories`, `donor_appointments`, `appointment_status_histories`.
  - Public Portal filtered projections -> `inventory_stocks`, `donation_camps` (strictly `Upcoming`/`Ongoing`, sensitive columns excluded).

---

## 3. Unresolved Requirements & Ambiguities Requiring Product Confirmation

Inspection of existing controllers, views, and JS modules surfaced **8 domain ambiguities** intentionally documented here rather than inventing hidden rules:

1. **Component-Level Inventory Granularity vs. 8-Group Totals**  
   `InventoryController`, `PublicPortalController`, and `BloodRequestsController` currently track and request units strictly across the 8 ABO/Rh groups without selecting component type (`Whole Blood`, `Packed RBC`, `Platelets`, `Plasma`), whereas `DonationsController`, `DonorPortalController`, and request clinical notes (`REQ-1044`, `DashboardController`) specify components. **Question**: Should future inventory UI cards and blood request forms require selecting both `(blood_group, blood_component_id)`? *(Schema supports both: `blood_requests.blood_component_id` is nullable while `inventory_stocks` tracks `(blood_group, blood_component_id)`.)*

2. **Cross-Group Compatibility Dispatch Policy (`requests/show.blade.php`)**  
   The fulfillment UI allows staff to select a dispatch blood group different from the requested blood group (e.g., emergency `O-` substitution). **Question**: Should the backend enforce a strict clinical ABO/Rh compatibility matrix (and warn or require medical officer override), or mandate exact blood-group matching except in `Critical` emergencies?

3. **Camp Re-Registration After Prior Cancellation (`camps.js` vs. `DonorPortalController`)**  
   In `camps.js`, `evaluateRegistrationEligibility()` blocks any `donorId` present in `registrations`—even if their `attendanceStatus` is `'Cancelled'`. Conversely, `DonorPortalController::buildDonorCamps()` excludes `'Cancelled'` rows when computing `donorAlreadyRegistered`. **Question**: If a donor cancels a camp registration, should they be allowed to re-register for that camp (reactivating the `camp_registrations` row) or remain permanently blocked?

4. **Mandatory Screening Panel Gate Before Unit Release**  
   Sample donation `DON-4403` displays a single `'Passed'` `Hepatitis C` screening record, while `DON-4405` has separate `HIV` and `Hepatitis B` rows. **Question**: Must every donation pass all 5 `screening_test_types` (`HIV`, `Hepatitis B`, `Hepatitis C`, `Syphilis`, `Blood Group Compatibility`) before releasing inventory, or do certain component types (`Plasma` vs `Whole Blood`) use distinct panel subsets?

5. **Unit Yield Mapping (`quantity_ml` vs. Integer `units`)**  
   `donations` collects volume in milliliters (`150`–`800` ml), whereas `inventory_stocks` and `blood_requests` count integer `units` (`bags`). **Question**: Does 1 completed donation always yield `1` blood unit in `blood_units`, or can 1 Whole Blood draw be component-split into multiple derived units (e.g., 1 `Packed RBC` + 1 `Plasma` + 1 `Platelets`)? *(Supporting 1-to-Many `donations → blood_units` already supports either rule cleanly!)*

6. **Incomplete Self-Registered Donors vs. Intake Requirements**  
   `/register` leaves `blood_group` and `date_of_birth` optional for donor accounts and omits `phone`, `gender`, `address`, and `emergency_contact`, while staff donor forms require all of them. **Question**: Should self-registered donors be routed through an onboarding profile-completion step before booking appointments, or only required to complete clinical intake details on arrival?

7. **Self-Registered Hospital Verification Gate**  
   `/register` allows selecting `Hospital Requester` and typing an arbitrary `organization_name` and `facility_code` (`HSP-2049`). **Question**: Should self-registered hospital accounts default to `hospitals.verification_status = 'Pending'` (blocking blood request submission until approved by a Blood Bank Administrator), or be provisioned exclusively by staff admins?

8. **Time-Slot Capacity Model for Donation Camps vs. Fixed Centers**  
   `DonorPortalController::sampleTimeSlots()` enforces per-hour slot capacities (`capacity = 3` at `09:00 AM`..`03:30 PM`), whereas `donation_camps` defines only an overall event `capacity` without per-slot sub-capacities. **Question**: Should camp bookings share the same fixed clinic hourly slot grid, use camp-specific time slots, or reserve strictly against total camp `capacity`?

