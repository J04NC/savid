/* =====================================================
   public/js/crud.js
   CRUD ENGINE PRO - SAVID
===================================================== */

let selectedRow = null;
let selectedId = null;

function crudNormalizeUppercaseField(el) {
    if (!el || el.getAttribute("data-crud-uppercase") !== "1") {
        return;
    }
    const v = String(el.value || "").toUpperCase();
    if (el.value !== v) {
        el.value = v;
    }
}

function initCrudUppercaseFields() {
    document.querySelectorAll('[data-crud-uppercase="1"]').forEach(function (el) {
        el.addEventListener("input", function () {
            const start = el.selectionStart;
            const end = el.selectionEnd;
            const dir = el.selectionDirection;
            const v = String(el.value || "").toUpperCase();
            if (el.value === v) {
                return;
            }
            el.value = v;
            if (start != null && typeof el.setSelectionRange === "function") {
                try {
                    el.setSelectionRange(start, end, dir || "forward");
                } catch (e2) {
                    /* noop */
                }
            }
        });
        el.addEventListener("blur", function () {
            crudNormalizeUppercaseField(el);
        });
        crudNormalizeUppercaseField(el);
    });
}

document.addEventListener("DOMContentLoaded", function () {

    initCrudRows();
    initCrudNuevo();
    initCrudSearch();
    initCrudDelete();
    initCrudAcciones();
    initCrudValidation();
    initCrudCatalog();
    initCrudUppercaseFields();
    initCrudZonaUbicacionToggle();
    initCrudEmpresaNitLookup();
    initCrudEmpresaRepresentanteLookup();
    initCrudUpload();

});

/* =====================================================
   EMPRESA: LOOKUP NIT
===================================================== */

function nitDvFromDigits(digits) {
    const s = String(digits || "").replace(/\D/g, "");
    if (!s) return "0";
    const factors = [3, 7, 13, 17, 19, 23, 29, 37, 41, 43, 47, 53, 59, 67, 71];
    let sum = 0;
    for (let i = 0; i < s.length; i++) {
        sum += parseInt(s[s.length - 1 - i], 10) * factors[i % factors.length];
    }
    const r = sum % 11;
    return r < 2 ? String(r) : String(11 - r);
}

/* =====================================================
   UPLOAD (logo empresa, foto usuario, etc.)
===================================================== */

const CRUD_UPLOAD_MAX_BYTES = 2800000;
const CRUD_IMAGE_MAX_SIDE = { image: 1280, signature: 1600, default: 1280 };
const CRUD_CROP_OUTPUT_PX = 800;

function crudBlobToImage(blobOrFile) {
    return new Promise(function (resolve, reject) {
        const url = URL.createObjectURL(blobOrFile);
        const img = new Image();
        img.onload = function () {
            URL.revokeObjectURL(url);
            resolve(img);
        };
        img.onerror = function () {
            URL.revokeObjectURL(url);
            reject(new Error("No se pudo leer la imagen"));
        };
        img.src = url;
    });
}

function crudCanvasToJpegBlob(canvas, quality) {
    return new Promise(function (resolve) {
        canvas.toBlob(function (b) {
            resolve(b);
        }, "image/jpeg", quality);
    });
}

function crudResizeImageToCanvas(img, maxSide) {
    const iw = img.naturalWidth || img.width;
    const ih = img.naturalHeight || img.height;
    const scale = Math.min(1, maxSide / Math.max(iw, ih, 1));
    const w = Math.max(1, Math.round(iw * scale));
    const h = Math.max(1, Math.round(ih * scale));
    const canvas = document.createElement("canvas");
    canvas.width = w;
    canvas.height = h;
    const ctx = canvas.getContext("2d");
    if (ctx) {
        ctx.drawImage(img, 0, 0, w, h);
    }
    return canvas;
}

async function crudCompressCanvasToBlob(canvas, maxBytes) {
    let quality = 0.82;
    let blob = await crudCanvasToJpegBlob(canvas, quality);
    while (blob && blob.size > maxBytes && quality > 0.42) {
        quality -= 0.07;
        blob = await crudCanvasToJpegBlob(canvas, quality);
    }
    if (blob && blob.size <= maxBytes) {
        return blob;
    }
    let side = Math.max(canvas.width, canvas.height);
    const imgData = canvas.getContext("2d");
    if (!imgData) {
        return blob;
    }
    const tmp = document.createElement("canvas");
    const tctx = tmp.getContext("2d");
    while (blob && blob.size > maxBytes && side > 400) {
        side = Math.round(side * 0.85);
        const scale = side / Math.max(canvas.width, canvas.height);
        tmp.width = Math.max(1, Math.round(canvas.width * scale));
        tmp.height = Math.max(1, Math.round(canvas.height * scale));
        tctx.drawImage(canvas, 0, 0, tmp.width, tmp.height);
        blob = await crudCanvasToJpegBlob(tmp, 0.72);
    }
    return blob;
}

async function crudCompressImageForUpload(blobOrFile, subtype) {
    const maxSide = CRUD_IMAGE_MAX_SIDE[subtype] || CRUD_IMAGE_MAX_SIDE.default;
    if (!(blobOrFile instanceof Blob)) {
        return blobOrFile;
    }
    if (blobOrFile.size > 0 && blobOrFile.size <= CRUD_UPLOAD_MAX_BYTES && blobOrFile.type === "image/jpeg") {
        return blobOrFile;
    }
    const img = await crudBlobToImage(blobOrFile);
    const canvas = crudResizeImageToCanvas(img, maxSide);
    const out = await crudCompressCanvasToBlob(canvas, CRUD_UPLOAD_MAX_BYTES);
    return out || blobOrFile;
}

let crudImageReviewModalEl = null;
const CRUD_CROP_VIEW_PX = 280;

function crudEnsureImageReviewModal() {
    if (crudImageReviewModalEl && !crudImageReviewModalEl.querySelector(".crud-crop-stage")) {
        crudImageReviewModalEl.remove();
        crudImageReviewModalEl = null;
    }
    if (crudImageReviewModalEl) {
        return crudImageReviewModalEl;
    }
    const el = document.createElement("div");
    el.className = "crud-image-review-modal";
    el.hidden = true;
    el.innerHTML =
        '<div class="crud-image-review-dialog" role="dialog" aria-modal="true">' +
        '<p class="crud-image-review-title"></p>' +
        '<div class="crud-image-review-preview-wrap">' +
        '<div class="crud-crop-stage" data-crud-crop-view="' + CRUD_CROP_VIEW_PX + '">' +
        '<canvas class="crud-crop-canvas" width="' + CRUD_CROP_VIEW_PX + '" height="' + CRUD_CROP_VIEW_PX + '" aria-label="Vista previa"></canvas>' +
        '<div class="crud-crop-frame" aria-hidden="true"></div>' +
        "</div></div>" +
        '<label class="crud-image-review-zoom-label">' +
        "Acercar <input type=\"range\" class=\"crud-image-review-zoom\" min=\"100\" max=\"280\" value=\"100\">" +
        "</label>" +
        '<p class="crud-image-review-hint"></p>' +
        '<div class="crud-image-review-actions">' +
        '<button type="button" class="btn-crud-image-review-save">Usar foto</button>' +
        '<button type="button" class="btn-crud-image-review-cancel">Cancelar</button>' +
        "</div></div>";
    document.body.appendChild(el);
    crudImageReviewModalEl = el;
    return el;
}

/**
 * Estado de recorte: zoom + desplazamiento con arrastre.
 */
function crudCropLayout(img, zoomFactor, offsetX, offsetY, viewSize) {
    const iw = img.naturalWidth;
    const ih = img.naturalHeight;
    const cropSize = Math.min(iw, ih) / zoomFactor;
    const displayScale = viewSize / cropSize;
    let ox = viewSize / 2 - (iw / 2) * displayScale + offsetX;
    let oy = viewSize / 2 - (ih / 2) * displayScale + offsetY;
    let sx = -ox / displayScale;
    let sy = -oy / displayScale;
    const maxSx = Math.max(0, iw - cropSize);
    const maxSy = Math.max(0, ih - cropSize);

    if (sx < 0) {
        ox -= sx * displayScale;
        sx = 0;
    }
    if (sy < 0) {
        oy -= sy * displayScale;
        sy = 0;
    }
    if (sx > maxSx) {
        ox -= (sx - maxSx) * displayScale;
        sx = maxSx;
    }
    if (sy > maxSy) {
        oy -= (sy - maxSy) * displayScale;
        sy = maxSy;
    }

    return {
        iw: iw,
        ih: ih,
        cropSize: cropSize,
        displayScale: displayScale,
        offsetX: ox,
        offsetY: oy,
        sx: sx,
        sy: sy,
    };
}

function crudCropFocalFromOffsets(img, zoomFactor, offsetX, offsetY, viewSize) {
    const layout = crudCropLayout(img, zoomFactor, offsetX, offsetY, viewSize);
    return {
        cx: layout.sx + layout.cropSize / 2,
        cy: layout.sy + layout.cropSize / 2,
    };
}

function crudCropOffsetsFromFocal(img, zoomFactor, focal, viewSize) {
    const iw = img.naturalWidth;
    const ih = img.naturalHeight;
    const cropSize = Math.min(iw, ih) / zoomFactor;
    const displayScale = viewSize / cropSize;
    let sx = focal.cx - cropSize / 2;
    let sy = focal.cy - cropSize / 2;
    const maxSx = Math.max(0, iw - cropSize);
    const maxSy = Math.max(0, ih - cropSize);
    sx = Math.min(maxSx, Math.max(0, sx));
    sy = Math.min(maxSy, Math.max(0, sy));
    return {
        offsetX: -sx * displayScale - (viewSize / 2 - (iw / 2) * displayScale),
        offsetY: -sy * displayScale - (viewSize / 2 - (ih / 2) * displayScale),
    };
}

function crudCropPaint(canvas, ctx, img, layout) {
    const viewSize = canvas.width;
    ctx.fillStyle = "#111";
    ctx.fillRect(0, 0, viewSize, viewSize);
    ctx.drawImage(
        img,
        layout.offsetX,
        layout.offsetY,
        layout.iw * layout.displayScale,
        layout.ih * layout.displayScale
    );
}

function crudCropExportCanvas(img, layout, outSize) {
    const out = document.createElement("canvas");
    out.width = outSize;
    out.height = outSize;
    const octx = out.getContext("2d");
    if (octx) {
        octx.drawImage(
            img,
            layout.sx,
            layout.sy,
            layout.cropSize,
            layout.cropSize,
            0,
            0,
            outSize,
            outSize
        );
    }
    return out;
}

/**
 * @returns {{ destroy: function, getExportCanvas: function, setZoomSlider: function }}
 */
function crudCropEditorBind(stage, canvas, zoomEl, img) {
    const viewSize = parseInt(stage.getAttribute("data-crud-crop-view") || String(CRUD_CROP_VIEW_PX), 10) || CRUD_CROP_VIEW_PX;
    const ctx = canvas.getContext("2d");
    let zoomFactor = 1;
    let offsetX = 0;
    let offsetY = 0;
    let dragging = false;
    let dragStartX = 0;
    let dragStartY = 0;
    let dragBaseOffsetX = 0;
    let dragBaseOffsetY = 0;
    let activePointerId = null;

    function layout() {
        return crudCropLayout(img, zoomFactor, offsetX, offsetY, viewSize);
    }

    function syncOffsetsFromLayout(l) {
        offsetX = l.offsetX - (viewSize / 2 - (img.naturalWidth / 2) * l.displayScale);
        offsetY = l.offsetY - (viewSize / 2 - (img.naturalHeight / 2) * l.displayScale);
    }

    function repaint() {
        if (!ctx) return;
        crudCropPaint(canvas, ctx, img, layout());
    }

    function setZoomFromSlider() {
        const focal = crudCropFocalFromOffsets(img, zoomFactor, offsetX, offsetY, viewSize);
        zoomFactor = (parseInt(zoomEl.value, 10) || 100) / 100;
        const next = crudCropOffsetsFromFocal(img, zoomFactor, focal, viewSize);
        offsetX = next.offsetX;
        offsetY = next.offsetY;
        repaint();
    }

    function onPointerDown(ev) {
        if (ev.button !== undefined && ev.button !== 0) return;
        ev.preventDefault();
        dragging = true;
        activePointerId = ev.pointerId;
        dragStartX = ev.clientX;
        dragStartY = ev.clientY;
        dragBaseOffsetX = offsetX;
        dragBaseOffsetY = offsetY;
        stage.setPointerCapture(ev.pointerId);
        stage.classList.add("is-dragging");
    }

    function onPointerMove(ev) {
        if (!dragging || (activePointerId !== null && ev.pointerId !== activePointerId)) return;
        offsetX = dragBaseOffsetX + (ev.clientX - dragStartX);
        offsetY = dragBaseOffsetY + (ev.clientY - dragStartY);
        const l = layout();
        syncOffsetsFromLayout(l);
        repaint();
    }

    function onPointerUp(ev) {
        if (activePointerId !== null && ev.pointerId !== activePointerId) return;
        dragging = false;
        activePointerId = null;
        stage.classList.remove("is-dragging");
        try {
            stage.releasePointerCapture(ev.pointerId);
        } catch (e2) { /* noop */ }
    }

    function onWheel(ev) {
        ev.preventDefault();
        const focal = crudCropFocalFromOffsets(img, zoomFactor, offsetX, offsetY, viewSize);
        const delta = ev.deltaY > 0 ? -8 : 8;
        const nextVal = Math.min(280, Math.max(100, (parseInt(zoomEl.value, 10) || 100) + delta));
        zoomEl.value = String(nextVal);
        zoomFactor = nextVal / 100;
        const next = crudCropOffsetsFromFocal(img, zoomFactor, focal, viewSize);
        offsetX = next.offsetX;
        offsetY = next.offsetY;
        repaint();
    }

    stage.addEventListener("pointerdown", onPointerDown);
    stage.addEventListener("pointermove", onPointerMove);
    stage.addEventListener("pointerup", onPointerUp);
    stage.addEventListener("pointercancel", onPointerUp);
    stage.addEventListener("wheel", onWheel, { passive: false });
    zoomEl.addEventListener("input", setZoomFromSlider);

    repaint();

    return {
        destroy: function () {
            stage.removeEventListener("pointerdown", onPointerDown);
            stage.removeEventListener("pointermove", onPointerMove);
            stage.removeEventListener("pointerup", onPointerUp);
            stage.removeEventListener("pointercancel", onPointerUp);
            stage.removeEventListener("wheel", onWheel);
            zoomEl.removeEventListener("input", setZoomFromSlider);
            stage.classList.remove("is-dragging");
        },
        getExportCanvas: function () {
            const outSize = Math.min(CRUD_IMAGE_MAX_SIDE.image, CRUD_CROP_OUTPUT_PX);
            return crudCropExportCanvas(img, layout(), outSize);
        },
    };
}

/**
 * Vista previa, recorte cuadrado (foto) y compresión antes de subir.
 *
 * @returns {Promise<Blob|null>}
 */
function crudOpenImageReviewModal(blobOrFile, options) {
    const opts = options || {};
    const aspect = opts.aspect;
    const isCrop = aspect === 1;
    const title = opts.title || (isCrop ? "Ajustar foto" : "Vista previa");

    return crudBlobToImage(blobOrFile).then(function (img) {
        const modal = crudEnsureImageReviewModal();
        const titleEl = modal.querySelector(".crud-image-review-title");
        const stage = modal.querySelector(".crud-crop-stage");
        const canvas = modal.querySelector(".crud-crop-canvas");
        const frame = modal.querySelector(".crud-crop-frame");
        const zoomEl = modal.querySelector(".crud-image-review-zoom");
        const zoomLabel = modal.querySelector(".crud-image-review-zoom-label");
        const hintEl = modal.querySelector(".crud-image-review-hint");
        const btnSave = modal.querySelector(".btn-crud-image-review-save");
        const btnCancel = modal.querySelector(".btn-crud-image-review-cancel");

        if (!canvas || !btnSave || !btnCancel) {
            return crudCompressImageForUpload(blobOrFile, opts.subtype || "image");
        }

        const ctx = canvas.getContext("2d");
        if (!ctx) {
            return crudCompressImageForUpload(blobOrFile, opts.subtype || "image");
        }

        if (titleEl) {
            titleEl.textContent = title;
        }
        if (zoomLabel) {
            zoomLabel.hidden = !isCrop;
        }
        if (stage) {
            stage.classList.toggle("crud-crop-stage--pan", !!isCrop);
        }
        if (frame) {
            frame.hidden = !isCrop;
        }
        if (hintEl) {
            hintEl.textContent = isCrop
                ? "Arrastre la foto para encuadrar el rostro. Use «Acercar» o la rueda del mouse. Se comprime al guardar (máx. 3 MB)."
                : "La imagen se ajustará automáticamente al límite de 3 MB.";
        }

        return new Promise(function (resolve) {
            let settled = false;
            let cropEditor = null;

            function paintFitPreview() {
                const cw = canvas.width;
                const ch = canvas.height;
                ctx.fillStyle = "#111";
                ctx.fillRect(0, 0, cw, ch);
                const scale = Math.min(cw / img.naturalWidth, ch / img.naturalHeight);
                const dw = img.naturalWidth * scale;
                const dh = img.naturalHeight * scale;
                ctx.drawImage(img, (cw - dw) / 2, (ch - dh) / 2, dw, dh);
            }

            function cleanup() {
                if (settled) return;
                settled = true;
                if (cropEditor) {
                    cropEditor.destroy();
                    cropEditor = null;
                }
                modal.hidden = true;
                btnSave.removeEventListener("click", onSave);
                btnCancel.removeEventListener("click", onCancel);
                modal.removeEventListener("click", onBackdrop);
            }

            function onCancel() {
                cleanup();
                resolve(null);
            }

            function onBackdrop(ev) {
                if (ev.target === modal) {
                    onCancel();
                }
            }

            async function onSave() {
                let outCanvas;
                if (isCrop && cropEditor) {
                    outCanvas = cropEditor.getExportCanvas();
                } else {
                    const maxSide = CRUD_IMAGE_MAX_SIDE[opts.subtype] || CRUD_IMAGE_MAX_SIDE.default;
                    outCanvas = crudResizeImageToCanvas(img, maxSide);
                }
                const blob = await crudCompressCanvasToBlob(outCanvas, CRUD_UPLOAD_MAX_BYTES);
                cleanup();
                resolve(blob);
            }

            if (isCrop && stage && zoomEl) {
                zoomEl.value = "100";
                cropEditor = crudCropEditorBind(stage, canvas, zoomEl, img);
            } else {
                paintFitPreview();
            }

            btnSave.addEventListener("click", onSave);
            btnCancel.addEventListener("click", onCancel);
            modal.addEventListener("click", onBackdrop);
            modal.hidden = false;
        });
    });
}

async function crudPrepareImageForUpload(blobOrFile, subtype, source) {
    const st = (subtype || "").toLowerCase();
    if (st !== "image" && st !== "signature") {
        return blobOrFile;
    }
    if (st === "image") {
        const reviewed = await crudOpenImageReviewModal(blobOrFile, {
            aspect: 1,
            subtype: st,
            title: source === "camera" ? "Ajustar foto capturada" : "Ajustar foto",
        });
        if (!reviewed) {
            return null;
        }
        return reviewed;
    }
    return crudCompressImageForUpload(blobOrFile, st);
}

function crudPaintUploadPreview(wrap, previewEl, url) {
    previewEl.innerHTML = "";
    if (!url) {
        const ph = document.createElement("span");
        ph.className = "crud-upload-placeholder";
        ph.textContent = "Sin archivo";
        previewEl.appendChild(ph);
        return;
    }
    const subtype = (wrap.getAttribute("data-subtype") || "").toLowerCase();
    if (subtype === "image" || subtype === "signature") {
        const img = document.createElement("img");
        img.src = url;
        img.alt = "";
        img.className = "crud-upload-thumb";
        img.loading = "lazy";
        previewEl.appendChild(img);
    } else {
        const sp = document.createElement("span");
        sp.className = "crud-upload-filename";
        sp.textContent = url;
        previewEl.appendChild(sp);
    }
}

let crudCameraModalEl = null;
let crudCameraStream = null;

function crudStopCameraStream() {
    if (!crudCameraStream) return;
    crudCameraStream.getTracks().forEach(function (t) {
        t.stop();
    });
    crudCameraStream = null;
}

function crudEnsureCameraModal() {
    if (crudCameraModalEl) return crudCameraModalEl;

    const el = document.createElement("div");
    el.className = "crud-camera-modal";
    el.hidden = true;
    el.innerHTML =
        '<div class="crud-camera-dialog" role="dialog" aria-modal="true" aria-label="Tomar foto">' +
        '<video class="crud-camera-video" playsinline autoplay muted></video>' +
        '<div class="crud-camera-actions">' +
        '<button type="button" class="btn-crud-camera-capture">Capturar</button>' +
        '<button type="button" class="btn-crud-camera-cancel">Cancelar</button>' +
        '</div></div>';
    document.body.appendChild(el);
    crudCameraModalEl = el;
    return el;
}

/**
 * Abre cámara (getUserMedia) en escritorio/móvil; si no hay soporte, usa input capture.
 * @returns {Promise<boolean>} true si se abrió el modal de cámara
 */
function crudTryOpenCameraCapture(onBlob, onFallback) {
    const fallback = function () {
        if (typeof onFallback === "function") {
            onFallback();
        }
    };

    if (!navigator.mediaDevices || typeof navigator.mediaDevices.getUserMedia !== "function") {
        fallback();
        return Promise.resolve(false);
    }

    const modal = crudEnsureCameraModal();
    const video = modal.querySelector(".crud-camera-video");
    const btnCap = modal.querySelector(".btn-crud-camera-capture");
    const btnCancel = modal.querySelector(".btn-crud-camera-cancel");

    if (!video || !btnCap || !btnCancel) {
        fallback();
        return Promise.resolve(false);
    }

    return navigator.mediaDevices
        .getUserMedia({ video: { facingMode: "user" }, audio: false })
        .then(function (stream) {
            crudCameraStream = stream;
            video.srcObject = stream;
            modal.hidden = false;

            const playPromise = video.play();
            if (playPromise && typeof playPromise.catch === "function") {
                playPromise.catch(function () { /* autoplay bloqueado: el usuario puede capturar igual */ });
            }

            return new Promise(function (resolve) {
                let settled = false;

                function finish(opened) {
                    if (settled) return;
                    settled = true;
                    modal.hidden = true;
                    crudStopCameraStream();
                    video.srcObject = null;
                    btnCap.removeEventListener("click", onCapture);
                    btnCancel.removeEventListener("click", onCancel);
                    modal.removeEventListener("click", onBackdrop);
                    resolve(opened);
                }

                function onCancel() {
                    finish(true);
                }

                function onBackdrop(ev) {
                    if (ev.target === modal) onCancel();
                }

                function onCapture() {
                    const w = video.videoWidth;
                    const h = video.videoHeight;
                    if (!w || !h) {
                        alert("Espere a que la cámara esté lista e intente de nuevo.");
                        return;
                    }
                    const maxCap = 1920;
                    const scale = Math.min(1, maxCap / Math.max(w, h));
                    const cw = Math.max(1, Math.round(w * scale));
                    const ch = Math.max(1, Math.round(h * scale));
                    const canvas = document.createElement("canvas");
                    canvas.width = cw;
                    canvas.height = ch;
                    const ctx = canvas.getContext("2d");
                    if (!ctx) {
                        finish(true);
                        return;
                    }
                    ctx.drawImage(video, 0, 0, w, h, 0, 0, cw, ch);
                    canvas.toBlob(
                        function (blob) {
                            if (blob) onBlob(blob);
                            finish(true);
                        },
                        "image/jpeg",
                        0.85
                    );
                }

                btnCap.addEventListener("click", onCapture);
                btnCancel.addEventListener("click", onCancel);
                modal.addEventListener("click", onBackdrop);
            });
        })
        .catch(function () {
            crudStopCameraStream();
            fallback();
            return false;
        });
}

function crudParseUploadResponse(text, status) {
    const trimmed = (text || "").trim();
    if (trimmed) {
        const jsonStart = trimmed.indexOf("{");
        if (jsonStart >= 0) {
            try {
                return JSON.parse(trimmed.slice(jsonStart));
            } catch (e2) { /* continuar */ }
        }
    }
    if (status === 413 || /upload_max|post_max|too large|demasiado grande/i.test(trimmed)) {
        throw new Error("La imagen supera el límite del servidor (3 MB). Use «Usar foto» tras ajustar el encuadre.");
    }
    throw new Error(
        "Respuesta no válida del servidor. Si la foto es muy pesada, confirme con «Usar foto» en la vista de ajuste."
    );
}

async function crudPostUpload(endpoint, formData) {
    let fetchUrl = String(endpoint || "").trim();
    try {
        fetchUrl = new URL(fetchUrl, window.location.href).href;
    } catch (e) { /* usar tal cual */ }

    const r = await fetch(fetchUrl, {
        method: "POST",
        body: formData,
        credentials: "same-origin",
    });
    const text = await r.text();
    const j = crudParseUploadResponse(text, r.status);
    if (!r.ok && j && j.error) {
        throw new Error(j.error);
    }
    return j;
}

function crudBindUploadWrap(wrap) {
    if (!wrap || wrap.dataset.crudUploadBound === "1") return;

    const endpoint = wrap.getAttribute("data-upload-endpoint") || "?url=usuario/uploadAsset";
    const pathInput = wrap.querySelector(".crud-upload-path");
    const preview = wrap.querySelector(".crud-upload-preview");
    const fileEl = wrap.querySelector(".crud-upload-input-hidden");
    const btnFile = wrap.querySelector(".btn-crud-upload-file");
    const btnCam = wrap.querySelector(".btn-crud-upload-camera");
    const btnClr = wrap.querySelector(".btn-crud-upload-clear");

    if (!pathInput || !fileEl || !preview) return;

    wrap.dataset.crudUploadBound = "1";

    function renderPreview(url) {
        crudPaintUploadPreview(wrap, preview, url);
    }

    const subtype = (wrap.getAttribute("data-subtype") || "").toLowerCase();

    function pickFile(capture) {
        fileEl.value = "";
        fileEl.removeAttribute("capture");
        if (capture === "user") {
            fileEl.setAttribute("capture", "user");
        } else if (capture === "environment") {
            fileEl.setAttribute("capture", "environment");
        }
        try {
            fileEl.click();
        } catch (e) {
            alert("No se pudo abrir el selector de archivos o la cámara del dispositivo.");
        }
    }

    async function uploadFileBlob(blob, filename) {
        if (!blob) {
            return;
        }
        const fd = new FormData();
        const name = filename || "captura.jpg";
        const safeName = /\.jpe?g$/i.test(name) ? name.replace(/\.(jpeg|jpg)$/i, ".jpg") : name.replace(/\.[^.]+$/, "") + ".jpg";
        fd.append("archivo", blob, safeName);
        const j = await crudPostUpload(endpoint, fd);
        if (!j || !j.ok || !j.path) {
            alert((j && j.error) || "No se pudo subir el archivo");
            return;
        }
        pathInput.value = j.path;
        renderPreview(j.path);
    }

    async function processAndUpload(blobOrFile, source) {
        const st = subtype;
        let prepared = blobOrFile;
        if (st === "image" || st === "signature") {
            prepared = await crudPrepareImageForUpload(blobOrFile, st, source);
            if (!prepared) {
                return;
            }
        }
        const fname = blobOrFile instanceof File ? blobOrFile.name : "captura.jpg";
        await uploadFileBlob(prepared, fname);
    }

    fileEl.addEventListener("change", async function () {
        const f = fileEl.files && fileEl.files[0];
        if (!f) return;
        try {
            await processAndUpload(f, "file");
        } catch (e) {
            alert(e && e.message ? e.message : "Error al subir el archivo");
        } finally {
            fileEl.value = "";
            fileEl.removeAttribute("capture");
        }
    });

    pathInput.addEventListener("change", function () {
        renderPreview(pathInput.value || "");
    });

    if (btnFile) {
        btnFile.addEventListener("click", function () {
            pickFile(null);
        });
    }
    if (btnCam) {
        btnCam.addEventListener("click", function (ev) {
            ev.preventDefault();
            ev.stopPropagation();
            if (subtype !== "image") {
                pickFile(null);
                return;
            }
            const onCaptureBlob = function (blob) {
                processAndUpload(blob, "camera").catch(function (e) {
                    alert(e && e.message ? e.message : "Error al subir la foto");
                });
            };
            const onCameraFallback = function () {
                pickFile("user");
            };
            crudTryOpenCameraCapture(onCaptureBlob, onCameraFallback);
        });
    }
    if (btnClr) {
        btnClr.addEventListener("click", function () {
            pathInput.value = "";
            renderPreview("");
        });
    }

    renderPreview(pathInput.value || "");
}

function initCrudUpload(root) {
    if (root && root.classList && root.classList.contains("crud-upload-wrap")) {
        crudBindUploadWrap(root);
        return;
    }
    const parent = root && root.querySelectorAll ? root : document;
    const selector = root
        ? ".crud-upload-wrap"
        : "form[data-crud-context] .crud-upload-wrap";
    parent.querySelectorAll(selector).forEach(crudBindUploadWrap);
}

window.crudInitUpload = initCrudUpload;
window.crudBindUploadWrap = crudBindUploadWrap;

function crudRefreshUploadPreviews(form) {
    if (!form) return;
    form.querySelectorAll(".crud-upload-wrap").forEach(function (wrap) {
        const pathInput = wrap.querySelector(".crud-upload-path");
        const preview = wrap.querySelector(".crud-upload-preview");
        if (pathInput && preview) {
            crudPaintUploadPreview(wrap, preview, pathInput.value || "");
        }
    });
}

function initCrudEmpresaNitLookup() {
    const form = document.querySelector('form[data-crud-context="empresa"]');
    if (!form) return;

    const nitInput = form.querySelector('[name="nit"]');
    const dvInput = form.querySelector('[name="documento_dv"]');
    if (!nitInput) return;

    function syncDv() {
        if (dvInput) dvInput.value = nitDvFromDigits(nitInput.value);
    }

    nitInput.addEventListener("input", syncDv);
    syncDv();

    nitInput.addEventListener("blur", function () {
        syncDv();
        const nit = String(nitInput.value || "").trim();
        if (nit === "") return;

        const idEl = form.querySelector('[name="id"]');
        const currentEmpresaId = idEl ? String(idEl.value || "").trim() : "";

        const url = new URL(window.location.origin + window.location.pathname);
        url.searchParams.set("url", "empresa/lookupNit");
        url.searchParams.set("nit", nit);
        if (currentEmpresaId !== "") {
            url.searchParams.set("empresa_id", currentEmpresaId);
        }

        fetch(url.toString(), { credentials: "same-origin" })
            .then(r => r.json())
            .then(data => {
                if (!data || !data.status) return;

                if (data.status === "empresa_exists") {
                    alert("⚠ " + (data.message || "Ya existe una empresa con este NIT."));
                    nitInput.value = "";
                    nitInput.focus();
                    return;
                }

                if (data.status === "inactive_tercero") {
                    alert("⚠ " + data.message);
                    return;
                }

                if (data.status === "tercero_only" || data.status === "same_empresa") {
                    const t = data.tercero || {};
                    const tIdEl = form.querySelector('[name="tercero_id"]');
                    if (tIdEl && data.tercero_id) tIdEl.value = data.tercero_id;
                    const iIdEl = form.querySelector('[name="terceroidentificacion_id"]');
                    if (iIdEl && data.terceroidentificacion_id) iIdEl.value = data.terceroidentificacion_id;

                    const map = {
                        razon_social: t.razon_social || "",
                        email: t.email || "",
                        telefono: t.telefono || "",
                        celular: t.celular || "",
                        direccion: t.direccion || "",
                        pais_id: t.pais_id || "",
                        departamento_id: t.departamento_id || "",
                        municipio_id: t.municipio_id || "",
                        zona_id: t.zona_id || "",
                        comuna_id: t.comuna_id || "",
                        corregimiento_id: t.corregimiento_id || "",
                        barrio_id: t.barrio_id || "",
                        vereda_id: t.vereda_id || "",
                        documento_dv: t.documento_dv || "",
                    };
                    Object.keys(map).forEach(key => {
                        const el = form.querySelector('[name="' + key + '"]');
                        if (!el) return;
                        if (String(el.value || "").trim() === "") {
                            el.value = map[key];
                            crudNormalizeUppercaseField(el);
                            if (el.classList.contains("crud-catalog-id")) {
                                el.dispatchEvent(new Event("change", { bubbles: true }));
                            }
                        }
                    });
                    const zf = document.querySelector('form[data-crud-zona-ubicacion-toggle]');
                    if (zf) crudZonaUbicacionApplyFromForm(zf);

                    if (data.status === "tercero_only" && data.message) {
                        showCrudFlashMessage(data.message);
                    }
                }
            })
            .catch(() => { /* silencioso */ });
    });
}

function initCrudEmpresaRepresentanteLookup() {
    const form = document.querySelector('form[data-crud-context="empresa"]');
    if (!form) return;

    const wrap = form.querySelector(".crud-rep-lookup-wrap");
    const searchEl = wrap && wrap.querySelector(".crud-rep-lookup-search");
    const dd = wrap && wrap.querySelector(".crud-catalog-dropdown");
    const tipoEl = form.querySelector('[name="rep_tipodocumento_id"]');
    const numEl = form.querySelector('[name="rep_numero_documento"]');
    const nombresEl = form.querySelector('[name="rep_nombres"]');
    const apellidosEl = form.querySelector('[name="rep_apellidos"]');
    const hidRep = form.querySelector('[name="representante_terceroidentificacion_id"]');

    function applyRepresentante(data) {
        if (!data) return;
        if (hidRep && data.representante_terceroidentificacion_id) {
            hidRep.value = String(data.representante_terceroidentificacion_id);
        }
        if (tipoEl && data.rep_tipodocumento_id) {
            tipoEl.value = String(data.rep_tipodocumento_id);
            tipoEl.dispatchEvent(new Event("change", { bubbles: true }));
        }
        if (numEl && data.rep_numero_documento != null) {
            numEl.value = String(data.rep_numero_documento);
            crudNormalizeUppercaseField(numEl);
        }
        if (nombresEl && data.rep_nombres != null) {
            nombresEl.value = String(data.rep_nombres);
            crudNormalizeUppercaseField(nombresEl);
        }
        if (apellidosEl && data.rep_apellidos != null) {
            apellidosEl.value = String(data.rep_apellidos);
            crudNormalizeUppercaseField(apellidosEl);
        }
        if (searchEl && data.label) {
            searchEl.value = String(data.label);
        }
    }

    function lookupRepDocumento() {
        if (!tipoEl || !numEl) return;
        const tipoId = String(tipoEl.value || "").trim();
        const numero = String(numEl.value || "").trim();
        if (!tipoId || !numero) return;

        const url = new URL(window.location.origin + window.location.pathname);
        url.searchParams.set("url", "empresa/lookupRepresentante");
        url.searchParams.set("tipodocumento_id", tipoId);
        url.searchParams.set("numero_documento", numero);

        fetch(url.toString(), { credentials: "same-origin" })
            .then(r => r.json())
            .then(data => {
                if (!data || !data.status) return;
                if (data.status === "none") return;
                if (data.blocked || data.status === "blocked" || data.status === "inactive_tercero") {
                    alert("⚠ " + (data.message || "No se puede usar este documento."));
                    return;
                }
                if (data.status === "found") {
                    applyRepresentante({
                        representante_terceroidentificacion_id: data.representante_terceroidentificacion_id,
                        rep_tipodocumento_id: data.rep_tipodocumento_id,
                        rep_numero_documento: data.rep_numero_documento,
                        rep_nombres: data.rep_nombres,
                        rep_apellidos: data.rep_apellidos,
                        label: numero,
                    });
                    showCrudFlashMessage("Representante legal cargado desde el catálogo de terceros.");
                }
            })
            .catch(() => { /* silencioso */ });
    }

    if (numEl) {
        numEl.addEventListener("blur", lookupRepDocumento);
    }

    if (!searchEl || !dd) return;

    let activeIdx = -1;

    function getSelectableItems() {
        return Array.prototype.slice.call(dd.querySelectorAll("li:not(.crud-catalog-hint)"));
    }

    function applyChoice(li) {
        if (!li || li.classList.contains("crud-catalog-hint")) return;
        applyRepresentante({
            representante_terceroidentificacion_id: li.getAttribute("data-rep-ident-id"),
            rep_tipodocumento_id: li.getAttribute("data-rep-tipo-id"),
            rep_numero_documento: li.getAttribute("data-rep-numero"),
            rep_nombres: li.getAttribute("data-rep-nombres"),
            rep_apellidos: li.getAttribute("data-rep-apellidos"),
            label: li.getAttribute("data-rep-label") || li.textContent,
        });
        dd.hidden = true;
        dd.innerHTML = "";
        activeIdx = -1;
        showCrudFlashMessage("Representante legal seleccionado.");
    }

    const doFetch = function () {
        activeIdx = -1;
        const q = searchEl.value.trim();
        if (q.length < 2) {
            dd.hidden = true;
            dd.innerHTML = "";
            return;
        }

        const url = new URL(window.location.origin + window.location.pathname);
        url.searchParams.set("url", "empresa/searchRepresentante");
        url.searchParams.set("q", q);

        fetch(url.toString(), { credentials: "same-origin" })
            .then(r => r.json())
            .then(data => {
                dd.innerHTML = "";
                if (!data || !data.ok || !Array.isArray(data.items) || data.items.length === 0) {
                    dd.hidden = false;
                    const li = document.createElement("li");
                    li.className = "crud-catalog-hint";
                    li.textContent = "Sin resultados.";
                    dd.appendChild(li);
                    return;
                }
                data.items.forEach(function (it) {
                    const li = document.createElement("li");
                    li.setAttribute("role", "option");
                    li.setAttribute("data-rep-ident-id", String(it.identificacion_id));
                    li.setAttribute("data-rep-tipo-id", String(it.tipodocumento_id || ""));
                    li.setAttribute("data-rep-numero", String(it.numero || ""));
                    li.setAttribute("data-rep-nombres", String(it.nombres || ""));
                    li.setAttribute("data-rep-apellidos", String(it.apellidos || ""));
                    li.setAttribute("data-rep-label", String(it.label || ""));
                    li.textContent = it.label || ("#" + it.identificacion_id);
                    li.addEventListener("mousedown", function (ev) {
                        ev.preventDefault();
                        applyChoice(li);
                    });
                    dd.appendChild(li);
                });
                dd.hidden = false;
            })
            .catch(function () {
                dd.innerHTML = "";
                dd.hidden = false;
                const li = document.createElement("li");
                li.className = "crud-catalog-hint";
                li.textContent = "Error de conexión.";
                dd.appendChild(li);
            });
    };

    const debounced = debounceCrud(doFetch, 280);
    searchEl.addEventListener("input", debounced);
    searchEl.addEventListener("focus", doFetch);

    searchEl.addEventListener("keydown", function (ev) {
        const items = getSelectableItems();
        if (ev.key === "Escape") {
            dd.hidden = true;
            activeIdx = -1;
            return;
        }
        if (!items.length) return;
        if (ev.key === "ArrowDown") {
            ev.preventDefault();
            activeIdx = activeIdx < items.length - 1 ? activeIdx + 1 : 0;
            items.forEach((li, i) => li.classList.toggle("crud-catalog-option-active", i === activeIdx));
            if (items[activeIdx]) items[activeIdx].scrollIntoView({ block: "nearest" });
        } else if (ev.key === "ArrowUp") {
            ev.preventDefault();
            activeIdx = activeIdx > 0 ? activeIdx - 1 : items.length - 1;
            items.forEach((li, i) => li.classList.toggle("crud-catalog-option-active", i === activeIdx));
            if (items[activeIdx]) items[activeIdx].scrollIntoView({ block: "nearest" });
        } else if (ev.key === "Enter" && activeIdx >= 0) {
            ev.preventDefault();
            applyChoice(items[activeIdx]);
        }
    });

    if (!window.__savidCrudRepLookupOutside) {
        window.__savidCrudRepLookupOutside = true;
        document.addEventListener("click", function (ev) {
            if (wrap && !wrap.contains(ev.target)) {
                dd.hidden = true;
            }
        });
    }
}

function showCrudFlashMessage(msg) {
    let div = document.getElementById("crudFlashMessage");
    if (!div) {
        div = document.createElement("div");
        div.id = "crudFlashMessage";
        div.style.cssText =
            "position:fixed; top:16px; right:16px; z-index:9999; max-width:340px; " +
            "padding:10px 14px; border-radius:8px; background:#fff8e1; border:1px solid #ffd54f; " +
            "color:#5d4037; box-shadow:0 6px 24px rgba(0,0,0,0.15); font-size:13px;";
        document.body.appendChild(div);
    }
    div.textContent = msg;
    div.style.display = "block";
    clearTimeout(div.__hideTimer);
    div.__hideTimer = setTimeout(() => { div.style.display = "none"; }, 6500);
}

/* =====================================================
   CLICK FILAS
===================================================== */

function crudSelectHasOption(sel, value) {
    const v = String(value);
    return Array.prototype.some.call(sel.options, function (opt) {
        return String(opt.value) === v;
    });
}

function crudSetSelectValueSafely(sel, value) {
    if (!sel || sel.tagName !== "SELECT") return;
    if (value === null || value === undefined || value === "") {
        return;
    }
    const v = String(value);
    if (crudSelectHasOption(sel, v)) {
        sel.value = v;
    }
}

function crudSetFieldValue(form, field, value, displayLabel) {
    const checkboxInput = form.querySelector('input[type="checkbox"][name="' + field + '"]');
    if (checkboxInput) {
        const checkedVal = value === null || value === undefined ? "" : String(value);
        checkboxInput.checked = checkedVal !== "" && checkedVal !== "0";
        checkboxInput.dispatchEvent(new Event("change", { bubbles: true }));
        return;
    }

    const input = form.querySelector('[name="' + field + '"]');
    if (!input) return;

    if (input.type === "password" || input.dataset.password === "1") {
        input.value = "";
        return;
    }

    const val = value === null || value === undefined ? "" : String(value);

    if (input.tagName === "SELECT") {
        crudSetSelectValueSafely(input, val);
        input.dispatchEvent(new Event("change", { bubbles: true }));
        return;
    }

    if (input.classList.contains("crud-catalog-id")) {
        input.value = val;
        if (field === "zona_id" && val !== "") {
            const zOpt = form.querySelector('select[name="zona_id"] option[value="' + val + '"]');
            const zt = zOpt && zOpt.getAttribute("data-zona-tipo");
            if (zt) {
                input.dataset.zonaTipo = zt;
            } else {
                delete input.dataset.zonaTipo;
            }
        }
        const wrap = input.closest(".crud-catalog-wrap");
        const searchEl = wrap && wrap.querySelector(".crud-catalog-search");
        if (searchEl) {
            searchEl.value = displayLabel !== undefined && displayLabel !== null
                ? String(displayLabel)
                : "";
            crudNormalizeUppercaseField(searchEl);
        }
        input.dispatchEvent(new Event("change", { bubbles: true }));
        return;
    }

    if (input.type === "file") {
        return;
    }

    input.value = val;
    crudNormalizeUppercaseField(input);

    const uploadPath = input.closest(".crud-upload-wrap");
    if (uploadPath) {
        const preview = uploadPath.querySelector(".crud-upload-preview");
        if (preview) {
            crudPaintUploadPreview(uploadPath, preview, val);
        }
    }
}

function crudFillFormFromEmpresaRow(row, form) {
    const raw = row.getAttribute("data-row-json");
    if (!raw) return false;

    let data;
    try {
        data = JSON.parse(raw);
    } catch (e) {
        return false;
    }
    if (!data || typeof data !== "object") return false;

    const trTerceroId = row.getAttribute("data-tercero-id");
    if (trTerceroId !== null) {
        crudSetFieldValue(form, "tercero_id", trTerceroId, null);
    }
    const trIdentId = row.getAttribute("data-terceroidentificacion-id");
    if (trIdentId !== null) {
        crudSetFieldValue(form, "terceroidentificacion_id", trIdentId, null);
    }

    const fkLabels = {};
    row.querySelectorAll("td[data-field]").forEach(function (cell) {
        fkLabels[cell.dataset.field] = cell.innerText.trim();
    });
    const mapGlobal = window.__CRUD_EMPRESA_FK_LABELS || {};

    Object.keys(data).forEach(function (field) {
        if (field === "id") return;
        let label;
        if (Object.prototype.hasOwnProperty.call(fkLabels, field)) {
            label = fkLabels[field];
        } else if (mapGlobal[field] && data[field] !== null && data[field] !== "") {
            label = mapGlobal[field][String(data[field])] || "";
        }
        crudSetFieldValue(form, field, data[field], label);
    });

    const repId = data.representante_terceroidentificacion_id;
    if (repId !== undefined && repId !== null && repId !== "") {
        crudSetFieldValue(form, "representante_terceroidentificacion_id", repId, null);
    }
    const repSearch = form.querySelector(".crud-rep-lookup-search");
    if (repSearch) {
        const repLabel = [
            data.rep_numero_documento,
            [data.rep_nombres, data.rep_apellidos].filter(Boolean).join(" ").trim(),
        ].filter(Boolean).join(" · ");
        if (repLabel) repSearch.value = repLabel;
    }

    const zf = form.matches("[data-crud-zona-ubicacion-toggle]")
        ? form
        : document.querySelector('form[data-crud-zona-ubicacion-toggle]');
    if (zf) {
        crudZonaUbicacionApplyFromForm(zf);
    }

    crudRefreshUploadPreviews(form);

    return true;
}

function crudFillFormFromUsuarioRow(row, form) {
    const raw = row.getAttribute("data-row-json");
    if (!raw) return false;

    let data;
    try {
        data = JSON.parse(raw);
    } catch (e) {
        return false;
    }
    if (!data || typeof data !== "object") return false;

    const trTerceroId = row.getAttribute("data-tercero-id");
    if (trTerceroId !== null) {
        crudSetFieldValue(form, "tercero_id", trTerceroId, null);
    }
    const trIdentId = row.getAttribute("data-terceroidentificacion-id");
    if (trIdentId !== null) {
        crudSetFieldValue(form, "terceroidentificacion_id", trIdentId, null);
    }

    const fkLabels = {};
    row.querySelectorAll("td[data-field]").forEach(function (cell) {
        fkLabels[cell.dataset.field] = cell.innerText.trim();
    });

    Object.keys(data).forEach(function (field) {
        if (field === "id") return;
        let label;
        if (Object.prototype.hasOwnProperty.call(fkLabels, field)) {
            label = fkLabels[field];
        }
        crudSetFieldValue(form, field, data[field], label);
    });

    const hid = form.querySelector("#crud_id") || form.querySelector('[name="id"]');
    if (hid && data.id != null && data.id !== "") {
        hid.value = String(data.id);
    }

    crudRefreshUploadPreviews(form);
    return true;
}

function handleCrudRowClick(row) {

    document.querySelectorAll(".crud-row.selected").forEach(function (r) {
        r.classList.remove("selected");
    });

    row.classList.add("selected");

    selectedRow = row;
    selectedId = row.dataset.id || "";

    const form = document.querySelector("form[data-crud-context]");
    const hiddenId = form
        ? form.querySelector("#crud_id") || form.querySelector('[name="id"]')
        : document.getElementById("crud_id");
    if (hiddenId) hiddenId.value = selectedId;

    if (!form) return;

    const ctx = form.getAttribute("data-crud-context") || "";

    if (ctx === "empresa" && crudFillFormFromEmpresaRow(row, form)) {
        return;
    }

    if (ctx === "usuario" && crudFillFormFromUsuarioRow(row, form)) {
        document.dispatchEvent(
            new CustomEvent("crud-usuario-row-filled", { detail: { row: row, form: form } })
        );
        return;
    }

    row.querySelectorAll("td[data-field]").forEach(function (cell) {

        const field = cell.dataset.field;
        const displayLabel = cell.innerText.trim();
        const value = cell.dataset.value ?? displayLabel;

        crudSetFieldValue(form, field, value, displayLabel);

    });

    const zf = document.querySelector("form[data-crud-zona-ubicacion-toggle]");
    if (zf) {
        crudZonaUbicacionApplyFromForm(zf);
    }
}

function initCrudRows() {

    document.querySelectorAll(".crud-table").forEach(function (wrap) {
        if (wrap.dataset.crudRowsBound === "1") {
            return;
        }
        wrap.dataset.crudRowsBound = "1";
        wrap.addEventListener("click", function (e) {
            const row = e.target.closest("tr.crud-row");
            if (!row || !wrap.contains(row)) {
                return;
            }
            handleCrudRowClick(row);
        });
    });

}

/* =====================================================
   BOTON NUEVO / LIMPIAR
===================================================== */

function initCrudNuevo() {

    const btn = document.getElementById("btnNuevo");

    if (!btn) return;

    btn.addEventListener("click", function () {

        document.querySelectorAll(".form-input").forEach(input => {

            if (input.tagName === "SELECT") {
                input.selectedIndex = 0;
            } else {
                input.value = "";
            }

            input.classList.remove("input-error");
            input.style.border = "";

        });

        document.querySelectorAll(".form-checkbox").forEach(input => {
            input.checked = false;
            input.classList.remove("input-error");
        });

        document.querySelectorAll(".crud-catalog-wrap").forEach((wrap) => {
            const hid = wrap.querySelector(".crud-catalog-id");
            const searchEl = wrap.querySelector(".crud-catalog-search");
            const dd = wrap.querySelector(".crud-catalog-dropdown");
            if (hid) {
                hid.value = "";
                if (hid.name === "zona_id") {
                    delete hid.dataset.zonaTipo;
                }
            }
            if (searchEl) searchEl.value = "";
            if (dd) {
                dd.hidden = true;
                dd.innerHTML = "";
            }
        });

        document.querySelectorAll(".error-text").forEach(e => {
            e.innerHTML = "";
            e.style.display = "none";
        });

        document.querySelectorAll(".crud-row").forEach(r => {
            r.classList.remove("selected");
        });

        const hiddenId = document.getElementById("crud_id");
        if (hiddenId) hiddenId.value = "";

        const usuarioForm = document.querySelector('form[data-crud-context="usuario"]');
        if (usuarioForm) {
            const tEl = usuarioForm.querySelector('[name="tercero_id"]');
            if (tEl) tEl.value = "";
            const iEl = usuarioForm.querySelector('[name="terceroidentificacion_id"]');
            if (iEl) iEl.value = "";
            const ovEl = usuarioForm.querySelector('#usuario_email_overwrite_ok');
            if (ovEl) ovEl.value = "0";
            const acEl = usuarioForm.querySelector('#usuario_identificacion_accion');
            if (acEl) acEl.value = "update_principal";
            try {
                sessionStorage.removeItem("savid_usuario_crud_form_draft_v1");
            } catch (e) {
                /* noop */
            }
        }

        const empresaForm = document.querySelector('form[data-crud-context="empresa"]');
        if (empresaForm) {
            const tEl = empresaForm.querySelector('[name="tercero_id"]');
            if (tEl) tEl.value = "";
            const iEl = empresaForm.querySelector('[name="terceroidentificacion_id"]');
            if (iEl) iEl.value = "";
        }

        selectedRow = null;
        selectedId = null;

        crudZonaUbicacionApplyFromForm(document.querySelector("form[data-crud-zona-ubicacion-toggle]"));

        const ctxForm = document.querySelector("form[data-crud-context]");
        if (ctxForm) {
            crudRefreshUploadPreviews(ctxForm);
        }

    });

}

function crudToggleRequiredInGroup(groupEl, enable) {
    if (!groupEl) return;
    if (enable) {
        groupEl.querySelectorAll("input, select, textarea").forEach(function (el) {
            if (el.dataset.crudSavedRequired === "1") {
                el.setAttribute("required", "required");
                delete el.dataset.crudSavedRequired;
            }
        });
    } else {
        groupEl.querySelectorAll("input[required], select[required], textarea[required]").forEach(function (el) {
            el.dataset.crudSavedRequired = "1";
            el.removeAttribute("required");
        });
    }
}

function crudClearInputsInGroup(groupEl) {
    if (!groupEl) return;
    groupEl.querySelectorAll("select.form-input").forEach(function (sel) {
        sel.selectedIndex = 0;
        sel.classList.remove("input-error");
    });
    groupEl.querySelectorAll(".crud-catalog-wrap").forEach(function (wrap) {
        const hid = wrap.querySelector(".crud-catalog-id");
        const searchEl = wrap.querySelector(".crud-catalog-search");
        const dd = wrap.querySelector(".crud-catalog-dropdown");
        if (hid) {
            hid.value = "";
            hid.classList.remove("input-error");
        }
        if (searchEl) {
            searchEl.value = "";
            searchEl.classList.remove("input-error");
        }
        if (dd) {
            dd.hidden = true;
            dd.innerHTML = "";
        }
    });
    groupEl.querySelectorAll("input.form-input:not(.crud-catalog-search)").forEach(function (inp) {
        if (inp.type === "password" || inp.dataset.password === "1") return;
        inp.value = "";
        inp.classList.remove("input-error");
    });
}

function crudGetZonaTipoFromForm(form) {
    if (!form) return "";
    const sel = form.querySelector('select[name="zona_id"]');
    if (sel) {
        const o = sel.options[sel.selectedIndex];
        if (o && o.dataset.zonaTipo) {
            return String(o.dataset.zonaTipo).toLowerCase();
        }
        return "";
    }
    const hid = form.querySelector('.crud-catalog-wrap[data-crud-zona-master] .crud-catalog-id[name="zona_id"]');
    if (hid && hid.dataset.zonaTipo) {
        return String(hid.dataset.zonaTipo).toLowerCase();
    }
    return "";
}

function crudZonaUbicacionApplyFromForm(form) {
    if (!form) return;
    if (!form.querySelector(".crud-zona-urban") && !form.querySelector(".crud-zona-rural")) return;
    const tipo = crudGetZonaTipoFromForm(form);
    const isRural = tipo === "rural";
    form.querySelectorAll(".crud-zona-urban").forEach(function (g) {
        const hide = isRural;
        g.classList.toggle("crud-zona-hidden", hide);
        crudToggleRequiredInGroup(g, !hide);
        if (hide) crudClearInputsInGroup(g);
    });
    form.querySelectorAll(".crud-zona-rural").forEach(function (g) {
        const hide = !isRural;
        g.classList.toggle("crud-zona-hidden", hide);
        crudToggleRequiredInGroup(g, !hide);
        if (hide) crudClearInputsInGroup(g);
    });
}

function initCrudZonaUbicacionToggle() {
    const form = document.querySelector("form[data-crud-zona-ubicacion-toggle]");
    if (!form) return;
    if (!form.querySelector(".crud-zona-urban") && !form.querySelector(".crud-zona-rural")) return;
    form.addEventListener("change", function (ev) {
        const t = ev.target;
        if (t && t.getAttribute && t.getAttribute("name") === "zona_id") {
            crudZonaUbicacionApplyFromForm(form);
        }
    });
    crudZonaUbicacionApplyFromForm(form);
}

/* =====================================================
   BUSQUEDA CRUD
===================================================== */

function debounceCrud(fn, ms) {
    let t = null;
    return function () {
        const args = arguments;
        const self = this;
        clearTimeout(t);
        t = setTimeout(function () {
            fn.apply(self, args);
        }, ms);
    };
}

function initCrudCatalog() {
    const form = document.querySelector("form[data-crud-context]");
    if (!form) return;

    const ctx = (form.getAttribute("data-crud-context") || "").trim();
    if (!ctx) return;

    const wraps = Array.prototype.slice.call(document.querySelectorAll(".crud-catalog-wrap"));
    if (!wraps.length) return;

    const parentToWraps = new Map();

    wraps.forEach(function (wrap) {
        let parents = [];
        try {
            parents = JSON.parse(wrap.getAttribute("data-parent-fields") || "[]");
        } catch (e) {
            parents = [];
        }
        if (!Array.isArray(parents)) parents = [];
        parents.forEach(function (p) {
            if (!parentToWraps.has(p)) parentToWraps.set(p, []);
            parentToWraps.get(p).push(wrap);
        });
    });

    parentToWraps.forEach(function (wrapList, pName) {
        const el = form.querySelector('[name="' + pName.replace(/"/g, "") + '"]');
        if (!el) return;
        const resetDependents = function () {
            wrapList.forEach(function (wrap) {
                const hid = wrap.querySelector(".crud-catalog-id");
                const searchEl = wrap.querySelector(".crud-catalog-search");
                const dd = wrap.querySelector(".crud-catalog-dropdown");
                if (hid) {
                    const wasZona = hid.name === "zona_id";
                    hid.value = "";
                    if (wasZona) {
                        delete hid.dataset.zonaTipo;
                        hid.dispatchEvent(new Event("change", { bubbles: true }));
                    }
                }
                if (searchEl) searchEl.value = "";
                if (dd) {
                    dd.hidden = true;
                    dd.innerHTML = "";
                }
            });
        };
        el.addEventListener("change", resetDependents);
        el.addEventListener("input", resetDependents);
    });

    if (!window.__savidCrudCatalogOutside) {
        window.__savidCrudCatalogOutside = true;
        document.addEventListener("click", function (ev) {
            document.querySelectorAll(".crud-catalog-wrap").forEach(function (w) {
                if (!w.contains(ev.target)) {
                    const d = w.querySelector(".crud-catalog-dropdown");
                    if (d) d.hidden = true;
                }
            });
        });
    }

    wraps.forEach(function (wrap) {
        const field = wrap.getAttribute("data-catalog-field");
        const hid = wrap.querySelector(".crud-catalog-id");
        const searchEl = wrap.querySelector(".crud-catalog-search");
        const dd = wrap.querySelector(".crud-catalog-dropdown");
        if (!field || !hid || !searchEl || !dd) return;

        let parents = [];
        try {
            parents = JSON.parse(wrap.getAttribute("data-parent-fields") || "[]");
        } catch (e2) {
            parents = [];
        }
        if (!Array.isArray(parents)) parents = [];

        let activeIdx = -1;

        function getSelectableItems() {
            return Array.prototype.slice.call(dd.querySelectorAll("li:not(.crud-catalog-hint)"));
        }

        function clearActiveHighlight() {
            dd.querySelectorAll("li.crud-catalog-option-active").forEach(function (li) {
                li.classList.remove("crud-catalog-option-active");
            });
        }

        function renderActiveHighlight() {
            const items = getSelectableItems();
            clearActiveHighlight();
            if (activeIdx >= 0 && activeIdx < items.length) {
                items[activeIdx].classList.add("crud-catalog-option-active");
                items[activeIdx].scrollIntoView({ block: "nearest", behavior: "smooth" });
            }
        }

        function moveActiveHighlight(delta) {
            const items = getSelectableItems();
            if (!items.length) {
                return;
            }
            if (activeIdx < 0) {
                activeIdx = delta > 0 ? 0 : items.length - 1;
            } else {
                activeIdx = (activeIdx + delta + items.length) % items.length;
            }
            renderActiveHighlight();
        }

        function applyCatalogChoice(li) {
            if (!li || li.classList.contains("crud-catalog-hint")) {
                return;
            }
            const id = li.getAttribute("data-catalog-id");
            const nombre = li.getAttribute("data-catalog-nombre");
            hid.value = id != null && id !== "" ? String(id) : "";
            searchEl.value = nombre != null && nombre !== "" ? nombre : String(li.textContent || "").trim();
            crudNormalizeUppercaseField(searchEl);
            if (field === "zona_id") {
                const zt = li.getAttribute("data-zona-tipo");
                if (zt) {
                    hid.dataset.zonaTipo = zt;
                } else {
                    delete hid.dataset.zonaTipo;
                }
            }
            dd.hidden = true;
            dd.innerHTML = "";
            activeIdx = -1;
            hid.classList.remove("input-error");
            hid.dispatchEvent(new Event("change", { bubbles: true }));
        }

        const buildUrl = function () {
            const u = new URL(window.location.href);
            u.search = "";
            u.searchParams.set("url", "module/catalogSearch");
            u.searchParams.set("context", ctx);
            u.searchParams.set("field", field);
            u.searchParams.set("q", searchEl.value.trim());
            parents.forEach(function (p) {
                const pel = form.querySelector('[name="' + String(p).replace(/"/g, "") + '"]');
                if (pel) u.searchParams.set("parent_" + p, pel.value);
            });
            return u.toString();
        };

        const doFetch = function () {
            activeIdx = -1;
            clearActiveHighlight();
            fetch(buildUrl(), { credentials: "same-origin" })
                .then(function (r) {
                    const ct = r.headers.get("content-type") || "";
                    if (!ct.includes("application/json")) {
                        return r.text().then(function (t) {
                            throw new Error(t ? "non-json" : "empty");
                        });
                    }
                    return r.json();
                })
                .then(function (data) {
                    dd.innerHTML = "";
                    if (!data || data.ok === false) {
                        dd.hidden = false;
                        const li = document.createElement("li");
                        li.className = "crud-catalog-hint";
                        li.textContent = (data && data.error) ? String(data.error) : "No se pudo cargar el catálogo.";
                        dd.appendChild(li);
                        return;
                    }
                    if (!Array.isArray(data.items)) {
                        dd.hidden = true;
                        return;
                    }
                    const parentsMissing = parents.some(function (p) {
                        const pel = form.querySelector('[name="' + String(p).replace(/"/g, "") + '"]');
                        return !pel || String(pel.value).trim() === "";
                    });
                    if (data.items.length === 0) {
                        dd.hidden = false;
                        const li = document.createElement("li");
                        li.className = "crud-catalog-hint";
                        if (parents.length && parentsMissing && searchEl.value.trim() === "") {
                            li.textContent = "Seleccione primero los datos de ubicación o jerarquía indicados arriba, o escriba para buscar.";
                        } else {
                            li.textContent = "Sin resultados.";
                        }
                        dd.appendChild(li);
                        return;
                    }
                    data.items.forEach(function (it) {
                        const li = document.createElement("li");
                        li.setAttribute("role", "option");
                        li.setAttribute("data-catalog-id", String(it.id));
                        li.setAttribute("data-catalog-nombre", it.nombre != null ? String(it.nombre) : "");
                        li.textContent = it.nombre || ("#" + it.id);
                        if (it.tipo !== undefined && it.tipo !== null && String(it.tipo) !== "") {
                            li.setAttribute("data-zona-tipo", String(it.tipo));
                        }
                        li.addEventListener("mousedown", function (ev) {
                            ev.preventDefault();
                            applyCatalogChoice(li);
                        });
                        dd.appendChild(li);
                    });
                    dd.hidden = false;
                })
                .catch(function () {
                    dd.innerHTML = "";
                    dd.hidden = false;
                    const li = document.createElement("li");
                    li.className = "crud-catalog-hint";
                    li.textContent = "Error de red o respuesta no válida.";
                    dd.appendChild(li);
                });
        };

        const debounced = debounceCrud(doFetch, 280);

        searchEl.addEventListener("input", debounced);
        searchEl.addEventListener("focus", function () {
            activeIdx = -1;
            doFetch();
        });

        searchEl.addEventListener("keydown", function (ev) {
            const key = ev.key;
            const items = getSelectableItems();

            if (key === "Escape") {
                if (!dd.hidden) {
                    ev.preventDefault();
                    dd.hidden = true;
                    activeIdx = -1;
                    clearActiveHighlight();
                }
                return;
            }

            if (!items.length) {
                return;
            }

            if (key === "ArrowDown") {
                ev.preventDefault();
                if (dd.hidden) {
                    dd.hidden = false;
                }
                moveActiveHighlight(1);
                return;
            }

            if (key === "ArrowUp") {
                ev.preventDefault();
                if (dd.hidden) {
                    dd.hidden = false;
                }
                moveActiveHighlight(-1);
                return;
            }

            if (key === "Tab" && ev.shiftKey) {
                return;
            }

            if (key === "Enter" || key === "Tab") {
                if (!dd.hidden && items.length) {
                    ev.preventDefault();
                    const idx = activeIdx >= 0 ? activeIdx : 0;
                    applyCatalogChoice(items[idx]);
                }
                return;
            }

            if (key === "Home" && !dd.hidden) {
                ev.preventDefault();
                activeIdx = 0;
                renderActiveHighlight();
                return;
            }

            if (key === "End" && !dd.hidden) {
                ev.preventDefault();
                activeIdx = items.length - 1;
                renderActiveHighlight();
            }
        });
    });
}

function initCrudSearch() {

    const search = document.querySelector(".crud-search:not(.savid-dt-col-filter)");

    if (!search) return;

    if (document.querySelector("table.savid-datatable[data-savid-dt-init='1'], table[data-savid-dt-init='1']")) {
        return;
    }

    search.addEventListener("keyup", function () {

        const value = this.value.toLowerCase();

        document.querySelectorAll(".crud-table tbody tr").forEach(row => {

            const text = row.innerText.toLowerCase();

            row.style.display = text.includes(value)
                ? ""
                : "none";

        });

    });

}

/* =====================================================
   ELIMINAR
===================================================== */

function initCrudDelete() {

    const btn = document.querySelector(".btn-delete");

    if (!btn) return;

    btn.addEventListener("click", function () {

        if (!selectedId) {
            alert("Seleccione un registro");
            return;
        }

        if (!confirm("¿Eliminar registro?")) return;

        let url = new URL(window.location.href);
        url.searchParams.set("delete", selectedId);

        window.location = url.toString();

    });

}

/* =====================================================
   ACCIONES ESPECIALES
===================================================== */

function crudResetUsuarioForm() {
    const btn = document.getElementById("btnNuevo");
    if (btn) {
        btn.click();
        return;
    }
    document.querySelectorAll(".form-input").forEach(input => {
        if (input.tagName === "SELECT") {
            input.selectedIndex = 0;
        } else {
            input.value = "";
        }
        input.classList.remove("input-error");
    });
    document.querySelectorAll(".form-checkbox").forEach(input => {
        input.checked = false;
        input.classList.remove("input-error");
    });
    const hiddenId = document.getElementById("crud_id");
    if (hiddenId) hiddenId.value = "";
    selectedRow = null;
    selectedId = null;
}

function crudRemoveUsuarioRow(usuarioId) {
    if (!usuarioId) return;
    const row = document.querySelector('.crud-row[data-id="' + usuarioId + '"]');
    if (row && row.parentNode) {
        row.parentNode.removeChild(row);
    }
}

window.usuarioEmpresaSedeOnSaved = function (usuarioId) {};

window.usuarioEmpresaSedeOnLostVisibility = function (usuarioId) {
    crudResetUsuarioForm();
    crudRemoveUsuarioRow(usuarioId);
};

function initCrudAcciones() {

    function crudSelectedRowEmpresaQuery() {
        const row = document.querySelector(".crud-row.selected");
        if (!row) return "";
        const td = row.querySelector('td[data-field="empresa_id"]');
        if (!td) return "";
        const val = (td.getAttribute("data-value") || "").trim();
        if (val !== "" && /^\d+$/.test(val)) {
            return "&empresa_id=" + encodeURIComponent(val);
        }
        return "";
    }

    document.querySelectorAll(".btn-accion").forEach(btn => {

        btn.addEventListener("click", function () {

            if (!selectedId) {
                alert("Seleccione un registro");
                return;
            }

            const raw = this.getAttribute("data-accion") ?? this.dataset.accion ?? "";
            const accion = String(raw).trim().toLowerCase();

            if (typeof openModalGod !== "function") {
                alert("Modal no disponible (openModalGod)");
                return;
            }

            const modales = {
                rol_permisos: [
                    "rol/permisos&id=" + selectedId,
                    "xl",
                    "Cargando permisos..."
                ],
                usuario_permisos: [
                    "usuario/permisos/" + selectedId,
                    "xl",
                    "Cargando permisos..."
                ],
                user_permisos: [
                    "usuario/permisos/" + selectedId,
                    "xl",
                    "Cargando permisos..."
                ],
                usuario_roles: [
                    "usuario/roles/" + selectedId,
                    "xl",
                    "Cargando roles..."
                ],
                user_roles: [
                    "usuario/roles/" + selectedId,
                    "xl",
                    "Cargando roles..."
                ],
                usuario_sedes: [
                    "usuario/empresa_sede/" + selectedId,
                    "lg",
                    "Cargando empresas y sedes..."
                ],
                usuario_sede: [
                    "usuario/empresa_sede/" + selectedId,
                    "lg",
                    "Cargando empresas y sedes..."
                ],
                tercero_identificaciones: [
                    "tercero/identificaciones/" + selectedId,
                    "lg",
                    "Cargando identificaciones…"
                ],
                empresa_sedes: [
                    "empresa/sedes/" + selectedId,
                    "lg",
                    "Cargando sedes..."
                ],
                empresa_usuarios: [
                    "empresa/usuarios/" + selectedId,
                    "lg",
                    "Cargando usuarios..."
                ],
                item_accion: [
                    "item/acciones/" + selectedId,
                    "lg",
                    "Cargando acciones del ítem..."
                ],
                sgd_tipo_padres: [
                    "sgd/tipoDocumentalPadres/" + selectedId,
                    "lg",
                    "Cargando padres permitidos..."
                ],
                sgd_tipo_secciones: [
                    "sgd/tipoDocumentalSecciones/" + selectedId,
                    "lg",
                    "Cargando perfil de secciones..."
                ]
            };

            const cfg = modales[accion];

            if (cfg) {
                const url = cfg[0] + ((accion === "sgd_tipo_padres" || accion === "sgd_tipo_secciones") ? crudSelectedRowEmpresaQuery() : "");
                openModalGod(url, cfg[1], cfg[2]);
            } else {
                alert("Acción sin handler en crud.js: " + (raw || "(vacío)") +
                    "\nNormalizada: " + accion);
            }

        });

    });

}

/* =====================================================
   VALIDACION
===================================================== */

function initCrudValidation() {

    const form = document.querySelector("form");

    if (!form) return;

    if (form.getAttribute("data-crud-context") === "usuario") {
        return;
    }

    form.addEventListener("submit", function (e) {

        let errores = 0;

        form.querySelectorAll(".form-input[required]").forEach(function (input) {
            if (input.disabled || input.offsetParent === null) {
                return;
            }

            if (!input.value.trim()) {

                input.classList.add("input-error");
                errores++;

            } else {
                input.classList.remove("input-error");
            }

        });

        if (errores > 0) {
            e.preventDefault();
            alert("Complete los campos obligatorios.");
        }

    });

}