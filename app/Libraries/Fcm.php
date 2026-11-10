<?php

namespace App\Libraries;

use Config\Buzon;
use Firebase\JWT\JWT;
use Throwable;

/**
 * Envío de notificaciones push a la PWA con Firebase Cloud Messaging (HTTP v1).
 *
 * Si buzon.fcmCredentialsPath no está configurado, todas las funciones no hacen
 * nada, así el resto de la API funciona igual sin Firebase. Cualquier error al
 * enviar se registra en el log y NUNCA rompe la petición del usuario.
 */
class Fcm
{
    private Buzon $config;
    /** @var array<string, mixed>|null */
    private ?array $cred = null;

    public function __construct()
    {
        $this->config = config(Buzon::class);
    }

    public function habilitado(): bool
    {
        return $this->credenciales() !== null;
    }

    /** @param list<int> $idsUsuario */
    public function aUsuarios(array $idsUsuario, string $titulo, string $cuerpo, array $data = []): void
    {
        if ($idsUsuario === [] || ! $this->habilitado()) {
            return;
        }

        try {
            $tokens = db_connect()->table('dispositivos')
                ->select('fcm_token')->whereIn('id_usuario', $idsUsuario)
                ->get()->getResultArray();

            foreach ($tokens as $t) {
                $this->enviar($t['fcm_token'], $titulo, $cuerpo, $data);
            }
        } catch (Throwable $e) {
            log_message('error', 'FCM: ' . $e->getMessage());
        }
    }

    public function aAdmins(string $titulo, string $cuerpo, array $data = [], ?int $excepto = null): void
    {
        if (! $this->habilitado()) {
            return;
        }

        $b = db_connect()->table('usuarios')->select('id')->where('rol', 'admin')->where('activo', 1);
        if ($excepto !== null) {
            $b->where('id !=', $excepto);
        }
        $ids = array_map('intval', array_column($b->get()->getResultArray(), 'id'));

        $this->aUsuarios($ids, $titulo, $cuerpo, $data);
    }

    private function enviar(string $token, string $titulo, string $cuerpo, array $data): void
    {
        $access = $this->accessToken();
        if ($access === null) {
            return;
        }

        $cred = $this->credenciales();
        $url  = 'https://fcm.googleapis.com/v1/projects/' . $cred['project_id'] . '/messages:send';

        // Mensaje de solo datos: el service worker de la PWA arma la notificación
        // y decide qué pantalla abrir al tocarla (ver public/app/sw.js).
        $payload = ['message' => [
            'token'   => $token,
            'data'    => array_map('strval', $data + ['titulo' => $titulo, 'cuerpo' => $cuerpo]),
            'webpush' => ['headers' => ['Urgency' => 'high', 'TTL' => '86400']],
        ]];

        $resp = service('curlrequest')->post($url, [
            'headers'     => ['Authorization' => 'Bearer ' . $access],
            'json'        => $payload,
            'http_errors' => false,
            'timeout'     => 5,
        ]);

        $codigo = $resp->getStatusCode();
        if ($codigo === 404 || ($codigo === 400 && str_contains($resp->getBody(), 'INVALID_ARGUMENT'))) {
            // Token caducado o inválido: lo quitamos.
            db_connect()->table('dispositivos')->where('fcm_token', $token)->delete();
        } elseif ($codigo >= 300) {
            log_message('error', 'FCM respondió ' . $codigo . ': ' . substr($resp->getBody(), 0, 300));
        }
    }

    private function accessToken(): ?string
    {
        $cached = cache('buzon_fcm_access_token');
        if (is_string($cached) && $cached !== '') {
            return $cached;
        }

        $cred = $this->credenciales();
        if ($cred === null) {
            return null;
        }

        $uri = $cred['token_uri'] ?? 'https://oauth2.googleapis.com/token';
        $now = time();
        $jwt = JWT::encode([
            'iss'   => $cred['client_email'],
            'scope' => 'https://www.googleapis.com/auth/firebase.messaging',
            'aud'   => $uri,
            'iat'   => $now,
            'exp'   => $now + 3600,
        ], $cred['private_key'], 'RS256');

        $resp = service('curlrequest')->post($uri, [
            'form_params' => ['grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer', 'assertion' => $jwt],
            'http_errors' => false,
            'timeout'     => 5,
        ]);

        $json = json_decode($resp->getBody(), true);
        if (! is_array($json) || empty($json['access_token'])) {
            log_message('error', 'FCM: no se pudo obtener access token: ' . substr($resp->getBody(), 0, 300));

            return null;
        }

        cache()->save('buzon_fcm_access_token', $json['access_token'], 3000);

        return $json['access_token'];
    }

    /** @return array<string, mixed>|null */
    private function credenciales(): ?array
    {
        if ($this->cred !== null) {
            return $this->cred;
        }

        $ruta = $this->config->fcmCredentialsPath;
        if ($ruta === '' || ! is_file($ruta)) {
            return null;
        }

        $json = json_decode((string) file_get_contents($ruta), true);
        if (! is_array($json) || empty($json['private_key']) || empty($json['client_email']) || empty($json['project_id'])) {
            log_message('error', 'FCM: el archivo de credenciales no es válido.');

            return null;
        }

        return $this->cred = $json;
    }
}
