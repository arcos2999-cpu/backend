<?php

namespace App\Controllers\Api;

use CodeIgniter\HTTP\ResponseInterface;
use Config\Buzon;

class CatalogosController extends ApiController
{
    /** GET /api/v1/catalogos  -> estados, categorías, ubicaciones, prioridades */
    public function index(): ResponseInterface
    {
        $db = db_connect();

        $estados = $db->table('estados')->orderBy('orden')->get()->getResultArray();
        $cats    = $db->table('categorias')->where('activo', 1)->orderBy('nombre')->get()->getResultArray();
        $ubis    = $db->table('ubicaciones')->where('activo', 1)->orderBy('nombre')->get()->getResultArray();

        return $this->ok([
            'estados' => array_map(static fn ($e) => [
                'id'       => (int) $e['id'],
                'clave'    => $e['clave'],
                'etiqueta' => $e['etiqueta'],
                'es_final' => (int) $e['es_final'] === 1,
            ], $estados),
            'categorias'  => array_map(static fn ($c) => ['id' => (int) $c['id'], 'nombre' => $c['nombre']], $cats),
            'ubicaciones' => array_map(static fn ($u) => ['id' => (int) $u['id'], 'nombre' => $u['nombre']], $ubis),
            'prioridades' => Buzon::PRIORIDADES,
            'roles'       => Buzon::ROLES,
        ]);
    }

    /** POST /api/v1/catalogos/ubicaciones  {nombre}   (admin) */
    public function crearUbicacion(): ResponseInterface
    {
        return $this->crearItem('ubicaciones', 120);
    }

    /** POST /api/v1/catalogos/categorias  {nombre}   (admin) */
    public function crearCategoria(): ResponseInterface
    {
        return $this->crearItem('categorias', 60);
    }

    private function crearItem(string $tabla, int $max): ResponseInterface
    {
        $nombre = trim((string) ($this->input()['nombre'] ?? ''));
        if ($r = $this->validarDatos(['nombre' => $nombre], [
            'nombre' => ['label' => 'Nombre', 'rules' => "required|min_length[2]|max_length[{$max}]|is_unique[{$tabla}.nombre]"],
        ])) {
            return $r;
        }

        $db = db_connect();
        $db->table($tabla)->insert(['nombre' => $nombre, 'activo' => 1]);

        return $this->ok(['id' => (int) $db->insertID(), 'nombre' => $nombre], 201);
    }
}
