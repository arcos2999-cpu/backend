<?php

namespace App\Controllers\Api;

use CodeIgniter\HTTP\ResponseInterface;
use Config\Buzon;

class EstadisticasController extends ApiController
{
    /** GET /api/v1/estadisticas  (admin) */
    public function index(): ResponseInterface
    {
        $db = db_connect();

        $porEstado = $db->table('estados e')
            ->select('e.id, e.etiqueta, e.es_final, COUNT(r.id) AS total', false)
            ->join('reportes r', 'r.id_estado = e.id AND r.deleted_at IS NULL', 'left')
            ->groupBy('e.id, e.etiqueta, e.es_final, e.orden')
            ->orderBy('e.orden')
            ->get()->getResultArray();

        $porCategoria = $db->table('reportes r')
            ->select("COALESCE(c.nombre, 'Sin categoría') AS nombre, COUNT(*) AS total", false)
            ->join('categorias c', 'c.id = r.id_categoria', 'left')
            ->where('r.deleted_at', null)
            ->groupBy('c.nombre')->orderBy('total', 'DESC')
            ->get()->getResultArray();

        $porUbicacion = $db->table('reportes r')
            ->select('r.ubicacion AS nombre, COUNT(*) AS total', false)
            ->where('r.deleted_at', null)
            ->groupBy('r.ubicacion')->orderBy('total', 'DESC')->limit(5)
            ->get()->getResultArray();

        // Tiempo promedio de resolución: desde el reporte hasta la última vez que pasó a "Solucionado".
        $resueltos = $db->table('reportes r')
            ->select('r.fecha_hora, MAX(a.fecha_hora) AS resuelto', false)
            ->join('anotaciones a', 'a.id_reporte = r.id AND a.id_estado_nuevo = ' . Buzon::ESTADO_SOLUCIONADO . ' AND a.id_estado_anterior <> a.id_estado_nuevo', 'inner')
            ->where('r.deleted_at', null)->where('r.id_estado', Buzon::ESTADO_SOLUCIONADO)
            ->groupBy('r.id, r.fecha_hora')
            ->get()->getResultArray();

        $horas = null;
        if ($resueltos !== []) {
            $suma = 0;
            foreach ($resueltos as $r) {
                $suma += max(0, strtotime($r['resuelto']) - strtotime($r['fecha_hora']));
            }
            $horas = round($suma / count($resueltos) / 3600, 1);
        }

        $total   = (int) $db->table('reportes')->where('deleted_at', null)->countAllResults();
        $activos = (int) $db->table('reportes r')->join('estados e', 'e.id = r.id_estado')
            ->where('r.deleted_at', null)->where('e.es_final', 0)->countAllResults();

        $num = static fn (array $filas) => array_map(
            static fn ($f) => ['nombre' => $f['nombre'], 'total' => (int) $f['total']],
            $filas
        );

        return $this->ok([
            'total'                       => $total,
            'activos'                     => $activos,
            'cerrados'                    => $total - $activos,
            'por_estado'                  => array_map(static fn ($f) => [
                'id' => (int) $f['id'], 'etiqueta' => $f['etiqueta'], 'total' => (int) $f['total'],
            ], $porEstado),
            'por_categoria'               => $num($porCategoria),
            'ubicaciones_con_mas_reportes' => $num($porUbicacion),
            'horas_promedio_resolucion'   => $horas,
        ]);
    }
}
