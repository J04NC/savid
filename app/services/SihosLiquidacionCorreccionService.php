<?php

/**
 * Corrige en SIHOS (DetaFact.CodiMedi) el profesional de las líneas de
 * liquidación que el usuario marcó en el modal del reporte "Liquidación",
 * con el profesional inferido que trae el CSV del reporte (confianza Alta o
 * Media). Escritura acotada: solo líneas de liquidaciones confirmadas y no
 * anuladas que hoy no tienen profesional o lo tienen un usuario de la
 * especialidad ADMINISTRATIVO (lo revalida SihosExternalWriteRepository con
 * la línea bloqueada). Cada cambio queda en `auditoria` con el valor anterior.
 */
class SihosLiquidacionCorreccionService
{
    public const MAX_LINEAS = 500;

    private SihosEmpresaConfigRepository $configRepository;

    public function __construct(
        private readonly SihosLiquidacionDetalleService $detalle,
        ?SihosEmpresaConfigRepository $configRepository = null
    ) {
        $this->configRepository = $configRepository ?? new SihosEmpresaConfigRepository();
    }

    /**
     * @param array<string,string> $filtros los del modal (ya normalizados): identifican la selección que contiene las líneas
     * @param list<string> $claves 'año|documento|número|consecutivo' de las líneas marcadas
     * @return array{ok:bool,error?:string,actualizadas?:int,omitidas?:int,resultados?:list<array{linea:string,ok:bool,motivo:string}>}
     */
    public function aplicar(int $empresaId, int $userId, string $token, array $filtros, array $claves): array
    {
        $claves = array_values(array_unique(array_filter($claves, static fn ($c): bool => is_string($c) && $c !== '' && strlen($c) <= 100)));
        if ($claves === [] || count($claves) > self::MAX_LINEAS) {
            return ['ok' => false, 'error' => 'Marque entre 1 y ' . self::MAX_LINEAS . ' líneas.'];
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
        $lineas = $this->detalle->lineasCorregibles($ruta, $claves);
        if ($lineas === null) {
            return ['ok' => false, 'error' => 'Este reporte se generó antes de que existiera la corrección. Genérelo de nuevo.'];
        }

        $lectura = new SihosExternalRepository([
            'host' => $cfg['host'], 'port' => (string)$cfg['puerto'], 'database' => $cfg['base_datos'], 'username' => $cfg['usuario'],
            'codiInst' => $codiInst, 'password' => SihosCredentialCipher::decrypt($cfg['password_cifrado'] ?? null), 'charset' => $cfg['charset'],
        ]);
        $usuaModi = (new SihosUsuaDigiResolver())->resolver($userId, $lectura);
        $escritura = new SihosExternalWriteRepository([
            'host' => $cfg['host'], 'port' => (string)$cfg['puerto'], 'database' => $cfg['base_datos'], 'username' => $usuarioEscritura,
            'codiInst' => $codiInst, 'password' => SihosCredentialCipher::decrypt($cfg['password_escritura_cifrado']), 'charset' => $cfg['charset'],
        ]);

        $resultados = [];
        $actualizadas = 0;
        foreach ($claves as $clave) {
            $l = $lineas[$clave] ?? null;
            $etiqueta = $l !== null ? "{$l['docu']}-{$l['nume']} línea {$l['cons']}" : 'Línea no válida';
            if ($l === null) {
                $resultados[] = ['linea' => $etiqueta, 'ok' => false, 'motivo' => 'No está en la selección o no tiene profesional inferido.'];
                continue;
            }
            try {
                $r = $escritura->corregirProfesionalLinea($l['ano'], $l['docu'], $l['nume'], (int)$l['cons'], $l['login'], SihosLiquidacionInferencia::CODI_ESPE_ADMINISTRATIVO, $usuaModi);
            } catch (SihosOperacionEnCursoException $e) {
                $resultados[] = ['linea' => $etiqueta, 'ok' => false, 'motivo' => $e->getMessage()];
                continue;
            } catch (PDOException $e) {
                error_log('SihosLiquidacionCorreccionService: ' . $e->getMessage());
                $resultados[] = ['linea' => $etiqueta, 'ok' => false, 'motivo' => 'Error al escribir en SIHOS; no se modificó.'];
                continue;
            }
            if ($r['ok']) {
                $actualizadas++;
                $this->registrarAuditoria($empresaId, "{$codiInst}-{$l['ano']}-{$l['docu']}-{$l['nume']}-{$l['cons']}", $r['anterior'], $l);
            }
            $resultados[] = ['linea' => $etiqueta, 'ok' => $r['ok'], 'motivo' => $r['motivo']];
        }

        return ['ok' => true, 'actualizadas' => $actualizadas, 'omitidas' => count($resultados) - $actualizadas, 'resultados' => $resultados];
    }

    /** @param array<string,string> $linea */
    private function registrarAuditoria(int $empresaId, string $registroId, string $anterior, array $linea): void
    {
        SihosAuditoriaManualService::registrar(
            $empresaId,
            'UPDATE',
            'sihos.DetaFact',
            $registroId,
            json_encode(['CodiMedi' => $anterior], JSON_UNESCAPED_UNICODE),
            json_encode(['CodiMedi' => $linea['login']], JSON_UNESCAPED_UNICODE),
            'Corrección del profesional (CodiMedi) de una línea de liquidación con el profesional inferido (confianza ' . $linea['confianza'] . ') desde el reporte Liquidación'
        );
    }
}
