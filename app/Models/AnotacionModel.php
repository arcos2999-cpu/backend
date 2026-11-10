<?php

namespace App\Models;

use CodeIgniter\Model;

class AnotacionModel extends Model
{
    protected $table         = 'anotaciones';
    protected $primaryKey    = 'id';
    protected $returnType    = 'array';
    protected $allowedFields = [
        'id_reporte', 'id_autor', 'rol_autor', 'comentario',
        'id_estado_anterior', 'id_estado_nuevo', 'fecha_hora',
    ];
    protected $useTimestamps = false;

    /** Historial de un reporte, lo más nuevo primero. */
    public function historial(int $idReporte): array
    {
        $filas = $this->db->table('anotaciones a')
            ->select('a.id, a.comentario, a.fecha_hora, a.rol_autor, a.id_estado_anterior, a.id_estado_nuevo, ea.etiqueta AS estado_anterior, en.etiqueta AS estado_nuevo')
            ->join('estados ea', 'ea.id = a.id_estado_anterior', 'left')
            ->join('estados en', 'en.id = a.id_estado_nuevo', 'left')
            ->where('a.id_reporte', $idReporte)
            ->orderBy('a.id', 'DESC')
            ->get()->getResultArray();

        return array_map(static fn ($f) => [
            'id'              => (int) $f['id'],
            'comentario'      => $f['comentario'],
            'fecha_hora'      => $f['fecha_hora'],
            'autor'           => $f['rol_autor'] === 'admin' ? 'Administración' : 'Usuario',
            'estado_anterior' => $f['estado_anterior'],
            'estado_nuevo'    => $f['estado_nuevo'],
            'cambio_estado'   => $f['id_estado_anterior'] !== null && $f['id_estado_nuevo'] !== null
                                 && (int) $f['id_estado_anterior'] !== (int) $f['id_estado_nuevo'],
        ], $filas);
    }
}
