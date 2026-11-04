<?php

namespace App\Http\Controllers;

use App\Http\Middleware\RequireDeliveryAccess;
use App\Http\Middleware\RequirePlanningAccess;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;

class SessionController extends Controller
{
    public function show(Request $request): JsonResponse
    {
        return response()->json([
            'user' => $request->user() ? [...$request->user()->toArray(), 'workspaces' => RequireDeliveryAccess::roles($request->user())] : null,
            'csrf_token' => csrf_token(),
        ]);
    }

    public function login(Request $request): JsonResponse
    {
        $credentials = $request->validate(['email' => 'required|email', 'password' => 'required|string']);

        if (! Auth::attempt([...$credentials, 'is_active' => true])) {
            throw ValidationException::withMessages(['email' => 'The email or password is incorrect, or the account is inactive.']);
        }

        $request->session()->regenerate();

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
        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return response()->json(['csrf_token' => csrf_token()]);
    }
}
