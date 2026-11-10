<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Se eliminan los reportes anónimos: todo reporte queda ligado a su autor.
 *
 * En una instalación nueva la columna ya no se crea (ver CreateBuzonSchema), así que
 * esta migración no hace nada. En una base que ya existía, borra la columna es_anonimo.
 * A partir de aquí, los reportes que antes eran anónimos muestran su autor al admin.
 */
class QuitarReportesAnonimos extends Migration
{
    public function up(): void
    {
        if ($this->db->fieldExists('es_anonimo', 'reportes')) {
            $this->forge->dropColumn('reportes', 'es_anonimo');
        }
    }

    public function down(): void
    {
        if (! $this->db->fieldExists('es_anonimo', 'reportes')) {
            $this->forge->addColumn('reportes', [
                'es_anonimo' => ['type' => 'TINYINT', 'constraint' => 1, 'default' => 0],
            ]);
        }
    }
}
