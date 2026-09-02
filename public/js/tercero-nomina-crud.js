/**
 * Terceros Nómina — al escribir tipo/número de documento, busca si ya existe
 * un tercero con esa identificación (de cualquier empresa) y, si lo
 * encuentra, autocompleta Razón social, la bloquea (readonly) y avisa que al
 * guardar quedará vinculado a la empresa de la sesión actual — sin poder
 * modificar la razón social de un tercero que puede estar en uso por otra
 * empresa. Esto es solo la ayuda visual: la protección real está en el
 * servidor (CrudService::resolveTerceroNominaIdentidadForSave() ignora
 * cualquier razón social que llegue en el POST cuando el tipo+número ya
 * existe, así se salte este JS).
 */
(function () {
    'use strict';

    var DEBOUNCE_MS = 400;
    var debounceTimer = null;

    function fieldsOf(form) {
        return {
            tipo: form.querySelector('[name="tipodocumento_id"]'),
            numero: form.querySelector('[name="numero_documento"]'),
            razonSocial: form.querySelector('[name="razon_social"]'),
        };
    }

    function ensureNotice(razonSocialInput) {
        var group = razonSocialInput.closest('.form-group');
        if (!group) {
            return null;
        }
        var notice = group.querySelector('.tercero-nomina-lookup-notice');
        if (!notice) {
            notice = document.createElement('p');
            notice.className = 'field-note tercero-nomina-lookup-notice';
            notice.hidden = true;
            group.appendChild(notice);
        }
        return notice;
    }

    function showNotice(notice, texto) {
        if (!notice) {
            return;
        }
        notice.textContent = texto;
        notice.hidden = false;
    }

    function hideNotice(notice) {
        if (!notice) {
            return;
        }
        notice.hidden = true;
        notice.textContent = '';
    }

    function consultar(form) {
        var f = fieldsOf(form);
        if (!f.tipo || !f.numero || !f.razonSocial) {
            return;
        }

        var tipo = f.tipo.value;
        var numero = (f.numero.value || '').trim();
        var notice = ensureNotice(f.razonSocial);

        if (!tipo || numero.length < 3) {
            f.razonSocial.readOnly = false;
            f.razonSocial.classList.remove('tercero-nomina-locked');
            hideNotice(notice);
            return;
        }

        var url = new URL(window.location.origin + window.location.pathname);
        url.searchParams.set('url', 'module/terceroNominaLookup');
        url.searchParams.set('tipodocumento_id', tipo);
        url.searchParams.set('numero', numero);

        fetch(url.toString(), { headers: { 'X-Requested-With': 'XMLHttpRequest' } })
            .then(function (r) { return r.json(); })
            .then(function (data) {
                if (!data || !data.ok) {
                    return;
                }
                if (data.found) {
                    f.razonSocial.value = data.razon_social || '';
                    f.razonSocial.readOnly = true;
                    f.razonSocial.classList.add('tercero-nomina-locked');
                    showNotice(
                        notice,
                        'Este tercero ya existe en el sistema (' + data.razon_social + '). La razón social no se puede modificar desde aquí — al guardar, este registro quedará vinculado a su empresa con el tipo que elija abajo.'
                    );
                } else {
                    f.razonSocial.readOnly = false;
                    f.razonSocial.classList.remove('tercero-nomina-locked');
                    showNotice(notice, 'No existe un tercero con este documento — al guardar se creará uno nuevo con los datos de este formulario.');
                }
            })
            .catch(function () {
                /* Sin conexión o error de red: no bloquea el formulario, solo no autocompleta. */
            });
    }

    function onTrigger(ev) {
        var el = ev.target;
        if (!el || !el.name) {
            return;
        }
        if (el.name !== 'numero_documento' && el.name !== 'tipodocumento_id') {
            return;
        }
        var form = el.closest('form');
        if (!form) {
            return;
        }
        clearTimeout(debounceTimer);
        debounceTimer = setTimeout(function () {
            consultar(form);
        }, DEBOUNCE_MS);
    }

    document.addEventListener('input', onTrigger, true);
    document.addEventListener('change', onTrigger, true);
})();
