export function usePageScripts(scripts) {
    function loadScript(src) {
        return new Promise((resolve) => {
            if (document.querySelector(`script[src="${src}"]`)) {
                resolve();
                return;
            }
            const script = document.createElement('script');
            script.src = src;
            script.onload = resolve;
            document.body.appendChild(script);
        });
    }

    async function loadAll() {
        for (const src of scripts) {
            await loadScript(src); // sequencial — respeita dependências
        }
        window.KTComponents?.init();
    }

    return { loadAll };
}
