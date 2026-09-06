<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use App\Http\Requests\RegisterRequest;
use App\Http\Requests\LoginRequest;
use Illuminate\Http\Request;
use App\Models\User;
use App\Models\Organization;
use App\Models\Membership;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;

class AuthController extends Controller
{
    public function register(RegisterRequest $request): JsonResponse
    {
        $data = $request->validated();

        $registration = DB::transaction(function () use ($data) {
            $user = User::create([
                'name' => $data['name'],
                'email' => $data['email'],
                'password' => $data['password'],
            ]);

            $organization = Organization::create([
                'name' => $user->name . "'s Organization",
            ]);

            $membership = Membership::create([
                'user_id' => $user->id,
                'organization_id' => $organization->id,
                'role' => 'owner',
            ]);

            $registration = [
                'user' => $user,
                'organization' => $organization,
                'membership' => $membership,
            ];

            return $registration;
        });

        Auth::login($registration['user']);
        $request->session()->regenerate();

        return response()->json([
            'data' => [
                'user' => [
                    'id' => $registration['user']->id,
                    'name' => $registration['user']->name,
                    'email' => $registration['user']->email,
                ],
                'organization' => [
                    'id' => $registration['organization']->id,
                    'name' => $registration['organization']->name,
                ],
                'membership' => [
                    'role' => $registration['membership']->role,
                ],
            ],
        ], 201);
    }

    public function me(Request $request): JsonResponse
    {
        $user = $request->user();
        $memberships = $user->memberships()->with('organization')->get();

        return response()->json([
            'data' => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'organizations' => $memberships->map(function ($membership) {
                    return [
                        'id' => $membership->organization->id,
                        'name' => $membership->organization->name,
                        'role' => $membership->role,
                    ];
                }),
            ],
        ]);
    }

    public function login(LoginRequest $request): JsonResponse
    {
        $data = $request->validated();

        if (!Auth::attempt($data)) {
            throw ValidationException::withMessages([
                'email' => ['The provided credentials are incorrect.'],
            ]);
        }

        $request->session()->regenerate();

        $user = Auth::user();

        return response()->json([
            'data' => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
            ],
        ], 200);
    }

    public function logout(Request $request): JsonResponse
    {
        Auth::guard('web')->logout();

        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return response()->json([
            'data' => null,
        ], 200);
    }
}
