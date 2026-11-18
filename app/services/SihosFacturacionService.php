<?php

/**
 * Reporte SIHOS "Facturación": dos tablas para cruzar contra la DIAN —
 * facturas del rango y notas (notas contables de facturación y glosas
 * aceptadas) que afectan facturas, una fila por nota + factura afectada.
 *
 * Nada de CodiDocu literal: cada institución puede llamarlos distinto, así
 * que se resuelven por DocuApli (28/59 facturas, 127/68 notas) exigiendo
 * ManeCont=1 — descarta, p. ej., RCE "Relación de cobro" (DocuApli 28). La
 * cuenta de cartera (13xx, 14xx...) sale de TipoUsua.CodiCont de la
 * institución. Cada tabla se cachea (SihosAuditoriaGlosaCache) para paginarla
 * del lado del servidor: un año completo son ~90.000 facturas.
 */
class SihosFacturacionService
{
    private const DOCU_APLI_FACTURA = [28, 59];
    private const DOCU_APLI_NOTA = [127, 68];

    private const ESTADOS = ['E' => 'Enviada', 'P' => 'Pendiente', 'R' => 'Rechazada'];

    private SihosEmpresaConfigRepository $configRepository;

    public function __construct()
    {
        $this->configRepository = new SihosEmpresaConfigRepository();
    }

    /** @return array<string, string> CodiTipo => NombTipo, para el filtro del formulario. */
    public function listarTiposUsuario(int $empresaId): array
    {
        $conexion = $this->conectar($empresaId);

        try {
            return isset($conexion['externo']) ? $conexion['externo']->fetchTipoUsuarioNombres() : [];
        } catch (PDOException $e) {
            error_log('SihosFacturacionService: ' . $e->getMessage());

            return [];
        }
    }

    /**
     * @param string[] $tipos
     * @return array{ok:bool,error?:string,facturas?:list<array<string,mixed>>,notas?:list<array<string,mixed>>}
     */
    public function buildReporte(int $empresaId, string $desde, string $hasta, array $tipos, string $nit): array
    {
        $fecha = '/^\d{4}-\d{2}-\d{2}$/';
        if (!preg_match($fecha, $desde) || !preg_match($fecha, $hasta) || $desde > $hasta) {
            return ['ok' => false, 'error' => 'El rango de fechas es inválido.'];
        }

        $conexion = $this->conectar($empresaId);
        if (isset($conexion['error'])) {
            return ['ok' => false, 'error' => $conexion['error']];
        }

        $tipos = array_values(array_filter(array_map('strval', $tipos), static fn (string $t): bool => ctype_digit($t)));
        $nit = preg_replace('/[^0-9A-Za-z]/', '', $nit) ?? '';

        try {
            $externo = $conexion['externo'];
            $codigosFactura = $externo->resolveCodigosDocumentoPorAplicacionContable(self::DOCU_APLI_FACTURA);
            $codigosNota = $externo->resolveCodigosDocumentoPorAplicacionContable(self::DOCU_APLI_NOTA);
            $prefijos = $externo->resolvePrefijosCuentaCarteraPorTipoUsuario();
            if ($prefijos === []) {
                return ['ok' => false, 'error' => 'Los tipos de usuario de SIHOS no tienen cuenta de cartera (CodiCont) configurada.'];
            }

            $repo = new SihosFacturacionRepository($externo->connect(), $conexion['codiInst']);
            $facturas = $repo->fetchFacturas($codigosFactura, $prefijos, $desde, $hasta, $tipos, $nit);
            $notas = $repo->fetchNotas($codigosNota, $codigosFactura, $prefijos, $desde, $hasta, $tipos, $nit);
            $envios = $notas === [] ? [] : $repo->fetchEnviosNotas($codigosNota, (string)((int)substr($desde, 0, 4) - 1));
        } catch (PDOException $e) {
            error_log('SihosFacturacionService: ' . $e->getMessage());

            return ['ok' => false, 'error' => 'No se pudo consultar SIHOS. Verifique la conexión e intente de nuevo.'];
        }

        return [
            'ok' => true,
            'facturas' => array_map(fn (array $f): array => $this->filaFactura($f), $facturas),
            'notas' => array_map(fn (array $n): array => $this->filaNota($n, $envios), $notas),
        ];
    }

    /** @return array<string, mixed> */
    private function filaFactura(array $f): array
    {
        return [
            'Documento' => $f['CodiDocu'] . '-' . $f['NumeDocu'], 'FechDocu' => (string)$f['FechDocu'],
            'FechEnvi' => $this->fechaEnvio($f['FechEnvi']), 'NombTipo' => (string)$f['NombTipo'],
            'NombTerc' => (string)$f['NombTerc'], 'Nit' => $f['TiDoTerc'] . ' ' . $f['NuDoTerc'],
            'ValorCartera' => $f['ValorCartera'], 'ValoTota' => $f['ValoTota'], 'ValoEven' => $f['ValoEven'],
            'Diferencia' => abs($f['ValorCartera'] - $f['ValoTota']) >= 0.01 ? 'Sí' : '',
            'Estado' => $this->estado($f['EstaFactElec']), 'CUFE' => (string)$f['CUFE'],
        ];
    }

    /**
     * @param array<string, array<string, mixed>> $envios
     * @return array<string, mixed>
     */
    private function filaNota(array $n, array $envios): array
    {
        $envio = $envios[$n['CodiDocu'] . '|' . (int)$n['NumeDocu']] ?? null;

        return [
            'Documento' => $n['CodiDocu'] . '-' . $n['NumeDocu'], 'FechDocu' => (string)$n['FechDocu'],
            'Electronico' => $envio === null ? '' : $envio['CodiDocu'] . '-' . $envio['NumeDocu'],
            'ConcDian' => $envio === null ? '' : (string)$envio['ConcDian'],
            'FechEnvi' => $this->fechaEnvio($envio['FechEnvi'] ?? null), 'Estado' => $this->estado($envio['EstaFactElec'] ?? null),
            'Factura' => $n['TiDoRefe'] . '-' . $n['NuDoRefe'], 'FechFactura' => (string)$n['FechFactura'],
            'NombTipo' => (string)$n['NombTipo'], 'NombTerc' => (string)$n['NombTerc'],
            'Nit' => $n['TiDoTerc'] . ' ' . $n['NuDoTerc'], 'ValorFactura' => $n['ValorFactura'],
            'CUFE' => (string)($envio['CUFE'] ?? ''),
        ];
    }

    private function estado(?string $codigo): string
    {
        return $codigo === null || $codigo === '' ? 'Sin envío' : (self::ESTADOS[$codigo] ?? $codigo);
    }

    private function fechaEnvio(?string $fecha): string
    {
        return $fecha === null || str_starts_with($fecha, '0000') ? '' : substr($fecha, 0, 16);
    }

    /** @return array{externo?:SihosExternalRepository,codiInst?:string,error?:string} */
    private function conectar(int $empresaId): array
    {
        $fila = $this->configRepository->findByEmpresaId($empresaId);
        if ($fila === null || $fila['host'] === '' || $fila['base_datos'] === '' || $fila['usuario'] === '') {
            return ['error' => 'Esta empresa no tiene conexión a SIHOS configurada.'];
        }

        $codiInst = trim((string)($fila['codi_inst'] ?? ''));
        if ($codiInst === '') {
            return ['error' => 'Falta configurar el CodiInst de esta empresa en Conexión SIHOS.'];
        }

        return ['codiInst' => $codiInst, 'externo' => new SihosExternalRepository([
            'host' => $fila['host'], 'port' => (string)$fila['puerto'], 'database' => $fila['base_datos'],
            'username' => $fila['usuario'], 'codiInst' => $codiInst,
            'password' => SihosCredentialCipher::decrypt($fila['password_cifrado'] ?? null), 'charset' => $fila['charset'],
        ])];
    }
}
