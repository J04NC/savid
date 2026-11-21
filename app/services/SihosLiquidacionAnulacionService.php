<?php

/**
 * Anula en SIHOS (EncaFact) las liquidaciones que el usuario marcó en el modal
 * "Liquidaciones con factura anulada" del reporte Liquidación: las que siguen
 * vivas aunque TODAS sus facturas fueron anuladas por una nota crédito. Las que
 * tienen además otra factura vigente nunca llegan a esta acción (el modal no las
 * deja marcar y SihosExternalWriteRepository vuelve a validarlo con la liquidación
 * bloqueada). Cada anulación queda en `auditoria` con el estado anterior.
 */
class SihosLiquidacionAnulacionService
{
    public const MAX_LIQUIDACIONES = 500;

    private SihosEmpresaConfigRepository $configRepository;

    public function __construct(
        private readonly SihosLiquidacionDetalleService $detalle,
        ?SihosEmpresaConfigRepository $configRepository = null
    ) {
        $this->configRepository = $configRepository ?? new SihosEmpresaConfigRepository();
    }

    /**
     * @param array<string,string> $filtros los del modal (ya normalizados)
     * @param list<string> $claves 'año|documento|número' de las liquidaciones marcadas
     * @return array{ok:bool,error?:string,anuladas?:int,omitidas?:int,resultados?:list<array{liquidacion:string,ok:bool,motivo:string}>}
     */
    public function aplicar(int $empresaId, int $userId, string $token, array $filtros, array $claves): array
    {
        $claves = array_values(array_unique(array_filter($claves, static fn ($c): bool => is_string($c) && $c !== '' && strlen($c) <= 100)));
        if ($claves === [] || count($claves) > self::MAX_LIQUIDACIONES) {
            return ['ok' => false, 'error' => 'Marque entre 1 y ' . self::MAX_LIQUIDACIONES . ' liquidaciones.'];
        }
        $cfg = $this->configRepository->findByEmpresaId($empresaId);
        $codiInst = trim((string)($cfg['codi_inst'] ?? ''));
        if ($cfg === null || $codiInst === '' || $cfg['host'] === '' || $cfg['base_datos'] === '') {
            return ['ok' => false, 'error' => 'Esta empresa no tiene conexión a SIHOS configurada.'];
        }
        $usuarioEscritura = trim((string)($cfg['usuario_escritura'] ?? ''));
        if ($usuarioEscritura === '' || empty($cfg['password_escritura_cifrado'])) {
            return ['ok' => false, 'error' => 'Configure las credenciales de escritura de esta empresa en Conexión SIHOS antes de usar esta acción.'];
        }
        $ruta = $this->detalle->seleccion($empresaId, $userId, $token, $filtros);
        if ($ruta === null) {
            return ['ok' => false, 'error' => 'El resultado de este reporte expiró o no existe. Vuelva a consultar.'];
        }
        $liquidaciones = $this->detalle->liquidacionesAnulables($ruta, $claves);
        if ($liquidaciones === null) {
            return ['ok' => false, 'error' => 'Este reporte se generó antes de que existiera esta acción. Genérelo de nuevo.'];
        }

        $escritura = SihosRepositoryFactory::buildWrite($cfg, $codiInst, $usuarioEscritura);

        $resultados = [];
        $anuladas = 0;
        foreach ($claves as $clave) {
            $l = $liquidaciones[$clave] ?? null;
            $etiqueta = $l !== null ? "{$l['docu']}-{$l['nume']}" : 'Liquidación no válida';
            if ($l === null) {
                $resultados[] = ['liquidacion' => $etiqueta, 'ok' => false, 'motivo' => 'No está en la selección o no es una liquidación completa con factura anulada.'];
                continue;
            }
            try {
                $r = $escritura->anularLiquidacionDeFacturaAnulada($l['ano'], $l['docu'], $l['nume'], range((int)$l['ano'], max((int)$l['ano'], (int)date('Y'))));
            } catch (SihosOperacionEnCursoException $e) {
                $resultados[] = ['liquidacion' => $etiqueta, 'ok' => false, 'motivo' => $e->getMessage()];
                continue;
            } catch (PDOException $e) {
                error_log('SihosLiquidacionAnulacionService: ' . $e->getMessage());
                $resultados[] = ['liquidacion' => $etiqueta, 'ok' => false, 'motivo' => 'Error al escribir en SIHOS; no se modificó.'];
                continue;
            }
            if ($r['ok']) {
                $anuladas++;
                $this->registrarAuditoria($empresaId, "{$codiInst}-{$l['ano']}-{$l['docu']}-{$l['nume']}", $r['anterior']);
            }
            $resultados[] = ['liquidacion' => $etiqueta, 'ok' => $r['ok'], 'motivo' => $r['motivo']];
        }

        return ['ok' => true, 'anuladas' => $anuladas, 'omitidas' => count($resultados) - $anuladas, 'resultados' => $resultados];
    }

    /** @param array<string,mixed> $anterior */
    private function registrarAuditoria(int $empresaId, string $registroId, array $anterior): void
    {
        SihosAuditoriaManualService::registrar(
            $empresaId,
            'UPDATE',
            'sihos.EncaFact',
            $registroId,
            json_encode($anterior, JSON_UNESCAPED_UNICODE),
            json_encode(['Anulado' => 1, 'CausAnul' => 2], JSON_UNESCAPED_UNICODE),
            'Anulación de una liquidación cuyas facturas están anuladas por nota crédito, desde el reporte Liquidación'
        );
    }
}
