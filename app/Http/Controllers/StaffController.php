<?php

namespace App\Http\Controllers;

use App\Models\Staff;
use App\Models\User;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

class StaffController extends Controller
{
    /**
     * Display the staff management directory with search, filters, and summary metrics.
     */
    public function index(Request $request): View
    {
        Gate::authorize('viewAny', Staff::class);

        $summary = [
            'totalStaff' => Staff::query()->count(),
            'activeStaff' => Staff::query()->where('status', 'Active')->count(),
            'inactiveStaff' => Staff::query()->where('status', 'Inactive')->count(),
            'departmentsCount' => (int) Staff::query()->distinct()->count('department'),
        ];

        $query = Staff::query()
            ->with('user')
            ->withCount('donations');

        if ($request->filled('search')) {
            $term = trim((string) $request->query('search'));
            $query->where(function (Builder $builder) use ($term): void {
                $builder->where('full_name', 'like', "%{$term}%")
                    ->orWhere('employee_id', 'like', "%{$term}%")
                    ->orWhere('phone', 'like', "%{$term}%")
                    ->orWhere('email', 'like', "%{$term}%");
            });
        }

        if ($request->filled('department') && $request->query('department') !== 'all') {
            $department = (string) $request->query('department');
            if (in_array($department, Staff::DEPARTMENTS, true)) {
                $query->where('department', $department);
            }
        }

        if ($request->filled('professional_role') && $request->query('professional_role') !== 'all') {
            $role = (string) $request->query('professional_role');
            if (in_array($role, Staff::PROFESSIONAL_ROLES, true)) {
                $query->where('professional_role', $role);
            }
        }

        if ($request->filled('status') && $request->query('status') !== 'all') {
            $status = (string) $request->query('status');
            if (in_array($status, Staff::STATUSES, true)) {
                $query->where('status', $status);
            }
        }

        $staffMembers = $query
            ->orderByRaw("CASE WHEN status = 'Active' THEN 0 ELSE 1 END")
            ->orderBy('full_name')
            ->orderByDesc('id')
            ->paginate(15)
            ->withQueryString();

        return view('staff.index', [
            'staffMembers' => $staffMembers,
            'summary' => $summary,
            'departments' => Staff::DEPARTMENTS,
            'professionalRoles' => Staff::PROFESSIONAL_ROLES,
            'statuses' => Staff::STATUSES,
            'filters' => [
                'search' => (string) $request->query('search', ''),
                'department' => (string) $request->query('department', 'all'),
                'professional_role' => (string) $request->query('professional_role', 'all'),
                'status' => (string) $request->query('status', 'all'),
            ],
        ]);
    }

    /**
     * Show the form for creating a new medical or operational staff member.
     */
    public function create(): View
    {
        Gate::authorize('create', Staff::class);

        return view('staff.create', [
            'professionalRoles' => Staff::PROFESSIONAL_ROLES,
            'departments' => Staff::DEPARTMENTS,
            'statuses' => Staff::STATUSES,
            'linkableUsers' => $this->linkableUserOptions(),
        ]);
    }

    /**
     * Persist a newly created staff member profile.
     */
    public function store(Request $request): RedirectResponse
    {
        Gate::authorize('create', Staff::class);

        $validated = $this->validateStaffPayload($request);

        $staff = DB::transaction(function () use ($validated): Staff {
            return Staff::query()->create($validated);
        }, 3);

        return redirect()
            ->route('staff.show', $staff)
            ->with('success', "Staff member {$staff->full_name} ({$staff->employee_id}) created successfully.");
    }

    /**
     * Display detailed profile and historical donation attributions for a staff member.
     */
    public function show(Staff $staff): View
    {
        Gate::authorize('view', $staff);

        $staff->loadMissing(['user.roles']);
        $staff->loadCount('donations');

        $recentDonations = $staff->donations()
            ->with(['donor', 'bloodComponent'])
            ->orderByDesc('donation_date')
            ->orderByDesc('id')
            ->limit(10)
            ->get();

        return view('staff.show', [
            'staff' => $staff,
            'recentDonations' => $recentDonations,
        ]);
    }

    /**
     * Show the form for editing an existing staff member profile.
     */
    public function edit(Staff $staff): View
    {
        Gate::authorize('update', $staff);

        return view('staff.edit', [
            'staff' => $staff,
            'professionalRoles' => Staff::PROFESSIONAL_ROLES,
            'departments' => Staff::DEPARTMENTS,
            'statuses' => Staff::STATUSES,
            'linkableUsers' => $this->linkableUserOptions($staff),
        ]);
    }

    /**
     * Update an existing staff member profile.
     */
    public function update(Request $request, Staff $staff): RedirectResponse
    {
        Gate::authorize('update', $staff);

        $validated = $this->validateStaffPayload($request, $staff);

        DB::transaction(function () use ($staff, $validated): void {
            $lockedStaff = Staff::query()
                ->whereKey($staff->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            $lockedStaff->update($validated);
        }, 3);

        $staff->refresh();

        return redirect()
            ->route('staff.show', $staff)
            ->with('success', "Staff member {$staff->full_name} ({$staff->employee_id}) updated successfully.");
    }

    /**
     * Toggle a staff member's employment status between Active and Inactive.
     */
    public function toggleStatus(Staff $staff): RedirectResponse
    {
        Gate::authorize('toggleStatus', $staff);

        $newStatus = DB::transaction(function () use ($staff): string {
            $lockedStaff = Staff::query()
                ->whereKey($staff->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            $targetStatus = $lockedStaff->isActive() ? 'Inactive' : 'Active';
            $lockedStaff->status = $targetStatus;
            $lockedStaff->save();

            return $targetStatus;
        }, 3);

        $staff->refresh();

        return redirect()
            ->back()
            ->with('success', "Staff member {$staff->full_name} ({$staff->employee_id}) marked as {$newStatus}.");
    }

    /**
     * Delete a staff member only when not referenced by any historical donation records.
     */
    public function destroy(Staff $staff): RedirectResponse
    {
        Gate::authorize('delete', $staff);

        $deleted = DB::transaction(function () use ($staff): bool {
            $lockedStaff = Staff::query()
                ->whereKey($staff->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            if ($lockedStaff->donations()->exists()) {
                return false;
            }

            $lockedStaff->delete();

            return true;
        }, 3);

        if (! $deleted) {
            return redirect()
                ->back()
                ->with('error', __('Cannot delete a staff member referenced by existing donation records. Deactivate the staff profile instead.'));
        }

        return redirect()
            ->route('staff.index')
            ->with('success', "Staff member {$staff->full_name} ({$staff->employee_id}) removed from directory.");
    }

    /**
     * Validate and normalize staff creation and update payloads.
     *
     * @return array{
     *     employee_id: string,
     *     user_id: ?int,
     *     full_name: string,
     *     professional_role: string,
     *     department: string,
     *     phone: string,
     *     email: ?string,
     *     status: string,
     *     notes: ?string
     * }
     */
    private function validateStaffPayload(Request $request, ?Staff $existing = null): array
    {
        $request->merge([
            'employee_id' => trim((string) $request->input('employee_id', '')),
            'full_name' => trim((string) $request->input('full_name', '')),
            'phone' => trim((string) $request->input('phone', '')),
            'email' => $request->filled('email') ? strtolower(trim((string) $request->input('email'))) : null,
            'user_id' => $request->filled('user_id') ? $request->input('user_id') : null,
        ]);

        $staffId = $existing?->id;

        $validated = $request->validate([
            'full_name' => ['required', 'string', 'min:2', 'max:150'],
            'employee_id' => [
                'required',
                'string',
                'min:2',
                'max:64',
                Rule::unique('staff', 'employee_id')->ignore($staffId),
            ],
            'professional_role' => ['required', 'string', Rule::in(Staff::PROFESSIONAL_ROLES)],
            'department' => ['required', 'string', Rule::in(Staff::DEPARTMENTS)],
            'phone' => ['required', 'string', 'min:7', 'max:32', 'regex:/^[0-9+\-\s().]{7,32}$/'],
            'email' => [
                'nullable',
                'string',
                'email',
                'max:191',
                Rule::unique('staff', 'email')->ignore($staffId),
            ],
            'status' => ['required', 'string', Rule::in(Staff::STATUSES)],
            'user_id' => [
                'nullable',
                'integer',
                'exists:users,id',
                Rule::unique('staff', 'user_id')->ignore($staffId),
            ],
            'notes' => ['nullable', 'string', 'max:1000'],
        ]);

        return [
            'employee_id' => $validated['employee_id'],
            'user_id' => isset($validated['user_id']) ? (int) $validated['user_id'] : null,
            'full_name' => $validated['full_name'],
            'professional_role' => $validated['professional_role'],
            'department' => $validated['department'],
            'phone' => $validated['phone'],
            'email' => $validated['email'] ?? null,
            'status' => $validated['status'],
            'notes' => isset($validated['notes']) && trim($validated['notes']) !== '' ? trim($validated['notes']) : null,
        ];
    }

    /**
     * Retrieve active system users eligible for optional linking to a staff profile.
     *
     * @return Collection<int, User>
     */
    private function linkableUserOptions(?Staff $currentStaff = null): Collection
    {
        return User::query()
            ->active()
            ->where(function (Builder $query) use ($currentStaff): void {
                $query->whereDoesntHave('staffMember');
                if ($currentStaff?->user_id !== null) {
                    $query->orWhereKey($currentStaff->user_id);
                }
            })
            ->orderBy('name')
            ->get(['id', 'name', 'email']);
    }
}
