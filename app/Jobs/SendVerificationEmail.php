<?php

namespace App\Jobs;

use App\Models\User;
use App\Models\VerificationCode;
use App\Services\MailService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

class SendVerificationEmail implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $tries = 3;
    public $timeout = 120; // Aumentado a 2 minutos
    public $backoff = 10; // Esperar 10 segundos entre reintentos

    public function __construct(
        public int $userId,
        public int $verificationCodeId
    ) {}

    public function handle(MailService $mailService): void
    {
        Log::info('🚀 Job iniciado', [
            'user_id' => $this->userId,
            'code_id' => $this->verificationCodeId,
            'attempt' => $this->attempts(),
        ]);

        try {
            // Cargar modelos
            $user = User::find($this->userId);
            $verificationCode = VerificationCode::find($this->verificationCodeId);

            if (!$user) {
                Log::error('❌ Usuario no encontrado en Job', ['user_id' => $this->userId]);
                $this->delete(); // Eliminar job de la cola
                return;
            }

            if (!$verificationCode) {
                Log::error('❌ VerificationCode no encontrado', ['code_id' => $this->verificationCodeId]);
                $this->delete();
                return;
            }

            Log::info('📧 Intentando enviar correo', [
                'user' => $user->email,
                'code' => $verificationCode->code,
            ]);

            // MÉTODO 1: Usar MailService (tu método actual)
            $emailSent = $mailService->sendConfirmationEmail($user, $verificationCode);

            if ($emailSent) {
                Log::info('✅ Correo enviado exitosamente', [
                    'user_id' => $user->id,
                    'email' => $user->email,
                ]);
                return;
            }

            // Si MailService falla, intentar método directo
            Log::warning('⚠️ MailService retornó false, intentando método directo');

            $this->sendDirectEmail($user, $verificationCode);

        } catch (\Symfony\Component\Mailer\Exception\TransportException $e) {
            Log::error('❌ Error de transporte SMTP', [
                'user_id' => $this->userId,
                'error' => $e->getMessage(),
                'code' => $e->getCode(),
            ]);

            // Si es el último intento, no reintentar
            if ($this->attempts() >= $this->tries) {
                Log::error('💀 Último intento fallido, moviendo a failed_jobs', [
                    'user_id' => $this->userId,
                ]);
            }

            throw $e; // Re-lanzar para que Laravel maneje el reintento

        } catch (\Exception $e) {
            Log::error('❌ Error general en Job', [
                'user_id' => $this->userId,
                'error' => $e->getMessage(),
                'class' => get_class($e),
                'trace' => $e->getTraceAsString(),
            ]);

            throw $e;
        }
    }

    /**
     * Método alternativo: envío directo sin MailService
     */
    private function sendDirectEmail(User $user, VerificationCode $verificationCode): void
    {
        try {
            Log::info('📤 Enviando correo por método directo');

            $subject = 'Confirma tu cuenta - ' . config('app.name');
            $code = $verificationCode->code;

            Mail::html($this->getSimpleEmailBody($user, $code), function ($message) use ($user, $subject) {
                $message->to($user->email, $user->name)
                    ->subject($subject)
                    ->from(config('mail.from.address'), config('mail.from.name'));
            });

            Log::info('✅ Correo directo enviado');

        } catch (\Exception $e) {
            Log::error('❌ Error en envío directo', [
                'error' => $e->getMessage(),
            ]);
            throw $e;
        }
    }

    /**
     * Template simple de email
     */
    private function getSimpleEmailBody(User $user, string $code): string
    {
        return "
            <!DOCTYPE html>
            <html>
            <head>
                <meta charset='UTF-8'>
                <style>
                    body { font-family: Arial, sans-serif; line-height: 1.6; padding: 20px; }
                    .container { max-width: 600px; margin: 0 auto; background: #f9f9f9; padding: 30px; border-radius: 10px; }
                    .code { font-size: 32px; font-weight: bold; color: #3b251d; text-align: center; padding: 20px; background: #fff; border-radius: 8px; letter-spacing: 8px; }
                    .footer { margin-top: 30px; font-size: 12px; color: #666; text-align: center; }
                </style>
            </head>
            <body>
                <div class='container'>
                    <h2>Hola {$user->name},</h2>
                    <p>Gracias por registrarte en " . config('app.name') . ".</p>
                    <p>Tu código de verificación es:</p>
                    <div class='code'>{$code}</div>
                    <p>Este código expira en 10 minutos.</p>
                    <div class='footer'>
                        <p>© " . date('Y') . " " . config('app.name') . "</p>
                    </div>
                </div>
            </body>
            </html>
        ";
    }

    public function failed(\Throwable $exception): void
    {
        Log::error('💀 Job FALLÓ definitivamente', [
            'user_id' => $this->userId,
            'error' => $exception->getMessage(),
            'class' => get_class($exception),
            'attempts' => $this->attempts(),
        ]);
    }
}
