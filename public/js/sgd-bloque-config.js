(function (global) {
    'use strict';

    var TERCERO_BLOQUES = ['op_encabezado_tercero'];

    function configKey(widget) {
        if (widget === 'grupo_campos') return 'campos';
        if (widget === 'tabla_repetible') return 'columnas';
        if (widget === 'bloque_firmas') return 'roles';
        return null;
    }

    function isConfigurable(widget) {
        return configKey(widget) !== null;
    }

    function supportsTerceroAutocomplete(seccionCodigo) {
        return TERCERO_BLOQUES.indexOf(String(seccionCodigo || '').toLowerCase()) >= 0;
    }

    function resolveTitulo(bloque) {
        var cfg = bloque.config || {};
        var t = String(cfg.titulo || '').trim();
        return t || String(bloque.nombre || bloque.seccion_codigo || '');
    }

    function resolveAyuda(bloque, catalogDef) {
        var cfg = bloque.config || {};
        var a = String(cfg.ayuda || '').trim();
        if (a) return a;
        if (catalogDef && catalogDef.ayuda) return String(catalogDef.ayuda);
        return '';
    }

    function resolveAutocompletar(bloque) {
        var cfg = bloque.config || {};
        if (cfg.autocompletar_maestro === 'tercero' && supportsTerceroAutocomplete(bloque.seccion_codigo)) {
            return 'tercero';
        }
        return null;
    }

    function resolveDefaults(bloque) {
        var key = configKey(bloque.widget || 'grupo_campos');
        if (!key || !bloque.config || !bloque.config[key]) return {};
        var out = {};
        bloque.config[key].forEach(function (row) {
            if (row && row.id && row.default != null && String(row.default).trim() !== '') {
                out[row.id] = String(row.default).trim();
            }
        });
        return out;
    }

    function buildDefaultConfig(definicion, widget) {
        var key = configKey(widget);
        if (!key) return {};
        var items = (definicion && definicion[key]) || [];
        var out = [];
        items.forEach(function (item) {
            if (!item || !item.id) return;
            var row = { id: item.id, visible: true };
            if (Object.prototype.hasOwnProperty.call(item, 'requerido')) {
                row.requerido = !!item.requerido;
            }
            out.push(row);
        });
        var cfg = {};
        cfg[key] = out;
        return cfg;
    }

    function getEffectiveConfig(bloque, catalogDefinicion) {
        var widget = bloque.widget || 'grupo_campos';
        var key = configKey(widget);
        if (!key) return {};
        var config = bloque.config;
        if (config && config[key] && config[key].length) {
            return config;
        }
        return buildDefaultConfig(catalogDefinicion || bloque.definicion || {}, widget);
    }

    function orderedConfigRows(bloque, catalogDefinicion) {
        var widget = bloque.widget || 'grupo_campos';
        var key = configKey(widget);
        if (!key) return [];
        var catDef = catalogDefinicion || bloque.definicion || {};
        var catalogItems = catDef[key] || [];
        var cfg = getEffectiveConfig(bloque, catDef);
        var cfgMap = {};
        (cfg[key] || []).forEach(function (row) { cfgMap[row.id] = row; });
        var ordered = [];
        (cfg[key] || []).forEach(function (row) {
            var base = catalogItems.find(function (c) { return c.id === row.id; });
            if (base) ordered.push({ catalog: base, config: row });
        });
        catalogItems.forEach(function (base) {
            if (!cfgMap[base.id]) {
                ordered.push({
                    catalog: base,
                    config: { id: base.id, visible: true, requerido: !!base.requerido }
                });
            }
        });
        return ordered;
    }

    function applyConfig(definicion, config, widget) {
        var key = configKey(widget);
        if (!key) return definicion || {};
        var def = JSON.parse(JSON.stringify(definicion || {}));
        var catalogItems = def[key] || [];
        if (!catalogItems.length) return def;

        var effective = (config && config[key] && config[key].length)
            ? config
            : buildDefaultConfig(def, widget);

        var byId = {};
        catalogItems.forEach(function (item) {
            if (item && item.id) byId[item.id] = item;
        });

        var merged = [];
        (effective[key] || []).forEach(function (cfg) {
            if (!cfg || !cfg.id || !cfg.visible || !byId[cfg.id]) return;
            var out = Object.assign({}, byId[cfg.id]);
            if (Object.prototype.hasOwnProperty.call(cfg, 'requerido')) {
                out.requerido = !!cfg.requerido;
            }
            if (cfg.label) out.label = cfg.label;
            if (cfg.default != null && String(cfg.default).trim() !== '') {
                out.default = String(cfg.default).trim();
            }
            merged.push(out);
        });

        if (!merged.length && catalogItems.length) {
            catalogItems.forEach(function (item) { if (item) merged.push(Object.assign({}, item)); });
        }

        def[key] = merged;
        return def;
    }

    function resolveDefinicion(bloque, catalogDefinicion) {
        var widget = bloque.widget || 'grupo_campos';
        var base = catalogDefinicion || bloque.definicion || {};
        return applyConfig(base, bloque.config, widget);
    }

    function configLabel(widget) {
        if (widget === 'tabla_repetible') return 'Columnas';
        if (widget === 'bloque_firmas') return 'Roles de firma';
        return 'Campos';
    }

    global.SgdBloqueConfig = {
        configKey: configKey,
        isConfigurable: isConfigurable,
        supportsTerceroAutocomplete: supportsTerceroAutocomplete,
        resolveTitulo: resolveTitulo,
        resolveAyuda: resolveAyuda,
        resolveAutocompletar: resolveAutocompletar,
        resolveDefaults: resolveDefaults,
        buildDefaultConfig: buildDefaultConfig,
        getEffectiveConfig: getEffectiveConfig,
        orderedConfigRows: orderedConfigRows,
        applyConfig: applyConfig,
        resolveDefinicion: resolveDefinicion,
        configLabel: configLabel
    };
}(typeof window !== 'undefined' ? window : this));
