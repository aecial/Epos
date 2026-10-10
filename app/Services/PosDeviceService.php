<?php

namespace App\Services;

use App\Models\PosDevice;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Sanctum\PersonalAccessToken;

/**
 * POS devices: one per POS login, each with a short code (P1, P2, ...) that prefixes the receipt
 * and order numbers it prints while offline. Codes come from the row id, so they never repeat.
 */
class PosDeviceService
{
    public function RegisterDevice(User $user, PersonalAccessToken $token): PosDevice
    {
        return DB::transaction(function () use ($user, $token): PosDevice {
            $device = PosDevice::create([
                'user_id' => $user->id,
                'personal_access_token_id' => $token->id,
                'name' => $token->name,
                // Placeholder until the id is known; replaced in the same transaction.
                'code' => 'T'.Str::random(15),
                'last_seen_at' => now(),
            ]);

            $device->update(['code' => 'P'.$device->id]);

            return $device;
        });
    }

    /**
     * The device behind the token a request was made with. A token issued before devices
     * existed gets its device on first use.
     */
    public function DeviceForToken(User $user, PersonalAccessToken $token): PosDevice
    {
        return PosDevice::query()->where('personal_access_token_id', $token->id)->first()
            ?? $this->RegisterDevice($user, $token);
    }
}
