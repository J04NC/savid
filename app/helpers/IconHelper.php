<?php

class IconHelper
{

    private static $icons = [

        "ADMINISTRACION" => "⚙️",
        "NOMINA" => "💰",
        "CONSENTIMIENTOS" => "📄",
        "REPORTES" => "📊",
        "INVENTARIO" => "📦",
        "VENTAS" => "🛒",
        "USUARIOS" => "👥",
        "CONFIGURACION" => "🧩"

    ];

    public static function get($name)
    {

        $name = strtoupper($name);

        return self::$icons[$name] ?? "📁";

    }

}
