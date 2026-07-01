<?php

namespace App\Services;

use App\Models\User;
use Tymon\JWTAuth\Facades\JWTAuth;

class TokenService
{
    const DEFAULT_TTL = 60; // 1 hora en minutos
    const REMEMBER_TTL = 10080; // 7 días en minutos
    const LONG_REMEMBER_TTL = 43200; // 30 días en minutos

    /**
     * Generar token JWT para usuario
     */
    public function generateToken(User $user, bool $remember = false): string
    {
        $ttl = $remember ? self::REMEMBER_TTL : self::DEFAULT_TTL;

        JWTAuth::factory()->setTTL($ttl);

        return JWTAuth::fromUser($user);
    }

    /**
     * Generar token con TTL personalizado
     */
    public function generateTokenWithTTL(User $user, int $ttl): string
    {
        JWTAuth::factory()->setTTL($ttl);

        return JWTAuth::fromUser($user);
    }

    /**
     * Refrescar token actual
     */
    public function refreshToken(): ?string
    {
        try {
            return JWTAuth::refresh(JWTAuth::getToken());
        } catch (\Exception $e) {
            return null;
        }
    }

    /**
     * Invalidar token actual
     */
    public function invalidateToken(): bool
    {
        try {
            JWTAuth::invalidate(JWTAuth::getToken());
            return true;
        } catch (\Exception $e) {
            return false;
        }
    }

    /**
     * Obtener usuario del token
     */
    public function getUserFromToken(): ?User
    {
        try {
            return JWTAuth::parseToken()->authenticate();
        } catch (\Exception $e) {
            return null;
        }
    }

    /**
     * Validar token
     */
    public function validateToken(string $token): bool
    {
        try {
            JWTAuth::setToken($token)->check();
            return true;
        } catch (\Exception $e) {
            return false;
        }
    }

    /**
     * Obtener TTL en segundos según opción "Recordarme"
     */
    public function getTTLInSeconds(bool $remember = false): int
    {
        $ttl = $remember ? self::REMEMBER_TTL : self::DEFAULT_TTL;
        return $ttl * 60; // Convertir minutos a segundos
    }
}
