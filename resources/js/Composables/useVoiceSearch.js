import { ref, onBeforeUnmount } from 'vue';

/**
 * Búsqueda por voz (Web Speech API). Reutilizable en cualquier índice / buscador.
 * Si el navegador no soporta SpeechRecognition, `micSupported` es false y el
 * botón ni se muestra (degradación elegante).
 *
 *   const { micSupported, listening, startVoiceSearch } = useVoiceSearch(quickSearch);
 *
 * `target` es un ref/computed escribible (ej. quickSearch): el transcript se
 * asigna a target.value.
 *
 * Robustez (fix del bug "mic colgado escuchando en loop"):
 *  - Instancia NUEVA en cada inicio → sin estados zombie de una sesión previa.
 *  - `abort()` (corta ya) en vez de `stop()` (suave) al apagar/cancelar.
 *  - Watchdog: si en 12s no terminó solo (cuelgue del engine), se aborta solo.
 *  - `onstart` setea `listening` (estado real, no optimista).
 *  - Se corta al desmontar el componente (no sobrevive a cambiar de página).
 */
export function useVoiceSearch(target) {
    const SpeechRec = typeof window !== 'undefined'
        ? (window.SpeechRecognition || window.webkitSpeechRecognition)
        : null;
    const micSupported = !!SpeechRec;
    const listening = ref(false);
    let recognition = null;
    let watchdog = null;

    const clearWatchdog = () => { clearTimeout(watchdog); watchdog = null; };

    // Corte duro: aborta la sesión actual y resetea el estado. Idempotente.
    const stop = () => {
        clearWatchdog();
        if (recognition) {
            // Sacamos los handlers para que un onend/onerror tardío no reabra nada.
            recognition.onstart = recognition.onresult = recognition.onend = recognition.onerror = null;
            try { recognition.abort(); } catch { /* noop */ }
            recognition = null;
        }
        listening.value = false;
    };

    const startVoiceSearch = () => {
        if (!SpeechRec) return;
        // Toggle: si ya está escuchando, este click apaga.
        if (listening.value || recognition) { stop(); return; }

        recognition = new SpeechRec();
        recognition.lang = (document.documentElement.lang || 'es').startsWith('en') ? 'en-US' : 'es-PE';
        recognition.interimResults = false;
        recognition.continuous = false;
        recognition.maxAlternatives = 1;

        recognition.onstart  = () => { listening.value = true; };
        recognition.onresult = (e) => { target.value = (e.results?.[0]?.[0]?.transcript || '').trim(); };
        recognition.onend    = () => { stop(); };
        recognition.onerror  = () => { stop(); };

        // Red de seguridad: si el engine se cuelga y nunca dispara onend/onerror,
        // lo cortamos a los 12s (en vez de quedar escuchando para siempre).
        clearWatchdog();
        watchdog = setTimeout(() => { stop(); }, 12000);

        try {
            recognition.start();
        } catch {
            // "already started" u otro estado raro → reset duro.
            stop();
        }
    };

    // Cambiar de página / desmontar con el mic abierto NO debe dejarlo colgado.
    onBeforeUnmount(() => stop());

    return { micSupported, listening, startVoiceSearch };
}
