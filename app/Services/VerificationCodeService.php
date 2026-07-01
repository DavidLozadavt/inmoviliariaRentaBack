<?php

namespace App\Services;

use App\Models\VerificationCode;
use Carbon\Carbon;
use Illuminate\Support\Str;

class VerificationCodeService
{
    const CODE_LENGTH = 6;
    const EXPIRATION_MINUTES = 10;
    const COOLDOWN_SECONDS = 120; // 2 minutos

    /**
     * Generar un nuevo código de verificación
     */
    public function generateCode(
        string $email,
        string $type = 'email_verification'
    ): VerificationCode {
        // Invalidar códigos anteriores del mismo tipo
        $this->invalidatePreviousCodes($email, $type);

        // Generar código OTP de 6 dígitos
        $code = $this->generateOTP();

        // Generar token único para links
        $token = $this->generateToken();

        // Crear el código
        return VerificationCode::create([
            'email' => $email,
            'code' => $code,
            'token' => $token,
            'type' => $type,
            'expires_at' => Carbon::now()->addMinutes(self::EXPIRATION_MINUTES),
            'last_sent_at' => Carbon::now(),
        ]);
    }

    /**
     * Verificar código OTP
     */
    public function verifyCode(string $email, string $code, string $type): ?VerificationCode
    {
        $verificationCode = VerificationCode::where('email', $email)
            ->where('code', $code)
            ->where('type', $type)
            ->where('used', false)
            ->first();

        if (!$verificationCode) {
            return null;
        }

        if ($verificationCode->isExpired()) {
            return null;
        }

        return $verificationCode;
    }

    /**
     * Verificar por token (para links)
     */
    public function verifyToken(string $token, string $type): ?VerificationCode
    {
        $verificationCode = VerificationCode::where('token', $token)
            ->where('type', $type)
            ->where('used', false)
            ->first();

        if (!$verificationCode) {
            return null;
        }

        if ($verificationCode->isExpired()) {
            return null;
        }

        return $verificationCode;
    }

    /**
     * Verificar cooldown antes de reenviar
     */
    public function checkCooldown(string $email, string $type): array
    {
        $lastCode = VerificationCode::where('email', $email)
            ->where('type', $type)
            ->orderBy('created_at', 'desc')
            ->first();

        if (!$lastCode) {
            return ['can_resend' => true, 'remaining' => 0];
        }

        $canResend = $lastCode->canResend(self::COOLDOWN_SECONDS);
        $remaining = $lastCode->cooldownRemaining(self::COOLDOWN_SECONDS);

        return [
            'can_resend' => $canResend,
            'remaining' => $remaining,
        ];
    }

    /**
     * Invalidar códigos anteriores del usuario
     */
    public function invalidatePreviousCodes(string $email, string $type): void
    {
        VerificationCode::where('email', $email)
            ->where('type', $type)
            ->where('used', false)
            ->update(['used' => true]);
    }

    /**
     * Limpiar códigos expirados o usados (tarea programada)
     */
    public function cleanupExpiredCodes(): int
    {
        return VerificationCode::expiredOrUsed()
            ->where('created_at', '<', Carbon::now()->subDays(7))
            ->delete();
    }

    /**
     * Generar código OTP de 6 dígitos
     */
    private function generateOTP(): string
    {
        return str_pad(random_int(0, 999999), self::CODE_LENGTH, '0', STR_PAD_LEFT);
    }

    /**
     * Generar token único para links
     */
    private function generateToken(): string
    {
        do {
            $token = Str::random(64);
        } while (VerificationCode::where('token', $token)->exists());

        return $token;
    }
}
