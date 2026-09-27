<?php

namespace App\Services;

use App\Exceptions\InvalidPasscodeException;
use App\Exceptions\TooManyPasscodeAttemptsException;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;

/**
 * The POS only sends the 4-digit passcode a manager/admin typed in; this works out whose it
 * is. Passcodes are salted bcrypt hashes, so they cannot be looked up by value - every
 * candidate is checked instead. That is also why uniqueness is enforced in the back office
 * forms rather than by a DB index.
 */
class PasscodeService
{
    public const MAX_FAILED_ATTEMPTS = 5;

    public const DECAY_SECONDS = 60;

    /**
     * Returns the single active admin/manager whose passcode matches. $requestedBy is the
     * logged-in user operating the terminal; failed attempts are throttled per that user.
     */
    public function ResolveApprover(User $requestedBy, string $passcode): User
    {
        $throttleKey = $this->throttleKey($requestedBy);

        if (RateLimiter::tooManyAttempts($throttleKey, self::MAX_FAILED_ATTEMPTS)) {
            throw new TooManyPasscodeAttemptsException(RateLimiter::availableIn($throttleKey));
        }

        $matches = $this->matchingApprovers($passcode);

        if ($matches->isEmpty()) {
            RateLimiter::hit($throttleKey, self::DECAY_SECONDS);

            throw new InvalidPasscodeException;
        }

        // Legacy data can hold the same passcode for two approvers. Guessing would put the
        // wrong name on the audit trail, so refuse until it is fixed in the back office.
        if ($matches->count() > 1) {
            throw new InvalidPasscodeException(
                'This passcode is shared by more than one manager. It must be changed in the back office before it can be used.'
            );
        }

        RateLimiter::clear($throttleKey);

        return $matches->first();
    }

    /**
     * Back office uniqueness check: does $passcode already belong to another active
     * admin/manager? $except is the user being edited, whose own passcode never conflicts.
     */
    public function IsPasscodeTaken(string $passcode, ?User $except = null): bool
    {
        return $this->matchingApprovers($passcode)
            ->contains(fn (User $user): bool => $except === null || (int) $user->id !== (int) $except->id);
    }

    /**
     * Checks every candidate rather than stopping at the first match, so the response time
     * does not depend on where the match sits and duplicates are always detected.
     *
     * @return Collection<int, User>
     */
    private function matchingApprovers(string $passcode): Collection
    {
        return User::query()
            ->whereIn('role', ['admin', 'manager'])
            ->where('status', 'active')
            ->whereNotNull('passcode')
            ->get()
            ->filter(fn (User $user): bool => Hash::check($passcode, $user->passcode))
            ->values();
    }

    private function throttleKey(User $requestedBy): string
    {
        return 'passcode-attempts:'.$requestedBy->id;
    }
}
