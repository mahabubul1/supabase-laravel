<?php

declare(strict_types=1);

namespace BlackDevs\SupabaseLaravel\Auth;

use BlackDevs\SupabaseLaravel\Exceptions\SupabaseAuthException;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Contracts\Auth\Guard;
use Illuminate\Http\Request;

/**
 * Stateless guard that authenticates requests by verifying the Supabase
 * JWT bearer token against the connection's JWT secret, without calling
 * the Supabase Auth API on every request.
 */
class SupabaseTokenGuard implements Guard
{
    protected bool $resolved = false;

    protected ?Authenticatable $user = null;

    public function __construct(
        protected Request $request,
        protected string $jwtSecret,
    ) {}

    public function user()
    {
        if ($this->resolved) {
            return $this->user;
        }

        $this->resolved = true;

        $token = $this->request->bearerToken();

        if (! $token || $this->jwtSecret === '') {
            return null;
        }

        try {
            $claims = SupabaseJwt::decode($token, $this->jwtSecret);
        } catch (SupabaseAuthException) {
            return null;
        }

        return $this->user = new SupabaseUser($claims);
    }

    public function check()
    {
        return ! is_null($this->user());
    }

    public function guest()
    {
        return ! $this->check();
    }

    public function id()
    {
        return $this->user()?->getAuthIdentifier();
    }

    public function validate(array $credentials = [])
    {
        return false;
    }

    public function hasUser()
    {
        return ! is_null($this->user);
    }

    public function setUser(Authenticatable $user)
    {
        $this->user = $user;
        $this->resolved = true;

        return $this;
    }
}
