<?php

namespace App\Controllers\Api;

use App\Libraries\Fcm;
use App\Models\AnotacionModel;
use App\Models\ReporteModel;
use CodeIgniter\HTTP\Files\UploadedFile;
use CodeIgniter\HTTP\ResponseInterface;
use Config\Buzon;
use Throwable;

class ReportesController extends ApiController
{
    private const MIMES_IMAGEN = ['image/jpeg', 'image/png', 'image/webp'];

    /**
     * GET /api/v1/reportes
     * Filtros: scope (mios|comunidad|activos|cerrados), estado, categoria, prioridad, q, ubicacion,
     *          desde, hasta (YYYY-MM-DD), orden (reciente|prioridad|apoyos),
     *          limit (p. ej. 3 para las tarjetas del inicio) o page + per_page.
     * Por defecto: admin -> activos, resto -> mios. Solo el admin puede usar activos/cerrados.
     */
    public function index(): ResponseInterface
    {
        $u = $this->user();
        $q = $this->request->getGet();

        $esAdmin = $u['rol'] === 'admin';
        $scope   = (string) ($q['scope'] ?? ($esAdmin ? 'activos' : 'mios'));
        $validos = $esAdmin ? ['mios', 'comunidad', 'activos', 'cerrados'] : ['mios', 'comunidad'];
        if (! in_array($scope, $validos, true)) {
            return $this->fail('No tienes permiso para ver esa lista.', 403);
        }

        $limit     = isset($q['limit']) ? max(1, min(50, (int) $q['limit'])) : 0;
        $porPagina = max(1, min(50, (int) ($q['per_page'] ?? 20)));
        $pagina    = max(1, (int) ($q['page'] ?? 1));

        $filtros = [
            'scope'     => $scope,
            'estado'    => $q['estado'] ?? null,
            'categoria' => $q['categoria'] ?? null,
            'prioridad' => in_array($q['prioridad'] ?? '', Buzon::PRIORIDADES, true) ? $q['prioridad'] : null,
            'q'         => isset($q['q']) ? trim((string) $q['q']) : null,
            'ubicacion' => isset($q['ubicacion']) ? trim((string) $q['ubicacion']) : null,
            'desde'     => $q['desde'] ?? null,
            'hasta'     => $q['hasta'] ?? null,
            'orden'     => in_array($q['orden'] ?? '', ['reciente', 'prioridad', 'apoyos'], true) ? $q['orden'] : 'reciente',
            'limit'     => $limit,
            'page'      => $pagina,
            'per_page'  => $porPagina,
        ];

        $res = model(ReporteModel::class)->listar($filtros, $u);

        $tamano = $limit > 0 ? $limit : $porPagina;

        return $this->ok([
            'data' => $res['rows'],
            'meta' => [
                'page'        => $limit > 0 ? 1 : $pagina,
                'per_page'    => $tamano,
                'total'       => $res['total'],
                'total_pages' => (int) max(1, ceil($res['total'] / $tamano)),
            ],
        ]);
    }

    /** GET /api/v1/reportes/{id} */
    public function show(int $id): ResponseInterface
    {
        $r = model(ReporteModel::class)->detalle($id, $this->user());

        return $r === null ? $this->fail('El reporte no existe.', 404) : $this->ok($r);
    }

    /** POST /api/v1/reportes  (multipart: nombre, descripcion, ubicacion, id_categoria?, imagenes[]?) */
    public function create(): ResponseInterface
    {
        /** @var Buzon $cfg */
        $cfg = config(Buzon::class);
        $u   = $this->user();

        $reportes = model(ReporteModel::class);
        $recientes = $reportes->withDeleted()
            ->where('id_usuario', $u['id'])
            ->where('fecha_hora >=', date('Y-m-d H:i:s', time() - 3600))
            ->countAllResults();
        if ($recientes >= $cfg->maxReportsPerHour) {
            return $this->fail("Alcanzaste el límite de {$cfg->maxReportsPerHour} reportes por hora. Inténtalo más tarde.", 429);
        }

        $p = $this->input();
        $datos = [
            'nombre'       => trim((string) ($p['nombre'] ?? '')),
            'descripcion'  => trim((string) ($p['descripcion'] ?? '')),
            'ubicacion'    => trim((string) ($p['ubicacion'] ?? '')),
            'id_categoria' => ($p['id_categoria'] ?? '') === '' ? null : $p['id_categoria'],
        ];
        $reglas = [
            'nombre'       => ['label' => 'Título', 'rules' => 'required|min_length[3]|max_length[150]'],
            'descripcion'  => ['label' => 'Descripción', 'rules' => 'required|min_length[10]|max_length[2000]'],
            'ubicacion'    => ['label' => 'Ubicación', 'rules' => 'required|min_length[2]|max_length[200]'],
            'id_categoria' => ['label' => 'Categoría', 'rules' => 'permit_empty|is_natural_no_zero|is_not_unique[categorias.id]'],
        ];
        if ($r = $this->validarDatos($datos, $reglas)) {
            return $r;
        }

        // ---- imágenes (opcionales) ----
        $archivos = [];
        foreach ((array) ($this->request->getFileMultiple('imagenes') ?? []) as $f) {
            /** @var UploadedFile $f */
            if ($f->getError() === UPLOAD_ERR_NO_FILE) {
                continue;
            }
            $archivos[] = $f;
        }
        if (count($archivos) > $cfg->maxImages) {
            return $this->fail("Solo puedes adjuntar hasta {$cfg->maxImages} imágenes.", 422);
        }
        foreach ($archivos as $f) {
            if (! $f->isValid()) {
                return $this->fail('No se pudo subir una de las imágenes. Intenta de nuevo.', 422);
            }
            if ($f->getSizeByUnit('kb') > $cfg->maxImageKB) {
                return $this->fail('Cada imagen debe pesar máximo ' . round($cfg->maxImageKB / 1024, 1) . ' MB.', 422);
            }
            if (! in_array($f->getMimeType(), self::MIMES_IMAGEN, true) || @getimagesize($f->getTempName()) === false) {
                return $this->fail('Solo se permiten imágenes JPG, PNG o WEBP.', 422);
            }
        }

        $ahora   = date('Y-m-d H:i:s');
        $carpeta = FCPATH . trim($cfg->uploadDir, '/');
        if (! is_dir($carpeta) && ! mkdir($carpeta, 0755, true) && ! is_dir($carpeta)) {
            return $this->fail('No se pudo preparar la carpeta de imágenes en el servidor.', 500);
        }

        $db      = db_connect();
        $movidos = [];

        try {
            $db->transException(true)->transStart();

            // id_usuario, rol_usuario y fecha_hora los pone el servidor, nunca la app.
            $idReporte = $reportes->insert([
                'nombre'       => $datos['nombre'],
                'descripcion'  => $datos['descripcion'],
                'ubicacion'    => $datos['ubicacion'],
                'fecha_hora'   => $ahora,
                'id_usuario'   => $u['id'],
                'rol_usuario'  => $u['rol'],
                'id_estado'    => Buzon::ESTADO_PENDIENTE,
                'id_categoria' => $datos['id_categoria'] !== null ? (int) $datos['id_categoria'] : null,
                'prioridad'    => 'media',
            ], true);

            foreach ($archivos as $f) {
                /** @var UploadedFile $f */
                $nombreArchivo = $f->getRandomName();
                $f->move($carpeta, $nombreArchivo);
                $movidos[] = $carpeta . DIRECTORY_SEPARATOR . $nombreArchivo;
                $db->table('reporte_imagenes')->insert(['id_reporte' => $idReporte, 'archivo' => $nombreArchivo]);
            }

            $db->transComplete();
        } catch (Throwable $e) {
            $db->transRollback();
            foreach ($movidos as $m) {
                @unlink($m);
            }
            log_message('error', 'Error creando reporte: ' . $e->getMessage());

            return $this->fail('No se pudo guardar el reporte. Inténtalo de nuevo.', 500);
        }

        (new Fcm())->aAdmins('Nuevo reporte', $datos['nombre'], ['tipo' => 'nuevo', 'id_reporte' => $idReporte], (int) $u['id']);

        return $this->ok($reportes->detalle((int) $idReporte, $u), 201);
    }

    /**
     * PATCH /api/v1/reportes/{id}/estado   (solo admin)
     * {id_estado, comentario?, prioridad?}
     */
    public function estado(int $id): ResponseInterface
    {
        $u        = $this->user();
        $reportes = model(ReporteModel::class);
        $reporte  = $reportes->find($id);
        if ($reporte === null) {
            return $this->fail('El reporte no existe.', 404);
        }

        $p     = $this->input();
        $datos = [
            'id_estado'  => $p['id_estado'] ?? null,
            'comentario' => trim((string) ($p['comentario'] ?? '')),
            'prioridad'  => (string) ($p['prioridad'] ?? ''),
        ];
        if ($r = $this->validarDatos($datos, [
            'id_estado'  => ['label' => 'Estado', 'rules' => 'required|is_natural_no_zero|is_not_unique[estados.id]'],
            'comentario' => ['label' => 'Anotación', 'rules' => 'permit_empty|max_length[1000]'],
            'prioridad'  => ['label' => 'Prioridad', 'rules' => 'permit_empty|in_list[' . implode(',', Buzon::PRIORIDADES) . ']'],
        ])) {
            return $r;
        }

        $nuevo      = (int) $datos['id_estado'];
        $anterior   = (int) $reporte['id_estado'];
        $cambioEdo  = $nuevo !== $anterior;
        $cambioPrio = $datos['prioridad'] !== '' && $datos['prioridad'] !== $reporte['prioridad'];
        $comentario = $datos['comentario'];

        if (! $cambioEdo && ! $cambioPrio && $comentario === '') {
            return $this->fail('No hay cambios que guardar: cambia el estado, la prioridad o escribe una anotación.', 422);
        }
        if ($nuevo === Buzon::ESTADO_NO_VIABLE && $comentario === '') {
            return $this->fail('Explica brevemente por qué no es posible solucionarlo.', 422, ['comentario' => 'Obligatorio para este estado.']);
        }

        $db = db_connect();
        $db->transStart();

        $cambios = [];
        if ($cambioEdo) {
            $cambios['id_estado']     = $nuevo;
            $cambios['confirmado_at'] = null;
        }
        if ($cambioPrio) {
            $cambios['prioridad'] = $datos['prioridad'];
        }
        if ($cambios !== []) {
            $reportes->update($id, $cambios);
        }

        if ($cambioEdo || $comentario !== '') {
            model(AnotacionModel::class)->insert([
                'id_reporte'         => $id,
                'id_autor'           => $u['id'],
                'rol_autor'          => $u['rol'],
                'comentario'         => $comentario !== '' ? $comentario : null,
                'id_estado_anterior' => $anterior,
                'id_estado_nuevo'    => $nuevo,
                'fecha_hora'         => date('Y-m-d H:i:s'),
            ]);
        }

        $db->transComplete();
        if (! $db->transStatus()) {
            return $this->fail('No se pudo guardar el cambio.', 500);
        }

        // Aviso al autor del reporte (si no es el propio admin).
        if (($cambioEdo || $comentario !== '') && (int) $reporte['id_usuario'] !== (int) $u['id']) {
            $etiqueta = $db->table('estados')->select('etiqueta')->where('id', $nuevo)->get()->getRow('etiqueta');
            (new Fcm())->aUsuarios(
                [(int) $reporte['id_usuario']],
                $cambioEdo ? 'Tu reporte: ' . $etiqueta : 'Nueva anotación en tu reporte',
                $reporte['nombre'] . ($comentario !== '' ? ' — ' . mb_strimwidth($comentario, 0, 80, '…') : ''),
                ['tipo' => 'estado', 'id_reporte' => $id]
            );
        }

        return $this->ok($reportes->detalle($id, $u));
    }

    /** POST /api/v1/reportes/{id}/apoyo  — alterna el "Yo también" */
    public function apoyo(int $id): ResponseInterface
    {
        $u       = $this->user();
        $reporte = model(ReporteModel::class)->find($id);
        if ($reporte === null) {
            return $this->fail('El reporte no existe.', 404);
        }
        if ((int) $reporte['id_usuario'] === (int) $u['id']) {
            return $this->fail('No puedes apoyar tu propio reporte.', 422);
        }
        $final = (int) db_connect()->table('estados')->select('es_final')->where('id', $reporte['id_estado'])->get()->getRow('es_final');
        if ($final === 1) {
            return $this->fail('Este reporte ya fue cerrado.', 422);
        }

        $tabla = db_connect()->table('apoyos');
        $filtro = ['id_reporte' => $id, 'id_usuario' => $u['id']];

        if ($tabla->where($filtro)->countAllResults() > 0) {
            db_connect()->table('apoyos')->where($filtro)->delete();
            $apoyado = false;
        } else {
            db_connect()->table('apoyos')->insert($filtro + ['fecha_hora' => date('Y-m-d H:i:s')]);
            $apoyado = true;
        }

        $total = db_connect()->table('apoyos')->where('id_reporte', $id)->countAllResults();

        return $this->ok(['apoyos' => $total, 'yo_apoye' => $apoyado]);
    }

    /** POST /api/v1/reportes/{id}/confirmar — el autor confirma que sí quedó solucionado */
    public function confirmar(int $id): ResponseInterface
    {
        $u       = $this->user();
        $reportes = model(ReporteModel::class);
        $reporte = $reportes->find($id);

        if ($reporte === null) {
            return $this->fail('El reporte no existe.', 404);
        }
        if ((int) $reporte['id_usuario'] !== (int) $u['id']) {
            return $this->fail('Solo quien creó el reporte puede confirmarlo.', 403);
        }
        if ((int) $reporte['id_estado'] !== Buzon::ESTADO_SOLUCIONADO) {
            return $this->fail('El reporte aún no está marcado como solucionado.', 422);
        }
        if ($reporte['confirmado_at'] !== null) {
            return $this->fail('Ya confirmaste este reporte.', 422);
        }

        $reportes->update($id, ['confirmado_at' => date('Y-m-d H:i:s')]);
        model(AnotacionModel::class)->insert([
            'id_reporte'         => $id,
            'id_autor'           => $u['id'],
            'rol_autor'          => $u['rol'],
            'comentario'         => 'El usuario confirmó que el problema quedó solucionado.',
            'id_estado_anterior' => Buzon::ESTADO_SOLUCIONADO,
            'id_estado_nuevo'    => Buzon::ESTADO_SOLUCIONADO,
            'fecha_hora'         => date('Y-m-d H:i:s'),
        ]);

        return $this->ok($reportes->detalle($id, $u));
    }

    /** POST /api/v1/reportes/{id}/reabrir {comentario} — el autor indica que sigue sin resolverse */
    public function reabrir(int $id): ResponseInterface
    {
        $u        = $this->user();
        $reportes = model(ReporteModel::class);
        $reporte  = $reportes->find($id);

        if ($reporte === null) {
            return $this->fail('El reporte no existe.', 404);
        }
        if ((int) $reporte['id_usuario'] !== (int) $u['id']) {
            return $this->fail('Solo quien creó el reporte puede reabrirlo.', 403);
        }
        if (! in_array((int) $reporte['id_estado'], [Buzon::ESTADO_SOLUCIONADO, Buzon::ESTADO_NO_VIABLE], true)) {
            return $this->fail('Solo se pueden reabrir reportes solucionados o marcados como no viables.', 422);
        }

        $comentario = trim((string) ($this->input()['comentario'] ?? ''));
        if ($r = $this->validarDatos(['comentario' => $comentario], [
            'comentario' => ['label' => 'Motivo', 'rules' => 'required|min_length[5]|max_length[1000]'],
        ])) {
            return $r;
        }

        $db = db_connect();
        $db->transStart();
        $reportes->update($id, ['id_estado' => Buzon::ESTADO_REABIERTO, 'confirmado_at' => null]);
        model(AnotacionModel::class)->insert([
            'id_reporte'         => $id,
            'id_autor'           => $u['id'],
            'rol_autor'          => $u['rol'],
            'comentario'         => $comentario,
            'id_estado_anterior' => (int) $reporte['id_estado'],
            'id_estado_nuevo'    => Buzon::ESTADO_REABIERTO,
            'fecha_hora'         => date('Y-m-d H:i:s'),
        ]);
        $db->transComplete();

        if (! $db->transStatus()) {
            return $this->fail('No se pudo reabrir el reporte.', 500);
        }

        (new Fcm())->aAdmins('Reporte reabierto', $reporte['nombre'], ['tipo' => 'reabierto', 'id_reporte' => $id], (int) $u['id']);

        return $this->ok($reportes->detalle($id, $u));
    }

    /** DELETE /api/v1/reportes/{id}  (admin) — borrado lógico */
    public function delete(int $id): ResponseInterface
    {
        $reportes = model(ReporteModel::class);
        if ($reportes->find($id) === null) {
            return $this->fail('El reporte no existe.', 404);
        }
        $reportes->delete($id);

        return $this->ok(['eliminado' => true]);
    }
}
