<?php

/**
 * Ejecución en segundo plano del reporte SIHOS "Facturación": un rango largo
 * (o una institución grande) son cientos de miles de filas y Cloudflare corta
 * a ~100 s (524). La petición solo crea el trabajo y lanza
 * scripts/sihos_facturacion_job.php; estado, las dos tablas (JSON) y sus CSV
 * viven en el storage privado por empresa, solo los usa quien lo lanzó y se
 * purgan pasadas TTL_SECONDS. Mismo esquema que SihosLiquidacionJobService.
 */
class SihosFacturacionJobService
{
    private SihosFacturacionTrabajoStore $store;

    public function __construct()
    {
        $this->store = new SihosFacturacionTrabajoStore();
    }

    /** Sin latido durante este tiempo el trabajo se da por interrumpido. */
    private const SIN_LATIDO_SEGUNDOS = 300;
    private const MAX_DIAS = 366;
    private const TABLAS = SihosFacturacionTrabajoStore::TABLAS;

    /**
     * @param string[] $tipos
     * @return array{ok:bool,token?:string,error?:string}
     */
    public function iniciar(int $empresaId, int $userId, string $desde, string $hasta, array $tipos, string $nit): array
    {
        $d = DateTimeImmutable::createFromFormat('!Y-m-d', $desde);
        $h = DateTimeImmutable::createFromFormat('!Y-m-d', $hasta);
        if (!$d || !$h || $d->format('Y-m-d') !== $desde || $h->format('Y-m-d') !== $hasta) {
            return ['ok' => false, 'error' => 'Las fechas son inválidas.'];
        }
        if ($d > $h) {
            return ['ok' => false, 'error' => 'La fecha inicial no puede ser posterior a la final.'];
        }
        if ($d->diff($h)->days > self::MAX_DIAS) {
            return ['ok' => false, 'error' => 'El rango máximo es de un año.'];
        }

        $this->store->purgarExpirados($empresaId);

        $token = bin2hex(random_bytes(16));
        $this->store->guardarEstado($empresaId, $token, [
            'estado' => 'pendiente', 'empresa_id' => $empresaId, 'user_id' => $userId, 'desde' => $desde, 'hasta' => $hasta,
            'tipos' => array_values($tipos), 'nit' => $nit, 'creado' => time(), 'actualizado' => time(), 'fase' => 'En cola', 'lineas' => 0,
        ]);

        $this->store->lanzarWorker(BASE_PATH . '/scripts/sihos_facturacion_job.php', $empresaId, $token);

        return ['ok' => true, 'token' => $token];
    }

    /** Lo llama el script CLI: consulta SIHOS y deja las dos tablas y sus CSV en el storage. */
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
        try {
            $ultimoLatido = 0;
            $reporte = (new SihosFacturacionService())->buildReporte(
                $empresaId, (string)$estado['desde'], (string)$estado['hasta'], (array)$estado['tipos'], (string)$estado['nit'],
                function (string $fase, int $filas) use (&$estado, $empresaId, $token, &$ultimoLatido): void {
                    $ultimoLatido = time();
                    $estado['fase'] = $fase;
                    $estado['lineas'] = $filas;
                    $estado['actualizado'] = $ultimoLatido;
                    $this->store->guardarEstado($empresaId, $token, $estado);
                }
            );
            if (!$reporte['ok']) {
                $this->store->terminar($empresaId, $token, $estado, 'error', (string)$reporte['error']);

                return;
            }

            foreach (self::TABLAS as $tabla) {
                $estado['fase'] = 'Generando archivos';
                $estado['actualizado'] = time();
                $this->store->guardarEstado($empresaId, $token, $estado);

                $tmp[$tabla] = tempnam(sys_get_temp_dir(), 'fac');
                $fh = fopen($tmp[$tabla], 'w');
                SihosFacturacionTabla::escribirCsv($fh, $tabla, $reporte[$tabla]);
                fclose($fh);
                if ($this->store->guardarTabla($empresaId, $token, $tabla, $tmp[$tabla], $reporte[$tabla]) === false) {
                    throw new RuntimeException('No se pudo guardar la tabla ' . $tabla . '.');
                }
                $estado['total_' . $tabla] = count($reporte[$tabla]);
                unset($reporte[$tabla]);
            }
            $this->store->terminar($empresaId, $token, $estado, 'listo');
        } catch (Throwable $e) {
            error_log('SihosFacturacionJobService: ' . $e->getMessage());
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
     * @return array{estado:string,fase:string,lineas:int,segundos:int,desde:string,hasta:string,total_facturas:int,total_notas:int,error?:string}|null
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
            'segundos' => max(0, time() - (int)$estado['creado']), 'desde' => (string)$estado['desde'], 'hasta' => (string)$estado['hasta'],
            'total_facturas' => (int)($estado['total_facturas'] ?? 0), 'total_notas' => (int)($estado['total_notas'] ?? 0),
        ];
        if (isset($estado['error'])) {
            $salida['error'] = (string)$estado['error'];
        }

        return $salida;
    }

    /** Filas cacheadas de una tabla del trabajo terminado del usuario (null si no hay). @return list<array<string,mixed>>|null */
    public function filas(int $empresaId, int $userId, string $token, string $tabla): ?array
    {
        $estado = $this->estado($empresaId, $userId, $token);
        if ($estado === null || $estado['estado'] !== 'listo' || !in_array($tabla, self::TABLAS, true)) {
            return null;
        }
        return $this->store->leerTabla($empresaId, $this->store->tokenSeguro($token), $tabla);
    }

    /** Ruta local del CSV de una tabla si el trabajo es del usuario y terminó; null si no. */
    public function rutaArchivo(int $empresaId, int $userId, string $token, string $tabla): ?string
    {
        $estado = $this->estado($empresaId, $userId, $token);
        if ($estado === null || $estado['estado'] !== 'listo' || !in_array($tabla, self::TABLAS, true)) {
            return null;
        }

        return $this->store->rutaCsv($empresaId, $this->store->tokenSeguro($token), $tabla);
    }
}
