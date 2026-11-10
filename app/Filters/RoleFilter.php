<?php

namespace App\Filters;

use App\Libraries\AuthContext;
use CodeIgniter\Filters\FilterInterface;
use CodeIgniter\HTTP\RequestInterface;
use CodeIgniter\HTTP\ResponseInterface;

/**
 * Restringe una ruta a ciertos roles. Debe ir DESPUÉS de "jwt".
 * Uso en rutas:  ['filter' => 'role:admin']  o  ['filter' => 'role:admin,personal']
 */
class RoleFilter implements FilterInterface
{
    public function before(RequestInterface $request, $arguments = null)
    {
        $permitidos = $arguments ?? [];
        if (in_array(AuthContext::rol(), $permitidos, true)) {
            return null;
        }

        return service('response')
            ->setStatusCode(403)
            ->setJSON(['error' => 'No tienes permiso para realizar esta acción.']);
    }

    public function after(RequestInterface $request, ResponseInterface $response, $arguments = null)
    {
        return null;
    }
}
