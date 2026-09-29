<?php

namespace App\Http\Controllers;

use App\Http\Requests\User\CreateUserRequest;
use App\Http\Requests\User\UpdateUserRequest;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;

class UserController extends Controller
{
    public function getUsers(): Response
    {
        return Inertia::render('EmployeeManagementPage', [
            'users' => User::where('role', '!=', 'admin')->orderBy('name')->get(),
        ]);
    }

    public function getCreateUser(): Response
    {
        return Inertia::render('CreateUserPage');
    }

    public function createUser(CreateUserRequest $request): RedirectResponse
    {
        User::create($request->validated() + ['status' => $request->validated('status', 'active')]);

        return redirect()->route('employee-management');
    }

    public function updateUser(UpdateUserRequest $request, User $user): RedirectResponse
    {
        abort_if($user->role === 'admin', 403);

        $data = $request->validated();

        if (blank($data['password'] ?? null)) {
            unset($data['password']);
        }

        if (($data['role'] ?? $user->role) !== 'manager') {
            $data['passcode'] = null;
        }

        $user->update($data);

        return redirect()->route('employee-management');
    }

    public function deleteUser(User $user): RedirectResponse
    {
        abort_if($user->role === 'admin', 403);

        $user->delete();

        return redirect()->route('employee-management');
    }

    /**
     * POS tokens never expire on their own (config/sanctum.php `expiration` is null) — a
     * cashier logs in once and stays signed in until someone revokes it here. Lists every
     * device currently signed in as $user, oldest login first.
     */
    public function getUserSessions(User $user): Response
    {
        abort_if($user->role === 'admin', 403);

        return Inertia::render('UserSessionsPage', [
            'user' => $user->only(['id', 'name', 'username']),
            'sessions' => $user->tokens()
                ->orderBy('created_at')
                ->get(['id', 'name', 'last_used_at', 'created_at'])
                ->map(fn ($token) => [
                    'id' => $token->id,
                    // The device_name the POS sent at login (e.g. "POS-01"), or Sanctum's
                    // "pos" default if it didn't send one.
                    'device_name' => $token->name,
                    'last_used_at' => $token->last_used_at,
                    'created_at' => $token->created_at,
                ]),
        ]);
    }

    /** Sign one device out. The next request with that token gets 401 immediately. */
    public function revokeUserSession(User $user, int $token): RedirectResponse
    {
        abort_if($user->role === 'admin', 403);

        $user->tokens()->whereKey($token)->delete();

        return redirect()->route('users.sessions', $user);
    }

    /** Sign every device out at once — e.g. a tablet was lost or an employee left. */
    public function revokeAllUserSessions(User $user): RedirectResponse
    {
        abort_if($user->role === 'admin', 403);

        $user->tokens()->delete();

        return redirect()->route('users.sessions', $user);
    }
}
