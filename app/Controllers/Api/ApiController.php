<?php

namespace App\Controllers\Api;

use App\Controllers\BaseController;
use App\Libraries\AuthContext;
use CodeIgniter\API\ResponseTrait;
use CodeIgniter\HTTP\ResponseInterface;

/**
 * Base de todos los controladores de la API.
 * Formato de respuestas:
 *   éxito  -> el JSON del recurso (HTTP 200/201)
 *   error  -> {"error": "mensaje", "errores": {"campo": "mensaje"}}  (HTTP 4xx/5xx)
 */
abstract class ApiController extends BaseController
{
    use ResponseTrait;

    /** @param mixed $data */
    protected function ok($data = [], int $status = 200): ResponseInterface
    {
        return $this->response->setStatusCode($status)->setJSON($data);
    }

    protected function fail(string $mensaje, int $status = 400, array $errores = []): ResponseInterface
    {
        $cuerpo = ['error' => $mensaje];
        if ($errores !== []) {
            $cuerpo['errores'] = $errores;
        }

        return $this->response->setStatusCode($status)->setJSON($cuerpo);
    }

    /** Usuario autenticado (lo llena el filtro "jwt"). */
    protected function user(): array
    {
        return AuthContext::user() ?? [];
    }

    /** Cuerpo de la petición: JSON o formulario. */
    protected function input(): array
    {
        if (str_contains(strtolower($this->request->getHeaderLine('Content-Type')), 'application/json')) {
            $d = json_decode((string) $this->request->getBody(), true);

            return is_array($d) ? $d : [];
        }

        return $this->request->getPost() ?: [];
    }

    /**
     * Valida y, si falla, devuelve la respuesta de error lista para retornar.
     *
     * @return ResponseInterface|null null si todo es válido
     */
    protected function validarDatos(array $data, array $reglas): ?ResponseInterface
    {
        if ($this->validateData($data, $reglas)) {
            return null;
        }

        $errores = $this->validator->getErrors();

        return $this->fail((string) reset($errores), 422, $errores);
    }

    /** Limita peticiones por IP. Devuelve respuesta 429 si se excedió. */
    protected function limitar(string $clave, int $maxPorMinuto): ?ResponseInterface
    {
        if (service('throttler')->check(md5($clave . $this->request->getIPAddress()), $maxPorMinuto, MINUTE)) {
            return null;
        }

        return $this->fail('Demasiados intentos. Espera un minuto e inténtalo de nuevo.', 429);
    }
}
