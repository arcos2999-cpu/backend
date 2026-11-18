<?php

namespace App\Database\Seeds;

use CodeIgniter\CLI\CLI;
use CodeIgniter\Database\Seeder;

/**
 * Anuncios de ejemplo para que el muro no salga vacío (los publica el primer administrador).
 *   php spark db:seed AnunciosSeeder
 * Es seguro ejecutarlo más de una vez: si ya hay anuncios, no hace nada.
 */
class AnunciosSeeder extends Seeder
{
    public function run(): void
    {
        if ($this->db->table('anuncios')->countAllResults() > 0) {
            CLI::write('Ya hay anuncios; no se agregó nada.');

            return;
        }

        $admin = $this->db->table('usuarios')->where('rol', 'admin')->orderBy('id')->get()->getRowArray();
        if ($admin === null) {
            CLI::error('No hay un administrador. Ejecuta primero: php spark db:seed BuzonSeeder');

            return;
        }

        $hoy = strtotime('today 10:00');
        $filas = [
            [
                'titulo'       => 'Bienvenidos al muro institucional',
                'texto'        => 'Aquí la administración y los profesores comparten avisos y próximos eventos. Puedes dar like, guardar y comentar los anuncios. El buzón de quejas sigue disponible en la pestaña “Quejas”.',
                'lugar'        => null,
                'fecha_evento' => null,
                'fecha_hora'   => date('Y-m-d H:i:s', $hoy - 3 * 3600),
            ],
            [
                'titulo'       => 'Plática de orientación para nuevo ingreso',
                'texto'        => 'Habrá una plática sobre servicios escolares, becas y reglamento. Se recomienda llegar 10 minutos antes.',
                'lugar'        => 'Auditorio del complejo',
                'fecha_evento' => date('Y-m-d H:i:00', strtotime('+7 days 11:00')),
                'fecha_hora'   => date('Y-m-d H:i:s', $hoy - 2 * 3600),
            ],
            [
                'titulo'       => 'Jornada de limpieza y mantenimiento',
                'texto'        => 'Se informa que algunas áreas tendrán acceso limitado durante la jornada. Si encuentras un problema, repórtalo desde el buzón de quejas.',
                'lugar'        => 'Edificios A y B',
                'fecha_evento' => date('Y-m-d H:i:00', strtotime('+10 days 08:00')),
                'fecha_hora'   => date('Y-m-d H:i:s', $hoy - 3600),
            ],
        ];

        foreach ($filas as $f) {
            $this->db->table('anuncios')->insert($f + ['id_autor' => $admin['id'], 'imagen' => null]);
        }

        CLI::write('Se agregaron ' . count($filas) . ' anuncios de ejemplo.', 'green');
    }
}
