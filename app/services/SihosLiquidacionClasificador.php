<?php

/**
 * Reglas de clasificación del reporte SIHOS "Liquidación" (puras, sin BD).
 *
 * La actividad sale del catálogo TipoServ de SIHOS (TipoConc = concepto RIPS:
 * 01 consulta, 02 apoyo diagnóstico, 03 terapéutico, 04 quirúrgico, 05 PyP,
 * 06 estancia, 08 sala, 14 transporte) y NO de códigos fijos de TipoServ, porque
 * entre instalaciones SIHOS los códigos altos (48+) significan cosas distintas.
 * Lo único fijo son los componentes de cirugía 20–25, estándar de SIHOS.
 */
class SihosLiquidacionClasificador
{
    /** DetaFact.TipoServ de una cirugía desglosada por componente. */
    private const COMPONENTES_CIRUGIA = [
        20 => 'Honorarios del cirujano',
        21 => 'Honorarios de anestesia',
        22 => 'Honorarios de ayudantía',
        23 => 'Honorarios de perfusionista',
        25 => 'Materiales de cirugía',
    ];

    public const COMPONENTE_PRINCIPAL = 'Principal';
    public const COMPONENTE_SALA = 'Derechos de sala';
    public const COMPONENTES_INSTITUCIONALES = [self::COMPONENTE_SALA, 'Materiales de cirugía'];

    /** Todas las actividades que puede devolver actividad(), en el orden de las columnas del CSV de especialistas. */
    public const ACTIVIDADES = [
        'Consulta', 'Interconsulta', 'Procedimiento', 'Cirugía', 'Laboratorio', 'Imágenes',
        'Apoyo diagnóstico', 'Estancia', 'Transporte', 'Derechos de sala', 'Otros',
    ];

    /** Actividades cuya producción se atribuye al profesional (CodiMedi). */
    private const ACTIVIDADES_MEDICAS = ['Consulta', 'Interconsulta', 'Procedimiento', 'Cirugía'];

    /**
     * @param array<int, array{NombTipo:string,TipoConc:string,TipoHoja:string,EsCirugi:int}> $tipos catálogo TipoServ
     */
    public function __construct(private readonly array $tipos)
    {
    }

    /** Rol de la línea dentro de la prestación: la prestación misma o un componente de cirugía. */
    public function componente(int $tipoServLinea): string
    {
        if (($this->tipos[$tipoServLinea]['EsCirugi'] ?? 0) === 1) {
            return self::COMPONENTE_SALA;
        }

        return self::COMPONENTES_CIRUGIA[$tipoServLinea] ?? self::COMPONENTE_PRINCIPAL;
    }

    /**
     * Actividad según el tipo de servicio del CUPS (el de la línea si el CUPS
     * no está en CodiProc) — así los componentes de una cirugía quedan como "Cirugía".
     */
    public function actividad(?int $tipoServCups, int $tipoServLinea, int $tipoDeta): string
    {
        if ($tipoDeta === 3) {
            return 'Cirugía';
        }

        $info = $this->tipos[$tipoServCups ?? $tipoServLinea] ?? null;
        if ($info === null) {
            return 'Otros';
        }

        $nombre = mb_strtolower($info['NombTipo']);

        return match ($info['TipoConc']) {
            '01' => str_contains($nombre, 'interconsulta') ? 'Interconsulta' : 'Consulta',
            '02' => match (true) {
                str_contains($nombre, 'imagen') => 'Imágenes',
                str_contains($nombre, 'laboratorio') || str_contains($nombre, 'patolog') || str_contains($nombre, 'citolog') => 'Laboratorio',
                default => 'Apoyo diagnóstico',
            },
            '03', '05' => 'Procedimiento',
            '04' => 'Cirugía',
            '06' => 'Estancia',
            '08' => self::COMPONENTE_SALA,
            '14' => 'Transporte',
            default => 'Otros',
        };
    }

    /** "Sí" si es trabajo del profesional en una actividad médica (no sala/materiales ni apoyo, estancia, etc.). */
    public function produccionMedica(string $actividad, string $componente): string
    {
        return in_array($actividad, self::ACTIVIDADES_MEDICAS, true) && !in_array($componente, self::COMPONENTES_INSTITUCIONALES, true)
            ? 'Sí'
            : 'No';
    }

    public function nombreTipoServicio(?int $tipo): string
    {
        return $tipo === null ? '' : ($this->tipos[$tipo]['NombTipo'] ?? (string)$tipo);
    }
}
