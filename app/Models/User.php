<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasApiTokens, HasFactory, Notifiable;

    public function isAdminOrManager(): bool
    {
        return in_array($this->role, ['admin', 'manager'], true);
    }

    /**
     * Why this account may not use the back office, or null if it may. The back office is for
     * active managers/admins only; cashiers sign in on the POS.
     */
    public function backOfficeRefusal(): ?string
    {
        if ($this->status !== 'active') {
            return 'This account is inactive.';
        }

        return $this->isAdminOrManager() ? null : 'Cashier accounts sign in on the POS only.';
    }

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'username',
        'name',
        'password',
        'passcode',
        'role',
        'status',
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var list<string>
     */
    protected $hidden = [
        'password',
        'passcode',
        'remember_token',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'password' => 'hashed',
            'passcode' => 'hashed',
        ];
    }
}
