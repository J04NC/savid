<?php

/**
 * Ejecución en segundo plano del reporte SIHOS "Auxiliar" (mismo patrón que
 * SihosDocumentosContablesJobService: Cloudflare corta a ~100 s y el detalle
 * de 9 meses tarda ~2,5 min). Estado, resumen por cuenta (JSON) y el .xlsx
 * viven en el storage privado por empresa; solo los ve quien lanzó el trabajo
 * y se purgan pasado el TTL de SihosTrabajoStore.
 */
class SihosAuxiliarJobService
{
    private const PREFIJO = 'auxiliar';
    /** Sin latido durante este tiempo el trabajo se da por interrumpido (la consulta del detalle puede tardar en devolver la primera fila). */
    private const SIN_LATIDO_SEGUNDOS = 600;

    private SihosTrabajoStore $store;

    public function __construct()
    {
        $this->store = new SihosTrabajoStore(self::PREFIJO);
    }

    /**
     * @param array<string,mixed> $entrada filtros del formulario
     * @return array{ok:bool,token?:string,error?:string}
     */
    public function iniciar(int $empresaId, int $userId, array $entrada): array
    {
        $v = SihosAuxiliarFiltros::validar($entrada);
        if (!$v['ok']) {
            return ['ok' => false, 'error' => $v['error']];
        }

        $this->store->purgarExpirados($empresaId, ['_resultado.json', '_libro.xlsx']);

        $token = bin2hex(random_bytes(16));
        $this->store->guardarEstado($empresaId, $token, [
            'estado' => 'pendiente', 'empresa_id' => $empresaId, 'user_id' => $userId, 'filtros' => $v['filtros'],
            'creado' => time(), 'actualizado' => time(), 'lineas' => 0, 'fase' => 'En cola',
        ]);
        $this->store->lanzarWorker(BASE_PATH . '/scripts/sihos_auxiliar_job.php', $empresaId, $token);

        return ['ok' => true, 'token' => $token];
    }

    /** Lo llama el script CLI: genera el .xlsx y el resumen y los deja en el storage. */
    public function ejecutar(int $empresaId, string $token): void
    {
        $token = $this->store->tokenSeguro($token);
        $estado = $token !== '' ? $this->store->leerEstado($empresaId, $token) : null;
        if ($estado === null || $estado['estado'] !== 'pendiente') {
            return;
        }
        $estado['estado'] = 'ejecutando';
        $this->store->guardarEstado($empresaId, $token, $estado);

        $tmp = tempnam(sys_get_temp_dir(), 'aux');
        try {
            $ultimo = 0;
            $reporte = (new SihosAuxiliarService())->generar(
                $empresaId, (array)$estado['filtros'], $tmp,
                function (string $fase, int $lineas) use (&$estado, $empresaId, $token, &$ultimo): void {
                    $cambioFase = $fase !== ($estado['fase'] ?? '');
                    if ($cambioFase || time() - $ultimo >= 2) {
                        $ultimo = time();
                        $estado['fase'] = $fase;
                        $estado['lineas'] = $lineas;
                        $estado['actualizado'] = $ultimo;
                        $this->store->guardarEstado($empresaId, $token, $estado);
                    }
                }
            );
            if (!$reporte['ok']) {
                $this->store->terminar($empresaId, $token, $estado, 'error', (string)$reporte['error']);

                return;
            }
            if ($this->store->storage()->putLocalFile(StorageService::ZONE_SIHOS_REPORTES, self::PREFIJO . '_' . $token . '_libro.xlsx', $tmp, true, $empresaId) === null) {
                throw new RuntimeException('No se pudo guardar el archivo Excel.');
            }
            $this->store->storage()->putContents($this->store->key($empresaId, $token, 'resultado'), json_encode($reporte, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE) ?: '{}');
            $estado['lineas'] = (int)$reporte['lineas'];
            $this->store->terminar($empresaId, $token, $estado, 'listo');
        } catch (Throwable $e) {
            error_log('SihosAuxiliarJobService: ' . $e->getMessage());
            $this->store->terminar($empresaId, $token, $estado, 'error', 'No se pudo generar el reporte. Intente de nuevo.');
        } finally {
            @unlink($tmp);
        }
    }

    /**
     * Estado visible para el usuario dueño del trabajo (null si no existe o no es suyo).
     *
     * @return array{estado:string,fase:string,lineas:int,segundos:int,filtros:array<string,mixed>,error?:string}|null
     */
    public function estado(int $empresaId, int $userId, string $token): ?array
    {
        $token = $this->store->tokenSeguro($token);
        $estado = $token !== '' ? $this->store->leerEstado($empresaId, $token) : null;
        if ($estado === null || (int)$estado['user_id'] !== $userId) {
            return null;
        }
        $estado = $this->store->verificarLatido($empresaId, $token, $estado, self::SIN_LATIDO_SEGUNDOS);

        $salida = [
            'estado' => (string)$estado['estado'], 'fase' => (string)($estado['fase'] ?? ''), 'lineas' => (int)($estado['lineas'] ?? 0),
            'segundos' => max(0, time() - (int)$estado['creado']), 'filtros' => (array)$estado['filtros'],
        ];
        if (isset($estado['error'])) {
            $salida['error'] = (string)$estado['error'];
        }

        return $salida;
    }

    /** @return array<string,mixed>|null resumen por cuenta de un trabajo terminado */
    public function resultado(int $empresaId, int $userId, string $token): ?array
    {
        $estado = $this->estado($empresaId, $userId, $token);
        if ($estado === null || $estado['estado'] !== 'listo') {
            return null;
        }
        $raw = $this->store->storage()->get($this->store->key($empresaId, $this->store->tokenSeguro($token), 'resultado'));
        $r = is_string($raw) ? json_decode($raw, true) : null;

        return is_array($r) ? $r : null;
    }

    /** Ruta local del .xlsx si el trabajo es del usuario y terminó; null si no. */
    public function rutaArchivo(int $empresaId, int $userId, string $token): ?string
    {
        $estado = $this->estado($empresaId, $userId, $token);
        if ($estado === null || $estado['estado'] !== 'listo') {
            return null;
        }

        return $this->store->storage()->localPath($this->store->key($empresaId, $this->store->tokenSeguro($token), 'libro', 'xlsx'));
    }

    /** p. ej. auxiliar_niif_2026-01-01_2026-09-30.xlsx */
    public static function nombreArchivo(array $filtros): string
    {
        return 'auxiliar_' . $filtros['contabilidad'] . '_' . $filtros['desde'] . '_' . $filtros['hasta'] . '.xlsx';
    }
}
