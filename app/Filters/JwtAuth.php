<?php

namespace App\Filters;

use App\Libraries\AuthContext;
use App\Libraries\JwtService;
use App\Models\UsuarioModel;
use CodeIgniter\Filters\FilterInterface;
use CodeIgniter\HTTP\RequestInterface;
use CodeIgniter\HTTP\ResponseInterface;

/**
 * Exige un token "Authorization: Bearer <jwt>" válido y una cuenta activa.
 */
class JwtAuth implements FilterInterface
{
    public function before(RequestInterface $request, $arguments = null)
    {
        AuthContext::set(null);

        $jwt = new JwtService();
        if (! $jwt->secretIsValid()) {
            return $this->json(500, 'El servidor no está configurado (falta buzon.jwtSecret).');
        }

        $header = $request->getHeaderLine('Authorization');
        if (! preg_match('/^Bearer\s+(\S+)$/i', $header, $m)) {
            return $this->json(401, 'Sesión requerida.');
        }

        $userId = $jwt->userIdFrom($m[1]);
        if ($userId === null) {
            return $this->json(401, 'Sesión inválida o vencida.');
        }

        $user = model(UsuarioModel::class)->find($userId);
        if ($user === null || (int) $user['activo'] !== 1) {
            return $this->json(401, 'La cuenta no existe o está desactivada.');
        }

        unset($user['password_hash']);
        AuthContext::set($user);

        return null;
    }

    public function after(RequestInterface $request, ResponseInterface $response, $arguments = null)
    {
        AuthContext::set(null);

        return null;
    }

    private function json(int $status, string $message): ResponseInterface
    {
        return service('response')->setStatusCode($status)->setJSON(['error' => $message]);
    }
}
