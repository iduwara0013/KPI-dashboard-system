<?php

namespace App\Http\Controllers;

use App\Http\Middleware\RequireDeliveryAccess;
use App\Http\Middleware\RequirePlanningAccess;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class SessionController extends Controller
{
    public function show(Request $request): JsonResponse
    {
        $user = $request->user();

        return response()->json([
            'user' => $user ? [
                ...$user->toArray(),
                'workspaces' => RequireDeliveryAccess::roles($user),
                'can_view_plans' => RequirePlanningAccess::canView($user),
            ] : null,
            'csrf_token' => csrf_token(),
        ]);
    }

    public function login(Request $request): JsonResponse
    {
        $data = $request->validate(['email' => 'required|string|max:180', 'password' => 'required|string']);
        $identifier = trim($data['email']);
        $isEmail = str_contains($identifier, '@');
        $credentials = [$isEmail ? 'email' : 'employee_number' => $isEmail ? strtolower($identifier) : strtoupper($identifier), 'password' => $data['password'], 'is_active' => true];
        if (($isEmail && (! filter_var($identifier, FILTER_VALIDATE_EMAIL) || str_ends_with(strtolower($identifier), '.cpstl.invalid'))) || ! Auth::attempt($credentials)) {
            throw ValidationException::withMessages(['email' => 'The email / EPF number or password is incorrect, or the account is inactive.']);
        }

        $request->session()->regenerate();
        $request->session()->put('account_fingerprint', $request->user()->sessionFingerprint());

        if (! in_array($request->user()->system_role, ['super_admin', 'md'], true) && ! RequirePlanningAccess::canView($request->user()) && RequireDeliveryAccess::roles($request->user()) === []) {
            Auth::logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();
            abort(403, 'Your account does not have an available workspace. Contact your administrator.');
        }

        return $this->show($request);
    }

    public function logout(Request $request): JsonResponse
    {
        Auth::guard('web')->logoutCurrentDevice();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return response()->json(['csrf_token' => csrf_token()]);
    }

    public function changePassword(Request $request): JsonResponse
    {
        $data = $request->validate(['current_password' => ['required', 'string'], 'password' => ['required', 'string', 'min:6', 'max:128', 'confirmed']]);
        $user = DB::transaction(function () use ($request, $data): User {
            $user = User::whereKey($request->user()->id)->lockForUpdate()->firstOrFail();
            abort_unless($user->is_active, 403);
            if (! Hash::check($data['current_password'], $user->password)) {
                throw ValidationException::withMessages(['current_password' => 'Your current password is incorrect.']);
            }
            if ($data['password'] === $user->employee_number || Hash::check($data['password'], $user->password)) {
                throw ValidationException::withMessages(['password' => 'Choose a new password different from your EPF number and current password.']);
            }
            $user->password = $data['password'];
            $user->must_change_password = false;
            $user->password_changed_at = now();
            $user->setRememberToken(Str::random(60));
            $user->save();
            DB::table('password_reset_tokens')->where('email', $user->email)->delete();
            if (config('session.driver') === 'database') {
                DB::table(config('session.table', 'sessions'))->where('user_id', $user->id)->delete();
            }
            DB::table('admin_activity')->insert(['actor_id' => $user->id, 'action' => 'password.changed', 'subject' => $user->name, 'created_at' => now()]);

            return $user;
        });
        Auth::guard('web')->setUser($user);
        $request->session()->regenerate();
        $request->session()->regenerateToken();
        $request->session()->put('account_fingerprint', $user->sessionFingerprint());

        return $this->show($request);
    }
}
