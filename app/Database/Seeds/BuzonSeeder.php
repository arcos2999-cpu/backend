<?php

namespace App\Database\Seeds;

use CodeIgniter\CLI\CLI;
use CodeIgniter\Database\Seeder;
use Config\Buzon;

/**
 * Carga estados, categorías, ubicaciones de ejemplo y el primer administrador.
 *   php spark db:seed BuzonSeeder
 * Es seguro ejecutarlo más de una vez (no duplica datos).
 */
class BuzonSeeder extends Seeder
{
    public function run(): void
    {
        $this->estados();
        $this->categorias();
        $this->ubicaciones();
        $this->admin();
    }

    private function estados(): void
    {
        $estados = [
            [Buzon::ESTADO_PENDIENTE,   'pendiente',   'Pendiente',                    0, 1],
            [Buzon::ESTADO_REVISION,    'en_revision', 'En revisión',                  0, 2],
            [Buzon::ESTADO_EN_PROCESO,  'en_proceso',  'Trabajando en la solución',    0, 3],
            [Buzon::ESTADO_NO_VIABLE,   'no_viable',   'No es posible solucionarlo',   1, 4],
            [Buzon::ESTADO_SOLUCIONADO, 'solucionado', 'Solucionado',                  1, 5],
            [Buzon::ESTADO_REABIERTO,   'reabierto',   'Reabierto',                    0, 6],
        ];

        foreach ($estados as [$id, $clave, $etiqueta, $final, $orden]) {
            $fila = ['id' => $id, 'clave' => $clave, 'etiqueta' => $etiqueta, 'es_final' => $final, 'orden' => $orden];
            if ($this->db->table('estados')->where('id', $id)->countAllResults() === 0) {
                $this->db->table('estados')->insert($fila);
            } else {
                $this->db->table('estados')->where('id', $id)->update($fila);
            }
        }
    }

    private function categorias(): void
    {
        foreach (['Infraestructura', 'Limpieza', 'Seguridad', 'Tecnología / Internet', 'Académico', 'Servicios (agua, luz)', 'Otro'] as $nombre) {
            if ($this->db->table('categorias')->where('nombre', $nombre)->countAllResults() === 0) {
                $this->db->table('categorias')->insert(['nombre' => $nombre, 'activo' => 1]);
            }
        }
    }

    /** Ubicaciones de EJEMPLO: edítalas con los nombres reales del plantel. */
    private function ubicaciones(): void
    {
        $lista = [
            'Edificio A', 'Edificio B', 'Edificio C', 'Laboratorio de cómputo', 'Laboratorio de ciencias',
            'Biblioteca', 'Cafetería', 'Baños', 'Estacionamiento', 'Canchas deportivas',
            'Auditorio', 'Áreas verdes', 'Acceso principal',
        ];
        foreach ($lista as $nombre) {
            if ($this->db->table('ubicaciones')->where('nombre', $nombre)->countAllResults() === 0) {
                $this->db->table('ubicaciones')->insert(['nombre' => $nombre, 'activo' => 1]);
            }
        }
    }

    private function admin(): void
    {
        /** @var Buzon $cfg */
        $cfg = config(Buzon::class);

        $matricula = trim($cfg->adminMatricula);
        if ($this->db->table('usuarios')->where('matricula', $matricula)->countAllResults() > 0) {
            return;
        }

        $generada = $cfg->adminPassword === '';
        $password = $generada ? bin2hex(random_bytes(6)) : $cfg->adminPassword;
        $ahora    = date('Y-m-d H:i:s');

        $this->db->table('usuarios')->insert([
            'nombre'        => $cfg->adminName,
            'matricula'     => $matricula,
            'carrera'       => null,
            'password_hash' => password_hash($password, PASSWORD_DEFAULT),
            'rol'           => 'admin',
            'activo'        => 1,
            'created_at'    => $ahora,
            'updated_at'    => $ahora,
        ]);

        if (is_cli()) {
            CLI::write('Administrador creado:', 'green');
            CLI::write('  matrícula:  ' . $matricula);
            CLI::write('  contraseña: ' . $password . ($generada ? '   (generada: cámbiala/guárdala ahora)' : ''));
        }
    }
}
