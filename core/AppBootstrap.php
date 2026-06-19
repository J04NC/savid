<?php

/**
 * Bootstrap mínimo compartido por scripts CLI (cron, pruebas, mantenimiento).
 */
class AppBootstrap
{
    public static function basePath(): string
    {
        return defined('BASE_PATH') ? BASE_PATH : dirname(__DIR__);
    }

    public static function initCore(bool $loadComposer = true): void
    {
        if (!defined('BASE_PATH')) {
            define('BASE_PATH', dirname(__DIR__));
        }

        if ($loadComposer) {
            $composerAutoload = BASE_PATH . '/vendor/autoload.php';
            if (is_readable($composerAutoload)) {
                require_once $composerAutoload;
            }
        }

        $dbFile = BASE_PATH . '/config/Database.php';
        if (is_readable($dbFile)) {
            require_once $dbFile;
        } else {
            require_once BASE_PATH . '/config.example/Database.php';
        }

        Database::bootstrapEnv();

        $paths = [
            BASE_PATH . '/core/',
            BASE_PATH . '/app/storage/',
            BASE_PATH . '/app/services/',
            BASE_PATH . '/app/models/',
            BASE_PATH . '/app/helpers/',
        ];

        spl_autoload_register(static function (string $class) use ($paths): void {
            foreach ($paths as $path) {
                $file = $path . $class . '.php';
                if (is_readable($file)) {
                    require_once $file;

                    return;
                }
            }
        });
    }
}
