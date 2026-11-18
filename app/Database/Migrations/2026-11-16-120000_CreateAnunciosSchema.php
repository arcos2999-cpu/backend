<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Muro institucional: anuncios publicados por la administración y la actividad de cada usuario
 * (likes, guardados, favoritos, comentarios e historial). Todo queda ligado al usuario con sesión.
 */
class CreateAnunciosSchema extends Migration
{
    private function attrs(): array
    {
        return $this->db->DBDriver === 'MySQLi' ? ['ENGINE' => 'InnoDB'] : [];
    }

    public function up(): void
    {
        $id = ['type' => 'INT', 'constraint' => 11, 'unsigned' => true, 'auto_increment' => true];
        $fk = ['type' => 'INT', 'constraint' => 11, 'unsigned' => true];

        // ---------------------------------------------------------- anuncios
        $this->forge->addField([
            'id'           => $id,
            'id_autor'     => $fk,
            'titulo'       => ['type' => 'VARCHAR', 'constraint' => 150],
            'texto'        => ['type' => 'TEXT'],
            'lugar'        => ['type' => 'VARCHAR', 'constraint' => 200, 'null' => true],
            'fecha_evento' => ['type' => 'DATETIME', 'null' => true],
            'imagen'       => ['type' => 'VARCHAR', 'constraint' => 255, 'null' => true],
            'fecha_hora'   => ['type' => 'DATETIME'],
        ]);
        $this->forge->addPrimaryKey('id');
        $this->forge->addKey('fecha_hora');
        $this->forge->addForeignKey('id_autor', 'usuarios', 'id', 'CASCADE', 'RESTRICT');
        $this->forge->createTable('anuncios', true, $this->attrs());

        // ------------------------------------- likes / guardados / favoritos
        foreach (['anuncio_likes', 'anuncio_guardados', 'anuncio_favoritos'] as $tabla) {
            $this->forge->addField([
                'id'         => $id,
                'id_anuncio' => $fk,
                'id_usuario' => $fk,
                'fecha_hora' => ['type' => 'DATETIME'],
            ]);
            $this->forge->addPrimaryKey('id');
            $this->forge->addUniqueKey(['id_anuncio', 'id_usuario']);
            $this->forge->addKey('id_usuario');
            $this->forge->addForeignKey('id_anuncio', 'anuncios', 'id', 'CASCADE', 'CASCADE');
            $this->forge->addForeignKey('id_usuario', 'usuarios', 'id', 'CASCADE', 'CASCADE');
            $this->forge->createTable($tabla, true, $this->attrs());
        }

        // ------------------------------------------------------- comentarios
        $this->forge->addField([
            'id'         => $id,
            'id_anuncio' => $fk,
            'id_usuario' => $fk,
            'texto'      => ['type' => 'VARCHAR', 'constraint' => 300],
            'fecha_hora' => ['type' => 'DATETIME'],
        ]);
        $this->forge->addPrimaryKey('id');
        $this->forge->addKey('id_anuncio');
        $this->forge->addForeignKey('id_anuncio', 'anuncios', 'id', 'CASCADE', 'CASCADE');
        $this->forge->addForeignKey('id_usuario', 'usuarios', 'id', 'CASCADE', 'CASCADE');
        $this->forge->createTable('anuncio_comentarios', true, $this->attrs());

        // --------------------------------------------- historial de actividad
        // Guarda el título para que el historial se siga entendiendo aunque se borre el anuncio.
        $this->forge->addField([
            'id'         => $id,
            'id_usuario' => $fk,
            'id_anuncio' => $fk + ['null' => true],
            'tipo'       => ['type' => 'VARCHAR', 'constraint' => 20], // like | guardar | favorito | comentario
            'quitar'     => ['type' => 'TINYINT', 'constraint' => 1, 'default' => 0],
            'titulo'     => ['type' => 'VARCHAR', 'constraint' => 150],
            'fecha_hora' => ['type' => 'DATETIME'],
        ]);
        $this->forge->addPrimaryKey('id');
        $this->forge->addKey('id_usuario');
        $this->forge->addForeignKey('id_usuario', 'usuarios', 'id', 'CASCADE', 'CASCADE');
        $this->forge->addForeignKey('id_anuncio', 'anuncios', 'id', 'SET NULL', 'CASCADE');
        $this->forge->createTable('anuncio_actividad', true, $this->attrs());
    }

    public function down(): void
    {
        foreach (['anuncio_actividad', 'anuncio_comentarios', 'anuncio_favoritos', 'anuncio_guardados', 'anuncio_likes', 'anuncios'] as $tabla) {
            $this->forge->dropTable($tabla, true);
        }
    }
}
