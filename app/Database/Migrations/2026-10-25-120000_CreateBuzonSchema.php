<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Esquema completo de Buzón BUAP SJC.
 * Equivalente manual en /database/schema.sql
 */
class CreateBuzonSchema extends Migration
{
    private function attrs(): array
    {
        // Solo MySQL/MariaDB entiende ENGINE; SQLite y otros lo ignoran.
        return $this->db->DBDriver === 'MySQLi' ? ['ENGINE' => 'InnoDB'] : [];
    }

    public function up(): void
    {
        $id = ['type' => 'INT', 'constraint' => 11, 'unsigned' => true, 'auto_increment' => true];
        $fk = ['type' => 'INT', 'constraint' => 11, 'unsigned' => true];

        // ---------------------------------------------------------- usuarios
        $this->forge->addField([
            'id'            => $id,
            'nombre'        => ['type' => 'VARCHAR', 'constraint' => 100],
            'matricula'     => ['type' => 'VARCHAR', 'constraint' => 20],
            'carrera'       => ['type' => 'VARCHAR', 'constraint' => 10, 'null' => true], // ISTII | IAA | IPGI
            'password_hash' => ['type' => 'VARCHAR', 'constraint' => 255],
            'rol'           => ['type' => 'VARCHAR', 'constraint' => 20, 'default' => 'alumno'],
            'activo'        => ['type' => 'TINYINT', 'constraint' => 1, 'default' => 1],
            'created_at'    => ['type' => 'DATETIME', 'null' => true],
            'updated_at'    => ['type' => 'DATETIME', 'null' => true],
        ]);
        $this->forge->addPrimaryKey('id');
        $this->forge->addUniqueKey('matricula');
        $this->forge->createTable('usuarios', true, $this->attrs());

        // ----------------------------------------------------------- estados
        $this->forge->addField([
            'id'       => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true],
            'clave'    => ['type' => 'VARCHAR', 'constraint' => 30],
            'etiqueta' => ['type' => 'VARCHAR', 'constraint' => 60],
            // 1 = el reporte ya no está activo (pasa a la lista de "cerrados")
            'es_final' => ['type' => 'TINYINT', 'constraint' => 1, 'default' => 0],
            'orden'    => ['type' => 'INT', 'constraint' => 11, 'default' => 0],
        ]);
        $this->forge->addPrimaryKey('id');
        $this->forge->addUniqueKey('clave');
        $this->forge->createTable('estados', true, $this->attrs());

        // -------------------------------------------------------- categorias
        $this->forge->addField([
            'id'     => $id,
            'nombre' => ['type' => 'VARCHAR', 'constraint' => 60],
            'activo' => ['type' => 'TINYINT', 'constraint' => 1, 'default' => 1],
        ]);
        $this->forge->addPrimaryKey('id');
        $this->forge->addUniqueKey('nombre');
        $this->forge->createTable('categorias', true, $this->attrs());

        // ------------------------------------------------------- ubicaciones
        $this->forge->addField([
            'id'     => $id,
            'nombre' => ['type' => 'VARCHAR', 'constraint' => 120],
            'activo' => ['type' => 'TINYINT', 'constraint' => 1, 'default' => 1],
        ]);
        $this->forge->addPrimaryKey('id');
        $this->forge->addUniqueKey('nombre');
        $this->forge->createTable('ubicaciones', true, $this->attrs());

        // ---------------------------------------------------------- reportes
        $this->forge->addField([
            'id'           => $id,
            'nombre'       => ['type' => 'VARCHAR', 'constraint' => 150],
            'descripcion'  => ['type' => 'TEXT'],
            'ubicacion'    => ['type' => 'VARCHAR', 'constraint' => 200],
            'fecha_hora'   => ['type' => 'DATETIME'],
            'id_usuario'   => $fk,
            'rol_usuario'  => ['type' => 'VARCHAR', 'constraint' => 20],
            'id_estado'    => $fk + ['default' => 1],
            'id_categoria' => $fk + ['null' => true],
            'prioridad'    => ['type' => 'VARCHAR', 'constraint' => 10, 'default' => 'media'],
            'id_asignado'  => $fk + ['null' => true],
            'confirmado_at' => ['type' => 'DATETIME', 'null' => true],
            'updated_at'   => ['type' => 'DATETIME', 'null' => true],
            'deleted_at'   => ['type' => 'DATETIME', 'null' => true],
        ]);
        $this->forge->addPrimaryKey('id');
        $this->forge->addKey('id_usuario');
        $this->forge->addKey('id_estado');
        $this->forge->addKey('fecha_hora');
        $this->forge->addForeignKey('id_usuario', 'usuarios', 'id', 'CASCADE', 'RESTRICT');
        $this->forge->addForeignKey('id_estado', 'estados', 'id', 'CASCADE', 'RESTRICT');
        $this->forge->addForeignKey('id_categoria', 'categorias', 'id', 'CASCADE', 'SET NULL');
        $this->forge->addForeignKey('id_asignado', 'usuarios', 'id', 'CASCADE', 'SET NULL');
        $this->forge->createTable('reportes', true, $this->attrs());

        // -------------------------------------------------- reporte_imagenes
        $this->forge->addField([
            'id'         => $id,
            'id_reporte' => $fk,
            'archivo'    => ['type' => 'VARCHAR', 'constraint' => 255],
        ]);
        $this->forge->addPrimaryKey('id');
        $this->forge->addKey('id_reporte');
        $this->forge->addForeignKey('id_reporte', 'reportes', 'id', 'CASCADE', 'CASCADE');
        $this->forge->createTable('reporte_imagenes', true, $this->attrs());

        // ------------------------------------------------------- anotaciones
        $this->forge->addField([
            'id'                 => $id,
            'id_reporte'         => $fk,
            'id_autor'           => $fk,
            'rol_autor'          => ['type' => 'VARCHAR', 'constraint' => 20],
            'comentario'         => ['type' => 'TEXT', 'null' => true],
            'id_estado_anterior' => $fk + ['null' => true],
            'id_estado_nuevo'    => $fk + ['null' => true],
            'fecha_hora'         => ['type' => 'DATETIME'],
        ]);
        $this->forge->addPrimaryKey('id');
        $this->forge->addKey('id_reporte');
        $this->forge->addForeignKey('id_reporte', 'reportes', 'id', 'CASCADE', 'CASCADE');
        $this->forge->addForeignKey('id_autor', 'usuarios', 'id', 'CASCADE', 'RESTRICT');
        $this->forge->createTable('anotaciones', true, $this->attrs());

        // ------------------------------------------------------------ apoyos
        $this->forge->addField([
            'id'         => $id,
            'id_reporte' => $fk,
            'id_usuario' => $fk,
            'fecha_hora' => ['type' => 'DATETIME'],
        ]);
        $this->forge->addPrimaryKey('id');
        $this->forge->addUniqueKey(['id_reporte', 'id_usuario']);
        $this->forge->addForeignKey('id_reporte', 'reportes', 'id', 'CASCADE', 'CASCADE');
        $this->forge->addForeignKey('id_usuario', 'usuarios', 'id', 'CASCADE', 'CASCADE');
        $this->forge->createTable('apoyos', true, $this->attrs());

        // ------------------------------------------------------ dispositivos
        $this->forge->addField([
            'id'         => $id,
            'id_usuario' => $fk,
            'fcm_token'  => ['type' => 'VARCHAR', 'constraint' => 255],
            'updated_at' => ['type' => 'DATETIME', 'null' => true],
        ]);
        $this->forge->addPrimaryKey('id');
        $this->forge->addUniqueKey('fcm_token');
        $this->forge->addKey('id_usuario');
        $this->forge->addForeignKey('id_usuario', 'usuarios', 'id', 'CASCADE', 'CASCADE');
        $this->forge->createTable('dispositivos', true, $this->attrs());
    }

    public function down(): void
    {
        foreach (['dispositivos', 'apoyos', 'anotaciones', 'reporte_imagenes', 'reportes', 'ubicaciones', 'categorias', 'estados', 'usuarios'] as $tabla) {
            $this->forge->dropTable($tabla, true);
        }
    }
}
