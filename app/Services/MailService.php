<?php

namespace App\Services;

use App\Models\User;
use App\Models\VerificationCode;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Log;

class MailService
{
    /**
     * Estilos CSS comunes para todos los correos
     */
    private function getCommonStyles(): string
    {
        return "
        <style>
            * {
                margin: 0;
                padding: 0;
                box-sizing: border-box;
            }

            body {
                font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
                line-height: 1.6;
                color: #333;
                background-color: #f5f5f5;
                padding: 20px;
            }

            .email-container {
                max-width: 600px;
                margin: 0 auto;
                background: white;
                border-radius: 15px;
                overflow: hidden;
                box-shadow: 0 10px 30px rgba(0, 0, 0, 0.1);
                border: 1px solid #e8e8e8;
            }

            .email-header {
                background: linear-gradient(135deg, #3b251d 0%, #8b6f47 100%);
                color: white;
                padding: 30px;
                text-align: center;
            }

            .email-header h1 {
                font-size: 28px;
                font-weight: 600;
                margin-bottom: 10px;
            }

            .email-header .logo {
                font-size: 24px;
                font-weight: bold;
                letter-spacing: 1px;
                margin-bottom: 15px;
            }

            .email-content {
                padding: 40px;
            }

            .greeting {
                font-size: 18px;
                margin-bottom: 25px;
                color: #555;
            }

            .greeting strong {
                color: #3b251d;
            }

            .code-container {
                text-align: center;
                margin: 40px 0;
            }

            .code {
                display: inline-block;
                background: linear-gradient(135deg, #3b251d 0%, #8b6f47 100%);
                color: white;
                padding: 25px 50px;
                border-radius: 12px;
                font-size: 36px;
                font-weight: bold;
                letter-spacing: 15px;
                text-align: center;
                box-shadow: 0 5px 15px rgba(139, 111, 71, 0.2);
                margin: 20px 0;
            }

            .instructions {
                background-color: #f9f9f9;
                padding: 25px;
                border-radius: 10px;
                margin: 30px 0;
                border-left: 5px solid #8b6f47;
            }

            .instructions h3 {
                color: #3b251d;
                margin-bottom: 15px;
                font-size: 18px;
            }

            .instructions ul {
                padding-left: 20px;
                margin-bottom: 0;
            }

            .instructions li {
                margin-bottom: 8px;
                color: #666;
            }

            .expiry-notice {
                text-align: center;
                color: #888;
                font-size: 14px;
                margin-top: 20px;
                padding: 15px;
                background-color: #f8f8f8;
                border-radius: 8px;
                border: 1px dashed #ddd;
            }

            .footer {
                text-align: center;
                padding: 25px;
                color: #888;
                font-size: 13px;
                border-top: 1px solid #eee;
                background-color: #fafafa;
            }

            .footer a {
                color: #8b6f47;
                text-decoration: none;
            }

            .action-button {
                display: inline-block;
                background: linear-gradient(135deg, #3b251d 0%, #8b6f47 100%);
                color: white;
                padding: 15px 30px;
                border-radius: 8px;
                text-decoration: none;
                font-weight: 600;
                margin: 20px 0;
                text-align: center;
            }

            .warning-box {
                background-color: #fff3cd;
                border: 1px solid #ffc107;
                border-left: 5px solid #ffc107;
                padding: 20px;
                border-radius: 8px;
                margin: 25px 0;
            }

            .warning-box h4 {
                color: #856404;
                margin-bottom: 10px;
            }

            .divider {
                height: 1px;
                background: linear-gradient(to right, transparent, #e0e0e0, transparent);
                margin: 30px 0;
            }
        </style>
        ";
    }

    /**
     * Enviar correo de confirmación de registro
     */
    public function sendConfirmationEmail(User $user, VerificationCode $verificationCode): bool
    {
        try {
            Log::info('📧 [1/5] Iniciando envío de correo', [
                'user_id' => $user->id,
                'email' => $user->email,
                'mail_config' => [
                    'host' => config('mail.host'),
                    'port' => config('mail.port'),
                    'username' => config('mail.username'),
                    'encryption' => config('mail.encryption'),
                    'from' => config('mail.from.address'),
                ]
            ]);

            $subject = 'Confirma tu cuenta - ' . config('app.name');

            Log::info('📧 [2/5] Generando HTML del correo');

            $body = "
        <!DOCTYPE html>
        <html>
        <head>
            <meta charset='UTF-8'>
            <meta name='viewport' content='width=device-width, initial-scale=1.0'>
            <title>{$subject}</title>
            {$this->getCommonStyles()}
        </head>
        <body>
            <div class='email-container'>
                <div class='email-header'>
                    <div class='logo'>" . config('app.name') . "</div>
                    <h1>¡Bienvenido a nuestra comunidad!</h1>
                </div>

                <div class='email-content'>
                    <div class='greeting'>
                        Hola <strong>{$user->name}</strong>,
                    </div>

                    <p>Gracias por registrarte en <strong>" . config('app.name') . "</strong>. Estamos emocionados de tenerte con nosotros.</p>

                    <p>Para activar tu cuenta, por favor ingresa el siguiente código de verificación:</p>

                    <div class='code-container'>
                        <div class='code'>{$verificationCode->code}</div>
                    </div>

                    <div class='instructions'>
                        <h3>📋 Instrucciones:</h3>
                        <ul>
                            <li>Ve a la página de verificación en nuestra aplicación</li>
                            <li>Ingresa el código de 6 dígitos mostrado arriba</li>
                            <li>Haz clic en 'Verificar' para activar tu cuenta</li>
                        </ul>
                    </div>

                    <div class='expiry-notice'>
                        ⏰ <strong>Importante:</strong> Este código expira en 10 minutos
                    </div>

                    <div class='warning-box'>
                        <h4>🔒 Seguridad:</h4>
                        <ul>
                            <li>Nunca compartas este código con nadie</li>
                            <li>Nuestro equipo nunca te pedirá este código</li>
                            <li>Si no solicitaste este registro, ignora este correo</li>
                        </ul>
                    </div>
                </div>

                <div class='footer'>
                    <p>Este es un correo automático, por favor no respondas.</p>
                    <p>Si necesitas ayuda, contacta a nuestro equipo de soporte.</p>
                    <p>© " . date('Y') . " " . config('app.name') . ". Todos los derechos reservados.</p>
                </div>
            </div>
        </body>
        </html>
        ";

            Log::info('📧 [3/5] Llamando a Mail::html()');

            Mail::html($body, function ($message) use ($user, $subject) {
                $message->to($user->email, $user->name)
                    ->subject($subject)
                    ->from(config('mail.from.address'), config('mail.from.name'));
            });

            Log::info('📧 [4/5] Mail::html() completado sin excepciones');
            Log::info('✅ [5/5] Correo enviado exitosamente', [
                'user_id' => $user->id,
                'email' => $user->email,
            ]);

            return true;
        } catch (\Exception $e) {
            Log::error('❌ Error general al enviar correo', [
                'user_id' => $user->id,
                'email' => $user->email,
                'error' => $e->getMessage(),
                'class' => get_class($e),
                'trace' => $e->getTraceAsString(),
            ]);
            return false;
        }
    }

    /**
     * Enviar correo de recuperación de contraseña
     */
    public function sendResetPasswordEmail(User $user, VerificationCode $verificationCode): bool
    {
        try {
            $subject = 'Recuperación de contraseña - ' . config('app.name');

            $body = "
            <!DOCTYPE html>
            <html>
            <head>
                <meta charset='UTF-8'>
                <meta name='viewport' content='width=device-width, initial-scale=1.0'>
                <title>{$subject}</title>
                {$this->getCommonStyles()}
            </head>
            <body>
                <div class='email-container'>
                    <div class='email-header'>
                        <div class='logo'>" . config('app.name') . "</div>
                        <h1>Recupera tu contraseña</h1>
                    </div>

                    <div class='email-content'>
                        <div class='greeting'>
                            Hola <strong>{$user->name}</strong>,
                        </div>

                        <p>Recibimos una solicitud para restablecer la contraseña de tu cuenta en <strong>" . config('app.name') . "</strong>.</p>

                        <p>Para continuar con el proceso de recuperación, utiliza el siguiente código de verificación:</p>

                        <div class='code-container'>
                            <div class='code'>{$verificationCode->code}</div>
                        </div>

                        <div class='instructions'>
                            <h3>🔑 Cómo restablecer tu contraseña:</h3>
                            <ul>
                                <li>Ve a la página de recuperación de contraseña</li>
                                <li>Ingresa el código de 6 dígitos mostrado arriba</li>
                                <li>Crea una nueva contraseña segura</li>
                                <li>Confirma tu nueva contraseña</li>
                            </ul>
                        </div>

                        <div class='expiry-notice'>
                            ⏰ <strong>Importante:</strong> Este código expira en 10 minutos
                        </div>

                        <div class='warning-box'>
                            <h4>⚠️ Si no solicitaste este cambio:</h4>
                            <p>Si no fuiste tú quien solicitó recuperar la contraseña, por favor ignora este correo. Tu cuenta sigue segura.</p>
                        </div>

                        <div class='divider'></div>

                        <p><strong>Consejo de seguridad:</strong> Te recomendamos usar una contraseña única y no compartirla con nadie.</p>
                    </div>

                    <div class='footer'>
                        <p>Este es un correo automático de seguridad.</p>
                        <p>Si tienes problemas, contacta a nuestro equipo de soporte.</p>
                        <p>© " . date('Y') . " " . config('app.name') . ". Todos los derechos reservados.</p>
                    </div>
                </div>
            </body>
            </html>
            ";

            Mail::html($body, function ($message) use ($user, $subject) {
                $message->to($user->email, $user->name)
                    ->subject($subject)
                    ->from(config('mail.from.address'), config('mail.from.name'));
            });

            return true;
        } catch (\Exception $e) {
            Log::error('Error al enviar correo de recuperación', [
                'user_id' => $user->id,
                'error' => $e->getMessage(),
            ]);
            return false;
        }
    }

    /**
     * Enviar correo de reenvío de código
     */
    public function sendCodeResendEmail(User $user, VerificationCode $verificationCode): bool
    {
        try {
            $subject = 'Nuevo código de verificación - ' . config('app.name');

            $body = "
            <!DOCTYPE html>
            <html>
            <head>
                <meta charset='UTF-8'>
                <meta name='viewport' content='width=device-width, initial-scale=1.0'>
                <title>{$subject}</title>
                {$this->getCommonStyles()}
            </head>
            <body>
                <div class='email-container'>
                    <div class='email-header'>
                        <div class='logo'>" . config('app.name') . "</div>
                        <h1>Nuevo código de verificación</h1>
                    </div>

                    <div class='email-content'>
                        <div class='greeting'>
                            Hola <strong>{$user->name}</strong>,
                        </div>

                        <p>Solicitaste un nuevo código de verificación para tu cuenta en <strong>" . config('app.name') . "</strong>.</p>

                        <p>Aquí tienes tu nuevo código de verificación:</p>

                        <div class='code-container'>
                            <div class='code'>{$verificationCode->code}</div>
                        </div>

                        <div class='instructions'>
                            <h3>📝 Cómo usar este código:</h3>
                            <ul>
                                <li>Regresa a la página de verificación</li>
                                <li>Ingresa el código de 6 dígitos mostrado arriba</li>
                                <li>Completa el proceso de verificación</li>
                            </ul>
                        </div>

                        <div class='expiry-notice'>
                            ⏰ <strong>Recuerda:</strong> Este código expira en 10 minutos
                        </div>

                        <div class='warning-box'>
                            <h4>🚫 Códigos anteriores invalidados</h4>
                            <p>Los códigos de verificación anteriores ya no son válidos. Solo puedes usar este nuevo código.</p>
                        </div>
                    </div>

                    <div class='footer'>
                        <p>Este es un correo automático generado por tu solicitud.</p>
                        <p>Si no solicitaste este código, por favor ignora este mensaje.</p>
                        <p>© " . date('Y') . " " . config('app.name') . ". Todos los derechos reservados.</p>
                    </div>
                </div>
            </body>
            </html>
            ";

            Mail::html($body, function ($message) use ($user, $subject) {
                $message->to($user->email, $user->name)
                    ->subject($subject)
                    ->from(config('mail.from.address'), config('mail.from.name'));
            });

            return true;
        } catch (\Exception $e) {
            Log::error('Error al reenviar código', [
                'user_id' => $user->id,
                'error' => $e->getMessage(),
            ]);
            return false;
        }
    }

    /**
     * Enviar notificación de contraseña cambiada exitosamente
     */
    public function sendPasswordChangedNotification(User $user): bool
    {
        try {
            $subject = 'Tu contraseña ha sido cambiada - ' . config('app.name');

            $body = "
            <!DOCTYPE html>
            <html>
            <head>
                <meta charset='UTF-8'>
                <meta name='viewport' content='width=device-width, initial-scale=1.0'>
                <title>{$subject}</title>
                {$this->getCommonStyles()}
            </head>
            <body>
                <div class='email-container'>
                    <div class='email-header'>
                        <div class='logo'>" . config('app.name') . "</div>
                        <h1>Contraseña actualizada</h1>
                    </div>

                    <div class='email-content'>
                        <div class='greeting'>
                            Hola <strong>{$user->name}</strong>,
                        </div>

                        <p>Te informamos que la contraseña de tu cuenta en <strong>" . config('app.name') . "</strong> ha sido cambiada exitosamente.</p>

                        <div class='instructions'>
                            <h3>✅ Cambio confirmado:</h3>
                            <ul>
                                <li>Fecha: " . now()->format('d/m/Y') . "</li>
                                <li>Hora: " . now()->format('H:i') . "</li>
                                <li>Estado: Cambio completado</li>
                            </ul>
                        </div>

                        <div class='warning-box'>
                            <h4>🔐 Seguridad de tu cuenta:</h4>
                            <p>Si tú realizaste este cambio, no necesitas hacer nada más.</p>
                            <p><strong>Si NO fuiste tú quien cambió la contraseña:</strong></p>
                            <ul>
                                <li>Contacta inmediatamente a nuestro equipo de soporte</li>
                                <li>Revisa la actividad reciente de tu cuenta</li>
                                <li>Considera habilitar la autenticación de dos factores</li>
                            </ul>
                        </div>

                        <div class='divider'></div>

                        <p><strong>Consejos para mantener tu cuenta segura:</strong></p>
                        <ul>
                            <li>Usa una contraseña única y compleja</li>
                            <li>No compartas tus credenciales con nadie</li>
                            <li>Habilita la verificación en dos pasos si está disponible</li>
                            <li>Actualiza tu contraseña periódicamente</li>
                        </ul>
                    </div>

                    <div class='footer'>
                        <p>Este es un correo automático de seguridad.</p>
                        <p>Para reportar actividad sospechosa, contacta a nuestro equipo de soporte.</p>
                        <p>© " . date('Y') . " " . config('app.name') . ". Todos los derechos reservados.</p>
                    </div>
                </div>
            </body>
            </html>
            ";

            Mail::html($body, function ($message) use ($user, $subject) {
                $message->to($user->email, $user->name)
                    ->subject($subject)
                    ->from(config('mail.from.address'), config('mail.from.name'));
            });

            return true;
        } catch (\Exception $e) {
            Log::error('Error al enviar notificación de cambio de contraseña', [
                'user_id' => $user->id,
                'error' => $e->getMessage(),
            ]);
            return false;
        }
    }

    /**
     * Enviar correo de bienvenida después de verificación exitosa
     */
    public function sendWelcomeEmail(User $user): bool
    {
        try {
            $subject = '¡Bienvenido a ' . config('app.name') . '!';

            $body = "
            <!DOCTYPE html>
            <html>
            <head>
                <meta charset='UTF-8'>
                <meta name='viewport' content='width=device-width, initial-scale=1.0'>
                <title>{$subject}</title>
                {$this->getCommonStyles()}
            </head>
            <body>
                <div class='email-container'>
                    <div class='email-header'>
                        <div class='logo'>" . config('app.name') . "</div>
                        <h1>¡Cuenta verificada exitosamente!</h1>
                    </div>

                    <div class='email-content'>
                        <div class='greeting'>
                            ¡Felicitaciones <strong>{$user->name}</strong>!
                        </div>

                        <p>Tu cuenta en <strong>" . config('app.name') . "</strong> ha sido verificada y activada exitosamente. ¡Ya puedes comenzar a disfrutar de todos nuestros servicios!</p>

                        <div class='instructions'>
                            <h3>🎉 Primeros pasos:</h3>
                            <ul>
                                <li>Completa tu perfil para una mejor experiencia</li>
                                <li>Explora nuestras funcionalidades principales</li>
                                <li>Configura tus preferencias de notificación</li>
                                <li>Revisa nuestro centro de ayuda si tienes dudas</li>
                            </ul>
                        </div>

                        <div style='text-align: center; margin: 30px 0;'>
                            <a href='" . config('app.frontend_url') . "' class='action-button'>
                                Comenzar a explorar
                            </a>
                        </div>

                        <div class='warning-box'>
                            <h4>💡 Tips para empezar:</h4>
                            <ul>
                                <li>Guarda tus credenciales en un lugar seguro</li>
                                <li>Revisa regularmente tu bandeja de entrada para ofertas especiales</li>
                                <li>Síguenos en nuestras redes sociales para estar al día</li>
                            </ul>
                        </div>
                    </div>

                    <div class='footer'>
                        <p>Gracias por unirte a nuestra comunidad.</p>
                        <p>Si necesitas ayuda, nuestro equipo de soporte está aquí para ti.</p>
                        <p>© " . date('Y') . " " . config('app.name') . ". Todos los derechos reservados.</p>
                    </div>
                </div>
            </body>
            </html>
            ";

            Mail::html($body, function ($message) use ($user, $subject) {
                $message->to($user->email, $user->name)
                    ->subject($subject)
                    ->from(config('mail.from.address'), config('mail.from.name'));
            });

            return true;
        } catch (\Exception $e) {
            Log::error('Error al enviar correo de bienvenida', [
                'user_id' => $user->id,
                'error' => $e->getMessage(),
            ]);
            return false;
        }
    }
}
