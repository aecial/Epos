<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Api\Concerns\ApiResponses;
use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\ApiLoginRequest;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;

class AuthController extends Controller
{
    use ApiResponses;

    public function login(ApiLoginRequest $request): JsonResponse
    {
        $credentials = $request->only('username', 'password');

        if (! Auth::guard('web')->validate($credentials)) {
            throw ValidationException::withMessages([
                'username' => __('auth.failed'),
            ]);
        }

        /** @var User $user */
        $user = User::query()->where('username', $credentials['username'])->firstOrFail();

        if ($user->status !== 'active') {
            throw ValidationException::withMessages([
                'username' => 'This account is inactive.',
            ]);
        }

        $token = $user->createToken($request->validated('device_name', 'pos'))->plainTextToken;

        return $this->success([
            'token' => $token,
            'user' => $user,
        ], 201);
    }

    public function me(Request $request): JsonResponse
    {
        return $this->success($request->user());
    }

    public function logout(Request $request): JsonResponse
    {
        $request->user()->currentAccessToken()->delete();

        return $this->success(['message' => 'Logged out.']);
    }
}
