<?php

namespace App\Http\Controllers;

use App\Http\Requests\LoginRequest;
use App\Http\Requests\RegisterRequest;
use App\Jobs\SendVerificationEmail;
use App\Models\User;
use App\Models\VerificationCode;
use App\Services\MailService;
use App\Services\TokenService;
use App\Services\VerificationCodeService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Tymon\JWTAuth\Facades\JWTAuth;

class AuthController extends Controller
{
    public function __construct(
        protected VerificationCodeService $verificationService,
        protected MailService $mailService,
        protected TokenService $tokenService
    ) {}

    // ─────────────────────────────────────────────
    // REGISTRO
    // ─────────────────────────────────────────────

    public function register(RegisterRequest $request): JsonResponse
    {
        DB::beginTransaction();

        try {
            $user = User::create([
                'name'                => $request->name,
                'email'               => strtolower(trim($request->email)),
                'phone'               => $request->phone,
                'password'            => $request->password, // Cast 'hashed' en model se encarga
                'address'             => $request->address,
                'id_documento'        => $request->id_documento,
                'status'              => 'inactive',
                'verification_status' => 'pending',
                'role'                => 'user',
            ]);

            $verificationCode = $this->verificationService->generateCode($user->email, 'email_verification');

            SendVerificationEmail::dispatch($user->id, $verificationCode->id);

            DB::commit();

            Log::info('Usuario registrado', [
                'user_id' => $user->id,
                'email'   => $user->email,
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Usuario registrado exitosamente. Por favor, verifica tu correo electrónico.',
                'data'    => [
                    'user' => $this->formatUserBasic($user),
                    'verification_required' => true,
                    'verification_token'    => $verificationCode->token,
                    'email'                 => $user->email,
                ],
            ], 201);

        } catch (\Throwable $e) {
            DB::rollBack();
            return $this->serverError('Error al registrar usuario', $e);
        }
    }

    // ─────────────────────────────────────────────
    // VERIFICACIÓN DE CORREO
    // ─────────────────────────────────────────────

    public function verifyEmail(Request $request): JsonResponse
    {
        $request->validate([
            'code'  => 'required|string',
            'token' => 'required|string',
        ]);

        $verification = VerificationCode::where('token', $request->token)
            ->where('code', $request->code)
            ->where('used', false)
            ->first();

        if (! $verification || $verification->isExpired()) {
            return $this->errorResponse('Código inválido o expirado');
        }

        $user = User::where('email', $verification->email)->first();

        if (! $user) {
            return $this->errorResponse('Usuario no encontrado');
        }

        DB::transaction(function () use ($user, $verification) {
            $user->update([
                'email_verified_at'   => now(),
                'status'              => 'active',
                'verification_status' => 'verified',
            ]);

            $verification->update(['used' => true]);
        });

        $token = $this->tokenService->generateToken($user, false);

        return response()->json([
            'success'    => true,
            'message'    => 'Correo verificado exitosamente',
            'data'       => [
                'token'      => $token,
                'token_type' => 'bearer',
                'user'       => $this->formatUserFull($user),
            ],
        ]);
    }

    public function checkToken(Request $request): JsonResponse
    {
        $request->validate(['token' => 'required|string']);

        $verification = VerificationCode::where('token', $request->token)
            ->where('used', false)
            ->first();

        $valid = $verification && !$verification->isExpired();

        return response()->json([
            'success' => $valid,
        ], $valid ? 200 : 404);
    }

    // ─────────────────────────────────────────────
    // REENVÍO DE CÓDIGO
    // ─────────────────────────────────────────────

    public function resendVerificationCode(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'email' => 'required|email',
        ], [
            'email.required' => 'El correo electrónico es obligatorio',
            'email.email'    => 'Debe ingresar un correo válido',
        ]);

        if ($validator->fails()) {
            return $this->validationError($validator->errors());
        }

        try {
            $user = User::where('email', strtolower(trim($request->email)))->first();

            if (! $user) {
                return $this->errorResponse('Usuario no encontrado', 404);
            }

            if ($user->verification_status === 'verified') {
                return $this->errorResponse('Este correo ya ha sido verificado', 400);
            }

            $cooldown = $this->verificationService->checkCooldown($user->email, 'email_verification');

            if (! $cooldown['can_resend']) {
                return response()->json([
                    'success'     => false,
                    'message'     => 'Debes esperar antes de solicitar un nuevo código',
                    'data'        => ['retry_after' => $cooldown['remaining']],
                ], 429);
            }

            $verificationCode = $this->verificationService->generateCode($user->email, 'email_verification');

            if (! $this->mailService->sendCodeResendEmail($user, $verificationCode)) {
                return $this->errorResponse('Error al enviar el correo. Intenta nuevamente.', 500);
            }

            return response()->json([
                'success' => true,
                'message' => 'Código de verificación enviado exitosamente',
                'data'    => ['email' => $user->email, 'expires_in' => 10],
            ]);

        } catch (\Throwable $e) {
            return $this->serverError('Error al reenviar código', $e);
        }
    }

    // ─────────────────────────────────────────────
    // LOGIN
    // ─────────────────────────────────────────────

    public function login(LoginRequest $request): JsonResponse
    {
        try {
            $email = strtolower(trim($request->email));

            $user = User::where('email', $email)->first();

            if (! $user) {
                return $this->errorResponse('Las credenciales no coinciden con nuestros registros', 401);
            }

            if ($user->verification_status !== 'verified') {
                return response()->json([
                    'success'               => false,
                    'message'               => 'Debes verificar tu correo electrónico antes de iniciar sesión',
                    'data'                  => [
                        'verification_required' => true,
                        'email'                 => $user->email,
                    ],
                ], 403);
            }

            if ($user->status !== 'active') {
                return $this->errorResponse('Tu cuenta está inactiva. Contacta al administrador', 403);
            }

            if (! JWTAuth::attempt(['email' => $email, 'password' => $request->password])) {
                return $this->errorResponse('Contraseña incorrecta', 401);
            }

            $remember = (bool) $request->input('remember', false);
            $token    = $this->tokenService->generateToken($user, $remember);

            if (! $token) {
                return $this->errorResponse('Error al generar token', 500);
            }

            return response()->json([
                'success'    => true,
                'message'    => 'Inicio de sesión exitoso',
                'user'       => $this->formatUserLogin($user),
                'token'      => $token,
                'token_type' => 'bearer',
                'expires_in' => $this->tokenService->getTTLInSeconds($remember),
                'remember'   => $remember,
            ]);

        } catch (\Throwable $e) {
            return $this->serverError('Error en el inicio de sesión', $e);
        }
    }

    // ─────────────────────────────────────────────
    // RECUPERACIÓN DE CONTRASEÑA
    // ─────────────────────────────────────────────

    public function forgotPassword(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'email' => 'required|email',
        ], [
            'email.required' => 'El correo electrónico es obligatorio',
            'email.email'    => 'Debe ingresar un correo válido',
        ]);

        if ($validator->fails()) {
            return $this->validationError($validator->errors());
        }

        $genericResponse = response()->json([
            'success' => true,
            'message' => 'Si el correo existe, recibirás instrucciones para recuperar tu contraseña',
        ]);

        try {
            $user = User::where('email', strtolower(trim($request->email)))->first();

            if (! $user) {
                return $genericResponse;
            }

            $cooldown = $this->verificationService->checkCooldown($user->email, 'password_reset');

            if (! $cooldown['can_resend']) {
                return response()->json([
                    'success'     => false,
                    'message'     => 'Debes esperar antes de solicitar un nuevo código',
                    'data'        => ['retry_after' => $cooldown['remaining']],
                ], 429);
            }

            $verificationCode = $this->verificationService->generateCode($user->email, 'password_reset');

            if (! $this->mailService->sendResetPasswordEmail($user, $verificationCode)) {
                return $this->errorResponse('Error al enviar el correo. Intenta nuevamente.', 500);
            }

            return response()->json([
                'success' => true,
                'message' => 'Si el correo existe, recibirás instrucciones para recuperar tu contraseña',
                'data'    => ['expires_in' => 10],
            ]);

        } catch (\Throwable $e) {
            return $this->serverError('Error al procesar la solicitud', $e);
        }
    }

    // ─────────────────────────────────────────────
    // RESET DE CONTRASEÑA
    // ─────────────────────────────────────────────

    public function resetPassword(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'email'    => 'required|email',
            'code'     => 'required_without:token|string|size:6',
            'token'    => 'required_without:code|string',
            'password' => 'required|string|min:8|confirmed',
        ], [
            'email.required'         => 'El correo electrónico es obligatorio',
            'email.email'            => 'Debe ingresar un correo válido',
            'code.required_without'  => 'El código o el token es obligatorio',
            'code.size'              => 'El código debe tener 6 dígitos',
            'token.required_without' => 'El código o el token es obligatorio',
            'password.required'      => 'La contraseña es obligatoria',
            'password.min'           => 'La contraseña debe tener al menos 8 caracteres',
            'password.confirmed'     => 'Las contraseñas no coinciden',
        ]);

        if ($validator->fails()) {
            return $this->validationError($validator->errors());
        }

        try {
            $user = User::where('email', strtolower(trim($request->email)))->first();

            if (! $user) {
                return $this->errorResponse('Usuario no encontrado', 404);
            }

            $verificationCode = $request->has('token')
                ? $this->verificationService->verifyToken($request->token, 'password_reset')
                : $this->verificationService->verifyCode($user->email, $request->code, 'password_reset');

            if (! $verificationCode) {
                return $this->errorResponse('Código o token inválido o expirado', 400);
            }

            DB::transaction(function () use ($user, $request, $verificationCode) {
                $user->update([
                    'password' => $request->password, // Cast 'hashed' handles it
                ]);

                $verificationCode->markAsUsed();
            });

            $this->mailService->sendPasswordChangedNotification($user);

            return response()->json([
                'success' => true,
                'message' => 'Contraseña restablecida exitosamente',
            ]);

        } catch (\Throwable $e) {
            return $this->serverError('Error al restablecer la contraseña', $e);
        }
    }

    // ─────────────────────────────────────────────
    // ACTUALIZAR CONTRASEÑA (usuario autenticado)
    // ─────────────────────────────────────────────

    public function updatePassword(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'current_password' => 'required|string',
            'new_password'     => 'required|string|min:8|confirmed',
        ], [
            'current_password.required' => 'La contraseña actual es obligatoria',
            'new_password.required'     => 'La nueva contraseña es obligatoria',
            'new_password.min'          => 'La nueva contraseña debe tener al menos 8 caracteres',
            'new_password.confirmed'    => 'Las contraseñas no coinciden',
        ]);

        if ($validator->fails()) {
            return $this->validationError($validator->errors());
        }

        try {
            $user = $this->tokenService->getUserFromToken();

            if (! $user) {
                return $this->errorResponse('Usuario no encontrado', 404);
            }

            if (! \Illuminate\Support\Facades\Hash::check($request->current_password, $user->password)) {
                return $this->errorResponse('La contraseña actual es incorrecta', 401);
            }

            if (\Illuminate\Support\Facades\Hash::check($request->new_password, $user->password)) {
                return $this->errorResponse('La nueva contraseña debe ser diferente a la actual', 422);
            }

            $user->update([
                'password' => $request->new_password, // Cast 'hashed' handles it
            ]);

            $this->mailService->sendPasswordChangedNotification($user);

            return response()->json([
                'success' => true,
                'message' => 'Contraseña actualizada exitosamente',
            ]);

        } catch (\Throwable $e) {
            return $this->serverError('Error al actualizar la contraseña', $e);
        }
    }

    // ─────────────────────────────────────────────
    // ME / LOGOUT / REFRESH
    // ─────────────────────────────────────────────

    public function me(): JsonResponse
    {
        try {
            $user = $this->tokenService->getUserFromToken();

            if (! $user) {
                return $this->errorResponse('Usuario no encontrado', 404);
            }

            return response()->json([
                'success' => true,
                'user'    => [
                    'id'                  => $user->id,
                    'name'                => $user->name,
                    'email'               => $user->email,
                    'phone'               => $user->phone,
                    'address'             => $user->address,
                    'id_documento'        => $user->id_documento,
                    'status'              => $user->status,
                    'verification_status' => $user->verification_status,
                    'photo'               => $user->photo,
                    'bio'                 => $user->bio,
                    'department'          => $user->department,
                    'city'                => $user->city,
                    'role'                => $user->role,
                    'created_at'          => $user->created_at,
                    'email_verified_at'   => $user->email_verified_at,
                ],
            ]);

        } catch (\Throwable $e) {
            Log::error('Error al obtener usuario', ['error' => $e->getMessage()]);
            return $this->errorResponse('Error al obtener usuario', 500);
        }
    }

    public function logout(): JsonResponse
    {
        try {
            $this->tokenService->invalidateToken();

            return response()->json([
                'success' => true,
                'message' => 'Sesión cerrada exitosamente',
            ]);

        } catch (\Throwable $e) {
            return $this->errorResponse('Error al cerrar sesión', 500);
        }
    }

    public function refresh(): JsonResponse
    {
        try {
            $newToken = $this->tokenService->refreshToken();

            if (! $newToken) {
                return $this->errorResponse('Token expirado, por favor inicia sesión nuevamente', 401);
            }

            return response()->json([
                'success'    => true,
                'token'      => $newToken,
                'token_type' => 'bearer',
            ]);

        } catch (\Throwable $e) {
            return $this->errorResponse('Error al refrescar token', 500);
        }
    }

    // ─────────────────────────────────────────────
    // HELPERS PRIVADOS
    // ─────────────────────────────────────────────

    private function validationError(mixed $errors): JsonResponse
    {
        return response()->json([
            'success' => false,
            'message' => 'Error de validación',
            'errors'  => $errors,
        ], 422);
    }

    private function errorResponse(string $message, int $status = 400): JsonResponse
    {
        return response()->json([
            'success' => false,
            'message' => $message,
        ], $status);
    }

    private function serverError(string $message, \Throwable $e): JsonResponse
    {
        Log::error($message, [
            'error' => $e->getMessage(),
            'trace' => $e->getTraceAsString(),
        ]);

        return response()->json([
            'success' => false,
            'message' => $message,
            'error'   => config('app.debug') ? $e->getMessage() : 'Error interno del servidor',
        ], 500);
    }

    private function formatUserBasic(User $user): array
    {
        return [
            'id'                  => $user->id,
            'name'                => $user->name,
            'email'               => $user->email,
            'verification_status' => $user->verification_status,
            'role'                => $user->role,
        ];
    }

    private function formatUserLogin(User $user): array
    {
        return [
            'id'                  => $user->id,
            'name'                => $user->name,
            'email'               => $user->email,
            'phone'               => $user->phone,
            'status'              => $user->status,
            'photo'               => $user->photo,
            'verification_status' => $user->verification_status,
            'role'                => $user->role,
        ];
    }

    private function formatUserFull(User $user): array
    {
        return array_merge($this->formatUserBasic($user), [
            'status' => $user->status,
        ]);
    }
}