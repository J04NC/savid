<?php

/**
 * Tope de almacenamiento para audios de Acad (TTS de referencia +
 * grabaciones de estudiantes): a diferencia del resto del storage, esta
 * zona crece con cada estudiante/intento, sin límite natural. Si en algún
 * momento se activa ACAD_MEDIA_STORAGE_DRIVER=s3 (R2), pasar el tier
 * gratis (10 GB) empieza a cobrar — se bloquean escrituras nuevas un poco
 * antes (9 GB) para dejar margen, independientemente de si hoy el driver
 * activo es local o s3.
 */
class AcadMediaQuotaService
{
    public const LIMITE_BYTES = 9 * 1024 * 1024 * 1024;

    private AcadRepository $repo;

    public function __construct()
    {
        $this->repo = new AcadRepository();
    }

    public function totalBytesUsed(): int
    {
        return $this->repo->sumAcadMediaBytes();
    }

    public function wouldExceed(int $tamanoNuevoBytes): bool
    {
        return $this->totalBytesUsed() + max(0, $tamanoNuevoBytes) > self::LIMITE_BYTES;
    }
}
