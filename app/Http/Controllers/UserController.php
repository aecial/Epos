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
    public function index(): Response
    {
        return Inertia::render('EmployeeManagementPage', [
            'users' => User::where('role', '!=', 'admin')->orderBy('name')->get(),
        ]);
    }

    public function create(): Response
    {
        return Inertia::render('CreateUserPage');
    }

    public function store(CreateUserRequest $request): RedirectResponse
    {
        User::create($request->validated() + ['status' => $request->validated('status', 'active')]);

        return redirect()->route('employee-management');
    }

    public function update(UpdateUserRequest $request, User $user): RedirectResponse
    {
        abort_if($user->role === 'admin', 403);

        $data = $request->validated();

        if (blank($data['password'] ?? null)) {
            unset($data['password']);
        }

        $user->update($data);

        return redirect()->route('employee-management');
    }

    public function destroy(User $user): RedirectResponse
    {
        abort_if($user->role === 'admin', 403);

        $user->delete();

        return redirect()->route('employee-management');
    }
}
