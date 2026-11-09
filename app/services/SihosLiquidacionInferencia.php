<?php

/**
 * Infiere el profesional real de las líneas de liquidación cargadas a un
 * usuario administrativo (DetaFact.CodiMedi = 'Admin') o sin profesional, a
 * partir de la atención de la misma admisión. NO reemplaza CodiMedi: el
 * resultado se muestra en columnas aparte, con el método y la confianza.
 *
 * Reglas (validadas contra líneas con médico conocido, sep–oct 2026):
 *  - Consulta/Interconsulta: profesional de la consulta (RipsCons) de la
 *    admisión. Mismo día y un solo profesional → Alta (~98 %); sin consulta ese
 *    día pero un solo profesional en la admisión → Media (~97 %).
 *  - Procedimiento: hoja de procedimiento (HojaProc) de la admisión con el
 *    mismo CUPS y fecha, un solo profesional → Media (~87 %).
 *  - Cirugía y demás: sin dato (las cirugías administrativas casi no tienen
 *    admisión). El procedimiento inferido por consulta acierta ~64 % y no se usa.
 */
class SihosLiquidacionInferencia
{
    /** Logins que no son personas atendiendo (comparación sin mayúsculas). */
    public const LOGINS_ADMINISTRATIVOS = ['admin'];

    public const SIN_DATO = 'Sin dato';

    /** @var array<int,array{consultas:list<array<string,mixed>>,hojas:list<array<string,mixed>>}> */
    private array $cache = [];

    public function __construct(private readonly SihosLiquidacionRepository $repo)
    {
    }

    /** Precarga en lote las atenciones de las admisiones administrativas del rango (reemplaza la caché anterior). */
    public function precargar(string $desde, string $hasta): void
    {
        $ids = $this->repo->fetchAdmisionesAdministrativas($desde, $hasta, self::LOGINS_ADMINISTRATIVOS);
        $this->cache = $ids === [] ? [] : $this->repo->fetchAtencionesAdmisiones($ids);
    }

    public static function esAdministrativo(?string $login): bool
    {
        $login = strtolower(trim((string)$login));

        return $login === '' || in_array($login, self::LOGINS_ADMINISTRATIVOS, true);
    }

    /**
     * @param array<string,mixed> $f línea del repository
     * @return array{cedula:string,nombre:string,metodo:string,confianza:string}|null null si la actividad no es médica
     */
    public function inferir(array $f, string $actividad): ?array
    {
        if (!in_array($actividad, ['Consulta', 'Interconsulta', 'Procedimiento', 'Cirugía'], true)) {
            return null;
        }
        $consAdmi = (int)$f['ConsAdmi'];
        if ($consAdmi <= 0) {
            return $this->resultado(null, 'Línea sin admisión', self::SIN_DATO);
        }
        if ($actividad === 'Cirugía') {
            return $this->resultado(null, 'Cirugía: no inferible', self::SIN_DATO);
        }
        $this->cache[$consAdmi] ??= $this->repo->fetchAtencionesAdmisiones([$consAdmi])[$consAdmi];
        $atenciones = $this->cache[$consAdmi];
        $fecha = (string)$f['FechFact'];

        if ($actividad === 'Procedimiento') {
            $hojas = array_filter(
                $atenciones['hojas'],
                static fn (array $h): bool => trim((string)$h['CodiProc']) === trim((string)$f['CodiProcLinea']) && $h['Fecha'] === $fecha
            );
            $unico = $this->unico($hojas);

            return $unico !== null
                ? $this->resultado($unico, 'Hoja de procedimiento (mismo CUPS y fecha)', 'Media')
                : $this->resultado(null, 'Procedimiento sin hoja única', self::SIN_DATO);
        }

        $delDia = $this->unico(array_filter($atenciones['consultas'], static fn (array $c): bool => $c['Fecha'] === $fecha));
        if ($delDia !== null) {
            return $this->resultado($delDia, 'Consulta de la admisión (mismo día)', 'Alta');
        }
        $hayDelDia = array_filter($atenciones['consultas'], static fn (array $c): bool => $c['Fecha'] === $fecha && !self::esAdministrativo($c['Login'])) !== [];
        $unico = $hayDelDia ? null : $this->unico($atenciones['consultas']);

        return $unico !== null
            ? $this->resultado($unico, 'Consulta de la admisión (otra fecha)', 'Media')
            : $this->resultado(null, 'Consulta sin profesional único', self::SIN_DATO);
    }

    /**
     * Profesional si todas las atenciones (sin usuarios administrativos) son de uno solo.
     *
     * @param iterable<array<string,mixed>> $atenciones
     * @return array<string,mixed>|null
     */
    private function unico(iterable $atenciones): ?array
    {
        $porLogin = [];
        foreach ($atenciones as $a) {
            if (!self::esAdministrativo($a['Login'])) {
                $porLogin[strtolower(trim((string)$a['Login']))] = $a;
            }
        }

        return count($porLogin) === 1 ? reset($porLogin) : null;
    }

    /** @param array<string,mixed>|null $prof @return array{cedula:string,nombre:string,metodo:string,confianza:string} */
    private function resultado(?array $prof, string $metodo, string $confianza): array
    {
        return [
            'cedula' => (string)($prof['Cedula'] ?? ''), 'nombre' => (string)($prof['Nombre'] ?? ''),
            'metodo' => $metodo, 'confianza' => $confianza,
        ];
    }
}
