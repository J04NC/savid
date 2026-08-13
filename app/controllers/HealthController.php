<?php

/**
 * Endpoint para balanceadores y monitoreo (?url=health o ?url=health/index).
 * No requiere sesión.
 */
class HealthController
{
    public function index(): void
    {
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store');

        $result = (new SystemHealthService())->runChecks();

        http_response_code($result['status'] === 'ok' ? 200 : 503);
        echo json_encode($result, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    }
}
