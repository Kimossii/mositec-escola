// Copia texto para a área de transferência.
//
// `navigator.clipboard` só existe em contexto seguro (HTTPS ou localhost). Em HTTP (ex.: um domínio
// `.test` em desenvolvimento) é `undefined`, por isso há um recurso alternativo com `execCommand('copy')`,
// que funciona em HTTP. Devolve `true` se copiou. O texto nunca é guardado em lado nenhum.
export async function copiarParaAreaDeTransferencia(texto) {
    if (window.isSecureContext && navigator.clipboard?.writeText) {
        try {
            await navigator.clipboard.writeText(texto);
            return true;
        } catch {
            // continua para o recurso alternativo
        }
    }

    const campo = document.createElement('textarea');
    campo.value = texto;
    campo.setAttribute('readonly', '');
    campo.style.position = 'fixed';
    campo.style.top = '0';
    campo.style.left = '0';
    campo.style.opacity = '0';
    document.body.appendChild(campo);
    campo.focus();
    campo.select();
    campo.setSelectionRange(0, texto.length);

    try {
        return document.execCommand('copy');
    } catch {
        return false;
    } finally {
        document.body.removeChild(campo);
    }
}
