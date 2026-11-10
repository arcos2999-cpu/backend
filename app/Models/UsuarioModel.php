<?php

namespace App\Models;

use CodeIgniter\Model;

class UsuarioModel extends Model
{
    protected $table            = 'usuarios';
    protected $primaryKey       = 'id';
    protected $returnType       = 'array';
    protected $useAutoIncrement = true;
    protected $allowedFields    = ['nombre', 'matricula', 'carrera', 'password_hash', 'rol', 'activo'];
    protected $useTimestamps    = true;
    protected $createdField     = 'created_at';
    protected $updatedField     = 'updated_at';

    /** Datos que se pueden mostrar/enviar a la app (nunca el hash). */
    public static function publico(array $u): array
    {
        return [
            'id'        => (int) $u['id'],
            'nombre'    => $u['nombre'],
            'matricula' => $u['matricula'],
            'carrera'   => $u['carrera'] ?? null,
            'rol'       => $u['rol'],
        ];
    }
}
