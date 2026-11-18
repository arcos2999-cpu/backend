<?php

namespace App\Controllers\Api;

use App\Models\AnuncioModel;
use CodeIgniter\HTTP\Files\UploadedFile;
use CodeIgniter\HTTP\ResponseInterface;
use Config\Buzon;
use Throwable;

/**
 * Muro institucional.
 *   - Cualquier usuario con sesión: ver anuncios, like, guardar, favorito, comentar, ver su actividad.
 *   - Solo el administrador: publicar y borrar anuncios.
 * El usuario SIEMPRE sale del token (AuthContext), nunca de la petición.
 */
class AnunciosController extends ApiController
{
    private const MIMES_IMAGEN = ['image/jpeg', 'image/png', 'image/webp'];
    private const SCOPES       = ['todos', 'likes', 'guardados', 'favoritos'];

    /** GET /api/v1/anuncios?scope=todos|likes|guardados|favoritos&q=&page=&per_page= */
    public function index(): ResponseInterface
    {
        $u = $this->user();
        $q = $this->request->getGet();

        $scope = (string) ($q['scope'] ?? 'todos');
        if (! in_array($scope, self::SCOPES, true)) {
            return $this->fail('Lista no válida.', 422);
        }

        $porPagina = max(1, min(50, (int) ($q['per_page'] ?? 10)));
        $pagina    = max(1, (int) ($q['page'] ?? 1));

        $res = model(AnuncioModel::class)->listar([
            'scope'    => $scope,
            'q'        => isset($q['q']) ? trim((string) $q['q']) : '',
            'page'     => $pagina,
            'per_page' => $porPagina,
        ], (int) $u['id']);

        return $this->ok([
            'data' => $res['rows'],
            'meta' => [
                'page'        => $pagina,
                'per_page'    => $porPagina,
                'total'       => $res['total'],
                'total_pages' => (int) max(1, ceil($res['total'] / $porPagina)),
            ],
        ]);
    }

    /** GET /api/v1/anuncios/{id} */
    public function show(int $id): ResponseInterface
    {
        $anuncio = model(AnuncioModel::class)->detalle($id, (int) $this->user()['id']);

        return $anuncio === null ? $this->fail('El anuncio no existe.', 404) : $this->ok($anuncio);
    }

    /**
     * POST /api/v1/anuncios   (solo admin; multipart: titulo, texto, lugar?, fecha_evento?, imagen?)
     * fecha_evento llega como "2026-11-20T10:00" (input datetime-local).
     */
    public function create(): ResponseInterface
    {
        $u   = $this->user();
        $cfg = config(Buzon::class);
        $in  = $this->input();

        $datos = [
            'titulo'       => trim((string) ($in['titulo'] ?? '')),
            'texto'        => trim((string) ($in['texto'] ?? '')),
            'lugar'        => trim((string) ($in['lugar'] ?? '')),
            'fecha_evento' => trim((string) ($in['fecha_evento'] ?? '')),
        ];
        $reglas = [
            'titulo'       => ['label' => 'Título', 'rules' => 'required|min_length[3]|max_length[150]'],
            'texto'        => ['label' => 'Texto', 'rules' => 'required|min_length[5]|max_length[3000]'],
            'lugar'        => ['label' => 'Lugar', 'rules' => 'permit_empty|max_length[200]'],
            'fecha_evento' => ['label' => 'Fecha del evento', 'rules' => 'permit_empty|regex_match[/^\d{4}-\d{2}-\d{2}[T ]\d{2}:\d{2}(:\d{2})?$/]'],
        ];
        if ($r = $this->validarDatos($datos, $reglas)) {
            return $r;
        }

        // ---- imagen (opcional, una sola) ----
        $f = $this->request->getFile('imagen');
        if ($f !== null && $f->getError() === UPLOAD_ERR_NO_FILE) {
            $f = null;
        }
        if ($f !== null) {
            if (! $f->isValid()) {
                return $this->fail('No se pudo subir la imagen. Intenta de nuevo.', 422);
            }
            if ($f->getSizeByUnit('kb') > $cfg->maxImageKB) {
                return $this->fail('La imagen debe pesar máximo ' . round($cfg->maxImageKB / 1024, 1) . ' MB.', 422);
            }
            if (! in_array($f->getMimeType(), self::MIMES_IMAGEN, true) || @getimagesize($f->getTempName()) === false) {
                return $this->fail('Solo se permiten imágenes JPG, PNG o WEBP.', 422);
            }
        }

        $archivo = null;
        $ruta    = null;
        if ($f !== null) {
            /** @var UploadedFile $f */
            $carpeta = FCPATH . trim($cfg->uploadDirAnuncios, '/');
            if (! is_dir($carpeta) && ! mkdir($carpeta, 0755, true) && ! is_dir($carpeta)) {
                return $this->fail('No se pudo preparar la carpeta de imágenes en el servidor.', 500);
            }
            $archivo = $f->getRandomName();
            $f->move($carpeta, $archivo);
            $ruta = $carpeta . DIRECTORY_SEPARATOR . $archivo;
        }

        try {
            $modelo = model(AnuncioModel::class);
            $id     = $modelo->insert([
                'id_autor'     => $u['id'],
                'titulo'       => $datos['titulo'],
                'texto'        => $datos['texto'],
                'lugar'        => $datos['lugar'] !== '' ? $datos['lugar'] : null,
                'fecha_evento' => $datos['fecha_evento'] !== '' ? date('Y-m-d H:i:00', strtotime($datos['fecha_evento'])) : null,
                'imagen'       => $archivo,
                'fecha_hora'   => date('Y-m-d H:i:s'),
            ], true);
        } catch (Throwable $e) {
            if ($ruta !== null) {
                @unlink($ruta);
            }
            log_message('error', 'Error creando anuncio: ' . $e->getMessage());

            return $this->fail('No se pudo publicar el anuncio. Inténtalo de nuevo.', 500);
        }

        return $this->ok($modelo->detalle((int) $id, (int) $u['id']), 201);
    }

    /** DELETE /api/v1/anuncios/{id}   (solo admin) */
    public function delete(int $id): ResponseInterface
    {
        $modelo  = model(AnuncioModel::class);
        $anuncio = $modelo->find($id);
        if ($anuncio === null) {
            return $this->fail('El anuncio no existe.', 404);
        }

        $modelo->delete($id);
        if (! empty($anuncio['imagen'])) {
            @unlink(FCPATH . trim(config(Buzon::class)->uploadDirAnuncios, '/') . DIRECTORY_SEPARATOR . $anuncio['imagen']);
        }

        return $this->ok(['eliminado' => true]);
    }

    /**
     * POST /api/v1/anuncios/{id}/(like|guardar|favorito)
     * Alterna la marca del usuario con sesión y la anota en su historial.
     * Respuesta: {tipo, activo, total}  (total = cuántos usuarios la tienen en ese anuncio)
     */
    public function reaccion(int $id, string $tipo): ResponseInterface
    {
        $tabla = AnuncioModel::REACCIONES[$tipo] ?? null;
        if ($tabla === null) {
            return $this->fail('Acción no válida.', 422);
        }

        $anuncio = model(AnuncioModel::class)->find($id);
        if ($anuncio === null) {
            return $this->fail('El anuncio no existe.', 404);
        }

        $idUsuario = (int) $this->user()['id'];
        $db        = db_connect();
        $filtro    = ['id_anuncio' => $id, 'id_usuario' => $idUsuario];

        if ($db->table($tabla)->where($filtro)->countAllResults() > 0) {
            $db->table($tabla)->where($filtro)->delete();
            $activo = false;
        } else {
            try {
                $db->table($tabla)->insert($filtro + ['fecha_hora' => date('Y-m-d H:i:s')]);
            } catch (Throwable $e) {
                // Doble toque a la vez: la marca ya existe, queda activa.
                log_message('debug', 'Reacción duplicada: ' . $e->getMessage());
            }
            $activo = true;
        }

        $this->anotarActividad($idUsuario, $anuncio, $tipo, ! $activo);

        return $this->ok([
            'tipo'   => $tipo,
            'activo' => $activo,
            'total'  => $db->table($tabla)->where('id_anuncio', $id)->countAllResults(),
        ]);
    }

    /** GET /api/v1/anuncios/{id}/comentarios  (más nuevos primero) */
    public function comentarios(int $id): ResponseInterface
    {
        if (model(AnuncioModel::class)->find($id) === null) {
            return $this->fail('El anuncio no existe.', 404);
        }

        $yo    = (int) $this->user()['id'];
        $filas = db_connect()->table('anuncio_comentarios c')
            ->select('c.id, c.id_usuario, c.texto, c.fecha_hora, u.nombre, u.rol')
            ->join('usuarios u', 'u.id = c.id_usuario', 'left')
            ->where('c.id_anuncio', $id)
            ->orderBy('c.id', 'DESC')
            ->limit(200)
            ->get()->getResultArray();

        return $this->ok(['data' => array_map(static fn ($c) => [
            'id'         => (int) $c['id'],
            'texto'      => $c['texto'],
            'fecha_hora' => $c['fecha_hora'],
            'autor'      => $c['nombre'] ?? 'Usuario',
            'rol'        => $c['rol'] ?? 'alumno',
            'es_mio'     => (int) $c['id_usuario'] === $yo,
        ], $filas)]);
    }

    /** POST /api/v1/anuncios/{id}/comentarios  {texto} */
    public function comentar(int $id): ResponseInterface
    {
        $u       = $this->user();
        $anuncio = model(AnuncioModel::class)->find($id);
        if ($anuncio === null) {
            return $this->fail('El anuncio no existe.', 404);
        }
        if ($r = $this->limitar('comentar-' . $u['id'], config(Buzon::class)->maxCommentsPerMinute)) {
            return $r;
        }

        $datos = ['texto' => trim((string) ($this->input()['texto'] ?? ''))];
        if ($r = $this->validarDatos($datos, ['texto' => ['label' => 'Comentario', 'rules' => 'required|min_length[1]|max_length[300]']])) {
            return $r;
        }

        $db = db_connect();
        $db->table('anuncio_comentarios')->insert([
            'id_anuncio' => $id,
            'id_usuario' => $u['id'],
            'texto'      => $datos['texto'],
            'fecha_hora' => date('Y-m-d H:i:s'),
        ]);
        $nuevo = (int) $db->insertID();

        $this->anotarActividad((int) $u['id'], $anuncio, 'comentario', false);

        return $this->ok([
            'id'         => $nuevo,
            'texto'      => $datos['texto'],
            'fecha_hora' => date('Y-m-d H:i:s'),
            'autor'      => $u['nombre'] ?? 'Usuario',
            'rol'        => $u['rol'] ?? 'alumno',
            'es_mio'     => true,
        ], 201);
    }

    /** DELETE /api/v1/comentarios/{id}   (su autor o el administrador) */
    public function borrarComentario(int $id): ResponseInterface
    {
        $u        = $this->user();
        $tabla    = db_connect()->table('anuncio_comentarios');
        $coment = $tabla->where('id', $id)->get()->getRowArray();
        if ($coment === null) {
            return $this->fail('El comentario no existe.', 404);
        }
        if ((int) $coment['id_usuario'] !== (int) $u['id'] && $u['rol'] !== 'admin') {
            return $this->fail('No tienes permiso para borrar este comentario.', 403);
        }

        db_connect()->table('anuncio_comentarios')->where('id', $id)->delete();

        return $this->ok(['eliminado' => true]);
    }

    /** GET /api/v1/actividad?page=&per_page=   Historial del usuario con sesión (lo más reciente primero). */
    public function actividad(): ResponseInterface
    {
        $q         = $this->request->getGet();
        $porPagina = max(1, min(50, (int) ($q['per_page'] ?? 20)));
        $pagina    = max(1, (int) ($q['page'] ?? 1));
        $idUsuario = (int) $this->user()['id'];

        $b     = db_connect()->table('anuncio_actividad')->where('id_usuario', $idUsuario);
        $total = $b->countAllResults(false);
        $filas = $b->select('id, id_anuncio, tipo, quitar, titulo, fecha_hora')
            ->orderBy('id', 'DESC')
            ->limit($porPagina, ($pagina - 1) * $porPagina)
            ->get()->getResultArray();

        return $this->ok([
            'data' => array_map(static fn ($a) => [
                'id'         => (int) $a['id'],
                'id_anuncio' => $a['id_anuncio'] !== null ? (int) $a['id_anuncio'] : null,
                'tipo'       => $a['tipo'],
                'quitar'     => (int) $a['quitar'] === 1,
                'titulo'     => $a['titulo'],
                'fecha_hora' => $a['fecha_hora'],
            ], $filas),
            'meta' => [
                'page'        => $pagina,
                'per_page'    => $porPagina,
                'total'       => $total,
                'total_pages' => (int) max(1, ceil($total / $porPagina)),
            ],
        ]);
    }

    /** @param array<string, mixed> $anuncio */
    private function anotarActividad(int $idUsuario, array $anuncio, string $tipo, bool $quitar): void
    {
        $db = db_connect();
        $db->table('anuncio_actividad')->insert([
            'id_usuario' => $idUsuario,
            'id_anuncio' => (int) $anuncio['id'],
            'tipo'       => $tipo,
            'quitar'     => $quitar ? 1 : 0,
            'titulo'     => mb_substr((string) $anuncio['titulo'], 0, 150),
            'fecha_hora' => date('Y-m-d H:i:s'),
        ]);

        // Solo se conservan las 200 acciones más recientes de cada usuario.
        $corte = $db->table('anuncio_actividad')->select('id')->where('id_usuario', $idUsuario)
            ->orderBy('id', 'DESC')->limit(1, 200)->get()->getRowArray();
        if ($corte !== null) {
            $db->table('anuncio_actividad')->where('id_usuario', $idUsuario)->where('id <=', (int) $corte['id'])->delete();
        }
    }
}
