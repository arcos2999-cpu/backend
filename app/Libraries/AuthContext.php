<?php

namespace App\Libraries;

/**
 * Guarda al usuario autenticado durante la petición actual.
 * Lo llena el filtro JwtAuth y lo leen los controladores.
 */
class AuthContext
{
    /** @var array<string, mixed>|null */
    private static ?array $user = null;

    /** @param array<string, mixed>|null $user */
    public static function set(?array $user): void
    {
        self::$user = $user;
    }

    /** @return array<string, mixed>|null */
    public static function user(): ?array
    {
        return self::$user;
    }

    public static function id(): int
    {
        return (int) (self::$user['id'] ?? 0);
    }

    public static function rol(): string
    {
        return (string) (self::$user['rol'] ?? '');
    }

    public static function esAdmin(): bool
    {
        return self::rol() === 'admin';
    }
}
