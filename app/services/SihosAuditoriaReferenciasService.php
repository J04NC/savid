<?php

/**
 * Modal "Auditar referencias" del reporte de cruce (?url=sihos/cruce):
 * trazabilidad de solo lectura de un documento puntual — inspirado en
 * sihos/modulos/procesos/audicart.php ("Auditoría de Cartera") de SIHOS
 * legado, modo "Trazabilidad de Documentos", pero agregando presupuesto
 * (DetaPlan), que SIHOS nativo no muestra en esa pantalla. Enteramente de
 * solo lectura — no toca ninguna de las escrituras del módulo.
 */
class SihosAuditoriaReferenciasService
{
    private SihosEmpresaConfigRepository $configRepository;

    public function __construct()
    {
        $this->configRepository = new SihosEmpresaConfigRepository();
    }

    public function auditarDocumento(int $empresaId, string $codiDocu, string $numeDocu): array
    {
        $configFila = $this->configRepository->findByEmpresaId($empresaId);
        if ($configFila === null || $configFila['host'] === '' || $configFila['base_datos'] === '') {
            return ['ok' => false, 'message' => 'Esta empresa no tiene conexión a SIHOS configurada.'];
        }

        $repositorio = new SihosExternalRepository([
            'host' => $configFila['host'],
            'port' => (string)$configFila['puerto'],
            'database' => $configFila['base_datos'],
            'username' => $configFila['usuario'],
            'codiInst' => trim((string)($configFila['codi_inst'] ?? '')),
            'password' => SihosCredentialCipher::decrypt($configFila['password_cifrado'] ?? null),
            'charset' => $configFila['charset'],
        ]);

        try {
            $encabezado = $repositorio->fetchEncabezadoDocumentoAuditoria($codiDocu, $numeDocu);
        } catch (PDOException $e) {
            return ['ok' => false, 'message' => 'No se pudo consultar SIHOS: ' . $e->getMessage()];
        }

        if ($encabezado === null) {
            return ['ok' => false, 'message' => "No se encontró el documento {$codiDocu}-{$numeDocu} en SIHOS."];
        }

        try {
            $contabilidadPropia = $repositorio->fetchDetaContPropio($codiDocu, $numeDocu);
            $presupuestoPropio = $repositorio->fetchDetaPlanPropio($codiDocu, $numeDocu);
            $referenciadoPor = $repositorio->fetchDocumentosQueReferencian($codiDocu, $numeDocu);
        } catch (PDOException $e) {
            return ['ok' => false, 'message' => 'No se pudo consultar la trazabilidad en SIHOS: ' . $e->getMessage()];
        }

        // Estado del documento — mismo criterio que sihos/modulos/comun/auditarefe.php
        // (Anulado > Causado > Preliminar); no se replica el caso especial
        // "Confirmado |N|" (factura anulada por nota) del legado, fuera de
        // alcance de este modal.
        $estadoDocumento = static function (int $anulado, int $causado): string {
            if ($anulado === 1) {
                return 'anulado';
            }

            return $causado === 1 ? 'confirmado' : 'preliminar';
        };

        $encabezado['Estado'] = $estadoDocumento($encabezado['Anulado'], $encabezado['Causado']);

        // Resumen débito/crédito del documento propio (todas sus líneas
        // DetaCont, no solo 4312 — mismo criterio que el bloque "Documento
        // X - Y" de auditarefe.php).
        $valorDebitoPropio = array_sum(array_map(
            static fn (array $l): float => $l['Valor'] > 0 ? $l['Valor'] : 0.0,
            $contabilidadPropia
        ));
        $valorCreditoPropio = abs(array_sum(array_map(
            static fn (array $l): float => $l['Valor'] < 0 ? $l['Valor'] : 0.0,
            $contabilidadPropia
        )));

        $referenciadoPor = array_map(
            static function (array $v) use ($estadoDocumento): array {
                $v['Estado'] = $estadoDocumento($v['Anulado'], $v['Causado']);

                return $v;
            },
            $referenciadoPor
        );

        // El "total" de contabilidad, para que sea comparable contra
        // presupuesto, aísla la familia 4312 (mismo criterio que
        // SihosCruceReconocimientoService::buildDiferenciasPresupuestoContabilidad())
        // — sumar TODAS las líneas de un documento balanceado (cartera +
        // ingreso) siempre daría ~0 y no diría nada. Las tablas de detalle
        // ('contabilidadPropia'/'lineas' de cada vinculado) sí muestran
        // todas las cuentas, sin filtrar, para trazabilidad completa. Mismo
        // signo que ya usa el resto del módulo: créditos en DetaCont quedan
        // negativos en la BD, se invierten para comparar contra
        // presupuesto (siempre positivo).
        $sumaCuentas4312 = static function (array $lineas): float {
            $filtradas = array_filter($lineas, static fn (array $l): bool => str_starts_with($l['CodiCont'], '4312'));

            return -array_sum(array_column($filtradas, 'Valor'));
        };

        $contabilidadTotal = $sumaCuentas4312($contabilidadPropia);
        $presupuestoTotal = array_sum(array_column($presupuestoPropio, 'Valor'));

        foreach ($referenciadoPor as $vinculado) {
            $contabilidadTotal += $sumaCuentas4312($vinculado['lineas']);
            $presupuestoTotal += $vinculado['presupuestoTotal'];
        }

        return [
            'ok' => true,
            'codiDocu' => $codiDocu,
            'numeDocu' => $numeDocu,
            'encabezado' => $encabezado,
            'contabilidadPropia' => $contabilidadPropia,
            'presupuestoPropio' => $presupuestoPropio,
            'referenciadoPor' => $referenciadoPor,
            'resumenPropio' => [
                'valorTotal' => $encabezado['ValoTota'],
                'valorDebito' => round($valorDebitoPropio, 2),
                'valorCredito' => round($valorCreditoPropio, 2),
            ],
            'totales' => [
                'presupuesto' => round($presupuestoTotal, 2),
                'contabilidad' => round($contabilidadTotal, 2),
                'diferencia' => round($presupuestoTotal - $contabilidadTotal, 2),
            ],
        ];
    }
}
