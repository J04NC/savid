<?php

/**
 * Ejecución en segundo plano del reporte 2193 CARTERA: con todas las
 * entidades tarda minutos y Cloudflare corta a ~100 s (524). La petición solo
 * crea el trabajo y lanza scripts/sihos_cartera2193_job.php; estado y resultado
 * (agregado por deudor, sin datos de pacientes) viven en el storage privado
 * por empresa, solo los lee quien lo lanzó y se purgan pasadas TTL_SECONDS.
 */
class SihosCartera2193JobService
{
    private const PREFIJO = 'cartera2193';
    /** Sin latido durante este tiempo el trabajo se da por interrumpido. */
    private const SIN_LATIDO_SEGUNDOS = 300;

    private SihosTrabajoStore $store;

    public function __construct()
    {
        $this->store = new SihosTrabajoStore(self::PREFIJO);
    }

    /**
     * @param string[] $tiposUsuario
     * @return array{ok:bool,token?:string,error?:string}
     */
    public function iniciar(int $empresaId, int $userId, string $fechaCorte, array $tiposUsuario, ?string $nit): array
    {
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $fechaCorte)) {
            return ['ok' => false, 'error' => 'La fecha de corte es inválida.'];
        }

        $this->store->purgarExpirados($empresaId, ['_resultado.json']);

        $token = bin2hex(random_bytes(16));
        $this->store->guardarEstado($empresaId, $token, [
            'estado' => 'pendiente',
            'empresa_id' => $empresaId,
            'user_id' => $userId,
            'fecha_corte' => $fechaCorte,
            'tipos' => array_values($tiposUsuario),
            'nit' => $nit,
            'creado' => time(),
            'actualizado' => time(),
            'facturas' => 0,
        ]);

        $this->store->lanzarWorker(BASE_PATH . '/scripts/sihos_cartera2193_job.php', $empresaId, $token);

        return ['ok' => true, 'token' => $token];
    }

    /** Lo llama el script CLI: calcula el reporte y deja el resultado en el storage. */
    public function ejecutar(int $empresaId, string $token): void
    {
        $token = $this->store->tokenSeguro($token);
        $estado = $token !== '' ? $this->store->leerEstado($empresaId, $token) : null;
        if ($estado === null || $estado['estado'] !== 'pendiente') {
            return;
        }

        $estado['estado'] = 'ejecutando';
        $this->store->guardarEstado($empresaId, $token, $estado);

        try {
            $ultimoLatido = 0;
            $reporte = (new SihosCartera2193Service())->buildReporte(
                $empresaId,
                (string)$estado['fecha_corte'],
                (array)$estado['tipos'],
                $estado['nit'],
                function (int $facturas) use (&$estado, $empresaId, $token, &$ultimoLatido): void {
                    $ahora = time();
                    if ($ahora - $ultimoLatido >= 2) {
                        $ultimoLatido = $ahora;
                        $estado['facturas'] = $facturas;
                        $estado['actualizado'] = $ahora;
                        $this->store->guardarEstado($empresaId, $token, $estado);
                    }
                }
            );

            if (!$reporte['ok']) {
                $this->store->terminar($empresaId, $token, $estado, 'error', (string)$reporte['error']);

                return;
            }

            $this->store->storage()->putContents($this->store->key($empresaId, $token, 'resultado'), json_encode($reporte, JSON_UNESCAPED_UNICODE) ?: '{}');
            $estado['facturas'] = (int)$reporte['facturas'];
            $this->store->terminar($empresaId, $token, $estado, 'listo');
        } catch (Throwable $e) {
            error_log('SihosCartera2193JobService: ' . $e->getMessage());
            $this->store->terminar($empresaId, $token, $estado, 'error', 'No se pudo generar el reporte. Intente de nuevo.');
        }
    }

    /**
     * Estado visible para el usuario dueño del trabajo (null si no existe o no es suyo).
     *
     * @return array{estado:string,facturas:int,segundos:int,error?:string}|null
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
            'estado' => (string)$estado['estado'],
            'facturas' => (int)($estado['facturas'] ?? 0),
            'segundos' => max(0, time() - (int)$estado['creado']),
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
}
