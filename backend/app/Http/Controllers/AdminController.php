<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Database\QueryException;
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
            'grade' => ['nullable', 'string', 'max:30'],
            'system_role' => ['required', Rule::in(['super_admin', 'md', 'plan_editor', 'member'])],
            'is_active' => ['required', 'boolean'],
            'password' => [$exists ? 'nullable' : 'required', 'string', 'min:5', 'max:128'],
            'memberships' => ['present', 'array', 'max:30'],
            'memberships.*.department_id' => ['required', 'integer', 'distinct', Rule::exists('departments', 'id')->where('is_active', true)],
            'memberships.*.responsibility' => ['sometimes', 'required', Rule::in(['hof', 'manager', 'employee'])],
            'memberships.*.responsibility_title' => ['nullable', 'string', 'max:120'],
            'memberships.*.manager_ids' => ['sometimes', 'array', 'max:10'],
            'memberships.*.manager_ids.*' => ['required', 'integer', Rule::exists('users', 'id')->where('is_active', true)],
        ]);

        if ($data['system_role'] === 'md' && $data['memberships'] !== []) {
            throw ValidationException::withMessages(['memberships' => 'The Managing Director has company-wide access and cannot be assigned to a department.']);
        }
        if ($data['is_active'] && str_ends_with($data['email'], '@pending.cpstl.invalid')) {
            throw ValidationException::withMessages(['email' => 'Enter the employee’s work email before activating the account.']);
        }
        if ($data['is_active'] && str_ends_with((string) $user?->email, '@pending.cpstl.invalid') && empty($data['password'])) {
            throw ValidationException::withMessages(['password' => 'Set an initial password before activating the imported account.']);
        }

        if ($user?->id === $request->user()->id && (! $data['is_active'] || $data['system_role'] !== 'super_admin')) {
            throw ValidationException::withMessages(['system_role' => 'You cannot deactivate or remove administrator access from your current account.']);
        }

        $saved = DB::transaction(function () use ($request, $user, $data, $exists) {
            // Serialize organization edits, including graph validation, across administrators.
            DB::table('users')->orderBy('id')->lockForUpdate()->get(['id']);
            if ($data['system_role'] === 'md' && $data['is_active']
                && DB::table('users')->where('system_role', 'md')->where('is_active', true)->where('id', '!=', $user?->id ?? 0)->exists()) {
                throw ValidationException::withMessages(['system_role' => 'The company already has an active Managing Director account.']);
            }
            $before = $exists ? $this->userState($user) : null;
            $previousMemberships = collect($before['memberships'] ?? [])->keyBy('department_id');
            foreach ($data['memberships'] as &$membership) {
                $membership['responsibility'] ??= $previousMemberships->get($membership['department_id'])?->responsibility ?? 'employee';
                $membership['manager_ids'] ??= collect($before['reporting'] ?? [])
                    ->where('department_id', $membership['department_id'])->pluck('manager_id')->all();
            }
            unset($membership);
            $this->validateReporting($data, $user?->id);
            $attributes = collect($data)->except(['memberships', 'password'])->all();
            if (! empty($data['password'])) {
                $attributes['password'] = $data['password'];
            }
            $saved = $user ?? new User;
            $saved->fill($attributes)->save();

            DB::table('reporting_relationships')->where('user_id', $saved->id)->delete();
            DB::table('department_memberships')->where('user_id', $saved->id)->delete();
            foreach ($data['memberships'] as $membership) {
                $title = array_key_exists('responsibility_title', $membership)
                    ? $membership['responsibility_title']
                    : $previousMemberships->get($membership['department_id'])?->responsibility_title;
                DB::table('department_memberships')->insert([
                    'user_id' => $saved->id, 'department_id' => $membership['department_id'],
                    'responsibility_title' => $title,
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

    public function removeUser(Request $request, int $user): JsonResponse
    {
        abort_if($request->user()->id === $user, 422, 'You cannot remove the account you are signed in with.');

        DB::transaction(function () use ($request, $user): void {
            DB::table('users')->orderBy('id')->lockForUpdate()->get(['id']);
            $person = User::findOrFail($user);
            abort_if(DB::table('reporting_relationships')->where('manager_id', $user)->exists(), 409,
                'Reassign this person’s direct reports before removing the account.');

            DB::table('reporting_relationships')->where('user_id', $user)->delete();
            DB::table('department_memberships')->where('user_id', $user)->delete();
            DB::table('work_notifications')->where('user_id', $user)->delete();
            DB::table('sessions')->where('user_id', $user)->delete();
            DB::table('password_reset_tokens')->where('email', $person->email)->delete();
            try {
                $person->delete();
            } catch (QueryException $exception) {
                if ($this->isForeignKeyViolation($exception)) {
                    abort(409, 'This person is linked to saved work or audit history. Keep the account inactive to preserve those records.');
                }
                throw $exception;
            }

            $this->record($request, 'user.removed', $person->name, $person->only(['id', 'employee_number']), null);
        });

        return response()->json(['message' => 'Person removed.']);
    }

    public function removeDepartment(Request $request, int $department): JsonResponse
    {
        DB::transaction(function () use ($request, $department): void {
            $query = DB::table('departments')->where('id', $department);
            $record = (clone $query)->lockForUpdate()->first();
            abort_unless($record, 404, 'Department not found.');
            abort_if(DB::table('department_memberships')->where('department_id', $department)->exists(), 409,
                'Move or remove the department’s people assignments before removing it.');

            try {
                $query->delete();
            } catch (QueryException $exception) {
                if ($this->isForeignKeyViolation($exception)) {
                    abort(409, 'This department is linked to saved work or reporting records and cannot be removed.');
                }
                throw $exception;
            }
            $this->record($request, 'department.removed', $record->name, (array) $record, null);
        });

        return response()->json(['message' => 'Department removed.']);
    }

    private function isForeignKeyViolation(QueryException $exception): bool
    {
        return ($exception->errorInfo[0] ?? null) === '23000'
            && ((int) ($exception->errorInfo[1] ?? 0) === 547
                || str_contains(strtoupper($exception->errorInfo[2] ?? ''), 'FOREIGN KEY'));
    }

    public function saveDepartmentReporting(Request $request, int $department): JsonResponse
    {
        $data = $request->validate([
            'changes' => ['required', 'array', 'max:500'],
            'changes.*.user_id' => ['required', 'integer', 'distinct', 'min:1'],
            'changes.*.manager_ids' => ['present', 'array', 'max:10'],
            'changes.*.manager_ids.*' => ['required', 'integer', 'min:1'],
            'changes.*.expected_manager_ids' => ['present', 'array', 'max:10'],
            'changes.*.expected_manager_ids.*' => ['required', 'integer', 'min:1'],
            'changes.*.responsibility' => ['sometimes', 'required', Rule::in(['hof', 'manager', 'employee'])],
            'changes.*.expected_responsibility' => ['required_with:changes.*.responsibility', Rule::in(['hof', 'manager', 'employee'])],
        ]);

        $links = DB::transaction(function () use ($request, $department, $data) {
            DB::table('users')->orderBy('id')->lockForUpdate()->get(['id']);
            $record = DB::table('departments')->where('is_active', true)->lockForUpdate()->find($department);
            abort_unless($record, 404, 'Choose an active department.');
            $members = DB::table('department_memberships as m')->join('users as u', 'u.id', '=', 'm.user_id')
                ->where('m.department_id', $department)->where('u.system_role', '!=', 'md')
                ->get(['u.id', 'u.is_active', 'u.system_role', 'm.responsibility'])->keyBy('id');
            $reporting = DB::table('reporting_relationships')->where('department_id', $department);
            $graph = $reporting->get()->groupBy('user_id')->map(function ($rows): array {
                return $rows->pluck('manager_id')->map(fn ($id): int => (int) $id)->sort()->values()->all();
            })->all();
            $before = [];
            $after = [];
            $rolesBefore = [];
            $rolesAfter = [];
            foreach ($data['changes'] as $change) {
                if (! array_key_exists('responsibility', $change)) {
                    continue;
                }
                $member = $members->get($change['user_id']);
                abort_unless($member && $member->responsibility === $change['expected_responsibility'], 409,
                    'This person’s workflow access changed. Reload the structure before saving.');
                abort_unless($member->system_role === 'member', 422, 'Department workflow access applies to team member accounts.');
                if ($member->responsibility !== $change['responsibility']) {
                    $rolesBefore[$member->id] = $member->responsibility;
                    $rolesAfter[$member->id] = $change['responsibility'];
                    $member->responsibility = $change['responsibility'];
                }
            }

            foreach ($data['changes'] as $index => $change) {
                $userId = (int) $change['user_id'];
                $expected = array_map('intval', $change['expected_manager_ids']);
                sort($expected);
                abort_unless($members->has($userId) && ($graph[$userId] ?? []) === $expected, 409,
                    'This department or its reporting links changed. Reload the structure before saving.');
                $managerIds = array_map('intval', $change['manager_ids']);
                sort($managerIds);
                $field = "changes.$index.manager_ids";
                if (count($managerIds) !== count(array_unique($managerIds))) {
                    throw ValidationException::withMessages([$field => 'Choose each reporting manager only once.']);
                }
                foreach ($managerIds as $managerId) {
                    $manager = $members->get($managerId);
                    if ($managerId === $userId || ! $manager?->is_active || ! in_array($manager->responsibility, ['hof', 'manager'], true)) {
                        throw ValidationException::withMessages([$field => 'Choose another active HOF or manager in this department.']);
                    }
                }
                if ($managerIds !== $expected) {
                    $before[$userId] = $expected;
                    $after[$userId] = $managerIds;
                    $graph[$userId] = $managerIds;
                }
            }

            foreach ($after as $userId => $managerIds) {
                foreach ($managerIds as $managerId) {
                    if ($this->reaches($graph, $managerId, $userId, [])) {
                        throw ValidationException::withMessages(['changes' => 'These changes would create a circular reporting structure.']);
                    }
                }
            }
            foreach ($graph as $managerIds) {
                foreach ($managerIds as $managerId) {
                    abort_unless(in_array($members->get($managerId)?->responsibility, ['hof', 'manager'], true), 422,
                        'Move this person’s direct reports before changing their workflow access to Employee.');
                }
            }
            foreach ($rolesAfter as $userId => $responsibility) {
                DB::table('department_memberships')->where('department_id', $department)->where('user_id', $userId)
                    ->update(['responsibility' => $responsibility, 'updated_at' => now()]);
            }
            foreach ($after as $userId => $managerIds) {
                (clone $reporting)->where('user_id', $userId)->delete();
                foreach ($managerIds as $managerId) {
                    DB::table('reporting_relationships')->insert([
                        'department_id' => $department, 'user_id' => $userId, 'manager_id' => $managerId,
                        'created_at' => now(), 'updated_at' => now(),
                    ]);
                }
            }
            if ($after !== []) {
                $this->record($request, 'department.reporting_updated', $record->name, $before, $after);
            }
            if ($rolesAfter !== []) {
                $this->record($request, 'department.workflow_access_updated', $record->name, $rolesBefore, $rolesAfter);
            }

            return $reporting->get();
        });

        return response()->json([
            'links' => $links,
            'memberships' => DB::table('department_memberships')->where('department_id', $department)->get(),
        ]);
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
                    ->where('users.is_active', true)->where('users.system_role', '!=', 'md')->whereIn('responsibility', ['hof', 'manager'])->exists();
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
            ...$user->only(['id', 'name', 'email', 'employee_number', 'designation', 'grade', 'system_role', 'is_active']),
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
