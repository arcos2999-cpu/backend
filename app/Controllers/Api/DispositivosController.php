<?php

namespace App\Controllers\Api;

use CodeIgniter\HTTP\ResponseInterface;

/** Registro de tokens FCM para notificaciones push. */
class DispositivosController extends ApiController
{
    /** POST /api/v1/dispositivos  {fcm_token} */
    public function registrar(): ResponseInterface
    {
        $token = trim((string) ($this->input()['fcm_token'] ?? ''));
        if ($token === '' || strlen($token) > 255) {
            return $this->fail('Token inválido.', 422);
        }

        $t     = db_connect()->table('dispositivos');
        $ahora = date('Y-m-d H:i:s');

        // Un token pertenece a un solo usuario: si alguien más inicia sesión en el mismo teléfono, se reasigna.
        if ($t->where('fcm_token', $token)->countAllResults() > 0) {
            db_connect()->table('dispositivos')->where('fcm_token', $token)
                ->update(['id_usuario' => $this->user()['id'], 'updated_at' => $ahora]);
        } else {
            db_connect()->table('dispositivos')
                ->insert(['id_usuario' => $this->user()['id'], 'fcm_token' => $token, 'updated_at' => $ahora]);
        }

        return $this->ok(['registrado' => true], 201);
    }

    /** POST /api/v1/dispositivos/baja  {fcm_token} — al cerrar sesión */
    public function baja(): ResponseInterface
    {
        $token = trim((string) ($this->input()['fcm_token'] ?? ''));
        if ($token !== '') {
            db_connect()->table('dispositivos')
                ->where('fcm_token', $token)->where('id_usuario', $this->user()['id'])->delete();
        }

        return $this->ok(['baja' => true]);
    }
}
