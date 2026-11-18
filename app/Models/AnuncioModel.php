<?php

namespace App\Models;

use CodeIgniter\Model;
use Config\Buzon;

/**
 * Anuncios del muro institucional. Solo la administración los publica; todos los usuarios con
 * sesión pueden darles like, guardarlos, marcarlos como favoritos y comentarlos.
 */
class AnuncioModel extends Model
{
    protected $table         = 'anuncios';
    protected $primaryKey    = 'id';
    protected $returnType    = 'array';
    protected $allowedFields = ['id_autor', 'titulo', 'texto', 'lugar', 'fecha_evento', 'imagen', 'fecha_hora'];
    protected $useTimestamps = false;

    /** Tabla de cada reacción y la lista (scope) que forma. */
    public const REACCIONES = [
        'like'     => 'anuncio_likes',
        'guardar'  => 'anuncio_guardados',
        'favorito' => 'anuncio_favoritos',
    ];
    public const SCOPES = [
        'likes'     => 'anuncio_likes',
        'guardados' => 'anuncio_guardados',
        'favoritos' => 'anuncio_favoritos',
    ];

    /**
     * Lista paginada de anuncios.
     *   scope: todos | likes | guardados | favoritos
     * En likes/guardados/favoritos el orden es el de la "pila": lo último que el usuario marcó va primero.
     *
     * @param array{scope?: string, q?: string, page: int, per_page: int} $f
     *
     * @return array{rows: list<array<string, mixed>>, total: int}
     */
    public function listar(array $f, int $idUsuario): array
    {
        $b     = $this->db->table('anuncios a')->join('usuarios u', 'u.id = a.id_autor', 'left');
        $orden = 'a.id DESC';

        $scope = (string) ($f['scope'] ?? 'todos');
        if (isset(self::SCOPES[$scope])) {
            $b->join(self::SCOPES[$scope] . ' x', 'x.id_anuncio = a.id AND x.id_usuario = ' . (int) $idUsuario, 'inner');
            $orden = 'x.id DESC';
        }

        $texto = trim((string) ($f['q'] ?? ''));
        if ($texto !== '') {
            $b->groupStart()
                ->like('a.titulo', $texto)
                ->orLike('a.texto', $texto)
                ->orLike('a.lugar', $texto)
                ->groupEnd();
        }

        $total = $b->countAllResults(false);
        $filas = $b->select($this->columnas($idUsuario), false)
            ->orderBy($orden)
            ->limit((int) $f['per_page'], ((int) $f['page'] - 1) * (int) $f['per_page'])
            ->get()->getResultArray();

        return ['rows' => array_map([$this, 'formatear'], $filas), 'total' => $total];
    }

    /** @return array<string, mixed>|null */
    public function detalle(int $id, int $idUsuario): ?array
    {
        $fila = $this->db->table('anuncios a')
            ->join('usuarios u', 'u.id = a.id_autor', 'left')
            ->select($this->columnas($idUsuario), false)
            ->where('a.id', $id)
            ->get()->getRowArray();

        return $fila ? $this->formatear($fila) : null;
    }

    /** SELECT con los contadores y las marcas del usuario actual (si le dio like, lo guardó, etc.). */
    private function columnas(int $idUsuario): string
    {
        $u = (int) $idUsuario;

        return "a.id, a.titulo, a.texto, a.lugar, a.fecha_evento, a.imagen, a.fecha_hora, u.nombre AS autor,
            (SELECT COUNT(*) FROM anuncio_likes WHERE id_anuncio = a.id) AS likes,
            (SELECT COUNT(*) FROM anuncio_comentarios WHERE id_anuncio = a.id) AS comentarios,
            (SELECT COUNT(*) FROM anuncio_likes WHERE id_anuncio = a.id AND id_usuario = {$u}) AS yo_like,
            (SELECT COUNT(*) FROM anuncio_guardados WHERE id_anuncio = a.id AND id_usuario = {$u}) AS yo_guardado,
            (SELECT COUNT(*) FROM anuncio_favoritos WHERE id_anuncio = a.id AND id_usuario = {$u}) AS yo_favorito";
    }

    /**
     * @param array<string, mixed> $f
     *
     * @return array<string, mixed>
     */
    private function formatear(array $f): array
    {
        return [
            'id'           => (int) $f['id'],
            'titulo'       => $f['titulo'],
            'texto'        => $f['texto'],
            'lugar'        => $f['lugar'],
            'fecha_evento' => $f['fecha_evento'],
            'imagen'       => $f['imagen'] ? base_url(trim(config(Buzon::class)->uploadDirAnuncios, '/') . '/' . $f['imagen']) : null,
            'fecha_hora'   => $f['fecha_hora'],
            'autor'        => $f['autor'] ?? 'Administración',
            'likes'        => (int) $f['likes'],
            'comentarios'  => (int) $f['comentarios'],
            'yo_like'      => (int) $f['yo_like'] > 0,
            'yo_guardado'  => (int) $f['yo_guardado'] > 0,
            'yo_favorito'  => (int) $f['yo_favorito'] > 0,
        ];
    }
}
