<?php

/**
 * Agregación de progreso: vista docente (todos los estudiantes de la
 * empresa) y vista estudiante (su propio progreso por destreza).
 */
class AcadProgressService
{
    private AcadRepository $repo;
    private AcadScopeService $scope;

    public function __construct()
    {
        $this->repo = new AcadRepository();
        $this->scope = new AcadScopeService();
    }

    /**
     * @return array<string, mixed>
     */
    public function teacherProgress(array $query): array
    {
        try {
            $empresaId = $this->scope->requireEmpresaId($query);
        } catch (RuntimeException $e) {
            return ['success' => false, 'message' => $e->getMessage()];
        }

        $rows = $this->repo->progressByStudent($empresaId);
        $students = array_map(static function (array $row): array {
            $intentos = (int)$row['intentos'];
            $calificados = (int)$row['calificados'];

            return [
                'usuario_id' => (int)$row['usuario_id'],
                'username' => (string)$row['username'],
                'level_codigo' => $row['level_codigo'],
                'intentos' => $intentos,
                'calificados' => $calificados,
                'pendientes' => (int)$row['pendientes'],
                'promedio_score' => $row['promedio_score'] !== null ? round((float)$row['promedio_score'], 1) : null,
            ];
        }, $rows);

        return ['success' => true, 'empresaId' => $empresaId, 'students' => $students];
    }

    /**
     * @return array<string, mixed>
     */
    public function studentProgress(array $query, int $usuarioId): array
    {
        try {
            $empresaId = $this->scope->requireEmpresaId($query);
        } catch (RuntimeException $e) {
            return ['success' => false, 'message' => $e->getMessage()];
        }

        $enrolment = $this->repo->findEnrolment($empresaId, $usuarioId);
        $bySkill = array_map(static function (array $row): array {
            return [
                'skill_codigo' => (string)$row['skill_codigo'],
                'intentos' => (int)$row['intentos'],
                'calificados' => (int)$row['calificados'],
                'promedio_score' => $row['promedio_score'] !== null ? round((float)$row['promedio_score'], 1) : null,
            ];
        }, $this->repo->progressByStudentSkill($empresaId, $usuarioId));

        return [
            'success' => true,
            'empresaId' => $empresaId,
            'enrolment' => $enrolment,
            'bySkill' => $bySkill,
        ];
    }
}
