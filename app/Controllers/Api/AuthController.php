<?php

namespace App\Controllers\Api;

use App\Libraries\JwtService;
use App\Models\UsuarioModel;
use CodeIgniter\HTTP\ResponseInterface;
use Config\Buzon;

class AuthController extends ApiController
{
    /** POST /api/v1/auth/register  {nombre, matricula, carrera, password} */
    public function register(): ResponseInterface
    {
        /** @var Buzon $cfg */
        $cfg = config(Buzon::class);

        if ($r = $this->limitar('auth', $cfg->authAttemptsPerMinute)) {
            return $r;
        }

        $d = $this->input();
        // IMPORTANTE: el rol NUNCA se toma de la petición; siempre empieza como alumno.
        $datos = [
            'nombre'    => trim((string) ($d['nombre'] ?? '')),
            'matricula' => self::limpiarMatricula($d['matricula'] ?? ''),
            'carrera'   => strtoupper(trim((string) ($d['carrera'] ?? ''))),
            'password'  => (string) ($d['password'] ?? ''),
        ];

        // El formato se revisa aparte: las reglas de CodeIgniter no admiten comas dentro de regex_match.
        if ($datos['matricula'] !== '' && ! preg_match($cfg->matriculaPatron, $datos['matricula'])) {
            return $this->fail('Escribe una matrícula válida (solo números).', 422, ['matricula' => 'Matrícula no válida.']);
        }

        $reglas = [
            'nombre'    => ['label' => 'Nombre', 'rules' => 'required|min_length[3]|max_length[100]'],
            'matricula' => [
                'label'  => 'Matrícula',
                'rules'  => 'required|max_length[20]|is_unique[usuarios.matricula]',
                'errors' => ['is_unique' => 'Ya existe una cuenta con esa matrícula.'],
            ],
            'carrera' => [
                'label'  => 'Carrera',
                'rules'  => 'required|in_list[' . implode(',', Buzon::CARRERAS) . ']',
                'errors' => ['in_list' => 'Elige tu carrera: ' . implode(', ', Buzon::CARRERAS) . '.'],
            ],
            'password' => ['label' => 'Contraseña', 'rules' => 'required|min_length[8]|max_length[72]'],
        ];
        if ($r = $this->validarDatos($datos, $reglas)) {
            return $r;
        }

        $usuarios = model(UsuarioModel::class);
        $id       = $usuarios->insert([
            'nombre'        => $datos['nombre'],
            'matricula'     => $datos['matricula'],
            'carrera'       => $datos['carrera'],
            'password_hash' => password_hash($datos['password'], PASSWORD_DEFAULT),
            'rol'           => 'alumno',
            'activo'        => 1,
        ]);

        $usuario = $usuarios->find($id);

        return $this->ok([
            'token'   => (new JwtService())->issue((int) $id),
            'usuario' => UsuarioModel::publico($usuario),
        ], 201);
    }

    /** POST /api/v1/auth/login  {matricula, password} */
    public function login(): ResponseInterface
    {
        /** @var Buzon $cfg */
        $cfg = config(Buzon::class);

        if ($r = $this->limitar('auth', $cfg->authAttemptsPerMinute * 2)) {
            return $r;
        }

        $d        = $this->input();
        $matricula = self::limpiarMatricula($d['matricula'] ?? '');
        $password  = (string) ($d['password'] ?? '');

        if ($matricula === '' || $password === '') {
            return $this->fail('Escribe tu matrícula y contraseña.', 422);
        }

        // Freno extra contra fuerza bruta sobre una cuenta concreta.
        if ($r = $this->limitar('login:' . $matricula, $cfg->authAttemptsPerMinute)) {
            return $r;
        }

        $usuario = model(UsuarioModel::class)->where('matricula', $matricula)->first();

        // Se verifica siempre un hash para que el tiempo de respuesta no revele si la matrícula existe.
        $hash = $usuario['password_hash'] ?? '$2y$10$YsR6upIgcIplFCRy1tRf1.MjXYbeLyRKBoX2xrN0mJ2jShQ4SAxIq';
        $ok   = password_verify($password, $hash);

        if (! $usuario || ! $ok) {
            return $this->fail('Matrícula o contraseña incorrectas.', 401);
        }
        if ((int) $usuario['activo'] !== 1) {
            return $this->fail('Tu cuenta está desactivada. Contacta a la administración.', 403);
        }

        return $this->ok([
            'token'   => (new JwtService())->issue((int) $usuario['id']),
            'usuario' => UsuarioModel::publico($usuario),
        ]);
    }

    /** Quita espacios y guiones que la gente suele escribir en la matrícula ("2023 12345" o "2023-12345"). */
    private static function limpiarMatricula($valor): string
    {
        return preg_replace('/[\s\-]+/', '', trim((string) $valor)) ?? '';
    }

    /** GET /api/v1/auth/me  (útil para refrescar el rol si el admin lo cambió) */
    public function me(): ResponseInterface
    {
        return $this->ok(['usuario' => UsuarioModel::publico($this->user())]);
    }
}
