<script setup>
import { ref } from 'vue';
import { toast } from 'vue-sonner';

/**
 * Botão «Exportar» com os 4 formatos (Excel, PDF, CSV e ZIP). Chama o endereço `url?formato=…&…params` e descarrega o
 * ficheiro; se o servidor recusar (ex.: registos a mais), mostra a mensagem em vez de abrir uma página de erro.
 */
const props = defineProps({
    url: { type: String, required: true },
    params: { type: Object, default: () => ({}) }, // filtros do ecrã (valores vazios não vão no pedido)
    rotulo: { type: String, default: 'Exportar' },
    classe: { type: String, default: 'btn btn-light-primary' },
});

const FORMATOS = [
    { chave: 'excel', texto: 'Excel', nota: 'Folha de cálculo (.xlsx)', icone: 'ki-file-sheet', paths: 2, cor: 'success' },
    { chave: 'pdf', texto: 'PDF', nota: 'Documento para imprimir', icone: 'ki-document', paths: 2, cor: 'danger' },
    { chave: 'csv', texto: 'CSV', nota: 'Dados em texto simples', icone: 'ki-file', paths: 2, cor: 'primary' },
    { chave: 'zip', texto: 'ZIP', nota: 'Excel + PDF + CSV juntos', icone: 'ki-folder', paths: 2, cor: 'warning' },
];

const aGerar = ref(null);

async function baixar(formato) {
    if (aGerar.value) return;
    aGerar.value = formato;

    const consulta = new URLSearchParams({ formato });
    Object.entries(props.params).forEach(([chave, valor]) => {
        if (valor !== '' && valor !== null && valor !== undefined) consulta.set(chave, valor);
    });

    try {
        const resposta = await fetch(`${props.url}?${consulta}`, {
            headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
            credentials: 'same-origin',
        });

        if (!resposta.ok) {
            const erro = await resposta.json().catch(() => ({}));
            toast.error(erro.message ?? Object.values(erro.errors ?? {})[0]?.[0] ?? 'Não foi possível exportar.');

            return;
        }

        const nome = /filename\*?=(?:UTF-8'')?"?([^";]+)"?/i.exec(resposta.headers.get('content-disposition') ?? '')?.[1] ?? `exportacao.${formato === 'excel' ? 'xlsx' : formato}`;
        const ligacao = document.createElement('a');
        ligacao.href = URL.createObjectURL(await resposta.blob());
        ligacao.download = decodeURIComponent(nome);
        document.body.appendChild(ligacao);
        ligacao.click();
        ligacao.remove();
        setTimeout(() => URL.revokeObjectURL(ligacao.href), 10000);
        toast.success('Exportação pronta.');
    } catch {
        toast.error('Não foi possível exportar. Tente novamente.');
    } finally {
        aGerar.value = null;
    }
}
</script>

<template>
    <span class="d-inline-block">
        <a href="#" :class="classe" data-kt-menu-trigger="click" data-kt-menu-placement="bottom-end" @click.prevent>
            <span v-if="aGerar" class="spinner-border spinner-border-sm me-2"></span>
            <i v-else class="ki-duotone ki-exit-up fs-2"><span class="path1"></span><span class="path2"></span></i>
            {{ aGerar ? 'A gerar…' : rotulo }}
            <i class="ki-duotone ki-down fs-5 ms-1"></i>
        </a>
        <div class="menu menu-sub menu-sub-dropdown menu-column menu-rounded menu-gray-600 menu-state-bg-light-primary fw-semibold fs-7 w-250px py-4" data-kt-menu="true">
            <div v-for="f in FORMATOS" :key="f.chave" class="menu-item px-3">
                <a href="#" class="menu-link px-3 py-3" :class="{ 'opacity-50 pe-none': aGerar }" @click.prevent="baixar(f.chave)">
                    <span class="symbol symbol-35px me-3"><span class="symbol-label" :class="`bg-light-${f.cor}`">
                        <i class="ki-duotone fs-3" :class="[f.icone, `text-${f.cor}`]"><span v-for="n in f.paths" :key="n" :class="`path${n}`"></span></i>
                    </span></span>
                    <span class="d-flex flex-column">
                        <span class="fw-bold fs-6 text-gray-800">{{ f.texto }}</span>
                        <span class="text-muted fs-8">{{ f.nota }}</span>
                    </span>
                </a>
            </div>
        </div>
    </span>
</template>
