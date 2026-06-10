(function () {
    'use strict';

    var catalogEl = document.getElementById('sgd-doc-catalogos');
    if (!catalogEl) return;

    var catalogos;
    try {
        catalogos = JSON.parse(catalogEl.textContent || '{}');
    } catch (e) {
        catalogos = {};
    }

    var consecutivoIndex = catalogos.consecutivoIndex || {};
    var padresPermitidosPorTipo = catalogos.padresPermitidosPorTipo || {};
    var padresById = {};
    (catalogos.padres || []).forEach(function (p) {
        padresById[String(p.id)] = p;
    });

    var elProceso = document.getElementById('sgd_doc_proceso_id');
    var elTipo = document.getElementById('sgd_doc_tipo_id');
    var elLinea = document.getElementById('sgd_doc_linea_id');
    var elPadre = document.getElementById('sgd_doc_padre_id');
    var elConsecutivo = document.getElementById('sgd_doc_consecutivo');
    var elDocId = document.getElementById('sgd_doc_id');
    var elCodeValue = document.getElementById('sgd-doc-code-value');
    var elCodeNote = document.getElementById('sgd-doc-code-note');
    var elLineaGroup = document.getElementById('sgd-doc-linea-group');
    var elPadreNote = document.getElementById('sgd-doc-padre-note');
    var elBtnNuevo = document.getElementById('sgd-doc-btn-nuevo');
    var tableWrap = document.querySelector('.sgd-doc-table-wrap');
    var consecutivoManual = false;

    function isEditing() {
        return elDocId && String(elDocId.value || '').trim() !== '';
    }

    function selectedCodigo(selectEl) {
        if (!selectEl || !selectEl.selectedOptions.length) return '';
        return (selectEl.selectedOptions[0].dataset.codigo || '').toUpperCase();
    }

    function scopeKey(procesoId, padreId, tipoId, lineaId) {
        return [
            parseInt(procesoId, 10) || 0,
            parseInt(padreId, 10) || 0,
            parseInt(tipoId, 10) || 0,
            parseInt(lineaId, 10) || 0
        ].join('|');
    }

    function suggestConsecutivo(force) {
        if (!elConsecutivo || isEditing()) return;
        if (consecutivoManual && !force) return;

        var procesoId = elProceso ? elProceso.value : '';
        var tipoId = elTipo ? elTipo.value : '';
        if (!procesoId || !tipoId) return;

        var padreId = elPadre && elPadre.value ? elPadre.value : '0';
        var lineaId = '0';
        if (elPadre && !elPadre.value && elLinea && elLinea.value) {
            lineaId = elLinea.value;
        }

        var key = scopeKey(procesoId, padreId, tipoId, lineaId);
        var max = consecutivoIndex[key] || 0;
        elConsecutivo.value = String(max + 1);
        updatePreview();
    }

    function buildChildSuffix(tipo, consecutivo) {
        consecutivo = String(consecutivo || '').trim();
        if (!consecutivo) return tipo;
        if (/^[A-Z]+\d+$/i.test(consecutivo)) return consecutivo.toUpperCase();
        if (/^\d+$/.test(consecutivo)) return tipo + consecutivo;
        return consecutivo.toUpperCase();
    }

    function buildRootCode(proceso, tipo, linea, consecutivo) {
        if (!proceso || !tipo) return '';
        if (!consecutivo) {
            return proceso + '-' + tipo + (linea || '');
        }
        if (linea) {
            return proceso + '-' + tipo + linea + '-' + consecutivo;
        }
        return proceso + '-' + tipo + consecutivo;
    }

    function buildCodigo() {
        var proceso = selectedCodigo(elProceso);
        var tipo = selectedCodigo(elTipo);
        var linea = selectedCodigo(elLinea);
        var consecutivo = elConsecutivo ? elConsecutivo.value.trim() : '';
        var padreId = elPadre ? elPadre.value : '';
        var padreCodigo = '';

        if (padreId && padresById[padreId]) {
            padreCodigo = padresById[padreId].codigo_display || '';
            if (!proceso) {
                proceso = (padresById[padreId].proceso_codigo || '').toUpperCase();
            }
        }

        if (padreCodigo) {
            return padreCodigo + '-' + buildChildSuffix(tipo, consecutivo);
        }
        return buildRootCode(proceso, tipo, linea, consecutivo);
    }

    function allowedPadreTipoIds(tipoId) {
        if (!tipoId) {
            return [];
        }
        var key = String(tipoId);
        if (!Object.prototype.hasOwnProperty.call(padresPermitidosPorTipo, key)) {
            return [];
        }
        var list = padresPermitidosPorTipo[key];
        return Array.isArray(list) ? list.map(function (n) { return parseInt(n, 10); }) : [];
    }

    function padreDocTipoId(docId) {
        if (!docId) return 0;
        var p = padresById[String(docId)];
        if (p && p.tipo_documental_id) {
            return parseInt(p.tipo_documental_id, 10);
        }
        if (!elPadre) return 0;
        var opt = elPadre.querySelector('option[value="' + docId + '"]');
        if (!opt) return 0;
        return parseInt(opt.getAttribute('data-tipo-documental-id') || '0', 10);
    }

    function padreDocProcesoId(docId) {
        if (!docId) return 0;
        var p = padresById[String(docId)];
        if (p && p.proceso_id) {
            return parseInt(p.proceso_id, 10);
        }
        if (!elPadre) return 0;
        var opt = elPadre.querySelector('option[value="' + docId + '"]');
        if (!opt) return 0;
        return parseInt(opt.getAttribute('data-proceso-id') || '0', 10);
    }

    function isPadrePermitido(docId, tipoId, procesoId) {
        if (!docId || !tipoId || !procesoId) return false;
        var allowed = allowedPadreTipoIds(tipoId);
        if (!allowed.length) return false;
        if (allowed.indexOf(padreDocTipoId(docId)) < 0) return false;
        return padreDocProcesoId(docId) === parseInt(procesoId, 10);
    }

    function filterPadreOptions() {
        if (!elPadre || !elTipo) return;
        var tipoId = elTipo.value;
        var procesoId = elProceso ? elProceso.value : '';
        var allowed = allowedPadreTipoIds(tipoId);
        var currentVal = elPadre.value;
        var visibleCount = 0;

        Array.prototype.forEach.call(elPadre.options, function (opt) {
            if (!opt.value) {
                opt.hidden = false;
                return;
            }
            var tipPadre = parseInt(opt.getAttribute('data-tipo-documental-id') || '0', 10);
            var optProceso = parseInt(opt.getAttribute('data-proceso-id') || '0', 10);
            var ok = !!(tipoId && procesoId && allowed.indexOf(tipPadre) >= 0
                && optProceso === parseInt(procesoId, 10));
            opt.hidden = !ok;
            if (ok) visibleCount += 1;
        });

        if (currentVal && !isPadrePermitido(currentVal, tipoId, procesoId)) {
            elPadre.value = '';
            highlightGridRow('');
            updateNuevoHref('');
        }

        if (elPadreNote) {
            if (!tipoId) {
                elPadreNote.textContent = 'Seleccione primero el tipo documental.';
            } else if (!procesoId) {
                elPadreNote.textContent = 'Seleccione el proceso; el padre debe ser del mismo proceso.';
            } else if (!allowed.length) {
                elPadreNote.textContent = 'Este tipo no admite documento padre; debe ser documento raíz.';
            } else if (visibleCount === 0) {
                elPadreNote.textContent = 'No hay documentos en este proceso con un tipo padre permitido. Cree primero el documento maestro (PD, M, etc.).';
            } else {
                elPadreNote.textContent = visibleCount + ' documento(s) del mismo proceso disponibles como padre.';
            }
        }
    }

    function updateLineaVisibility() {
        if (!elLineaGroup || !elPadre) return;
        var hasPadre = elPadre.value !== '';
        elLineaGroup.hidden = hasPadre;
        if (hasPadre && elLinea) {
            elLinea.value = '';
        }
    }

    function updatePreview() {
        updateLineaVisibility();
        filterPadreOptions();
        var code = buildCodigo();
        if (elCodeValue) {
            elCodeValue.textContent = code || '—';
        }
        if (elCodeNote) {
            if (elPadre && elPadre.value) {
                elCodeNote.textContent = 'Hijo: código del padre + tipo + consecutivo.';
            } else if (elLinea && elLinea.value) {
                elCodeNote.textContent = 'Raíz: proceso + tipo + línea + consecutivo.';
            } else {
                elCodeNote.textContent = 'Raíz: proceso + tipo + consecutivo.';
            }
        }
    }

    function padreOptionExists(docId) {
        if (!elPadre || !docId) return false;
        return Array.prototype.some.call(elPadre.options, function (opt) {
            return opt.value === String(docId);
        });
    }

    function applyPadreFromGrid(docId, rowProcesoId) {
        if (!elPadre || !docId) return;
        var hijoTipoId = elTipo ? elTipo.value : '';
        var hijoProcesoId = elProceso ? elProceso.value : '';

        if (hijoProcesoId && rowProcesoId && String(hijoProcesoId) !== String(rowProcesoId)) {
            return;
        }

        var procesoRef = hijoProcesoId || rowProcesoId;
        if (hijoTipoId && procesoRef && !isPadrePermitido(docId, hijoTipoId, procesoRef)) {
            return;
        }
        if (!padreOptionExists(docId)) return;

        if (elProceso && rowProcesoId && !hijoProcesoId) {
            elProceso.value = String(rowProcesoId);
            procesoRef = rowProcesoId;
        }

        elPadre.value = String(docId);
        consecutivoManual = false;
        suggestConsecutivo(true);
        updatePreview();
    }

    function highlightGridRow(docId) {
        if (!tableWrap) return;
        tableWrap.querySelectorAll('.sgd-doc-row').forEach(function (row) {
            row.classList.toggle('is-selected', String(row.getAttribute('data-doc-id')) === String(docId));
        });
    }

    function updateNuevoHref(docId) {
        if (!elBtnNuevo) return;
        var base = elBtnNuevo.getAttribute('data-base-href') || elBtnNuevo.getAttribute('href') || '';
        if (!elBtnNuevo.getAttribute('data-base-href')) {
            elBtnNuevo.setAttribute('data-base-href', base);
        }
        if (docId) {
            var sep = base.indexOf('?') >= 0 ? '&' : '?';
            elBtnNuevo.setAttribute('href', base + sep + 'padre_id=' + encodeURIComponent(docId));
        } else {
            elBtnNuevo.setAttribute('href', base);
        }
    }

    function onGridRowSelect(row) {
        if (!row) return;
        var docId = row.getAttribute('data-doc-id');
        var procesoId = row.getAttribute('data-proceso-id');
        highlightGridRow(docId);
        updateNuevoHref(docId);
        if (!isEditing()) {
            applyPadreFromGrid(docId, procesoId);
        }
    }

    if (tableWrap) {
        tableWrap.addEventListener('click', function (e) {
            if (e.target.closest('a')) return;
            var row = e.target.closest('.sgd-doc-row');
            if (row) onGridRowSelect(row);
        });
        tableWrap.addEventListener('keydown', function (e) {
            if (e.key !== 'Enter' && e.key !== ' ') return;
            var row = e.target.closest('.sgd-doc-row');
            if (!row) return;
            e.preventDefault();
            onGridRowSelect(row);
        });
    }

    if (elPadre) {
        elPadre.addEventListener('change', function () {
            var padreId = elPadre.value;
            if (padreId && padresById[padreId]) {
                var p = padresById[padreId];
                if (elProceso && p.proceso_id) {
                    elProceso.value = String(p.proceso_id);
                }
                highlightGridRow(padreId);
                updateNuevoHref(padreId);
            } else {
                highlightGridRow('');
                updateNuevoHref('');
            }
            consecutivoManual = false;
            suggestConsecutivo(true);
            updatePreview();
        });
    }

    if (elConsecutivo) {
        elConsecutivo.addEventListener('input', function () {
            consecutivoManual = true;
            updatePreview();
        });
    }

    [elProceso, elTipo, elLinea, elConsecutivo].forEach(function (el) {
        if (!el) return;
        el.addEventListener('change', function () {
            if (el === elTipo || el === elProceso) {
                consecutivoManual = false;
                filterPadreOptions();
            }
            if (el !== elConsecutivo) {
                consecutivoManual = false;
                suggestConsecutivo(false);
            }
            updatePreview();
        });
        el.addEventListener('input', function () {
            if (el === elConsecutivo) return;
            updatePreview();
        });
    });

    var initialSelected = tableWrap && tableWrap.querySelector('.sgd-doc-row.is-active');
    if (initialSelected) {
        highlightGridRow(initialSelected.getAttribute('data-doc-id'));
        updateNuevoHref(initialSelected.getAttribute('data-doc-id'));
    }

    updatePreview();
    if (!isEditing() && elConsecutivo && !elConsecutivo.value.trim()) {
        suggestConsecutivo(true);
    }

    function readPdfStable(file, retriesLeft, delayMs) {
        return new Promise(function (resolve, reject) {
            function attempt() {
                var reader = new FileReader();
                reader.onload = function () {
                    resolve({
                        blob: new Blob([reader.result], { type: 'application/pdf' }),
                        name: file.name || 'documento.pdf'
                    });
                };
                reader.onerror = function () {
                    if (retriesLeft > 0) {
                        retriesLeft -= 1;
                        setTimeout(attempt, delayMs);
                        return;
                    }
                    reject(new Error(
                        'No se pudo leer el archivo desde la nube. Descárguelo al teléfono o ábralo desde Archivos locales.'
                    ));
                };
                try {
                    reader.readAsArrayBuffer(file);
                } catch (err) {
                    if (retriesLeft > 0) {
                        retriesLeft -= 1;
                        setTimeout(attempt, delayMs);
                        return;
                    }
                    reject(err);
                }
            }
            setTimeout(attempt, delayMs);
        });
    }

    function resolveUploadUrl(action) {
        if (!action) return '';
        if (/^https?:\/\//i.test(action)) return action;
        var base = window.location.pathname || '/';
        if (action.charAt(0) === '?') return base + action;
        if (action.charAt(0) === '/') return action;
        return base + '?' + action.replace(/^\?/, '');
    }

    function parseUploadResponse(text) {
        try {
            return JSON.parse(text);
        } catch (e) {
            throw new Error('El servidor no respondió correctamente. Recargue la página e intente de nuevo.');
        }
    }

    document.querySelectorAll('.sgd-doc-ver-upload-form').forEach(function (form) {
        var fileInput = form.querySelector('.sgd-doc-ver-file');
        var submitBtn = form.querySelector('.sgd-doc-ver-submit-btn');
        var nameEl = form.querySelector('.sgd-doc-ver-file-name');
        if (!fileInput || !submitBtn) return;

        form._pdfPayload = null;

        fileInput.addEventListener('change', function () {
            var file = fileInput.files && fileInput.files[0];
            form._pdfPayload = null;
            submitBtn.disabled = true;
            if (!file) {
                if (nameEl) {
                    nameEl.textContent = '';
                    nameEl.classList.remove('is-error');
                }
                return;
            }

            if (nameEl) {
                nameEl.textContent = 'Preparando archivo…';
                nameEl.classList.remove('is-error');
            }

            readPdfStable(file, 4, 500).then(function (payload) {
                form._pdfPayload = payload;
                if (nameEl) {
                    nameEl.textContent = payload.name + ' — listo para subir';
                }
                submitBtn.disabled = false;
            }).catch(function (err) {
                if (nameEl) {
                    nameEl.textContent = err && err.message ? err.message : 'No se pudo preparar el archivo.';
                    nameEl.classList.add('is-error');
                }
            });
        });

        form.addEventListener('submit', function (e) {
            e.preventDefault();
            var payload = form._pdfPayload;
            if (!payload) {
                if (nameEl) {
                    nameEl.textContent = 'Espere a que el archivo quede listo o elíjalo de nuevo.';
                    nameEl.classList.add('is-error');
                }
                return false;
            }

            submitBtn.disabled = true;
            submitBtn.textContent = 'Subiendo…';
            if (nameEl) {
                nameEl.classList.remove('is-error');
            }

            var fd = new FormData();
            fd.append('archivo', payload.blob, payload.name);
            fd.append('version_id', form.getAttribute('data-version-id') || '');
            fd.append('documento_id', form.getAttribute('data-documento-id') || '');

            fetch(resolveUploadUrl(form.action), {
                method: 'POST',
                body: fd,
                credentials: 'same-origin',
                headers: {
                    'ngrok-skip-browser-warning': '1',
                    'X-Requested-With': 'XMLHttpRequest',
                    'Accept': 'application/json'
                }
            })
                .then(function (r) {
                    return r.text().then(function (text) {
                        return parseUploadResponse(text);
                    });
                })
                .then(function (data) {
                    if (data && data.ok) {
                        window.location.href = form.getAttribute('data-return-url') || window.location.href;
                        return;
                    }
                    throw new Error((data && (data.error || data.message)) || 'Error al subir el PDF');
                })
                .catch(function (err) {
                    submitBtn.disabled = false;
                    submitBtn.textContent = 'Subir';
                    if (nameEl) {
                        nameEl.textContent = err && err.message ? err.message : 'Error al subir';
                        nameEl.classList.add('is-error');
                    }
                });

            return false;
        });
    });
})();
