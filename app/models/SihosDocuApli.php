<?php

/**
 * Roles de documento de SIHOS por MaesDocu.DocuApli. Los códigos de documento (CodiDocu) y sus nombres
 * cambian de una instalación a otra; DocuApli es el rol fijo. Nunca se filtra por CodiDocu literal.
 */
final class SihosDocuApli
{
    /** Facturas de venta. */
    public const FACTURA = [28, 59];
    /** Notas contables y de cartera, que pueden anular una factura. */
    public const NOTA = [3, 127];

    /**
     * Códigos de documento de la institución para los roles dados.
     *
     * @param list<int> $docuAplis
     * @return list<string>
     */
    public static function codigos(PDO $pdo, string $codiInst, array $docuAplis): array
    {
        $ph = implode(',', array_fill(0, count($docuAplis), '?'));
        $stmt = $pdo->prepare("SELECT DISTINCT CodiDocu FROM MaesDocu WHERE CodiInst = ? AND DocuApli IN ($ph)");
        $stmt->execute([$codiInst, ...$docuAplis]);

        return array_map('strval', $stmt->fetchAll(PDO::FETCH_COLUMN));
    }
}
