<?php

/**
 * Reporte SIHOS "Cruce reconocimiento de ingresos": 5 chequeos entre
 * facturación, presupuesto (DetaPlan) y contabilidad (DetaCont) de una
 * empresa, sobre su conexión externa de solo lectura a SIHOS.
 *
 * El universo de documentos NO se filtra por CodiDocu literal ('FE', 'GLA',
 * ...) porque cada instalación de SIHOS puede tener su propio catálogo en
 * MaesDocu — se resuelve por DocuApli, que es el catálogo fijo de "rol"
 * del documento en el software:
 *   28/59 = facturas de venta (FE/FV/FVC en esta instalación)
 *   68    = glosa aceptada o concluida (GLA)
 *   127   = nota contabilidad de facturación (NCC)
 *
 * Las cuentas esperadas (reversión de glosa, capita) tampoco se hardcodean:
 * se leen de CodiInst.CuenGlosAct y de CentCost.AntiCapi de cada empresa.
 */
class SihosCruceReconocimientoService
{
    private const DOCU_APLI_FACTURA = [28, 59];
    private const DOCU_APLI_GLOSA = [68];
    private const DOCU_APLI_NOTA = [127];
    private const DOCU_APLI_NOTA_GENERICA = [3];
    private const DOCU_APLI_RECONOCIMIENTO_TESORERIA = [40];
    private const DOCU_APLI_DAC = [125];

    private SihosEmpresaConfigRepository $configRepository;

    public function __construct()
    {
        $this->configRepository = new SihosEmpresaConfigRepository();
    }

    public function buildReporte(int $empresaId, string $fechaIni, string $fechaFin): array
    {
        if (!$this->fechaValida($fechaIni) || !$this->fechaValida($fechaFin) || $fechaIni > $fechaFin) {
            return ['ok' => false, 'error' => 'Rango de fechas inválido.'];
        }

        $configFila = $this->configRepository->findByEmpresaId($empresaId);
        if ($configFila === null || $configFila['host'] === '' || $configFila['base_datos'] === '' || $configFila['usuario'] === '') {
            return ['ok' => false, 'error' => 'Esta empresa no tiene conexión a SIHOS configurada.'];
        }

        $codiInst = trim((string)($configFila['codi_inst'] ?? ''));
        if ($codiInst === '') {
            return ['ok' => false, 'error' => 'Falta configurar el CodiInst de esta empresa en Conexión SIHOS.'];
        }

        $repository = new SihosExternalRepository([
            'host' => $configFila['host'],
            'port' => (string)$configFila['puerto'],
            'database' => $configFila['base_datos'],
            'username' => $configFila['usuario'],
            'codiInst' => $codiInst,
            'password' => SihosCredentialCipher::decrypt($configFila['password_cifrado'] ?? null),
            'charset' => $configFila['charset'],
        ]);

        try {
            $codigosFactura = $repository->resolveCodigosDocumentoPorAplicacion(self::DOCU_APLI_FACTURA);
            $codigosGlosa = $repository->resolveCodigosDocumentoPorAplicacion(self::DOCU_APLI_GLOSA);
            $codigosNota = $repository->resolveCodigosDocumentoPorAplicacion(self::DOCU_APLI_NOTA);

            $cuentasInstitucion = $repository->fetchCuentasInstitucion();
            $cuentaReversion = trim((string)($cuentasInstitucion['CuenGlosAct'] ?? ''));
            $cuentaGasto = trim((string)($cuentasInstitucion['CuenGlos'] ?? ''));
            // Familia de cuentas, no el valor exacto: CuenGlosAct/CuenGlos son
            // solo UN ejemplo de una familia con una subcuenta por tipo de
            // pagador (ver docblock de fetchCuentasInesperadasNotasVigenciaActual).
            $prefijoReversion = $cuentaReversion !== '' ? substr($cuentaReversion, 0, 4) : '';
            $prefijoGasto = $cuentaGasto !== '' ? substr($cuentaGasto, 0, 2) : '';
            $cuentasCapitaPasivo = $repository->fetchCuentasCapitaPasivo();

            return [
                'ok' => true,
                'codigosFactura' => $codigosFactura,
                'codigosGlosa' => $codigosGlosa,
                'codigosNota' => $codigosNota,
                'cuentaReversion' => $cuentaReversion,
                'cuentaGasto' => $cuentaGasto,
                'cuentasCapitaPasivo' => $cuentasCapitaPasivo,
                'facturasSinPresupuesto' => $repository->fetchFacturasSinPresupuesto($codigosFactura, $fechaIni, $fechaFin),
                'facturasSinCuentaIngreso' => $repository->fetchFacturasSinCuentaIngreso(
                    $codigosFactura,
                    $fechaIni,
                    $fechaFin,
                    $cuentasCapitaPasivo
                ),
                'notasIncompletas' => $repository->fetchNotasVigenciaActualIncompletas($codigosNota, $fechaIni, $fechaFin),
                'glosasIncompletas' => $repository->fetchNotasVigenciaActualIncompletas($codigosGlosa, $fechaIni, $fechaFin),
                'cuentasInesperadasFacturas' => $repository->fetchCuentasInesperadasFacturas(
                    $codigosFactura,
                    $fechaIni,
                    $fechaFin,
                    $cuentasCapitaPasivo
                ),
                'cuentasInesperadasNotas' => $repository->fetchCuentasInesperadasNotasVigenciaActual(
                    $codigosNota,
                    $fechaIni,
                    $fechaFin,
                    $prefijoReversion,
                    $prefijoGasto
                ),
                'cuentasInesperadasGlosas' => $repository->fetchCuentasInesperadasNotasVigenciaActual(
                    $codigosGlosa,
                    $fechaIni,
                    $fechaFin,
                    $prefijoReversion,
                    $prefijoGasto
                ),
                'diferenciasPresupuestoContabilidad' => $this->buildDiferenciasPresupuestoContabilidad(
                    $repository,
                    $codigosFactura,
                    $codigosGlosa,
                    $codigosNota,
                    $cuentasCapitaPasivo,
                    $fechaIni,
                    $fechaFin
                ),
            ];
        } catch (PDOException $e) {
            return ['ok' => false, 'error' => 'No se pudo consultar SIHOS: ' . $e->getMessage()];
        }
    }

    private function fechaValida(string $fecha): bool
    {
        $d = DateTime::createFromFormat('Y-m-d', $fecha);

        return $d !== false && $d->format('Y-m-d') === $fecha;
    }

    /**
     * Chequeo 6: diferencia entre el presupuesto reconocido (EncaCont+
     * DetaPlan, igual al reporte de "ejecución presupuestal" de SIHOS) y el
     * saldo contable de la familia 4312 (EncaCont+DetaCont), por documento.
     * El universo de "presupuesto" son las facturas + glosas + notas ya
     * resueltas por DocuApli, más los ajustes contables genéricos con
     * presupuesto (DocuApli=3, ManePres=1 — p. ej. "Nota de Tesorería"/
     * "Nota Contabilidad" en instalaciones que las usan así) y los
     * "Reconocimiento" de tesorería (DocuApli=40 — rendimientos financieros,
     * saldos de vigencia anterior, disponibilidad inicial: NUNCA tienen
     * contrapartida en 4312 porque no son ingreso por facturación de
     * pacientes, así que su diferencia siempre es "esperada" y se reporta
     * aparte para no tapar los hallazgos reales — decisión pedida por el
     * usuario). El universo de "contabilidad" es deliberadamente MÁS AMPLIO
     * (cualquier documento que toque 4312xx, sin restringir tipo) para que
     * un documento "fuera de lista" que sí afecta el ingreso contable
     * aparezca como diferencia en vez de quedar oculto por el filtro.
     *
     * El signo de DetaCont se invierte (créditos quedan negativos en SIHOS)
     * para comparar en la misma convención positiva que el presupuesto.
     *
     * Documentos vinculados (nota, glosa, nota genérica, DAC): cualquiera de
     * estos que referencie una factura (vía TiDoRefe/NuDoRefe) se atribuye a
     * ELLA — tanto su presupuesto como su contabilidad — SOLO cuando ambos
     * documentos caen dentro del rango filtrado (conciliación silenciosa,
     * sin importar si son de meses distintos). Dos casos reales que exigen
     * esto:
     *   - Capita: un DAC redistribuye el pasivo de una factura capita hacia
     *     4312 en varios centros de costo y NUNCA tiene DetaPlan propio — el
     *     presupuesto ya se reconoció en la factura.
     *   - Anulación por nota: una nota (TipoComp='Devolución Factura') puede
     *     anular una factura completa reversando cartera/pasivo — con signo
     *     invertido en su propio DetaPlan (ver más abajo) cancela
     *     exactamente el presupuesto de la factura, pero su reverso no
     *     necesariamente toca 4312 (puede tocar cartera/pasivo, como se
     *     verificó con un caso real), así que sin este tratamiento la
     *     factura Y la nota aparecían como dos diferencias opuestas que en
     *     realidad se cancelan.
     * Si cualquiera de los dos documentos del par queda fuera del rango, se
     * deja como diferencia real y se anota el documento relacionado con su
     * fecha real — nunca se trae en silencio algo de fuera del rango que el
     * usuario eligió (regla acordada con el usuario).
     */
    private function buildDiferenciasPresupuestoContabilidad(
        SihosExternalRepository $repository,
        array $codigosFactura,
        array $codigosGlosa,
        array $codigosNota,
        array $cuentasCapitaPasivo,
        string $fechaIni,
        string $fechaFin
    ): array {
        $codigosNotaGenerica = $repository->resolveCodigosDocumentoPorAplicacion(self::DOCU_APLI_NOTA_GENERICA);
        $codigosDac = $repository->resolveCodigosDocumentoPorAplicacion(self::DOCU_APLI_DAC);
        $codigosReconocimientoTesoreria = $repository->resolveCodigosDocumentoPorAplicacionSinFiltroPresupuesto(
            self::DOCU_APLI_RECONOCIMIENTO_TESORERIA
        );
        $codigosSignoInvertido = array_values(array_unique([...$codigosGlosa, ...$codigosNota]));
        $codigosPresupuesto = array_values(array_unique([
            ...$codigosFactura,
            ...$codigosGlosa,
            ...$codigosNota,
            ...$codigosNotaGenerica,
            ...$codigosReconocimientoTesoreria,
        ]));

        $porPresupuesto = $repository->fetchPresupuestoReconocidoPorDocumento(
            $codigosPresupuesto,
            $codigosSignoInvertido,
            $fechaIni,
            $fechaFin
        );
        $porContabilidad = $repository->fetchContabilidad4312PorDocumento($fechaIni, $fechaFin);
        $nombresTipoUsua = $repository->fetchTipoUsuarioNombres();
        $rubrosReconocimientoTesoreria = $repository->fetchRubrosPresupuestoPorDocumento(
            $codigosReconocimientoTesoreria,
            $fechaIni,
            $fechaFin
        );

        $claves = array_unique([...array_keys($porPresupuesto), ...array_keys($porContabilidad)]);

        // Para todo lo que no sea la factura misma ni un "Reconocimiento" de
        // tesorería (que no referencia ninguna factura puntual), se resuelve
        // qué factura afecta — así una nota/glosa en el detalle muestra la
        // factura y fecha reales, no solo su propio número.
        $codigosNoFacturaDirecta = [];
        foreach ($claves as $clave) {
            [$cd] = explode('-', $clave, 2);
            if (!in_array($cd, $codigosFactura, true) && !in_array($cd, $codigosReconocimientoTesoreria, true)) {
                $codigosNoFacturaDirecta[$cd] = true;
            }
        }
        $facturaReferenciada = $repository->fetchFacturaReferenciadaPorDocumento(
            array_keys($codigosNoFacturaDirecta),
            $fechaIni,
            $fechaFin
        );

        $codigosVinculados = array_values(array_unique([...$codigosGlosa, ...$codigosNota, ...$codigosNotaGenerica, ...$codigosDac]));

        // Presupuesto: DetaPlan NO tiene referencia por línea (TipoDoRe/
        // NumeDoRe no se usan en la práctica — verificado), así que solo se
        // puede atribuir el presupuesto de un vinculado a una factura si ese
        // vinculado referencia UNA sola factura distinta (ReferenciaAmbigua
        // = false). Si referencia varias (nota consolidada), no hay forma
        // de saber cuánto presupuesto corresponde a cada una — se deja el
        // vinculado con su propio presupuesto sin fusionar.
        foreach ($claves as $clave) {
            [$cd] = explode('-', $clave, 2);
            if (!in_array($cd, $codigosVinculados, true) || !isset($porPresupuesto[$clave])) {
                continue;
            }

            $ref = $facturaReferenciada[$clave] ?? null;
            if ($ref === null || ($ref['ReferenciaAmbigua'] ?? false)) {
                continue;
            }

            $facturaFecha = (string)$ref['FacturaFecha'];
            if ($facturaFecha < $fechaIni || $facturaFecha > $fechaFin) {
                continue;
            }

            $claveFactura = $ref['FacturaCodiDocu'] . '-' . $ref['FacturaNumeDocu'];
            if (!isset($porPresupuesto[$claveFactura])) {
                $porPresupuesto[$claveFactura] = [
                    'CodiDocu' => $ref['FacturaCodiDocu'],
                    'NumeDocu' => $ref['FacturaNumeDocu'],
                    'FechDocu' => $facturaFecha,
                    'CentCost' => null,
                    'TipoUsua' => $porPresupuesto[$clave]['TipoUsua'] ?? null,
                    'Valor' => 0,
                ];
            }
            $porPresupuesto[$claveFactura]['Valor'] += (float)$porPresupuesto[$clave]['Valor'];
            unset($porPresupuesto[$clave]);
        }

        // Contabilidad: DetaCont SÍ tiene referencia por línea, así que se
        // atribuye línea por línea (no por documento completo) — un mismo
        // vinculado puede redistribuir varias facturas a la vez (verificado
        // con un caso real: una nota con 12 líneas, una por factura). Se
        // suma lo atribuido a cada factura EN RANGO y se resta del propio
        // vinculado (para no contarlo dos veces); lo que no se atribuyó a
        // ninguna factura en rango se queda como saldo propio del vinculado.
        $atribucionesContabilidad = $repository->fetchAtribucionContabilidadVinculada(
            $codigosVinculados,
            $codigosFactura,
            $fechaIni,
            $fechaFin
        );
        foreach ($atribucionesContabilidad as $fila) {
            $claveVinculado = $fila['VinculadoCodiDocu'] . '-' . $fila['VinculadoNumeDocu'];
            $claveFactura = $fila['FacturaCodiDocu'] . '-' . $fila['FacturaNumeDocu'];
            $valor = (float)$fila['Valor'];

            if (!isset($porContabilidad[$claveFactura])) {
                $porContabilidad[$claveFactura] = [
                    'CodiDocu' => $fila['FacturaCodiDocu'],
                    'NumeDocu' => $fila['FacturaNumeDocu'],
                    'FechDocu' => $fila['FacturaFecha'],
                    'TipoUsua' => $fila['FacturaTipoUsua'],
                    'Valor' => 0,
                ];
            }
            $porContabilidad[$claveFactura]['Valor'] += $valor;

            if (isset($porContabilidad[$claveVinculado])) {
                $porContabilidad[$claveVinculado]['Valor'] -= $valor;
            }
        }

        // Para las facturas que se quedaron sin documento vinculado
        // conciliado dentro del rango (o nunca tuvieron), se busca el
        // vinculado real (sin filtro de fecha) solo para anotar dónde está
        // — nunca para sumarlo al total.
        $vinculadoPorFactura = $repository->fetchDocumentoVinculadoPorFactura(
            $codigosFactura,
            $codigosVinculados,
            $fechaIni,
            $fechaFin
        );

        // Solo para trazabilidad (no cambia ningún total ni oculta ninguna
        // diferencia): qué otra cuenta tocó la factura cuando no es 4312 —
        // p. ej. "Otros deudores" que acredita 48xx (otros ingresos) en vez
        // de 4312 (verificado con un caso real).
        $cuentasNoIdentificadas = $repository->fetchCuentasNoIdentificadasFacturas(
            $codigosFactura,
            $cuentasCapitaPasivo,
            $fechaIni,
            $fechaFin
        );

        $claves = array_unique([...array_keys($porPresupuesto), ...array_keys($porContabilidad)]);

        $detalle = [];
        $detalleReconocimientoTesoreria = [];
        $totalPresupuesto = 0.0;
        $totalContabilidad = 0.0;
        $totalDiferenciaReconocimientoTesoreria = 0.0;
        $porTipoUsua = [];

        foreach ($claves as $clave) {
            $filaPres = $porPresupuesto[$clave] ?? null;
            $filaCont = $porContabilidad[$clave] ?? null;
            [$codiDocu, $numeDocu] = explode('-', $clave, 2);
            $esReconocimientoTesoreria = in_array($codiDocu, $codigosReconocimientoTesoreria, true);

            $presupuesto = round((float)($filaPres['Valor'] ?? 0), 2);
            // Créditos en DetaCont quedan en negativo (convención SIHOS); se
            // invierte para comparar contra el presupuesto, que es positivo.
            $contabilidad = round((float)($filaCont['Valor'] ?? 0) * -1, 2);
            $diferencia = round($presupuesto - $contabilidad, 2);

            $totalPresupuesto += $presupuesto;
            $totalContabilidad += $contabilidad;

            if ($esReconocimientoTesoreria) {
                $totalDiferenciaReconocimientoTesoreria += $diferencia;
                if (abs($diferencia) >= 0.01) {
                    $detalleReconocimientoTesoreria[] = [
                        'CodiDocu' => $codiDocu,
                        'NumeDocu' => $numeDocu,
                        'FechDocu' => $filaPres['FechDocu'] ?? $filaCont['FechDocu'] ?? '',
                        'presupuesto' => $presupuesto,
                        'contabilidad' => $contabilidad,
                        'diferencia' => $diferencia,
                        'rubros' => $rubrosReconocimientoTesoreria[$clave] ?? [],
                    ];
                }

                continue;
            }

            $codiTipoUsua = $filaPres['TipoUsua'] ?? $filaCont['TipoUsua'] ?? null;
            $nombreTipoUsua = $codiTipoUsua !== null && isset($nombresTipoUsua[$codiTipoUsua])
                ? $nombresTipoUsua[$codiTipoUsua]
                : ('Tipo ' . ($codiTipoUsua ?? 'sin dato'));

            if (!isset($porTipoUsua[$nombreTipoUsua])) {
                $porTipoUsua[$nombreTipoUsua] = ['presupuesto' => 0.0, 'contabilidad' => 0.0, 'diferencia' => 0.0];
            }
            $porTipoUsua[$nombreTipoUsua]['presupuesto'] += $presupuesto;
            $porTipoUsua[$nombreTipoUsua]['contabilidad'] += $contabilidad;
            $porTipoUsua[$nombreTipoUsua]['diferencia'] += $diferencia;

            if (abs($diferencia) >= 0.01) {
                $ref = $facturaReferenciada[$clave] ?? null;
                $fechaDocu = $filaPres['FechDocu'] ?? $filaCont['FechDocu'] ?? '';
                $facturaFecha = $ref['FacturaFecha'] ?? null;

                // Elegible para borrar el DetaPlan huérfano solo cuando es una
                // nota (no la factura misma) sobre una factura de vigencia
                // ANTERIOR con presupuesto ≠ 0 — esa combinación nunca debería
                // existir (regla de negocio confirmada por el usuario: una
                // nota sobre factura de vigencia anterior va a gasto, no
                // genera presupuesto). El chequeo real de si el período está
                // cerrado se hace en el momento de la acción, no aquí.
                $puedeEliminarDetaPlan = $facturaFecha !== null
                    && $fechaDocu !== ''
                    && (int)substr((string)$facturaFecha, 0, 4) < (int)substr((string)$fechaDocu, 0, 4)
                    && abs($presupuesto) >= 0.01;

                // Documento relacionado a mostrar (trazabilidad): la factura
                // que referencia una nota/glosa/DAC; o, si es una factura
                // cuyo documento vinculado quedó fuera del rango filtrado, el
                // vinculado real que la afecta (nunca se usa para sumar,
                // solo para mostrar dónde está y por qué no se concilió).
                $relacionadoCodiDocu = $ref['FacturaCodiDocu'] ?? null;
                $relacionadoNumeDocu = $ref['FacturaNumeDocu'] ?? null;
                $relacionadoFecha = $facturaFecha;

                if ($relacionadoCodiDocu === null && isset($vinculadoPorFactura[$clave])) {
                    $relacionadoCodiDocu = $vinculadoPorFactura[$clave]['VinculadoCodiDocu'];
                    $relacionadoNumeDocu = $vinculadoPorFactura[$clave]['VinculadoNumeDocu'];
                    $relacionadoFecha = $vinculadoPorFactura[$clave]['VinculadoFecha'];
                }

                // Solo trazabilidad: qué otra cuenta tocó la factura cuando
                // su contabilidad(4312) no cuadra — no cambia la diferencia.
                $cuentaReal = null;
                if (isset($cuentasNoIdentificadas[$clave])) {
                    // Mismo criterio de signo que "contabilidad": créditos en
                    // DetaCont quedan negativos, se invierten para mostrar.
                    $partes = array_map(
                        static fn (array $c): string => $c['CodiCont'] . ' (' . number_format($c['Valor'] * -1, 0, ',', '.') . ')',
                        $cuentasNoIdentificadas[$clave]
                    );
                    $cuentaReal = implode(', ', $partes);
                }

                $detalle[] = [
                    'CodiDocu' => $codiDocu,
                    'NumeDocu' => $numeDocu,
                    'FechDocu' => $fechaDocu,
                    'TipoUsua' => $nombreTipoUsua,
                    'presupuesto' => $presupuesto,
                    'contabilidad' => $contabilidad,
                    'diferencia' => $diferencia,
                    'RelacionadoCodiDocu' => $relacionadoCodiDocu,
                    'RelacionadoNumeDocu' => $relacionadoNumeDocu,
                    'RelacionadoFecha' => $relacionadoFecha,
                    'CuentaReal' => $cuentaReal,
                    'puedeEliminarDetaPlan' => $puedeEliminarDetaPlan,
                ];
            }
        }

        usort($detalle, static fn (array $a, array $b): int => abs($b['diferencia']) <=> abs($a['diferencia']));
        usort(
            $detalleReconocimientoTesoreria,
            static fn (array $a, array $b): int => abs($b['diferencia']) <=> abs($a['diferencia'])
        );

        return [
            'detalle' => $detalle,
            'detalleReconocimientoTesoreria' => $detalleReconocimientoTesoreria,
            'totalPresupuesto' => round($totalPresupuesto, 2),
            'totalContabilidad' => round($totalContabilidad, 2),
            'totalDiferencia' => round($totalPresupuesto - $totalContabilidad, 2),
            'totalDiferenciaReconocimientoTesoreria' => round($totalDiferenciaReconocimientoTesoreria, 2),
            'totalDiferenciaOperativa' => round(
                ($totalPresupuesto - $totalContabilidad) - $totalDiferenciaReconocimientoTesoreria,
                2
            ),
            'porTipoUsua' => $porTipoUsua,
        ];
    }
}
