# BDMS Entity Relationship Diagram (ERD)

This document defines the final reviewed relational data model (**31 tables**: 3 Laravel baseline auth/session tables + 28 BDMS domain tables) for the **Blood Donation Management System (BDMS)**. It incorporates all resolved architectural, clinical-governance, inventory-traceability, and MySQL 8.4.3 / SQLite `:memory:` compatibility decisions.

> **Target Engine:** Laravel 13 (`database/migrations`) + MySQL 8.4.3 (`utf8mb4` / `utf8mb4_unicode_ci`, InnoDB) & SQLite `:memory:` (`phpunit.xml` test compatibility)  
> **Scope:** Design only (no migrations, models, controllers, or frontend files created/modified)

---

## 1. Complete System ERD

```mermaid
erDiagram
    users ||--o{ role_user : "assigned"
    roles ||--o{ role_user : "granted_to"
    roles ||--o{ permission_role : "includes"
    permissions ||--o{ permission_role : "assigned_to"
    users ||--o{ password_reset_tokens : "resets_password"
    users |o--o{ sessions : "holds"

    users |o--o| donors : "authenticates_optional"
    donors ||--o| donor_medical_profiles : "protects_1_to_1"
    users |o--o{ donor_medical_profiles : "updates"
    donors ||--o{ donor_eligibility_records : "audited_by"
    users |o--o{ donor_eligibility_records : "reviewed_by"

    users ||--o{ hospital_users : "acts_for"
    hospitals ||--o{ hospital_users : "employs"
    hospitals ||--o{ blood_requests : "submits"
    blood_components ||--o{ blood_requests : "specifies_optional"
    blood_components ||--o{ blood_compatibility_rules : "governs_component"

    blood_requests ||--o{ blood_request_status_histories : "logs_transitions"
    blood_requests ||--o{ inventory_allocations : "reserves_or_allocates"
    blood_requests ||--o{ request_fulfillments : "fulfilled_by"
    blood_components ||--o{ request_fulfillments : "dispatched_component"
    request_fulfillments ||--o{ inventory_allocations : "dispatches"

    blood_components ||--o{ inventory_stocks : "categorizes"
    inventory_stocks ||--o{ inventory_allocations : "sourced_from"
    inventory_stocks ||--o{ inventory_transactions : "ledger_for"

    donors ||--o{ donations : "donates"
    donor_appointments |o--o| donations : "results_in_optional"
    donation_camps |o--o{ donations : "hosts_optional"
    donation_centers |o--o{ donations : "hosts_optional"
    blood_components ||--o{ donations : "yields_type"

    blood_components ||--o{ component_screening_requirements : "configures_panels"
    screening_test_types ||--o{ component_screening_requirements : "required_by"
    donations ||--o{ screening_records : "tested_by"
    screening_test_types ||--o{ screening_records : "defines_panel"

    donations ||--o{ blood_units : "produces"
    blood_units |o--o{ blood_units : "derived_from_optional"
    blood_components ||--o{ blood_units : "classifies"
    blood_units |o--o{ inventory_allocations : "allocated_to_unit"
    blood_units |o--o{ inventory_transactions : "traced_by_unit"

    donors ||--o{ donor_appointments : "books"
    blood_components ||--o{ donor_appointments : "scheduled_type"
    donation_centers |o--o{ donor_appointments : "at_center_xor"
    donation_camps |o--o{ donor_appointments : "at_camp_xor"
    donor_appointments ||--o{ appointment_status_histories : "logs_changes"

    donation_camps ||--o{ camp_registrations : "registers"
    donors ||--o{ camp_registrations : "participates"
    donor_appointments |o--o| camp_registrations : "linked_from_portal"
    donation_camps ||--o{ camp_status_histories : "logs_changes"

    users |o--o{ hospitals : "verifies"
    users |o--o{ donations : "records_or_approves"
    users |o--o{ screening_records : "performs"
    users |o--o{ blood_units : "releases"
    users |o--o{ inventory_transactions : "executes"
    users |o--o{ blood_requests : "submits_or_reviews"
    users |o--o{ blood_request_status_histories : "actors_on"
    users |o--o{ request_fulfillments : "dispatches_or_overrides"
    users |o--o{ inventory_allocations : "allocates"
    users |o--o{ donation_camps : "creates"
    users |o--o{ camp_registrations : "logs"
    users |o--o{ camp_status_histories : "actors_on"
    users |o--o{ appointment_status_histories : "actors_on"
```

---

## 2. Domain-Focused ER Views

### 2.1 Identity, Sessions, RBAC, Donors & Clinical Governance

```mermaid
erDiagram
    users {
        bigint id PK
        varchar name
        varchar email UK
        timestamp email_verified_at
        varchar password
        boolean is_active
        timestamp last_login_at
    }

    password_reset_tokens {
        varchar email PK
        varchar token
        timestamp created_at
    }

    sessions {
        varchar id PK
        bigint user_id FK
        varchar ip_address
        text user_agent
        longtext payload
        int last_activity
    }

    roles {
        bigint id PK
        varchar name UK
        varchar slug UK
        varchar description
    }

    permissions {
        bigint id PK
        varchar name
        varchar slug UK
        varchar module
    }

    role_user {
        bigint user_id PK,FK
        bigint role_id PK,FK
        bigint assigned_by_user_id FK
        timestamp assigned_at
    }

    permission_role {
        bigint permission_id PK,FK
        bigint role_id PK,FK
    }

    donors {
        bigint id PK
        varchar donor_code UK
        bigint user_id UK,FK
        varchar full_name
        varchar email UK
        varchar phone
        enum gender
        date date_of_birth
        enum blood_group
        enum availability_status
        enum eligibility_status
        enum account_status
        date last_donation_date
        date next_eligible_date
        int total_completed_donations
        boolean profile_completed
    }

    donor_medical_profiles {
        bigint id PK
        bigint donor_id UK,FK
        text medical_notes
        decimal last_hemoglobin_g_dl
        decimal weight_kg
        text deferral_notes
        bigint updated_by_user_id FK
    }

    donor_eligibility_records {
        bigint id PK
        bigint donor_id FK
        enum eligibility_status
        text reason
        varchar clearance_source
        datetime reviewed_at
        date next_eligible_date
        bigint reviewed_by_user_id FK
        boolean is_current
        tinyint current_record_guard
    }

    users ||--o{ role_user : "has"
    users ||--o{ password_reset_tokens : "resets"
    users |o--o{ sessions : "holds"
    roles ||--o{ role_user : "assigned"
    roles ||--o{ permission_role : "has"
    permissions ||--o{ permission_role : "in"
    users |o--o| donors : "optional_account"
    donors ||--o| donor_medical_profiles : "clinical_notes"
    users |o--o{ donor_medical_profiles : "updates"
    donors ||--o{ donor_eligibility_records : "eligibility_log"
    users |o--o{ donor_eligibility_records : "reviewer"
```

### 2.2 Donations, Component Screening Matrix, Units & Inventory Ledger

```mermaid
erDiagram
    blood_components {
        bigint id PK
        varchar code UK
        varchar name UK
        int default_volume_ml
        int shelf_life_days
        varchar storage_requirements
    }

    screening_test_types {
        bigint id PK
        varchar code UK
        varchar name UK
        varchar description
        boolean is_active
    }

    component_screening_requirements {
        bigint id PK
        bigint blood_component_id FK
        bigint test_type_id FK
        boolean is_required
        boolean is_active
    }

    donations {
        bigint id PK
        varchar donation_code UK
        bigint donor_id FK
        bigint appointment_id UK,FK
        bigint camp_id FK
        bigint center_id FK
        bigint blood_component_id FK
        enum blood_group
        date donation_date
        datetime collected_at
        int quantity_ml
        enum collection_status
        enum screening_status
        datetime released_to_inventory_at
        bigint approved_by_user_id FK
    }

    screening_records {
        bigint id PK
        varchar screening_code UK
        bigint donation_id FK
        bigint test_type_id FK
        date test_date
        enum screening_result
        boolean is_confirmatory
        bigint performed_by_user_id FK
        varchar lab_staff_name
        varchar assay_method
        varchar specimen_tube_code
        datetime result_recorded_at
    }

    inventory_stocks {
        bigint id PK
        enum blood_group
        bigint blood_component_id FK
        int available_units
        int reserved_units
        int minimum_level
        int target_capacity
        datetime last_adjusted_at
    }

    blood_units {
        bigint id PK
        varchar unit_code UK
        bigint donation_id FK
        bigint parent_unit_id FK
        enum blood_group
        bigint blood_component_id FK
        int volume_ml
        varchar storage_location
        enum unit_status
        datetime collected_at
        datetime expires_at
        datetime released_at
        bigint released_by_user_id FK
    }

    inventory_transactions {
        bigint id PK
        varchar transaction_code UK
        bigint inventory_stock_id FK
        bigint blood_unit_id FK
        enum operation_type
        int units_delta
        int available_before
        int available_after
        int reserved_before
        int reserved_after
        varchar reference_type
        bigint reference_id
        bigint performed_by_user_id FK
        datetime occurred_at
    }

    blood_components ||--o{ component_screening_requirements : "defines_panel"
    screening_test_types ||--o{ component_screening_requirements : "mapped_to"
    blood_components ||--o{ donations : "component"
    donations ||--o{ screening_records : "screened_by"
    screening_test_types ||--o{ screening_records : "panel_type"
    donations ||--o{ blood_units : "yields"
    blood_units |o--o{ blood_units : "fractionated_into"
    blood_components ||--o{ blood_units : "classifies"
    blood_components ||--o{ inventory_stocks : "tracks"
    inventory_stocks ||--o{ inventory_transactions : "audits"
    blood_units |o--o{ inventory_transactions : "traces"
```

### 2.3 Hospitals, Compatibility Rules, Requests & Fulfillment

```mermaid
erDiagram
    hospitals {
        bigint id PK
        varchar hospital_code UK
        varchar name
        varchar address
        varchar city
        varchar primary_contact_name
        varchar primary_phone
        varchar primary_email
        enum verification_status
        bigint verified_by_user_id FK
        datetime verified_at
        boolean is_active
    }

    hospital_users {
        bigint id PK
        bigint hospital_id FK
        bigint user_id UK,FK
        varchar contact_name
        varchar department
        varchar job_title
        boolean is_primary_contact
    }

    blood_compatibility_rules {
        bigint id PK
        enum recipient_blood_group
        enum donor_blood_group
        bigint blood_component_id FK
        enum compatibility_level
        boolean requires_medical_override
        boolean is_active
    }

    blood_requests {
        bigint id PK
        varchar request_code UK
        bigint hospital_id FK
        bigint requested_by_user_id FK
        varchar department_or_ward
        enum blood_group
        bigint blood_component_id FK
        int requested_units
        int reserved_units
        int fulfilled_units
        enum priority
        enum status
        date request_date
        date required_date
        bigint reviewed_by_user_id FK
    }

    blood_request_status_histories {
        bigint id PK
        bigint blood_request_id FK
        varchar event_name
        enum previous_status
        enum new_status
        int units_affected
        bigint actor_user_id FK
        datetime occurred_at
    }

    inventory_allocations {
        bigint id PK
        varchar allocation_code UK
        bigint blood_request_id FK
        bigint inventory_stock_id FK
        bigint blood_unit_id FK
        bigint fulfillment_id FK
        int units_allocated
        enum allocation_status
        tinyint active_unit_guard
        bigint allocated_by_user_id FK
        datetime allocated_at
        datetime released_at
        datetime dispatched_at
    }

    request_fulfillments {
        bigint id PK
        varchar fulfillment_code UK
        bigint blood_request_id FK
        bigint dispatched_by_user_id FK
        enum dispatched_blood_group
        bigint blood_component_id FK
        int units_dispatched
        date dispatch_date
        datetime dispatched_at
        varchar courier_reference
        enum delivery_status
        boolean is_compatibility_override
        bigint override_approved_by_user_id FK
        datetime override_approved_at
        text override_reason
    }

    hospitals ||--o{ hospital_users : "staffed_by"
    hospitals ||--o{ blood_requests : "places"
    blood_requests ||--o{ blood_request_status_histories : "status_audit"
    blood_requests ||--o{ inventory_allocations : "holds_stock"
    blood_requests ||--o{ request_fulfillments : "fulfilled_by"
    request_fulfillments ||--o{ inventory_allocations : "dispatches"
```

### 2.4 Donation Centers, Camps, Registrations & Donor Appointments

```mermaid
erDiagram
    donation_centers {
        bigint id PK
        varchar center_code UK
        varchar name
        varchar address
        varchar city
        varchar operating_hours
        int default_slot_capacity
        boolean is_active
    }

    donation_camps {
        bigint id PK
        varchar camp_code UK
        varchar name
        varchar organizer_name
        varchar organizer_contact
        varchar organizer_email
        varchar venue_name
        varchar city
        date start_date
        date end_date
        time start_time
        time end_time
        int capacity
        date registration_deadline
        boolean allow_walk_in
        enum status
    }

    camp_registrations {
        bigint id PK
        varchar registration_code UK
        bigint camp_id FK
        bigint donor_id FK
        bigint appointment_id UK,FK
        date registration_date
        boolean is_walk_in
        enum attendance_status
        tinyint active_registration_guard
        datetime checked_in_at
        datetime cancelled_at
    }

    camp_status_histories {
        bigint id PK
        bigint camp_id FK
        varchar event_name
        enum previous_status
        enum new_status
        bigint actor_user_id FK
        datetime occurred_at
    }

    donor_appointments {
        bigint id PK
        varchar appointment_code UK
        bigint donor_id FK
        bigint donation_component_id FK
        bigint donation_center_id FK
        bigint donation_camp_id FK
        date appointment_date
        time slot_time
        tinyint active_slot_guard
        enum status
        datetime cancelled_at
    }

    appointment_status_histories {
        bigint id PK
        bigint appointment_id FK
        varchar event_name
        enum previous_status
        enum new_status
        bigint actor_user_id FK
        datetime occurred_at
    }

    donation_camps ||--o{ camp_registrations : "registers"
    donation_camps ||--o{ camp_status_histories : "audits"
    donation_centers |o--o{ donor_appointments : "center_visit"
    donation_camps |o--o{ donor_appointments : "camp_visit"
    donor_appointments |o--o| camp_registrations : "syncs"
    donor_appointments ||--o{ appointment_status_histories : "audits"
```

---

## 3. Relationship & Referential Integrity Matrix

| Relationship | Cardinality | Foreign Key Placement | Participation (Parent -> Child) | `ON DELETE` | `ON UPDATE` | Rationale |
|---|---|---|---|---|---|---|
| `users` → `role_user` ← `roles` | M:N | `role_user.user_id`, `role_user.role_id` | Optional → Mandatory | `CASCADE` (`user_id`) / `RESTRICT` (`role_id`) | `CASCADE` | Removing an unused user deletes role assignments; active roles cannot be dropped silently. |
| `roles` → `permission_role` ← `permissions` | M:N | `permission_role.role_id`, `permission_role.permission_id` | Optional → Mandatory | `CASCADE` | `CASCADE` | Standard permission-mapping pivot. |
| `users` → `password_reset_tokens` / `sessions` | 1:N | `password_reset_tokens.email` (PK) / `sessions.user_id` | Optional → Optional | Application / Session GC | — | Existing Laravel 13 auth & session tables. |
| `users` → `donors` | 1:0..1 | `donors.user_id` (`UNIQUE`) | Optional → Optional | `SET NULL` | `CASCADE` | Walk-in donors may lack logins; unlinking a login keeps clinical donor history intact. |
| `donors` → `donor_medical_profiles` | 1:0..1 | `donor_medical_profiles.donor_id` (`UNIQUE`) | Mandatory → Optional | `RESTRICT` | `CASCADE` | Isolates confidential clinical notes from public/portal queries. |
| `donors` → `donor_eligibility_records` | 1:N | `donor_eligibility_records.donor_id` | Mandatory → Mandatory | `RESTRICT` | `CASCADE` | Authoritative eligibility source of truth (`UNIQUE (donor_id, current_record_guard)` enforces one active row; `donors.eligibility_status` is a read-only synced cache). |
| `donors` → `donations` | 1:N | `donations.donor_id` | Mandatory → Optional | `RESTRICT` | `CASCADE` | Preserves immutable donation provenance; donors deactivate via `account_status`. |
| `donor_appointments` → `donations` | 1:0..1 | `donations.appointment_id` (`UNIQUE`) | Optional → Optional | `SET NULL` | `CASCADE` | Connects completed visits (e.g., `APT-2012` → `DON-4310`); walk-ins leave `NULL`. |
| `donation_camps` / `donation_centers` → `donations` | 1:N | `donations.camp_id`, `donations.center_id` | Optional → Optional | `RESTRICT` | `CASCADE` | Traces collection facilities without blocking ad-hoc legacy location labels. |
| `blood_components` → `component_screening_requirements` ← `screening_test_types` | M:N | `blood_component_id`, `test_type_id` | Mandatory → Optional | `RESTRICT` | `CASCADE` | Seeded mandatory for all 5 current assays (`HIV`, `HBV`, `HCV`, `Syphilis`, `Compatibility`) across all components; extensible for future policies. |
| `donations` → `screening_records` | 1:N | `screening_records.donation_id` | Mandatory → Optional | `RESTRICT` | `CASCADE` | One donation carries multiple assay panels (`HIV`, `HBV`, `HCV`, `Syphilis`, `Compatibility`). |
| `screening_test_types` → `screening_records` | 1:N | `screening_records.test_type_id` | Mandatory → Mandatory | `RESTRICT` | `CASCADE` | Controlled panel taxonomy. |
| `donations` → `blood_units` | 1:N | `blood_units.donation_id` | Mandatory → Optional | `RESTRICT` | `CASCADE` | Supports both 1-donation-to-1-unit and future multi-component fractionation (`parent_unit_id`). |
| `blood_units` → `blood_units` (Self) | 1:N | `blood_units.parent_unit_id` | Optional → Optional | `SET NULL` | `CASCADE` | Traces derived component bags back to parent Whole Blood draw bag. |
| `blood_components` → `inventory_stocks` | 1:N | `inventory_stocks.blood_component_id` | Mandatory → Mandatory | `RESTRICT` | `CASCADE` | Tracks `(blood_group, blood_component_id)` stock while aggregating clean 8-group totals. |
| `inventory_stocks` → `inventory_transactions` | 1:N | `inventory_transactions.inventory_stock_id` | Mandatory → Mandatory | `RESTRICT` | `CASCADE` | Immutable audit ledger for every stock change. |
| `blood_units` → `inventory_transactions` | 1:N | `inventory_transactions.blood_unit_id` | Optional → Optional | `RESTRICT` | `CASCADE` | Bag-level traceability inside movement ledger. |
| `blood_components` → `blood_compatibility_rules` | 1:N | `blood_compatibility_rules.blood_component_id` | Mandatory → Optional | `RESTRICT` | `CASCADE` | Component-specific substitution matrix (`RBC` vs `Plasma` differ clinically). |
| `hospitals` → `hospital_users` ← `users` | 1:N / 1:1 | `hospital_users.hospital_id`, `hospital_users.user_id` (`UNIQUE`) | Mandatory → Mandatory | `RESTRICT` (`hospital`) / `CASCADE` (`user`) | `CASCADE` | Separates hospital facilities (`HSP-2049`) and verification gates from user credentials. |
| `hospitals` → `blood_requests` | 1:N | `blood_requests.hospital_id` | Mandatory → Mandatory | `RESTRICT` | `CASCADE` | Normalized requester ownership; blocks unverified facilities in backend service/policy. |
| `blood_requests` → `blood_request_status_histories` | 1:N | `blood_request_status_histories.blood_request_id` | Mandatory → Mandatory | `RESTRICT` | `CASCADE` | Complete request audit history. |
| `blood_requests` → `inventory_allocations` | 1:N | `inventory_allocations.blood_request_id` | Mandatory → Mandatory | `RESTRICT` | `CASCADE` | Explicit unit reservations vs dispatches via `active_unit_guard`. |
| `inventory_stocks` / `blood_units` → `inventory_allocations` | 1:N | `inventory_stock_id`, `blood_unit_id` | Mandatory → Optional | `RESTRICT` | `CASCADE` | Links reserved/dispatched bags cleanly; prevents concurrent multi-request allocation. |
| `blood_requests` → `request_fulfillments` | 1:N | `request_fulfillments.blood_request_id` | Mandatory → Mandatory | `RESTRICT` | `CASCADE` | Supports multiple partial dispatches (`Partially Fulfilled` → `Fulfilled`) and medical override tracking. |
| `request_fulfillments` → `inventory_allocations` | 1:N | `inventory_allocations.fulfillment_id` | Optional → Optional | `RESTRICT` | `CASCADE` | Connects physical courier batch to specific dispatched `blood_units`. |
| `donation_camps` → `camp_registrations` ← `donors` | 1:N / 1:N | `camp_id`, `donor_id` | Mandatory → Optional | `RESTRICT` | `CASCADE` | Guarded by `UNIQUE (camp_id, donor_id, active_registration_guard)` so `'Cancelled'` rows stay archived while re-registration works. |
| `donation_camps` → `camp_status_histories` | 1:N | `camp_status_histories.camp_id` | Mandatory → Mandatory | `RESTRICT` | `CASCADE` | Camp status & roster audit events. |
| `donors` / `donation_centers` / `donation_camps` → `donor_appointments` | 1:N | `donor_id`, `donation_center_id`, `donation_camp_id` | Mandatory/Optional → Optional | `RESTRICT` | `CASCADE` | Booking requires `donors.profile_completed = 1`; guarded by XOR location check + `UNIQUE (donor_id, appointment_date, slot_time, active_slot_guard)`. |
| `donor_appointments` → `camp_registrations` | 1:0..1 | `camp_registrations.appointment_id` (`UNIQUE`) | Optional → Optional | `SET NULL` | `CASCADE` | Syncs Donor Portal camp bookings (`APT-2041` ↔ `REG-801`). |
| `donor_appointments` → `appointment_status_histories` | 1:N | `appointment_status_histories.appointment_id` | Mandatory → Mandatory | `RESTRICT` | `CASCADE` | Booking status audit trail. |
