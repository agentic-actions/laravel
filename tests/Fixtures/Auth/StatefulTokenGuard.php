<?php

namespace Tests\Fixtures\Auth;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Contracts\Auth\StatefulGuard;

/**
 * A guard that implements StatefulGuard but is not Laravel's SessionGuard, as a third-party token guard might. The
 * default token reader must not read it as a session.
 */
final class StatefulTokenGuard implements StatefulGuard
{
    /**
     * Create the guard with the user it authenticates.
     */
    public function __construct(private ?Authenticatable $current = null) {}

    /**
     * Whether a user is authenticated.
     */
    public function check(): bool
    {
        return $this->current !== null;
    }

    /**
     * Whether no user is authenticated.
     */
    public function guest(): bool
    {
        return $this->current === null;
    }

    /**
     * The authenticated user.
     */
    public function user(): ?Authenticatable
    {
        return $this->current;
    }

    /**
     * The authenticated user's identifier.
     */
    public function id(): int|string|null
    {
        $id = $this->current?->getAuthIdentifier();

        return is_int($id) || is_string($id) ? $id : null;
    }

    /**
     * Credentials are never validated by this fixture.
     *
     * @param  array<string, mixed>  $credentials
     */
    public function validate(array $credentials = []): bool
    {
        return false;
    }

    /**
     * Whether a user is set.
     */
    public function hasUser(): bool
    {
        return $this->current !== null;
    }

    /**
     * Set the user.
     */
    public function setUser(Authenticatable $user): static
    {
        $this->current = $user;

        return $this;
    }

    /**
     * Attempts always fail.
     *
     * @param  array<string, mixed>  $credentials
     */
    public function attempt(array $credentials = [], $remember = false): bool
    {
        return false;
    }

    /**
     * Attempts always fail.
     *
     * @param  array<string, mixed>  $credentials
     */
    public function once(array $credentials = []): bool
    {
        return false;
    }

    /**
     * Log a user in.
     */
    public function login(Authenticatable $user, $remember = false): void
    {
        $this->current = $user;
    }

    /**
     * Logging in by id is not supported.
     */
    public function loginUsingId($id, $remember = false): Authenticatable|false
    {
        return false;
    }

    /**
     * Logging in by id is not supported.
     */
    public function onceUsingId($id): Authenticatable|false
    {
        return false;
    }

    /**
     * No remember cookie.
     */
    public function viaRemember(): bool
    {
        return false;
    }

    /**
     * Log the user out.
     */
    public function logout(): void
    {
        $this->current = null;
    }
}
