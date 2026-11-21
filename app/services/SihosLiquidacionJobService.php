<?php

/**
 * Ejecución en segundo plano del reporte SIHOS "Liquidación": un rango largo
 * son cientos de miles de líneas y Cloudflare corta a ~100 s (524). La petición
 * solo crea el trabajo y lanza scripts/sihos_liquidacion_job.php; estado,
 * agregados y los dos CSV viven en el storage privado por empresa (contienen
 * nombre y cédula de profesionales, nunca datos de pacientes), solo los
 * descarga quien lo lanzó y se purgan pasadas TTL_SECONDS.
 */
class SihosLiquidacionJobService
{
    private const PREFIJO = 'liquidacion';
    /** Sin latido durante este tiempo el trabajo se da por interrumpido. */
    private const SIN_LATIDO_SEGUNDOS = 300;
    private const ARCHIVOS = ['detalle', 'resumen', 'especialistas', 'cups'];

    private SihosTrabajoStore $store;

    public function __construct()
    {
        $this->store = new SihosTrabajoStore(self::PREFIJO);
    }

    /** @return array{ok:bool,token?:string,error?:string} */
    public function iniciar(int $empresaId, int $userId, string $desde, string $hasta, int $entidades = 3): array
    {
        $error = SihosLiquidacionService::validarRango($desde, $hasta);
        if ($error !== null) {
            return ['ok' => false, 'error' => $error];
        }

        $this->store->purgarExpirados($empresaId, array_merge(
            ['_resultado.json'],
            array_map(static fn (string $parte): string => '_' . $parte . '.csv', self::ARCHIVOS)
        ), ['_sel_*.csv']);

        $token = bin2hex(random_bytes(16));
        $this->store->guardarEstado($empresaId, $token, [
            'estado' => 'pendiente', 'empresa_id' => $empresaId, 'user_id' => $userId,
            'desde' => $desde, 'hasta' => $hasta, 'entidades' => max(1, min(5, $entidades)), 'creado' => time(), 'actualizado' => time(), 'lineas' => 0,
        ]);

        $this->store->lanzarWorker(BASE_PATH . '/scripts/sihos_liquidacion_job.php', $empresaId, $token);

        return ['ok' => true, 'token' => $token];
    }

    /** Lo llama el script CLI: genera los CSV y deja agregados y archivos en el storage. */
    public function ejecutar(int $empresaId, string $token): void
    {
        $token = $this->store->tokenSeguro($token);
        $estado = $token !== '' ? $this->store->leerEstado($empresaId, $token) : null;
        if ($estado === null || $estado['estado'] !== 'pendiente') {
            return;
        }
        $estado['estado'] = 'ejecutando';
        $this->store->guardarEstado($empresaId, $token, $estado);

        $tmp = [];
        foreach (self::ARCHIVOS as $parte) {
            $tmp[$parte] = tempnam(sys_get_temp_dir(), 'liq');
        }
        try {
            $fh = [];
            foreach ($tmp as $parte => $ruta) {
                $fh[$parte] = fopen($ruta, 'w');
                // BOM + "sep=;": ver la explicación en SihosController::auditoriaGlosaExportar(). El CSV por CUPS va sin BOM
                // y en Windows-1252 (ver más abajo): con "sep=;" Excel ignora el BOM y muestra mal los acentos.
                fwrite($fh[$parte], ($parte === 'cups' ? '' : "\xEF\xBB\xBF") . "sep=;\r\n");
            }

            $ultimoLatido = 0;
            $reporte = (new SihosLiquidacionService())->generar(
                $empresaId, (string)$estado['desde'], (string)$estado['hasta'], $fh['detalle'], $fh['resumen'], $fh['especialistas'], $fh['cups'],
                function (int $lineas) use (&$estado, $empresaId, $token, &$ultimoLatido): void {
                    if (time() - $ultimoLatido >= 2) {
                        $ultimoLatido = time();
                        $estado['lineas'] = $lineas;
                        $estado['actualizado'] = $ultimoLatido;
                        $this->store->guardarEstado($empresaId, $token, $estado);
                    }
                },
                (int)($estado['entidades'] ?? 3)
            );
            foreach ($fh as $h) {
                fclose($h);
            }

            if (!$reporte['ok']) {
                $this->store->terminar($empresaId, $token, $estado, 'error', (string)$reporte['error']);

                return;
            }
            // CSV por CUPS: Windows-1252, que Excel lee bien (tildes y ñ) junto con la línea "sep=;" que fija el separador.
            $cupsAnsi = iconv('UTF-8', 'Windows-1252//TRANSLIT//IGNORE', (string)file_get_contents($tmp['cups']));
            if ($cupsAnsi === false || file_put_contents($tmp['cups'], $cupsAnsi) === false) {
                throw new RuntimeException('No se pudo convertir el archivo por CUPS.');
            }

            foreach (self::ARCHIVOS as $parte) {
                if ($this->store->storage()->putLocalFile(StorageService::ZONE_SIHOS_REPORTES, self::PREFIJO . '_' . $token . '_' . $parte . '.csv', $tmp[$parte], true, $empresaId) === null) {
                    throw new RuntimeException('No se pudo guardar el archivo ' . $parte . '.');
                }
            }
            $this->store->storage()->putContents($this->store->key($empresaId, $token, 'resultado'), json_encode($reporte, JSON_UNESCAPED_UNICODE) ?: '{}');
            $estado['lineas'] = (int)$reporte['lineas'];
            $this->store->terminar($empresaId, $token, $estado, 'listo');
        } catch (Throwable $e) {
            error_log('SihosLiquidacionJobService: ' . $e->getMessage());
            $this->store->terminar($empresaId, $token, $estado, 'error', 'No se pudo generar el reporte. Intente de nuevo.');
        } finally {
            foreach ($tmp as $ruta) {
                @unlink($ruta);
            }
        }
    }

    /**
     * Estado visible para el usuario dueño del trabajo (null si no existe o no es suyo).
     *
     * @return array{estado:string,lineas:int,segundos:int,desde:string,hasta:string,error?:string}|null
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
            'estado' => (string)$estado['estado'], 'lineas' => (int)($estado['lineas'] ?? 0),
            'segundos' => max(0, time() - (int)$estado['creado']),
            'desde' => (string)$estado['desde'], 'hasta' => (string)$estado['hasta'],
        ];
        if (isset($estado['error'])) {
            $salida['error'] = (string)$estado['error'];
        }

        return $salida;
    }

    /** @return array<string,mixed>|null */
    public function resultado(int $empresaId, int $userId, string $token): ?array
    {
        $estado = $this->estado($empresaId, $userId, $token);
        if ($estado === null || $estado['estado'] !== 'listo') {
            return null;
        }

        $raw = $this->store->storage()->get($this->store->key($empresaId, $this->store->tokenSeguro($token), 'resultado'));
        $reporte = is_string($raw) ? json_decode($raw, true) : null;

        return is_array($reporte) ? $reporte : null;
    }

    /** Ruta local del CSV ('detalle' o 'resumen') si el trabajo es del usuario y terminó; null si no. */
    public function rutaArchivo(int $empresaId, int $userId, string $token, string $parte): ?string
    {
        $estado = $this->estado($empresaId, $userId, $token);
        if ($estado === null || $estado['estado'] !== 'listo' || !in_array($parte, self::ARCHIVOS, true)) {
            return null;
        }

        return $this->store->storage()->localPath($this->store->key($empresaId, $this->store->tokenSeguro($token), $parte, 'csv'));
    }
}
