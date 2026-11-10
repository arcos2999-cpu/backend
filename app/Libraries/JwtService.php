<?php

namespace App\Libraries;

use Config\Buzon;
use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use RuntimeException;
use Throwable;

/**
 * Emite y valida los tokens de sesión (JWT HS256).
 * El token solo guarda el id del usuario ("sub"): el rol se consulta siempre en
 * la base de datos, así un cambio de rol o una cuenta desactivada surten efecto
 * de inmediato.
 */
class JwtService
{
    private Buzon $config;

    public function __construct(?Buzon $config = null)
    {
        $this->config = $config ?? config(Buzon::class);
    }

    public function secretIsValid(): bool
    {
        return strlen($this->config->jwtSecret) >= 32;
    }

    public function issue(int $userId): string
    {
        if (! $this->secretIsValid()) {
            throw new RuntimeException('buzon.jwtSecret no está configurado (mínimo 32 caracteres).');
        }

        $now = time();

        return JWT::encode([
            'iss' => 'buzon-buap-sjc',
            'iat' => $now,
            'exp' => $now + ($this->config->jwtTtlDays * 86400),
            'sub' => (string) $userId,
        ], $this->config->jwtSecret, 'HS256');
    }

    /** Devuelve el id de usuario o null si el token no es válido / está vencido. */
    public function userIdFrom(string $token): ?int
    {
        if (! $this->secretIsValid()) {
            return null;
        }

        try {
            $payload = JWT::decode($token, new Key($this->config->jwtSecret, 'HS256'));

            return isset($payload->sub) ? (int) $payload->sub : null;
        } catch (Throwable $e) {
            return null;
        }
    }
}
