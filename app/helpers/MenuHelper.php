<?php

class MenuHelper
{
    private static ?int $moduloId = null;

    /**
     * @param array<int, array<string, mixed>> $items Árbol de ítems (con clave children opcional).
     */
    public static function renderMenu(array $items, ?int $moduloId = null): void
    {
        if ($items === []) {
            return;
        }

        self::$moduloId = $moduloId !== null && (int)$moduloId > 0 ? (int)$moduloId : null;

        echo "<div class='sidebar-menu'>";
        self::renderNodes($items, 0);
        echo "</div>";

        self::$moduloId = null;
    }

    /**
     * @param array<int, array<string, mixed>> $nodes
     */
    private static function renderNodes(array $nodes, int $nivel): void
    {
        foreach ($nodes as $item) {
            self::renderNode($item, $nivel);
        }
    }

    /**
     * @param array<string, mixed> $item
     */
    private static function renderNode(array $item, int $nivel): void
    {
        $hasChildren = !empty($item['children']) && is_array($item['children']);
        $ruta = trim((string)($item['ruta'] ?? ''));
        $nombre = htmlspecialchars((string)($item['nombre'] ?? ''), ENT_QUOTES, 'UTF-8');
        $icono = trim((string)($item['icono'] ?? ''));

        $isRoot = $nivel === 0;
        $itemClass = $isRoot ? 'sidebar-item' : 'sidebar-subitem';
        $levelClass = 'menu-level-' . $nivel;
        $iconChar = $icono !== '' ? $icono : ($hasChildren ? '◉' : '•');

        echo "<div class='menu-block'>";

        if ($hasChildren) {
            echo "<div class='{$itemClass} menu-parent {$levelClass}' onclick='toggleSubMenu(this)'>";
            echo "<div class='menu-left'>";
            echo "<span class='menu-icon'>" . htmlspecialchars($iconChar, ENT_QUOTES, 'UTF-8') . "</span>";
            echo "<span class='menu-label'>{$nombre}</span>";
            echo "</div>";
            echo "<span class='menu-arrow'>▾</span>";
            echo "</div>";

            echo "<div class='submenu hidden'>";
            self::renderNodes($item['children'], $nivel + 1);
            echo "</div>";
        } elseif ($ruta !== '') {
            $href = self::itemHref($ruta);
            echo "<a class='{$itemClass} menu-link {$levelClass}' href='{$href}'>";
            echo "<div class='menu-left'>";
            echo "<span class='menu-icon'>" . htmlspecialchars($iconChar, ENT_QUOTES, 'UTF-8') . "</span>";
            echo "<span class='menu-label'>{$nombre}</span>";
            echo "</div>";
            echo "</a>";
        } else {
            echo "<div class='{$itemClass} menu-label-only {$levelClass}'>";
            echo "<div class='menu-left'>";
            echo "<span class='menu-icon'>" . htmlspecialchars($iconChar, ENT_QUOTES, 'UTF-8') . "</span>";
            echo "<span class='menu-label'>{$nombre}</span>";
            echo "</div>";
            echo "</div>";
        }

        echo "</div>";
    }

    private static function itemHref(string $ruta): string
    {
        $href = '?url=' . rawurlencode($ruta);
        if (self::$moduloId !== null) {
            $href .= '&modulo=' . self::$moduloId;
        }

        return htmlspecialchars($href, ENT_QUOTES, 'UTF-8');
    }
}
