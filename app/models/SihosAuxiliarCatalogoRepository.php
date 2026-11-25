<?php

/**
 * Nombres que el reporte SIHOS "Auxiliar" muestra junto a los códigos (solo
 * lectura). SIHOS los buscaba con una consulta por cada línea del informe
 * (nombregeneral/HistNomb); aquí se cargan una vez en memoria.
 */
class SihosAuxiliarCatalogoRepository
{
    private const LOTE = 500;

    public function __construct(private readonly PDO $pdo, private readonly string $codiInst)
    {
    }

    /** @return array<string,string> código de cuenta => nombre (ColGAAP: plan del año; NIIF: PartNIIF.CodiPart) */
    public function nombresCuentas(string $contab, int $ano): array
    {
        if ($contab === 'niif') {
            $stmt = $this->pdo->prepare('SELECT CAST(id AS CHAR) AS Cuenta, CodiPart, NombPart AS Nombre FROM PartNIIF WHERE CodiInst = ?');
            $stmt->execute([$this->codiInst]);
        } else {
            $stmt = $this->pdo->prepare('SELECT CodiCont AS Cuenta, NombCuen AS Nombre FROM CodiCont WHERE CodiInst = ? AND Periodo = ?');
            $stmt->execute([$this->codiInst, (string)$ano]);
        }
        $out = [];
        foreach ($stmt as $f) {
            $out[trim((string)$f['Cuenta'])] = (string)$f['Nombre'];
        }

        return $out;
    }

    /** @return array<string,array{nombre:string,docuApli:int}> CodiDocu => nombre y rol (DocuApli) */
    public function documentos(): array
    {
        $stmt = $this->pdo->prepare('SELECT CodiDocu, NombDocu, DocuApli FROM MaesDocu WHERE CodiInst = ?');
        $stmt->execute([$this->codiInst]);
        $out = [];
        foreach ($stmt as $f) {
            $out[trim((string)$f['CodiDocu'])] = ['nombre' => (string)$f['NombDocu'], 'docuApli' => (int)$f['DocuApli']];
        }

        return $out;
    }

    /** @return array<string,string> CodiCent => NombCent */
    public function centrosCosto(): array
    {
        return $this->pares('SELECT CodiCent, NombCent FROM CentCost ORDER BY CodiCent');
    }

    /** @return array<string,string> sede (CodiInst.CodiCent) => ConfSede, como la lista de SIHOS */
    public function sedes(): array
    {
        return $this->pares("SELECT CodiCent, ConfSede FROM CodiInst WHERE CodiCent <> '' ORDER BY CodiCent");
    }

    /** @return array<string,string> CodiRecu => NombRecu */
    public function recursos(): array
    {
        return $this->pares('SELECT CodiRecu, NombRecu FROM Recursos ORDER BY CodiRecu');
    }

    /**
     * Nombre actual de cada tercero (CodiTerc) y su historial de nombres (HistNoTe).
     *
     * @param list<string> $numeros NuDoTerc
     * @return array{actual:array<string,string>,historial:array<string,list<array{0:string,1:string}>>} clave "TiDo\tNuDo"; historial ordenado por FechCamb
     */
    public function terceros(array $numeros): array
    {
        $out = ['actual' => [], 'historial' => []];
        foreach (array_chunk(array_values(array_unique($numeros)), self::LOTE) as $lote) {
            $in = implode(',', array_fill(0, count($lote), '?'));
            $stmt = $this->pdo->prepare("SELECT TipoDocu, NumeTerc, NombTerc FROM CodiTerc WHERE NumeTerc IN ($in)");
            $stmt->execute($lote);
            foreach ($stmt as $f) {
                $out['actual'][trim((string)$f['TipoDocu']) . "\t" . trim((string)$f['NumeTerc'])] = (string)$f['NombTerc'];
            }
            $stmt = $this->pdo->prepare("SELECT TiDoTerc, NuDoTerc, FechCamb, NombTerc FROM HistNoTe WHERE NuDoTerc IN ($in) ORDER BY FechCamb");
            $stmt->execute($lote);
            foreach ($stmt as $f) {
                $out['historial'][trim((string)$f['TiDoTerc']) . "\t" . trim((string)$f['NuDoTerc'])][] = [(string)$f['FechCamb'], (string)$f['NombTerc']];
            }
        }

        return $out;
    }

    /** @return array<string,string> "TipoDocu\tNumePers" => nombre completo del empleado (para documentos de nómina) */
    public function empleados(): array
    {
        $out = [];
        foreach ($this->pdo->query('SELECT TipoDocu, NumePers, Nom1Pers, Nom2Pers, Ape1Pers, Ape2Pers FROM Empleado') as $f) {
            $nombre = preg_replace('/\s+/', ' ', trim($f['Nom1Pers'] . ' ' . $f['Nom2Pers'] . ' ' . $f['Ape1Pers'] . ' ' . $f['Ape2Pers'])) ?? '';
            $out[trim((string)$f['TipoDocu']) . "\t" . trim((string)$f['NumePers'])] = $nombre;
        }

        return $out;
    }

    /** @return array<string,string> */
    private function pares(string $sql): array
    {
        $out = [];
        foreach ($this->pdo->query($sql) as $f) {
            $f = array_values($f);
            $out[trim((string)$f[0])] = trim((string)$f[1]);
        }

        return $out;
    }
}
