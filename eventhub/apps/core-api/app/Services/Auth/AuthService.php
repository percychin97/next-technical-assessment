<?php

namespace App\Services\Auth;

use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\NewAccessToken;

class AuthService
{
    /**
     * Register a new user and return an access token.
     *
     * @throws \InvalidArgumentException if role is invalid
     */
    public function register(array $data): array
    {
        $user = User::create([
            'email'    => $data['email'],
            'password' => Hash::make($data['password']),
            'role'     => $data['role'] ?? UserRole::Attendee->value,
        ]);

        $token = $user->createToken('auth_token');

        return [
            'user'  => $user,
            'token' => $token->plainTextToken,
        ];
    }

    /**
     * Authenticate with email/password and return a token.
     *
     * @throws AuthenticationException
     */
    public function login(string $email, string $password): array
    {
        $user = User::where('email', $email)->first();

        if (!$user || !Hash::check($password, $user->password)) {
            throw new AuthenticationException('Invalid email or password');
        }

        // Revoke previous tokens for single-session simplicity
        // In production, consider per-device tokens
        $user->tokens()->delete();
        $token = $user->createToken('auth_token');

        return [
            'user'  => $user,
            'token' => $token->plainTextToken,
        ];
    }

    /**
     * Revoke the current access token.
     */
    public function logout(User $user): void
    {
        $user->currentAccessToken()->delete();
    }
}
