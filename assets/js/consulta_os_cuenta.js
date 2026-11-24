"use strict";
// Copy the configured clipboard source. The visible textarea may intentionally show the phone number instead. Clipboard API requires HTTPS or localhost.
document.addEventListener("click", async (event) => {
    const button = event.target.closest("button[data-copy-target]");
    if (!button) return;
    const field = document.getElementById(button.dataset.copyTarget);
    if (!(field instanceof HTMLTextAreaElement)) return;
    const status = button.parentElement.querySelector(".lookup-copy-status");
    const preserveLabel = button.dataset.copyPreserveLabel === "1";
    button.disabled = true;
    let copied = false;
    try {
        if (navigator.clipboard && window.isSecureContext) {
            try { await navigator.clipboard.writeText(field.value); copied = true; } catch (_) { /* Try selection fallback. */ }
        }
        if (!copied) {
            field.focus(); field.select(); field.setSelectionRange(0, field.value.length);
            try { copied = document.execCommand("copy"); } catch (_) { copied = false; }
        }
        if (!preserveLabel) button.textContent = copied ? "Copiado" : "Copiar";
        if (status) status.textContent = copied ? "Texto copiado al portapapeles." : "Texto seleccionado. Usa Ctrl+C o la opción Copiar de tu dispositivo.";
        if (copied) button.focus();
    } finally { button.disabled = false; }
});
