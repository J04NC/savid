#!/usr/bin/env php
<?php
/**
 * Matriz de pruebas guardado usuario (spec VALIDACIONES_CRUD_USUARIO §10).
 * Uso: php scripts/test_usuario_save_matrix.php
 */
define('BASE_PATH', dirname(__DIR__));

spl_autoload_register(function ($class) {
    foreach ([
        BASE_PATH . '/core/',
        BASE_PATH . '/app/controllers/',
        BASE_PATH . '/app/models/',
        BASE_PATH . '/app/services/',
        BASE_PATH . '/app/helpers/',
        BASE_PATH . '/core/middleware/',
    ] as $path) {
        $file = $path . $class . '.php';
        if (file_exists($file)) {
            require_once $file;

            return;
        }
    }
});

require BASE_PATH . '/config/Database.php';

$_SESSION['user_id'] = 1;
$_SESSION['es_super_admin'] = true;

$pdo = (new Database())->connect();
$svc = new CrudService();

function basePost(): array
{
    return [
        'username' => 'test_matrix_' . bin2hex(random_bytes(3)),
        'password' => 'Test2026!',
        'password_confirm' => 'Test2026!',
        'estado_id' => '1',
        'tipodocumento_id' => '4',
        'numero_documento' => (string) random_int(1000000000, 1999999999),
        'nombres' => 'MATRIX',
        'apellidos' => 'USER',
        'email' => 'matrix' . random_int(1, 99999) . '@test.local',
        'sesion_idle_minutos' => '10',
        'tercero_id' => '',
        'terceroidentificacion_id' => '',
    ];
}

$passed = 0;
$failed = 0;

function runCase(string $label, callable $fn): void
{
    global $passed, $failed;
    try {
        $fn();
        echo "[OK] $label\n";
        $passed++;
    } catch (Throwable $e) {
        echo "[FAIL] $label\n       " . $e->getMessage() . "\n";
        $failed++;
    }
}

runCase('T1 alta limpia', function () use ($svc) {
    $post = basePost();
    if (!$svc->save('usuario', $post)) {
        throw new RuntimeException('save returned false');
    }
});

runCase('T2 alta con id huérfano', function () use ($svc) {
    $post = basePost();
    $post['id'] = '999999';
    if (!$svc->save('usuario', $post)) {
        throw new RuntimeException('save returned false');
    }
});

runCase('T3 alta con tercero_id huérfano', function () use ($svc) {
    $post = basePost();
    $post['tercero_id'] = '999999';
    $post['terceroidentificacion_id'] = '999999';
    if (!$svc->save('usuario', $post)) {
        throw new RuntimeException('save returned false');
    }
});

runCase('T4 edición solo contraseña', function () use ($svc, $pdo) {
    $post = basePost();
    $svc->save('usuario', $post);
    $row = $pdo->query(
        'SELECT id, username FROM usuario WHERE username = ' . $pdo->quote($post['username'])
    )->fetch(PDO::FETCH_ASSOC);
    if (!$row) {
        throw new RuntimeException('usuario no creado');
    }
    $edit = $post;
    $edit['id'] = (string) $row['id'];
    $edit['password'] = 'NewPass2026!';
    $edit['password_confirm'] = 'NewPass2026!';
    $edit['tercero_id'] = '';
    $edit['terceroidentificacion_id'] = '';
    if (!$svc->save('usuario', $edit)) {
        throw new RuntimeException('update failed');
    }
    $pdo->exec('DELETE FROM usuario WHERE id = ' . (int) $row['id']);
});

runCase('T5 documento existente sin usuario nuevo username', function () use ($svc, $pdo) {
    $post1 = basePost();
    $svc->save('usuario', $post1);
    $doc = $post1['numero_documento'];
    $pdo->exec('DELETE FROM usuario WHERE username = ' . $pdo->quote($post1['username']));
    $post2 = basePost();
    $post2['numero_documento'] = $doc;
    $post2['tipodocumento_id'] = '4';
    $post2['email'] = $post1['email'];
    $post2['usuario_email_overwrite_ok'] = '1';
    if (!$svc->save('usuario', $post2)) {
        throw new RuntimeException('save returned false');
    }
});

runCase('T7 tercero soft-deleted reactivado', function () use ($svc, $pdo) {
    $post1 = basePost();
    $svc->save('usuario', $post1);
    $u = $pdo->query('SELECT id, terceroidentificacion_id FROM usuario WHERE username = ' . $pdo->quote($post1['username']))->fetch(PDO::FETCH_ASSOC);
    $tid = (int) $pdo->query('SELECT tercero_id FROM terceroidentificacion WHERE id = ' . (int) $u['terceroidentificacion_id'])->fetchColumn();
    $pdo->exec('DELETE FROM usuario WHERE id = ' . (int) $u['id']);
    $pdo->exec('UPDATE tercero SET deleted_at = NOW(3) WHERE id = ' . $tid);
    $post2 = basePost();
    $post2['numero_documento'] = $post1['numero_documento'];
    $post2['tipodocumento_id'] = '4';
    $post2['nombres'] = $post1['nombres'];
    $post2['apellidos'] = $post1['apellidos'];
    $post2['email'] = $post1['email'];
    $post2['usuario_email_overwrite_ok'] = '1';
    if (!$svc->save('usuario', $post2)) {
        throw new RuntimeException('save returned false');
    }
});

echo "\nResumen: $passed OK, $failed FAIL\n";
exit($failed > 0 ? 1 : 0);
