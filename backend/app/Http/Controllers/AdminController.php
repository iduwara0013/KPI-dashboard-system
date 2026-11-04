<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class AdminController extends Controller
{
    public function workspace(): JsonResponse
    {
        return response()->json([
            'users' => User::orderBy('name')->get(),
            'departments' => DB::table('departments')->orderBy('name')->get()->map(function ($department) {
                $department->is_active = (bool) $department->is_active;
                return $department;
            }),
            'memberships' => DB::table('department_memberships')->get(),
            'reporting' => DB::table('reporting_relationships')->get(),
            'activity' => DB::table('admin_activity')
                ->leftJoin('users', 'users.id', '=', 'admin_activity.actor_id')
                ->select('admin_activity.id', 'admin_activity.action', 'admin_activity.subject', 'admin_activity.created_at', 'users.name as actor_name')
                ->orderByDesc('admin_activity.id')->limit(60)->get(),
        ]);
    }

    public function saveUser(Request $request, ?User $user = null): JsonResponse
    {
        $request->merge([
            'email' => strtolower(trim((string) $request->input('email'))),
            'employee_number' => strtoupper(trim((string) $request->input('employee_number'))),
        ]);
        $exists = $user?->exists ?? false;
        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'email' => ['required', 'email', 'max:180', Rule::unique('users')->ignore($user?->id)],
            'employee_number' => ['required', 'string', 'max:40', Rule::unique('users')->ignore($user?->id)],
            'designation' => ['required', 'string', 'max:120'],
            'system_role' => ['required', Rule::in(['super_admin', 'md', 'plan_editor', 'member'])],
            'is_active' => ['required', 'boolean'],
            'password' => [$exists ? 'nullable' : 'required', 'string', 'min:12', 'max:128'],
            'memberships' => ['present', 'array', 'max:30'],
            'memberships.*.department_id' => ['required', 'integer', 'distinct', Rule::exists('departments', 'id')->where('is_active', true)],
            'memberships.*.responsibility' => ['required', Rule::in(['hof', 'manager', 'employee'])],
            'memberships.*.manager_ids' => ['present', 'array', 'max:10'],
            'memberships.*.manager_ids.*' => ['required', 'integer', Rule::exists('users', 'id')->where('is_active', true)],
        ]);

        if ($user?->id === $request->user()->id && (! $data['is_active'] || $data['system_role'] !== 'super_admin')) {
            throw ValidationException::withMessages(['system_role' => 'You cannot deactivate or remove administrator access from your current account.']);
        }

        $saved = DB::transaction(function () use ($request, $user, $data, $exists) {
            // Serialize organization edits, including graph validation, across administrators.
            DB::table('users')->orderBy('id')->lockForUpdate()->get(['id']);
            $this->validateReporting($data, $user?->id);
            $before = $exists ? $this->userState($user) : null;
            $attributes = collect($data)->except(['memberships', 'password'])->all();
            if (! empty($data['password'])) {
                $attributes['password'] = $data['password'];
            }
            $saved = $user ?? new User;
            $saved->fill($attributes)->save();

            DB::table('reporting_relationships')->where('user_id', $saved->id)->delete();
            DB::table('department_memberships')->where('user_id', $saved->id)->delete();
            foreach ($data['memberships'] as $membership) {
                DB::table('department_memberships')->insert([
                    'user_id' => $saved->id, 'department_id' => $membership['department_id'],
                    'responsibility' => $membership['responsibility'], 'created_at' => now(), 'updated_at' => now(),
                ]);
                foreach ($membership['manager_ids'] as $managerId) {
                    DB::table('reporting_relationships')->insert([
                        'department_id' => $membership['department_id'], 'user_id' => $saved->id,
                        'manager_id' => $managerId, 'created_at' => now(), 'updated_at' => now(),
                    ]);
                }
            }
            $this->record($request, $exists ? 'user.updated' : 'user.created', $saved->name, $before, $this->userState($saved));
            return $saved;
        });

        return response()->json(['user' => $saved], $exists ? 200 : 201);
    }

    private function validateReporting(array $data, ?int $userId): void
    {
        $proposed = collect($data['memberships'])->keyBy('department_id');
        $existingReports = DB::table('reporting_relationships')->where('manager_id', $userId ?? 0)->get();
        foreach ($existingReports as $report) {
            $membership = $proposed->get($report->department_id);
            if (! $data['is_active'] || ! $membership || ! in_array($membership['responsibility'], ['hof', 'manager'])) {
                throw ValidationException::withMessages(['memberships' => 'Reassign this person’s reporting employees before removing their department leadership or deactivating their account.']);
            }
        }

        foreach ($data['memberships'] as $index => $membership) {
            if (! DB::table('departments')->where('id', $membership['department_id'])->where('is_active', true)->exists()) {
                throw ValidationException::withMessages(["memberships.$index.department_id" => 'Select an active department.']);
            }
            if (count($membership['manager_ids']) !== count(array_unique($membership['manager_ids']))) {
                throw ValidationException::withMessages(["memberships.$index.manager_ids" => 'Choose each reporting manager only once per department.']);
            }
            $departmentId = $membership['department_id'];
            $graph = DB::table('reporting_relationships')->where('department_id', $departmentId)
                ->when($userId, fn ($query) => $query->where('user_id', '!=', $userId))
                ->get()->groupBy('user_id')->map(fn ($rows) => $rows->pluck('manager_id')->all())->all();
            foreach ($membership['manager_ids'] as $managerId) {
                $valid = DB::table('department_memberships')->join('users', 'users.id', '=', 'department_memberships.user_id')
                    ->where('department_id', $departmentId)->where('user_id', $managerId)
                    ->where('users.is_active', true)->whereIn('responsibility', ['hof', 'manager'])->exists();
                if (! $valid || $managerId === $userId) {
                    throw ValidationException::withMessages(["memberships.$index.manager_ids" => 'Choose an active HOF or manager in the same department. A person cannot report to themselves.']);
                }
                if ($userId && $this->reaches($graph, $managerId, $userId, [])) {
                    throw ValidationException::withMessages(["memberships.$index.manager_ids" => 'This reporting relationship would create a circular hierarchy.']);
                }
            }
        }
    }

    private function reaches(array $graph, int $node, int $target, array $seen): bool
    {
        if ($node === $target) return true;
        if (isset($seen[$node])) return false;
        $seen[$node] = true;
        foreach ($graph[$node] ?? [] as $parent) {
            if ($this->reaches($graph, (int) $parent, $target, $seen)) return true;
        }
        return false;
    }

    public function saveDepartment(Request $request, ?int $department = null): JsonResponse
    {
        $before = $department ? DB::table('departments')->find($department) : null;
        if ($department) abort_unless($before, 404);
        $data = $request->validate([
            'name' => ['required', 'string', 'max:120', Rule::unique('departments')->ignore($department)],
            'code' => ['required', 'string', 'max:20', 'regex:/^[A-Z0-9-]+$/', Rule::unique('departments')->ignore($department)],
            'description' => ['nullable', 'string', 'max:500'],
            'color' => ['required', 'regex:/^#[0-9a-fA-F]{6}$/'],
            'is_active' => ['required', 'boolean'],
        ]);

        $id = DB::transaction(function () use ($request, $department, $data, $before) {
            DB::table('users')->orderBy('id')->lockForUpdate()->get(['id']);
            if (! $data['is_active'] && $department && DB::table('department_memberships')->where('department_id', $department)->exists()) {
                throw ValidationException::withMessages(['is_active' => 'Move or remove department memberships before archiving this department.']);
            }
            if ($department) {
                DB::table('departments')->where('id', $department)->update([...$data, 'updated_at' => now()]);
                $id = $department;
            } else {
                $id = DB::table('departments')->insertGetId([...$data, 'created_at' => now(), 'updated_at' => now()]);
            }
            $this->record($request, $department ? 'department.updated' : 'department.created', $data['name'], $before, $data);
            return $id;
        });

        return response()->json(['id' => $id], $department ? 200 : 201);
    }

    private function userState(User $user): array
    {
        return [
            ...$user->only(['id', 'name', 'email', 'employee_number', 'designation', 'system_role', 'is_active']),
            'memberships' => DB::table('department_memberships')->where('user_id', $user->id)->get()->toArray(),
            'reporting' => DB::table('reporting_relationships')->where('user_id', $user->id)->get()->toArray(),
        ];
    }

    private function record(Request $request, string $action, string $subject, mixed $before, mixed $after): void
    {
        DB::table('admin_activity')->insert([
            'actor_id' => $request->user()->id, 'action' => $action, 'subject' => $subject,
            'before_data' => $before === null ? null : json_encode($before),
            'after_data' => json_encode($after), 'created_at' => now(),
        ]);
    }
}
