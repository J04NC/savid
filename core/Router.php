<?php
/**
 * Router Principal con Middleware de Seguridad
 */
class Router
{
    public function run()
    {
        $url = $_GET['url'] ?? 'login';
        $url = trim($url, '/');
        $parts = explode('/', $url);

        $controllerName = ucfirst($parts[0]) . 'Controller';
        $method = $parts[1] ?? 'index';
        $params = array_slice($parts, 2);

        // 🔥 MIDDLEWARE DE SEGURIDAD
        $this->middleware($controllerName, $method);

        if (SessionManager::userLogged()
            && ContextGateService::mustRedirectToContextSelection($controllerName, $method)) {
            // Un fetch() sigue la redirección y recibe HTML donde esperaba JSON:
            // queda constancia para poder distinguirlo de otros fallos.
            if ($_SERVER['REQUEST_METHOD'] === 'POST') {
                @file_put_contents(
                    BASE_PATH . '/storage/debug_permisos_http.log',
                    sprintf(
                        "[%s] REDIRIGIDO a contexto | url=%s | %s::%s\n",
                        date('Y-m-d H:i:s'),
                        (string)($_GET['url'] ?? ''),
                        $controllerName,
                        $method
                    ),
                    FILE_APPEND
                );
            }

            header('Location: ?url=context/cambiarSede');
            exit;
        }

        $controllerFile = BASE_PATH . '/app/controllers/' . $controllerName . '.php';

        if (!file_exists($controllerFile)) {
            $controllerName = 'ModuleController';
            $controllerFile = BASE_PATH . '/app/controllers/ModuleController.php';
        }

        require_once $controllerFile;
        $controller = new $controllerName();

        if (!method_exists($controller, $method)) {
            http_response_code(404);
            die("Método no encontrado: $controllerName::$method()");
        }

        call_user_func_array([$controller, $method], $params);
    }

    /**
     * Deja rastro de por qué falló el CSRF: distingue "no había sesión" de
     * "el token del navegador no coincide con el de la sesión" (página servida
     * desde caché con un token viejo). Solo huellas cortas, nunca el token.
     */
    private static function logFalloCsrf(): void
    {
        $enviado = $_POST['csrf_token'] ?? ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? null);
        $enSesion = $_SESSION['csrf_token'] ?? null;

        $huella = static function ($v): string {
            return is_string($v) && $v !== '' ? substr(hash('sha256', $v), 0, 8) : 'ninguno';
        };

        $linea = sprintf(
            "[%s] url=%s sesion_id=%s hay_sesion=%s token_sesion=%s token_enviado=%s via=%s login=%s\n",
            date('Y-m-d H:i:s'),
            (string)($_GET['url'] ?? ''),
            $huella(session_id()),
            $_SESSION === [] ? 'no(vacia)' : 'si',
            $huella($enSesion),
            $huella($enviado),
            isset($_POST['csrf_token']) ? 'form' : (isset($_SERVER['HTTP_X_CSRF_TOKEN']) ? 'header' : 'nada'),
            isset($_SESSION['user_id']) ? 'si' : 'no'
        );

        @file_put_contents(BASE_PATH . '/storage/debug_csrf.log', $linea, FILE_APPEND);
    }

    /**
     * ¿El cliente espera JSON? (fetch/XHR de la app: cuerpo JSON, Accept JSON
     * o X-Requested-With). Se usa para no responderle HTML/texto plano.
     */
    private static function requestWantsJson(): bool
    {
        $contentType = strtolower((string)($_SERVER['CONTENT_TYPE'] ?? ''));
        if (strpos($contentType, 'application/json') !== false) {
            return true;
        }

        $accept = strtolower((string)($_SERVER['HTTP_ACCEPT'] ?? ''));
        if (strpos($accept, 'application/json') !== false) {
            return true;
        }

        return strtolower((string)($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '')) === 'xmlhttprequest';
    }

    private function middleware($controllerName, $method)
    {
        /*
         * CSRF: toda request POST (formularios nativos vía $_POST['csrf_token'], fetch/XHR
         * vía header X-CSRF-Token inyectado por public/js/csrf.js) debe traer el token de
         * la sesión actual. Corre antes que cualquier otra verificación porque $_SESSION ya
         * está activo desde public/index.php para cualquier visitante, incluso sin login.
         */
        if ($_SERVER['REQUEST_METHOD'] === 'POST' && class_exists('CsrfService') && !CsrfService::verifyRequest()) {
            self::logFalloCsrf();
            http_response_code(403);

            // A un cliente que espera JSON hay que responderle JSON: si recibe
            // este texto plano, su r.json() lanza una excepción de parseo que
            // el front confunde con una caída de red ("Error de conexión") en
            // lugar de avisar de que la sesión expiró.
            if (self::requestWantsJson()) {
                header('Content-Type: application/json; charset=utf-8');
                exit(json_encode([
                    'success' => false,
                    'error' => 'csrf_invalido',
                    'message' => 'Su sesión expiró. Recargue la página e intente de nuevo.',
                ], JSON_UNESCAPED_UNICODE));
            }

            exit('Token de seguridad inválido o expirado. Recargue la página e intente de nuevo.');
        }

        /*
         * Sesión obligatoria en todo el sistema salvo pantallas de Login.
         * (Antes se excluían index/modulo/item y cualquier usuario podía pegar ?url=usuario sin sesión.)
         * PrivacidadController: debe verse ANTES de loguearse (enlazada desde
         * login.php) y también por terceros que ni siquiera son usuarios del
         * sistema (Habeas Data no exige tener cuenta para consultarla).
         */
        if (!in_array($controllerName, ['LoginController', 'HealthController', 'PrivacidadController'], true)) {
            SessionManager::requireLogin();
        }

        /*
         * Permiso "ver" por URL para controladores dedicados (usuario/permisos, rol/permisos, etc.).
         * Dashboard y CRUD dinámico se validan dentro de cada controlador con PermisoService::can(ruta).
         */
        $rutaCompleta = $_GET['url'] ?? '';
        $usuarioJsonLookupMethods = [
            'uploadAsset',
            'lookupDocumento',
            'lookupDocumentoNumero',
            'lookupEmail',
            'lookupUsername',
            'lookupEmailTercero',
            'lookupIdentificacion',
        ];

        $empresaJsonApiMethods = ['uploadLogo', 'lookupNit', 'searchRepresentante', 'lookupRepresentante'];

        $sgdElaboracionJsonMethods = ['elaboracionUploadMedia', 'elaboracionImportWord', 'elaboracionImportWordFetch', 'elaboracionPreviewPdf'];

        /* Latido de actividad: para todo usuario logueado, no solo quien puede ver el reporte de sesiones. */
        $sesionesJsonMethods = ['ping'];

        /*
         * Acciones POST de sihos/cruce: su URL (sihos/cruceX) no tiene ítem de menú propio, así
         * que el fallback genérico de PermisoService::resolveItemAccionId() caería al segmento
         * raíz "sihos" — el ítem "Conexión SIHOS" (config de conexión), que no tiene relación con
         * estas acciones del reporte. Se validan explícitamente contra sihos/cruce en su lugar.
         */
        $sihosCruceAccionMethods = [
            'cruceEliminarDetaPlan',
            'cruceReversarCuentaInesperada',
            'cruceReclasificarCuentaVigenciaAnterior',
            'cruceConstruirDetaPlan',
        ];

        /*
         * Misma razón que $sihosCruceAccionMethods: sihos/nominaPilaExportar
         * no tiene ítem de menú propio (es la descarga .xlsx del reporte
         * sihos/nominaPila), así que sin este caso especial caería al
         * fallback genérico de PermisoService::resolveItemAccionId() sobre
         * el segmento raíz "sihos" (Conexión SIHOS), sin relación con el
         * reporte de nómina.
         */
        $sihosNominaPilaAccionMethods = ['nominaPilaExportar'];

        /*
         * Misma razón: sihos/nominaPilaCorreccionAplicar (la escritura AJAX
         * de la pantalla de corrección) no tiene ítem de menú propio — su
         * pantalla es sihos/nominaPilaCorreccion. El permiso fino ('guardar')
         * se re-valida dentro del propio controller; aquí solo se evita que
         * caiga al fallback genérico sobre el segmento raíz "sihos".
         */
        $sihosNominaPilaCorreccionAccionMethods = ['nominaPilaCorreccionAplicar'];

        if (SessionManager::userLogged()
            && $controllerName === 'UsuarioController'
            && in_array($method, $usuarioJsonLookupMethods, true)
            && class_exists('PermisoService')
            && !PermisoService::canUsuarioFormApi()) {
            http_response_code(403);
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode(['ok' => false, 'error' => 'Sin permiso'], JSON_UNESCAPED_UNICODE);
            exit;
        }

        if (SessionManager::userLogged()
            && $controllerName === 'ItemController'
            && class_exists('PermisoService')
            && !PermisoService::isSuperAdminSession()) {
            http_response_code(403);
            exit('Acceso denegado. Solo superadministrador.');
        }

        if (SessionManager::userLogged()
            && !in_array($controllerName, ['LoginController', 'HealthController', 'PrivacidadController', 'DashboardController', 'ModuleController', 'ContextController'], true)
            && class_exists('PermisoService')
            && !($controllerName === 'UsuarioController' && in_array($method, $usuarioJsonLookupMethods, true))
            && !($controllerName === 'EmpresaController' && in_array($method, $empresaJsonApiMethods, true))
            && !($controllerName === 'SesionesController' && in_array($method, $sesionesJsonMethods, true))
            && !($controllerName === 'SgdController'
                && in_array($method, $sgdElaboracionJsonMethods, true)
                && (
                    ($method === 'elaboracionPreviewPdf'
                        && (PermisoService::can('sgd/elaboracion', 'ver') || PermisoService::can('sgd/documentos', 'ver')))
                    || ($method !== 'elaboracionPreviewPdf' && PermisoService::can('sgd/elaboracion', 'ver'))
                ))
            && !($controllerName === 'SihosController'
                && in_array($method, $sihosCruceAccionMethods, true)
                && PermisoService::can('sihos/cruce', 'ver'))
            && !($controllerName === 'SihosController'
                && in_array($method, $sihosNominaPilaAccionMethods, true)
                && PermisoService::can('sihos/nominaPila', 'ver'))
            && !($controllerName === 'SihosController'
                && in_array($method, $sihosNominaPilaCorreccionAccionMethods, true)
                && PermisoService::can('sihos/nominaPilaCorreccion', 'ver'))
            && !PermisoService::can($rutaCompleta, 'ver')) {
            http_response_code(403);
            exit('Acceso denegado.');
        }
    }
}