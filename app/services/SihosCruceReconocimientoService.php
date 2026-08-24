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

    /*
     * El reporte carga cada documento de presupuesto/contabilidad en un
     * array PHP para cruzarlos (no hay forma de agregar todo en SQL: la
     * atribución de vinculados a su factura, sección "Diferencias
     * presupuesto/contabilidad", es lógica que vive aquí). El volumen es
     * real del negocio, no un error — un año fiscal completo de una sola
     * empresa mediana (Roldanillo) ya son más de 100.000 documentos.
     *
     * Medido contra datos reales de producción, en procesos aislados
     * (memory_get_peak_usage es acumulativo por proceso, no por llamada):
     *   ~8 meses (2026 corrido)     -> 130 MB  (ya por encima del límite
     *                                            por defecto de PHP, 128 MB)
     *   año calendario completo     -> 258 MB
     *   12 meses móviles            -> 200 MB
     *   18 meses                    -> 365 MB
     *
     * El caso de uso real es el año fiscal (~12 meses: la cuenta de
     * ingresos y el presupuesto se reinician cada año), que cabe con
     * bastante margen bajo MEMORY_LIMIT_MB. Los repositorios ya iteran con
     * `while ($stmt->fetch())` en vez de `foreach ($stmt->fetchAll() as …)`
     * en las consultas pesadas (evita mantener a la vez el array bruto de
     * fetchAll() y el reindexado — PHP reutiliza cada fila por
     * copy-on-write, así que el ahorro es solo de la estructura del array
     * temporal, no de los datos: ~8% medido, no el 50% que parecía a
     * primera vista). No hay más margen de optimización sin cambiar el
     * enfoque: las consultas ya seleccionan solo las columnas usadas y ya
     * agregan con GROUP BY en SQL.
     *
     * MEMORY_LIMIT_MB da colchón para el rango permitido; RANGO_MAXIMO_DIAS
     * corta ANTES de ejecutar un rango que igual reventaría, con un mensaje
     * claro en vez de un Fatal error. Si el negocio crece y esto empieza a
     * molestar en rangos normales (~12 meses), la solución de fondo es
     * mover la atribución vinculado→factura a SQL en vez de cruzarla en
     * PHP, no seguir subiendo estos números.
     */
    private const MEMORY_LIMIT_MB = 512;
    private const RANGO_MAXIMO_DIAS = 400;

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

        $dias = (new DateTime($fechaIni))->diff(new DateTime($fechaFin))->days;
        if ($dias > self::RANGO_MAXIMO_DIAS) {
            return [
                'ok' => false,
                'error' => sprintf(
                    'El rango seleccionado es de %d días. Por el volumen de documentos de SIHOS, '
                        . 'este reporte admite hasta %d días (~%d meses) por consulta — más allá de eso '
                        . 'el servidor se queda sin memoria. Divida el rango en partes más pequeñas.',
                    $dias,
                    self::RANGO_MAXIMO_DIAS,
                    (int)round(self::RANGO_MAXIMO_DIAS / 30)
                ),
            ];
        }

        // Colchón local, no global: solo esta petición pesada lo necesita, y
        // solo SUBE el límite — nunca lo baja. Verificado que ini_set()
        // acepta reducir memory_limit en runtime (a diferencia de otras
        // directivas PHP_INI_SYSTEM): forzar 512M a ciegas bajaría el límite
        // en un servidor ya configurado con más.
        $limitePrevio = ini_get('memory_limit');
        $bytesPrevios = $this->aBytes($limitePrevio);
        $bytesDeseados = self::MEMORY_LIMIT_MB * 1024 * 1024;

        if ($bytesPrevios !== -1 && $bytesPrevios < $bytesDeseados) {
            ini_set('memory_limit', self::MEMORY_LIMIT_MB . 'M');
        }

        try {
            return $this->buildReporteInterno($empresaId, $fechaIni, $fechaFin);
        } finally {
            // Solo se restaura si es seguro: bajar el límite por debajo de
            // la memoria ya usada por ESTE reporte no libera nada (los datos
            // siguen vivos hasta que termine la petición) y solo consigue
            // que PHP emita un warning ruidoso — visto en pruebas reales,
            // el reporte deja 130-260 MB en uso, muy por encima de los 128M
            // por defecto. En PHP-FPM cada petición arranca con el
            // memory_limit del pool de todos modos: esta restauración es
            // cortesía para quien reutilice el mismo proceso PHP (CLI,
            // pruebas), no una necesidad de producción.
            if ($bytesPrevios === -1 || memory_get_usage(true) < $bytesPrevios) {
                ini_set('memory_limit', $limitePrevio);
            }
        }
    }

    /** "128M" / "1G" / "-1" → bytes. -1 = sin límite. */
    private function aBytes(string $valor): int
    {
        $valor = trim($valor);
        if ($valor === '-1') {
            return -1;
        }

        $unidad = strtolower(substr($valor, -1));
        $numero = (int)$valor;

        return match ($unidad) {
            'g' => $numero * 1024 * 1024 * 1024,
            'm' => $numero * 1024 * 1024,
            'k' => $numero * 1024,
            default => (int)$valor,
        };
    }

    private function buildReporteInterno(int $empresaId, string $fechaIni, string $fechaFin): array
    {
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
            // Vigencia anterior: CuenGlos = "Aceptación de Glosas Vigencia
            // Anterior" (el nombre de columna no lo dice, confirmado contra
            // el código fuente de SIHOS); CuenDeAn = "Devolución de Facturas
            // Vigencias Anteriores"; CuenCaAn = "Conciliación Vigencias
            // Anteriores". Las 3 son las que debe ofrecer el selector de
            // reclasificación (sección 5b) — CuenGlosAct/CuenCast son de
            // vigencia ACTUAL, no aplican ahí.
            $cuentaAceptacionGlosaAnterior = trim((string)($cuentasInstitucion['CuenGlos'] ?? ''));
            $cuentaDevolucionAnterior = trim((string)($cuentasInstitucion['CuenDeAn'] ?? ''));
            $cuentaConciliacionAnterior = trim((string)($cuentasInstitucion['CuenCaAn'] ?? ''));
            // Familia de cuentas, no el valor exacto: CuenGlosAct es solo UN
            // ejemplo de una familia con una subcuenta por tipo de pagador
            // (ver docblock de fetchCuentasInesperadasNotasVigenciaActual).
            // Las 3 cuentas de vigencia anterior comparten clase 58, por eso
            // un solo prefijo de 2 dígitos basta para excluirlas a las tres.
            $prefijoReversion = $cuentaReversion !== '' ? substr($cuentaReversion, 0, 4) : '';
            $prefijoVigenciaAnterior = $cuentaAceptacionGlosaAnterior !== '' ? substr($cuentaAceptacionGlosaAnterior, 0, 2) : '';
            $cuentasCapitaPasivo = $repository->fetchCuentasCapitaPasivo();

            // Facturas con cuenta fuera de lo esperado que YA tienen un
            // ajuste (nota_ajuste u otra corrección hecha directamente en
            // SIHOS) se excluyen del hallazgo, sin importar la fecha de esa
            // corrección — la pregunta es si ya está resuelto, no si la
            // corrección cae dentro del rango filtrado. La sección 6
            // (Detalle de diferencias a revisar) no se toca: se
            // autorresuelve sola cuando la nota cae en el rango filtrado,
            // porque ya compensa la contabilidad real.
            $cuentasInesperadasFacturas = $repository->fetchCuentasInesperadasFacturas(
                $codigosFactura,
                $fechaIni,
                $fechaFin,
                $cuentasCapitaPasivo
            );
            $clavesConAjuste = $repository->fetchClavesConAjustePrevio($cuentasInesperadasFacturas);
            $cuentasInesperadasFacturas = array_values(array_filter(
                $cuentasInesperadasFacturas,
                static fn (array $f): bool => !isset($clavesConAjuste[$f['CodiDocu'] . '-' . $f['NumeDocu'] . '-' . $f['CodiCont']])
            ));

            // Sección 5b: se une la vigencia actual (como ya existía, sin
            // acción propia) con la vigencia anterior (nueva — notas que
            // tocan 4312 sobre una factura de otro año en vez de la cuenta
            // de vigencia anterior configurada). Cada fila lleva
            // 'EsVigenciaAnterior' para que la vista sepa cuáles llevan
            // botón. Igual que en 5a: las que ya tienen un ajuste (nota
            // nueva de la rama de mes cerrado) se excluyen sin importar la
            // fecha de esa corrección — la rama de mes abierto (edición en
            // sitio) se autorresuelve sola, porque la línea corregida ya no
            // vuelve a calzar con "cuenta fuera de lo esperado".
            $cuentasInesperadasNotasActual = array_map(
                static function (array $f): array {
                    $f['EsVigenciaAnterior'] = false;

                    return $f;
                },
                $repository->fetchCuentasInesperadasNotasVigenciaActual($codigosNota, $fechaIni, $fechaFin, $prefijoReversion, $prefijoVigenciaAnterior)
            );
            // Sin $prefijoReversion aquí: es la familia de CuenGlosAct (vigencia
            // ACTUAL), no tiene por qué excluir nada en la detección de vigencia
            // ANTERIOR — se pasa '' y la consulta simplemente no aplica ese filtro.
            $cuentasInesperadasNotasAnterior = $repository->fetchCuentasInesperadasNotasVigenciaAnterior(
                $codigosNota,
                $fechaIni,
                $fechaFin,
                '',
                $prefijoVigenciaAnterior
            );
            $clavesConAjusteNotas = $repository->fetchClavesConAjustePrevio($cuentasInesperadasNotasAnterior);
            $cuentasInesperadasNotasAnterior = array_values(array_map(
                static function (array $f): array {
                    $f['EsVigenciaAnterior'] = true;

                    return $f;
                },
                array_filter(
                    $cuentasInesperadasNotasAnterior,
                    static fn (array $f): bool => !isset($clavesConAjusteNotas[$f['CodiDocu'] . '-' . $f['NumeDocu'] . '-' . $f['CodiCont']])
                )
            ));
            $cuentasInesperadasNotas = [...$cuentasInesperadasNotasActual, ...$cuentasInesperadasNotasAnterior];

            return [
                'ok' => true,
                'codigosFactura' => $codigosFactura,
                'codigosGlosa' => $codigosGlosa,
                'codigosNota' => $codigosNota,
                'cuentaReversion' => $cuentaReversion,
                'cuentaAceptacionGlosaAnterior' => $cuentaAceptacionGlosaAnterior,
                'cuentaDevolucionAnterior' => $cuentaDevolucionAnterior,
                'cuentaConciliacionAnterior' => $cuentaConciliacionAnterior,
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
                'cuentasInesperadasFacturas' => $cuentasInesperadasFacturas,
                'cuentasInesperadasNotas' => $cuentasInesperadasNotas,
                'cuentasInesperadasGlosas' => $repository->fetchCuentasInesperadasNotasVigenciaActual(
                    $codigosGlosa,
                    $fechaIni,
                    $fechaFin,
                    $prefijoReversion,
                    $prefijoVigenciaAnterior
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
