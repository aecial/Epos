<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Api\Concerns\ApiResponses;
use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\ApiLoginRequest;
use App\Models\User;
use App\Services\PosDeviceService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;

class AuthController extends Controller
{
    use ApiResponses;

    public function __construct(private PosDeviceService $posDeviceService) {}

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

        // Every existing POS/back-office route requires the full-access ability
        // (routes/api_v1.php); a token with no explicit scope gets Sanctum's default ['*'],
        // which satisfies any ability check. A kds-scoped token gets nothing else.
        $abilities = match ($request->validated('scope')) {
            'kds' => ['kds:read', 'kds:complete'],
            default => ['*'],
        };

        $newToken = $user->createToken($request->validated('device_name', 'pos'), $abilities);

        // A POS login is a device with a short code for its offline receipt/order numbers. A
        // kitchen display never sells, so it gets none.
        $device = $abilities === ['*']
            ? $this->posDeviceService->RegisterDevice($user, $newToken->accessToken)
            : null;

        return $this->success([
            'token' => $newToken->plainTextToken,
            'user' => $user,
            'device' => $device?->only(['code', 'name']),
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
