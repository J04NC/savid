<?php

class MenuHelper
{

    public static function renderMenu($items)
    {
        if (!$items) return;

        echo "<div class='sidebar-menu'>";

        foreach ($items as $item) {

            $hasChildren = !empty($item['children']);
            $nombre      = htmlspecialchars($item['nombre']);
            $ruta        = $item['ruta'] ?? '';

            echo "<div class='menu-block'>";

            /* =========================
               ITEM PADRE CON HIJOS
            ========================= */
            if (!$ruta && $hasChildren) {

                echo "<div class='sidebar-item menu-parent' onclick='toggleSubMenu(this)'>";

                    echo "<div class='menu-left'>";
                        echo "<span class='menu-icon'>◉</span>";
                        echo "<span class='menu-label'>{$nombre}</span>";
                    echo "</div>";

                    echo "<span class='menu-arrow'>▾</span>";

                echo "</div>";

            }

            /* =========================
               ITEM NORMAL LINK
            ========================= */
            else {

                echo "<a class='sidebar-item menu-link' href='?url={$ruta}'>";

                    echo "<div class='menu-left'>";
                        echo "<span class='menu-icon'>•</span>";
                        echo "<span class='menu-label'>{$nombre}</span>";
                    echo "</div>";

                echo "</a>";

            }

            /* =========================
               SUBMENU
            ========================= */
            if ($hasChildren) {

                echo "<div class='submenu hidden'>";

                self::renderSubMenu($item['children'], 1);

                echo "</div>";
            }

            echo "</div>";
        }

        echo "</div>";
    }


    private static function renderSubMenu($children, $nivel = 1)
    {
        foreach ($children as $child) {

            $hasChildren = !empty($child['children']);
            $nombre      = htmlspecialchars($child['nombre']);
            $ruta        = $child['ruta'] ?? '';

            $claseNivel = "menu-level-" . $nivel;

            /* =========================
               SUBITEM PADRE
            ========================= */
            if (!$ruta && $hasChildren) {

                echo "<div class='sidebar-subitem menu-parent {$claseNivel}' onclick='toggleSubMenu(this)'>";

                    echo "<div class='menu-left'>";
                        echo "<span class='menu-icon'>▸</span>";
                        echo "<span class='menu-label'>{$nombre}</span>";
                    echo "</div>";

                    echo "<span class='menu-arrow'>▾</span>";

                echo "</div>";

            }

            /* =========================
               SUBITEM LINK
            ========================= */
            else {

                echo "<a class='sidebar-subitem menu-link {$claseNivel}' href='?url={$ruta}'>";

                    echo "<div class='menu-left'>";
                        echo "<span class='menu-icon'>•</span>";
                        echo "<span class='menu-label'>{$nombre}</span>";
                    echo "</div>";

                echo "</a>";

            }

            /* =========================
               HIJOS INTERNOS
            ========================= */
            if ($hasChildren) {

                echo "<div class='submenu hidden'>";

                self::renderSubMenu($child['children'], $nivel + 1);

                echo "</div>";
            }
        }
    }

}