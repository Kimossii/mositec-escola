<script setup>
import { computed, ref, watch } from 'vue';
import { ESTADO_MATRICULA, estadoMatriculaLabel } from '../Models/Estado';

const props = defineProps({
    show: { type: Boolean, default: false },
    matricula: { type: Object, default: null },
    estado: { type: Number, default: null },
    processando: { type: Boolean, default: false },
});
const emit = defineEmits(['confirmar', 'cancelar']);

const hoje = () => new Date().toISOString().slice(0, 10);
const dataFim = ref(hoje());
const propinas = ref([]);
const aVerificar = ref(false);

// Só Cancelada e Transferida pedem data (e cancelam propinas futuras); Concluída mantém o comportamento simples.
const pedeData = computed(() => [ESTADO_MATRICULA.CANCELADA, ESTADO_MATRICULA.TRANSFERIDA].includes(props.estado));
const vencidas = computed(() => propinas.value.filter((p) => p.vencida));

async function verificar() {
    if (!props.show || !props.matricula || !pedeData.value) return;
    aVerificar.value = true;
    try {
        const resposta = await fetch(`/alunos/${props.matricula.aluno_id}/matriculas/${props.matricula.id}/previsao-fim?data_fim=${dataFim.value}`, {
            headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
        });
        propinas.value = resposta.ok ? (await resposta.json()).propinas : [];
    } finally {
        aVerificar.value = false;
    }
}

watch(() => props.show, (aberto) => {
    if (!aberto) return;
    dataFim.value = hoje();
    propinas.value = [];
    verificar();
});
watch(dataFim, verificar);
</script>

<template>
    <div v-if="show" class="modal d-block" style="background: rgba(0,0,0,0.5);" @click.self="emit('cancelar')">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content p-6">
                <h3 class="mb-4">Alterar estado da matrícula</h3>
                <p class="text-gray-700 mb-4">
                    Alterar o estado da matrícula {{ matricula?.numero_registo_matricula }} para «{{ estadoMatriculaLabel(estado) }}»?
                </p>

                <div v-if="pedeData" class="mb-4">
                    <label class="form-label fw-semibold">Data de fim</label>
                    <input v-model="dataFim" type="date" class="form-control form-control-solid" :max="hoje()" :min="matricula?.data_matricula?.slice(0, 10)">
                </div>

                <div v-if="pedeData && propinas.length" class="alert py-3 mb-4" :class="vencidas.length ? 'alert-danger' : 'alert-warning'">
                    <div class="fw-semibold mb-1">Serão canceladas {{ propinas.length }} propina(s) sem pagamentos:</div>
                    <div class="fs-7">{{ propinas.map((p) => p.descricao + (p.vencida ? ' (vencida)' : '')).join(', ') }}</div>
                    <div v-if="vencidas.length" class="fs-7 fw-semibold mt-2">Atenção: {{ vencidas.length }} já está(ão) vencida(s) e deixa(m) de ser cobrada(s).</div>
                </div>

                <div class="text-end">
                    <button type="button" class="btn btn-light-primary me-2" :disabled="processando" @click="emit('cancelar')">Cancelar</button>
                    <button type="button" class="btn btn-danger" :disabled="processando || aVerificar" @click="emit('confirmar', pedeData ? dataFim : null)">Confirmar</button>
                </div>
            </div>
        </div>
    </div>
</template>
