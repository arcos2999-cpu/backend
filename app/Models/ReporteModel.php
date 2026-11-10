<?php

namespace App\Models;

use CodeIgniter\Database\BaseBuilder;
use CodeIgniter\Model;

class ReporteModel extends Model
{
    protected $table            = 'reportes';
    protected $primaryKey       = 'id';
    protected $returnType       = 'array';
    protected $useAutoIncrement = true;
    protected $useSoftDeletes   = true;
    protected $deletedField     = 'deleted_at';
    protected $allowedFields    = [
        'nombre', 'descripcion', 'ubicacion', 'fecha_hora', 'id_usuario', 'rol_usuario',
        'id_estado', 'id_categoria', 'prioridad', 'id_asignado', 'confirmado_at',
    ];
    protected $useTimestamps    = true;
    protected $createdField     = '';
    protected $updatedField     = 'updated_at';

    private const PESO_PRIORIDAD = "CASE r.prioridad WHEN 'urgente' THEN 4 WHEN 'alta' THEN 3 WHEN 'media' THEN 2 ELSE 1 END";

    /**
     * Lista paginada con filtros.
     *
     * @param array<string, mixed> $f      scope, estado, categoria, prioridad, q, ubicacion, desde, hasta, orden, limit, page, per_page
     * @param array<string, mixed> $viewer usuario que consulta (id, rol, nombre)
     *
     * @return array{rows: list<array<string, mixed>>, total: int}
     */
    public function listar(array $f, array $viewer): array
    {
        $total = $this->base($f, $viewer)->countAllResults();

        $vid = (int) $viewer['id'];
        $b   = $this->base($f, $viewer)->select(
            'r.id, r.nombre, r.descripcion, r.ubicacion, r.fecha_hora, r.id_usuario, r.rol_usuario, r.id_estado, '
            . 'r.id_categoria, r.prioridad, r.confirmado_at, '
            . 'e.etiqueta AS estado, e.clave AS estado_clave, e.es_final, c.nombre AS categoria, u.nombre AS autor_nombre, '
            . '(SELECT COUNT(*) FROM apoyos a WHERE a.id_reporte = r.id) AS apoyos, '
            . "(SELECT COUNT(*) FROM apoyos a2 WHERE a2.id_reporte = r.id AND a2.id_usuario = {$vid}) AS yo_apoye",
            false
        );

        switch ($f['orden'] ?? 'reciente') {
            case 'prioridad':
                $b->orderBy(self::PESO_PRIORIDAD, 'DESC', false)->orderBy('apoyos', 'DESC')->orderBy('r.fecha_hora', 'DESC');
                break;
            case 'apoyos':
                $b->orderBy('apoyos', 'DESC')->orderBy('r.fecha_hora', 'DESC');
                break;
            default:
                $b->orderBy('r.fecha_hora', 'DESC')->orderBy('r.id', 'DESC');
        }

        if (isset($f['limit']) && $f['limit'] > 0) {
            $b->limit((int) $f['limit']);
        } else {
            $porPagina = max(1, min(50, (int) ($f['per_page'] ?? 20)));
            $pagina    = max(1, (int) ($f['page'] ?? 1));
            $b->limit($porPagina, ($pagina - 1) * $porPagina);
        }

        $rows = $b->get()->getResultArray();

        return ['rows' => $this->conImagenes($rows, $viewer), 'total' => $total];
    }

    /** Un reporte con todos sus datos presentables, o null. */
    public function detalle(int $id, array $viewer): ?array
    {
        $vid = (int) $viewer['id'];
        $row = $this->base(['scope' => 'comunidad'], $viewer)
            ->select(
                'r.id, r.nombre, r.descripcion, r.ubicacion, r.fecha_hora, r.id_usuario, r.rol_usuario, r.id_estado, '
                . 'r.id_categoria, r.prioridad, r.confirmado_at, '
                . 'e.etiqueta AS estado, e.clave AS estado_clave, e.es_final, c.nombre AS categoria, u.nombre AS autor_nombre, '
                . '(SELECT COUNT(*) FROM apoyos a WHERE a.id_reporte = r.id) AS apoyos, '
                . "(SELECT COUNT(*) FROM apoyos a2 WHERE a2.id_reporte = r.id AND a2.id_usuario = {$vid}) AS yo_apoye",
                false
            )
            ->where('r.id', $id)
            ->get()->getRowArray();

        if ($row === null) {
            return null;
        }

        $item = $this->conImagenes([$row], $viewer)[0];
        $item['anotaciones'] = model(AnotacionModel::class)->historial($id);

        return $item;
    }

    private function base(array $f, array $viewer): BaseBuilder
    {
        $b = $this->db->table('reportes r')
            ->join('estados e', 'e.id = r.id_estado')
            ->join('categorias c', 'c.id = r.id_categoria', 'left')
            ->join('usuarios u', 'u.id = r.id_usuario', 'left')
            ->where('r.deleted_at', null);

        switch ($f['scope'] ?? 'comunidad') {
            case 'mios':
                $b->where('r.id_usuario', (int) $viewer['id']);
                break;
            case 'activos':
                $b->where('e.es_final', 0);
                break;
            case 'cerrados':
                $b->where('e.es_final', 1);
                break;
        }

        if (! empty($f['estado'])) {
            $b->where('r.id_estado', (int) $f['estado']);
        }
        if (! empty($f['categoria'])) {
            $b->where('r.id_categoria', (int) $f['categoria']);
        }
        if (! empty($f['prioridad'])) {
            $b->where('r.prioridad', (string) $f['prioridad']);
        }
        if (! empty($f['ubicacion'])) {
            $b->like('r.ubicacion', (string) $f['ubicacion']);
        }
        if (! empty($f['q'])) {
            $b->groupStart()
                ->like('r.nombre', (string) $f['q'])
                ->orLike('r.descripcion', (string) $f['q'])
                ->orLike('r.ubicacion', (string) $f['q'])
                ->groupEnd();
        }
        if (! empty($f['desde']) && preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) $f['desde'])) {
            $b->where('r.fecha_hora >=', $f['desde'] . ' 00:00:00');
        }
        if (! empty($f['hasta']) && preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) $f['hasta'])) {
            $b->where('r.fecha_hora <=', $f['hasta'] . ' 23:59:59');
        }

        return $b;
    }

    /**
     * Agrega las URLs de imágenes y da formato final a cada fila.
     *
     * @param list<array<string, mixed>> $rows
     *
     * @return list<array<string, mixed>>
     */
    private function conImagenes(array $rows, array $viewer): array
    {
        if ($rows === []) {
            return [];
        }

        $ids  = array_map(static fn ($r) => (int) $r['id'], $rows);
        $imgs = $this->db->table('reporte_imagenes')->whereIn('id_reporte', $ids)->orderBy('id', 'ASC')->get()->getResultArray();

        $porReporte = [];
        $dir        = trim((string) config('Buzon')->uploadDir, '/');
        foreach ($imgs as $i) {
            $porReporte[(int) $i['id_reporte']][] = base_url($dir . '/' . $i['archivo']);
        }

        return array_map(fn ($r) => self::presentar($r, $viewer, $porReporte[(int) $r['id']] ?? []), $rows);
    }

    /**
     * Da formato a un reporte aplicando las reglas de privacidad:
     *  - El id del usuario nunca sale del servidor.
     *  - El nombre del autor solo lo ven el propio autor y el admin.
     *  - El rol de quien reportó (alumno, maestro, personal) lo ve todo el mundo.
     *  - No existen reportes anónimos: todo reporte queda ligado a su autor.
     */
    public static function presentar(array $r, array $viewer, array $imagenes): array
    {
        $esMio   = (int) $r['id_usuario'] === (int) $viewer['id'];
        $esAdmin = $viewer['rol'] === 'admin';

        $autor = null;
        if ($esMio) {
            $autor = $viewer['nombre'];
        } elseif ($esAdmin) {
            $autor = $r['autor_nombre'];
        }

        return [
            'id'           => (int) $r['id'],
            'nombre'       => $r['nombre'],
            'descripcion'  => $r['descripcion'],
            'ubicacion'    => $r['ubicacion'],
            'fecha_hora'   => $r['fecha_hora'],
            'id_estado'    => (int) $r['id_estado'],
            'estado'       => $r['estado'],
            'estado_clave' => $r['estado_clave'],
            'es_final'     => (int) $r['es_final'] === 1,
            'prioridad'    => $r['prioridad'],
            'id_categoria' => $r['id_categoria'] !== null ? (int) $r['id_categoria'] : null,
            'categoria'    => $r['categoria'],
            'autor'        => $autor,
            'rol_usuario'  => $r['rol_usuario'],
            'apoyos'       => (int) $r['apoyos'],
            'yo_apoye'     => (int) $r['yo_apoye'] > 0,
            'es_mio'       => $esMio,
            'confirmado'   => $r['confirmado_at'] !== null,
            'imagenes'     => $imagenes,
        ];
    }
}
