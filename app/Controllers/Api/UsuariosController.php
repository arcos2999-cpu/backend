<?php

namespace App\Controllers\Api;

use App\Models\UsuarioModel;
use CodeIgniter\HTTP\ResponseInterface;
use Config\Buzon;

/** Gestión de usuarios y roles. Todas las rutas son solo para admin. */
class UsuariosController extends ApiController
{
    /** GET /api/v1/usuarios?q=&rol=&page=&per_page= */
    public function index(): ResponseInterface
    {
        $q         = $this->request->getGet();
        $porPagina = max(1, min(50, (int) ($q['per_page'] ?? 20)));
        $pagina    = max(1, (int) ($q['page'] ?? 1));

        $filtrar = function ($b) use ($q) {
            if (! empty($q['rol']) && in_array($q['rol'], Buzon::ROLES, true)) {
                $b->where('u.rol', $q['rol']);
            }
            if (! empty($q['q'])) {
                $b->groupStart()->like('u.nombre', trim((string) $q['q']))->orLike('u.matricula', trim((string) $q['q']))->groupEnd();
            }

            return $b;
        };

        $db    = db_connect();
        $total = $filtrar($db->table('usuarios u'))->countAllResults();
        $rows  = $filtrar($db->table('usuarios u'))
            ->select('u.id, u.nombre, u.matricula, u.carrera, u.rol, u.activo, u.created_at, '
                . '(SELECT COUNT(*) FROM reportes r WHERE r.id_usuario = u.id AND r.deleted_at IS NULL) AS reportes', false)
            ->orderBy('u.nombre', 'ASC')
            ->limit($porPagina, ($pagina - 1) * $porPagina)
            ->get()->getResultArray();

        return $this->ok([
            'data' => array_map(static fn ($r) => [
                'id'         => (int) $r['id'],
                'nombre'     => $r['nombre'],
                'matricula'  => $r['matricula'],
                'carrera'    => $r['carrera'],
                'rol'        => $r['rol'],
                'activo'     => (int) $r['activo'] === 1,
                'reportes'   => (int) $r['reportes'],
                'created_at' => $r['created_at'],
            ], $rows),
            'meta' => [
                'page'        => $pagina,
                'per_page'    => $porPagina,
                'total'       => $total,
                'total_pages' => (int) max(1, ceil($total / $porPagina)),
            ],
        ]);
    }

    /** PATCH /api/v1/usuarios/{id}/rol  {rol} */
    public function rol(int $id): ResponseInterface
    {
        $rol = (string) ($this->input()['rol'] ?? '');
        if ($r = $this->validarDatos(['rol' => $rol], [
            'rol' => ['label' => 'Rol', 'rules' => 'required|in_list[' . implode(',', Buzon::ROLES) . ']'],
        ])) {
            return $r;
        }

        return $this->actualizar($id, ['rol' => $rol]);
    }

    /** PATCH /api/v1/usuarios/{id}/activo  {activo: true|false} */
    public function activo(int $id): ResponseInterface
    {
        $v = $this->input()['activo'] ?? null;
        if ($v === null) {
            return $this->fail('Indica si la cuenta debe quedar activa.', 422);
        }

        return $this->actualizar($id, ['activo' => filter_var($v, FILTER_VALIDATE_BOOLEAN) ? 1 : 0]);
    }

    private function actualizar(int $id, array $cambios): ResponseInterface
    {
        // Protección: nadie puede quitarse a sí mismo el rol de admin ni desactivarse,
        // así el sistema nunca se queda sin administradores.
        if ($id === (int) $this->user()['id']) {
            return $this->fail('No puedes modificar tu propia cuenta desde aquí.', 422);
        }

        $usuarios = model(UsuarioModel::class);
        if ($usuarios->find($id) === null) {
            return $this->fail('El usuario no existe.', 404);
        }

        $usuarios->update($id, $cambios);
        $u = $usuarios->find($id);

        return $this->ok([
            'id'        => (int) $u['id'],
            'nombre'    => $u['nombre'],
            'matricula' => $u['matricula'],
            'carrera'   => $u['carrera'],
            'rol'       => $u['rol'],
            'activo'    => (int) $u['activo'] === 1,
        ]);
    }
}
