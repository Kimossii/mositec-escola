<script setup>
import AcaoIcone from '@/Components/Shared/AcaoIcone.vue';
import { computed } from 'vue';
import { formatarTamanho, formatarData } from '../../Support/ficheiros';

const props = defineProps({
    ficheiro: { type: Object, required: true }, // { id, titulo, formato, nome, linhas, tamanho, estado (0 a preparar | 1 pronto | 2 erro), erro, criado_em }
    selecionado: { type: Boolean, default: false },
    recuo: { type: Boolean, default: false }, // linha dentro de uma pasta expandida
    mostrarPasta: { type: Boolean, default: false },
    recebido: { type: Boolean, default: false }, // ficheiro partilhado comigo: só descarregar
});
const emit = defineEmits(['alternar', 'eliminar', 'partilhar']);

// Cor e ícone por formato, como o gestor de ficheiros do tema: o tipo reconhece-se à primeira vista.
const FORMATOS = {
    pdf: { cor: 'danger', rotulo: 'PDF' },
    excel: { cor: 'success', rotulo: 'Excel' },
    csv: { cor: 'primary', rotulo: 'CSV' },
    zip: { cor: 'warning', rotulo: 'ZIP' },
};
const formato = computed(() => FORMATOS[props.ficheiro.formato] ?? { cor: 'secondary', rotulo: props.ficheiro.formato });
const pronto = computed(() => props.ficheiro.estado === 1);
</script>

<template>
    <tr :class="{ 'bg-body-secondary': recuo }">
        <td class="w-10px pe-2">
            <div class="form-check form-check-sm form-check-custom form-check-solid">
                <input v-if="!recebido" class="form-check-input" type="checkbox" :checked="selecionado" :disabled="ficheiro.estado === 0" @change="emit('alternar', ficheiro.id)" />
            </div>
        </td>
        <td>
            <div class="d-flex align-items-center" :class="{ 'ps-10': recuo }">
                <span v-if="ficheiro.estado === 0" class="spinner-border spinner-border-sm text-warning me-4" style="width: 1.6rem; height: 1.6rem"></span>
                <i v-else class="ki-duotone ki-file fs-2x me-4" :class="`text-${formato.cor}`"><span class="path1"></span><span class="path2"></span></i>
                <div class="d-flex flex-column">
                    <a v-if="pronto" :href="`/ficheiros/${ficheiro.id}/descarregar`" class="text-gray-800 text-hover-primary fw-semibold">{{ ficheiro.nome }}</a>
                    <span v-else class="text-gray-800 fw-semibold">{{ ficheiro.nome }}</span>
                    <span v-if="ficheiro.estado === 0" class="text-warning fs-8">A preparar…</span>
                    <span v-else-if="ficheiro.estado === 2" class="text-danger fs-8">{{ ficheiro.erro ?? 'Falhou.' }}</span>
                    <span v-else-if="recebido" class="text-muted fs-8">Partilhado por {{ ficheiro.partilhado_por }}</span>
                    <span v-else-if="ficheiro.partilhado_com?.length" class="text-primary fs-8" :title="ficheiro.partilhado_com.map((u) => u.nome).join(', ')">Partilhado com {{ ficheiro.partilhado_com.length }}</span>
                    <span v-else-if="mostrarPasta" class="text-muted fs-8">{{ ficheiro.titulo }}</span>
                </div>
            </div>
        </td>
        <td><span class="badge" :class="`badge-light-${formato.cor}`">{{ formato.rotulo }}</span></td>
        <td>{{ ficheiro.estado === 1 ? formatarTamanho(ficheiro.tamanho) : '—' }}</td>
        <td>{{ ficheiro.linhas }}</td>
        <td>{{ formatarData(ficheiro.criado_em) }}</td>
        <td class="text-end text-nowrap">
            <a v-if="pronto" :href="`/ficheiros/${ficheiro.id}/descarregar`" class="btn btn-sm btn-icon btn-light btn-active-light-primary" title="Descarregar">
                <AcaoIcone acao="descarregar" />
            </a>
            <button v-if="ficheiro.partilhavel" type="button" class="btn btn-sm btn-icon btn-light btn-active-light-primary ms-2" title="Partilhar" @click="emit('partilhar', ficheiro)">
                <AcaoIcone acao="partilhar" />
            </button>
            <button v-if="!recebido && ficheiro.estado !== 0" type="button" class="btn btn-sm btn-icon btn-light btn-active-light-danger ms-2" title="Eliminar" @click="emit('eliminar', ficheiro.id)">
                <AcaoIcone acao="eliminar" />
            </button>
        </td>
    </tr>
</template>
