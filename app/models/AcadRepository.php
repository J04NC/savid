<?php

class AcadRepository
{
    /** Estados académicos (tabla estado, reltipo ACAD_MATRICULA / ACAD_INTENTO). */
    public const ESTADO_MATRICULADO = 11;
    public const ESTADO_RETIRADO = 12;
    public const ESTADO_GRADUADO = 13;
    public const ESTADO_PENDIENTE = 14;
    public const ESTADO_CALIFICADO = 15;
    public const ESTADO_AUTO_CALIFICADO = 16;

    private PDO $pdo;

    public function __construct(?PDO $pdo = null)
    {
        if ($pdo === null) {
            $pdo = (new Database())->connect();
        }
        $this->pdo = $pdo;
    }

    /* ------------------------------------------------------------------ */
    /* Currículo                                                          */
    /* ------------------------------------------------------------------ */

    /** @return list<array<string, mixed>> */
    public function getLevels(int $empresaId): array
    {
        $nd = SoftDeleteService::sqlAndNotDeleted($this->pdo, 'acad_level');
        $stmt = $this->pdo->prepare("
            SELECT * FROM acad_level
            WHERE empresa_id = ? AND estado_id = 1 {$nd}
            ORDER BY orden, codigo
        ");
        $stmt->execute([$empresaId]);

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function findLevel(int $empresaId, int $levelId): ?array
    {
        $nd = SoftDeleteService::sqlAndNotDeleted($this->pdo, 'acad_level');
        $stmt = $this->pdo->prepare("
            SELECT * FROM acad_level WHERE empresa_id = ? AND id = ? {$nd} LIMIT 1
        ");
        $stmt->execute([$empresaId, $levelId]);

        return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    /** @return list<array<string, mixed>> */
    public function getModulesByLevel(int $empresaId, int $levelId): array
    {
        $nd = SoftDeleteService::sqlAndNotDeleted($this->pdo, 'acad_module');
        $stmt = $this->pdo->prepare("
            SELECT * FROM acad_module
            WHERE empresa_id = ? AND level_id = ? AND estado_id = 1 {$nd}
            ORDER BY orden, id
        ");
        $stmt->execute([$empresaId, $levelId]);

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function findModule(int $empresaId, int $moduleId): ?array
    {
        $nd = SoftDeleteService::sqlAndNotDeleted($this->pdo, 'acad_module');
        $stmt = $this->pdo->prepare("
            SELECT * FROM acad_module WHERE empresa_id = ? AND id = ? {$nd} LIMIT 1
        ");
        $stmt->execute([$empresaId, $moduleId]);

        return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    /** @return list<array<string, mixed>> */
    public function getUnitsByModule(int $empresaId, int $moduleId): array
    {
        $nd = SoftDeleteService::sqlAndNotDeleted($this->pdo, 'acad_unit');
        $stmt = $this->pdo->prepare("
            SELECT * FROM acad_unit
            WHERE empresa_id = ? AND module_id = ? AND estado_id = 1 {$nd}
            ORDER BY orden, id
        ");
        $stmt->execute([$empresaId, $moduleId]);

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function findUnit(int $empresaId, int $unitId): ?array
    {
        $nd = SoftDeleteService::sqlAndNotDeleted($this->pdo, 'acad_unit');
        $stmt = $this->pdo->prepare("
            SELECT * FROM acad_unit WHERE empresa_id = ? AND id = ? {$nd} LIMIT 1
        ");
        $stmt->execute([$empresaId, $unitId]);

        return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    /** @return list<array<string, mixed>> */
    public function getLessonsByUnit(int $empresaId, int $unitId): array
    {
        $nd = SoftDeleteService::sqlAndNotDeleted($this->pdo, 'acad_lesson');
        $stmt = $this->pdo->prepare("
            SELECT * FROM acad_lesson
            WHERE empresa_id = ? AND unit_id = ? AND estado_id = 1 {$nd}
            ORDER BY orden, id
        ");
        $stmt->execute([$empresaId, $unitId]);

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function findLesson(int $empresaId, int $lessonId): ?array
    {
        $nd = SoftDeleteService::sqlAndNotDeleted($this->pdo, 'acad_lesson');
        $stmt = $this->pdo->prepare("
            SELECT * FROM acad_lesson WHERE empresa_id = ? AND id = ? {$nd} LIMIT 1
        ");
        $stmt->execute([$empresaId, $lessonId]);

        return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    /** @return list<array<string, mixed>> */
    public function getExercisesByLesson(int $empresaId, int $lessonId): array
    {
        $nd = SoftDeleteService::sqlAndNotDeleted($this->pdo, 'acad_exercise', 'e');
        $stmt = $this->pdo->prepare("
            SELECT e.*, s.codigo AS skill_codigo, t.codigo AS exercise_type_codigo
            FROM acad_exercise e
            INNER JOIN acad_skill s ON s.id = e.skill_id
            INNER JOIN acad_exercise_type t ON t.id = e.exercise_type_id
            WHERE e.empresa_id = ? AND e.lesson_id = ? AND e.estado_id = 1 {$nd}
            ORDER BY e.orden, e.id
        ");
        $stmt->execute([$empresaId, $lessonId]);

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function findExercise(int $empresaId, int $exerciseId): ?array
    {
        $stmt = $this->pdo->prepare("
            SELECT e.*, s.codigo AS skill_codigo, t.codigo AS exercise_type_codigo,
                   l.titulo_en AS lesson_titulo_en, l.unit_id AS unit_id,
                   u.titulo_en AS unit_titulo_en, u.module_id AS module_id,
                   mo.level_id AS level_id
            FROM acad_exercise e
            INNER JOIN acad_skill s ON s.id = e.skill_id
            INNER JOIN acad_exercise_type t ON t.id = e.exercise_type_id
            INNER JOIN acad_lesson l ON l.id = e.lesson_id
            INNER JOIN acad_unit u ON u.id = l.unit_id
            INNER JOIN acad_module mo ON mo.id = u.module_id
            WHERE e.empresa_id = ? AND e.id = ?
            LIMIT 1
        ");
        $stmt->execute([$empresaId, $exerciseId]);

        return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    /* ------------------------------------------------------------------ */
    /* Opciones de opción múltiple                                        */
    /* ------------------------------------------------------------------ */

    /** @return list<array<string, mixed>> */
    public function getOptionsByExercise(int $exerciseId): array
    {
        $stmt = $this->pdo->prepare('
            SELECT * FROM acad_exercise_option
            WHERE exercise_id = ? AND deleted_at IS NULL
            ORDER BY orden, id
        ');
        $stmt->execute([$exerciseId]);

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function findOption(int $optionId): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM acad_exercise_option WHERE id = ? AND deleted_at IS NULL LIMIT 1');
        $stmt->execute([$optionId]);

        return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    public function deleteOptionsByExercise(int $exerciseId): void
    {
        $stmt = $this->pdo->prepare('DELETE FROM acad_exercise_option WHERE exercise_id = ?');
        $stmt->execute([$exerciseId]);
    }

    public function insertOption(int $exerciseId, string $textoEn, bool $esCorrecta, int $orden): int
    {
        $stmt = $this->pdo->prepare('
            INSERT INTO acad_exercise_option (exercise_id, texto_en, es_correcta, orden, created_at)
            VALUES (?, ?, ?, ?, NOW(3))
        ');
        $stmt->execute([$exerciseId, $textoEn, $esCorrecta ? 1 : 0, $orden]);

        return (int)$this->pdo->lastInsertId();
    }

    public function setExerciseAudioReferencia(int $empresaId, int $exerciseId, string $path): void
    {
        $stmt = $this->pdo->prepare('
            UPDATE acad_exercise SET audio_referencia_ruta = ?
            WHERE empresa_id = ? AND id = ?
        ');
        $stmt->execute([$path, $empresaId, $exerciseId]);
    }

    /* ------------------------------------------------------------------ */
    /* Matrícula (nivel actual del estudiante)                             */
    /* ------------------------------------------------------------------ */

    public function findEnrolment(int $empresaId, int $usuarioId): ?array
    {
        $stmt = $this->pdo->prepare('
            SELECT en.*, lv.codigo AS level_codigo, lv.nombre_en AS level_nombre_en
            FROM acad_enrolment en
            INNER JOIN acad_level lv ON lv.id = en.level_id
            WHERE en.empresa_id = ? AND en.usuario_id = ? AND en.deleted_at IS NULL
            LIMIT 1
        ');
        $stmt->execute([$empresaId, $usuarioId]);

        return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    public function upsertEnrolment(int $empresaId, int $usuarioId, int $levelId, ?int $sedeId = null): void
    {
        $stmt = $this->pdo->prepare('
            INSERT INTO acad_enrolment (empresa_id, sede_id, usuario_id, level_id, estado_id, created_at)
            VALUES (?, ?, ?, ?, ?, NOW(3))
            ON DUPLICATE KEY UPDATE level_id = VALUES(level_id), sede_id = VALUES(sede_id), estado_id = VALUES(estado_id)
        ');
        $stmt->execute([$empresaId, $sedeId, $usuarioId, $levelId, self::ESTADO_MATRICULADO]);
    }

    /* ------------------------------------------------------------------ */
    /* Intentos                                                           */
    /* ------------------------------------------------------------------ */

    public function findLatestAttempt(int $empresaId, int $exerciseId, int $usuarioId): ?array
    {
        $stmt = $this->pdo->prepare('
            SELECT * FROM acad_attempt
            WHERE empresa_id = ? AND exercise_id = ? AND usuario_id = ? AND deleted_at IS NULL
            ORDER BY id DESC LIMIT 1
        ');
        $stmt->execute([$empresaId, $exerciseId, $usuarioId]);

        return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    public function findAttempt(int $empresaId, int $attemptId): ?array
    {
        $stmt = $this->pdo->prepare('
            SELECT * FROM acad_attempt WHERE empresa_id = ? AND id = ? AND deleted_at IS NULL LIMIT 1
        ');
        $stmt->execute([$empresaId, $attemptId]);

        return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    /**
     * @param array<string, mixed> $data
     */
    public function insertAttempt(array $data): int
    {
        $stmt = $this->pdo->prepare('
            INSERT INTO acad_attempt (
                empresa_id, exercise_id, usuario_id, iniciado_at, enviado_at,
                respuesta_texto, selected_option_id, audio_ruta,
                score, feedback_en, calificado_by, calificado_at, estado_id, created_at
            ) VALUES (
                ?, ?, ?, ?, ?,
                ?, ?, ?,
                ?, ?, ?, ?, ?, NOW(3)
            )
        ');
        $stmt->execute([
            $data['empresa_id'],
            $data['exercise_id'],
            $data['usuario_id'],
            $data['iniciado_at'] ?? null,
            $data['enviado_at'] ?? null,
            $data['respuesta_texto'] ?? null,
            $data['selected_option_id'] ?? null,
            $data['audio_ruta'] ?? null,
            $data['score'] ?? null,
            $data['feedback_en'] ?? null,
            $data['calificado_by'] ?? null,
            $data['calificado_at'] ?? null,
            $data['estado_id'],
        ]);

        return (int)$this->pdo->lastInsertId();
    }

    public function gradeAttempt(int $attemptId, float $score, ?string $feedbackEn, int $calificadoBy): void
    {
        $stmt = $this->pdo->prepare('
            UPDATE acad_attempt
            SET score = ?, feedback_en = ?, calificado_by = ?, calificado_at = NOW(3), estado_id = ?
            WHERE id = ?
        ');
        $stmt->execute([$score, $feedbackEn, $calificadoBy, self::ESTADO_CALIFICADO, $attemptId]);
    }

    /** @return list<array<string, mixed>> */
    public function findPendingAttempts(int $empresaId): array
    {
        $stmt = $this->pdo->prepare('
            SELECT a.*, e.titulo_en AS exercise_titulo_en, e.skill_id, s.codigo AS skill_codigo,
                   e.max_score, u.username AS estudiante_username
            FROM acad_attempt a
            INNER JOIN acad_exercise e ON e.id = a.exercise_id
            INNER JOIN acad_skill s ON s.id = e.skill_id
            INNER JOIN usuario u ON u.id = a.usuario_id
            WHERE a.empresa_id = ? AND a.estado_id = ? AND a.deleted_at IS NULL
            ORDER BY a.enviado_at ASC, a.id ASC
        ');
        $stmt->execute([$empresaId, self::ESTADO_PENDIENTE]);

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /** @return list<array<string, mixed>> */
    public function findAttemptsByUser(int $empresaId, int $usuarioId): array
    {
        $stmt = $this->pdo->prepare('
            SELECT a.*, e.titulo_en AS exercise_titulo_en, e.skill_id, s.codigo AS skill_codigo, e.max_score
            FROM acad_attempt a
            INNER JOIN acad_exercise e ON e.id = a.exercise_id
            INNER JOIN acad_skill s ON s.id = e.skill_id
            WHERE a.empresa_id = ? AND a.usuario_id = ? AND a.deleted_at IS NULL
            ORDER BY a.id DESC
        ');
        $stmt->execute([$empresaId, $usuarioId]);

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /* ------------------------------------------------------------------ */
    /* Progreso agregado                                                   */
    /* ------------------------------------------------------------------ */

    /** @return list<array<string, mixed>> */
    public function progressByStudent(int $empresaId): array
    {
        $stmt = $this->pdo->prepare('
            SELECT
                u.id AS usuario_id, u.username,
                en.level_id, lv.codigo AS level_codigo,
                COUNT(DISTINCT a.id) AS intentos,
                SUM(CASE WHEN a.estado_id IN (?, ?) THEN 1 ELSE 0 END) AS calificados,
                SUM(CASE WHEN a.estado_id = ? THEN 1 ELSE 0 END) AS pendientes,
                AVG(CASE WHEN a.estado_id IN (?, ?) THEN a.score ELSE NULL END) AS promedio_score
            FROM acad_enrolment en
            INNER JOIN usuario u ON u.id = en.usuario_id
            INNER JOIN acad_level lv ON lv.id = en.level_id
            LEFT JOIN acad_attempt a ON a.usuario_id = en.usuario_id AND a.empresa_id = en.empresa_id AND a.deleted_at IS NULL
            WHERE en.empresa_id = ? AND en.deleted_at IS NULL
            GROUP BY u.id, u.username, en.level_id, lv.codigo
            ORDER BY u.username
        ');
        $stmt->execute([
            self::ESTADO_CALIFICADO, self::ESTADO_AUTO_CALIFICADO,
            self::ESTADO_PENDIENTE,
            self::ESTADO_CALIFICADO, self::ESTADO_AUTO_CALIFICADO,
            $empresaId,
        ]);

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /** @return list<array<string, mixed>> */
    public function progressByStudentSkill(int $empresaId, int $usuarioId): array
    {
        $stmt = $this->pdo->prepare('
            SELECT
                s.codigo AS skill_codigo,
                COUNT(DISTINCT a.id) AS intentos,
                SUM(CASE WHEN a.estado_id IN (?, ?) THEN 1 ELSE 0 END) AS calificados,
                AVG(CASE WHEN a.estado_id IN (?, ?) THEN a.score ELSE NULL END) AS promedio_score
            FROM acad_attempt a
            INNER JOIN acad_exercise e ON e.id = a.exercise_id
            INNER JOIN acad_skill s ON s.id = e.skill_id
            WHERE a.empresa_id = ? AND a.usuario_id = ? AND a.deleted_at IS NULL
            GROUP BY s.codigo
            ORDER BY s.codigo
        ');
        $stmt->execute([
            self::ESTADO_CALIFICADO, self::ESTADO_AUTO_CALIFICADO,
            self::ESTADO_CALIFICADO, self::ESTADO_AUTO_CALIFICADO,
            $empresaId, $usuarioId,
        ]);

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
}
