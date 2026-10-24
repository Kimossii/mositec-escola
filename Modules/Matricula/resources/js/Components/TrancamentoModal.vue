<script setup>
import { computed, ref, watch } from 'vue';
import { router } from '@inertiajs/vue3';
import { toast } from 'vue-sonner';

const props = defineProps({
    show: { type: Boolean, default: false },
    matricula: { type: Object, default: null },
    modo: { type: String, default: 'trancar' }, // 'trancar' | 'reactivar'
});
const emit = defineEmits(['fechar', 'concluido']);

const hoje = () => new Date().toISOString().slice(0, 10);
const dataEfeito = ref(hoje());
const motivo = ref('');
const confirmar = ref(false);
const verificacao = ref({ bloqueios: [], avisos: [] });
const aVerificar = ref(false);
const processando = ref(false);
const erros = ref({});

const aTrancar = computed(() => props.modo === 'trancar');
const pedeConfirmacao = computed(() => verificacao.value.avisos.some((a) => a.detalhe?.confirmar));
const bloqueado = computed(() => verificacao.value.bloqueios.length > 0);
const podeSubmeter = computed(() => !processando.value && !aVerificar.value && !bloqueado.value
    && motivo.value.trim().length >= 5 && (!pedeConfirmacao.value || confirmar.value));

async function verificar() {
    if (!aTrancar.value || !props.matricula) return;
    aVerificar.value = true;
    try {
        const resposta = await fetch(`/alunos/${props.matricula.aluno_id}/matriculas/${props.matricula.id}/trancamento?data_efeito=${dataEfeito.value}`, {
            headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
        });
        verificacao.value = resposta.ok ? await resposta.json() : { bloqueios: [], avisos: [] };
    } finally {
        aVerificar.value = false;
    }
}

watch(() => props.show, (aberto) => {
    if (!aberto) return;
    dataEfeito.value = hoje();
    motivo.value = '';
    confirmar.value = false;
    erros.value = {};
    verificacao.value = { bloqueios: [], avisos: [] };
    verificar();
});
watch(dataEfeito, verificar);

function submeter() {
    processando.value = true;
    router.post(`/alunos/${props.matricula.aluno_id}/matriculas/${props.matricula.id}/${aTrancar.value ? 'trancar' : 'reactivar'}`, {
        data_efeito: dataEfeito.value,
        motivo: motivo.value,
        ...(aTrancar.value ? { confirmar_com_dividas: confirmar.value } : {}),
    }, {
        preserveScroll: true,
        onSuccess: () => {
            toast.success(aTrancar.value ? 'Matrícula trancada com sucesso.' : 'Matrícula reactivada com sucesso.');
            emit('concluido');
        },
        onError: (e) => {
            erros.value = e;
            if (e.estado) toast.error(e.estado);
        },
        onFinish: () => { processando.value = false; },
    });
}
</script>

<template>
    <div v-if="show" class="modal d-block" style="background: rgba(0,0,0,0.5);" @click.self="emit('fechar')">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content p-6">
                <h3 class="mb-4">{{ aTrancar ? 'Trancar matrícula' : 'Reactivar matrícula' }}</h3>

                <div class="mb-4">
                    <label class="form-label fw-semibold">{{ aTrancar ? 'Data do trancamento' : 'Data da reactivação' }}</label>
                    <input v-model="dataEfeito" type="date" class="form-control form-control-solid" :max="hoje()">
                    <div v-if="erros.data_efeito" class="text-danger fs-7 mt-1">{{ erros.data_efeito }}</div>
                </div>

                <div class="mb-4">
                    <label class="form-label fw-semibold">Motivo</label>
                    <textarea v-model="motivo" rows="3" class="form-control form-control-solid" maxlength="1000"></textarea>
                    <div v-if="erros.motivo" class="text-danger fs-7 mt-1">{{ erros.motivo }}</div>
                </div>

                <template v-if="aTrancar">
                    <div v-for="(b, i) in verificacao.bloqueios" :key="`b${i}`" class="alert alert-danger py-3 mb-3">
                        {{ b.mensagem }} Regularize a situação financeira antes de trancar.
                    </div>
                    <div v-for="(a, i) in verificacao.avisos" :key="`a${i}`" class="alert alert-warning py-3 mb-3">{{ a.mensagem }}</div>
                    <div v-if="pedeConfirmacao" class="form-check mb-4">
                        <input id="confirmar_com_dividas" v-model="confirmar" type="checkbox" class="form-check-input">
                        <label for="confirmar_com_dividas" class="form-check-label">Confirmo que pretendo trancar com dívidas em aberto.</label>
                    </div>
                    <div v-if="erros.confirmar_com_dividas" class="text-danger fs-7 mb-3">{{ erros.confirmar_com_dividas }}</div>
                </template>

                <div class="text-end">
                    <button type="button" class="btn btn-light-primary me-2" :disabled="processando" @click="emit('fechar')">Cancelar</button>
                    <button type="button" class="btn btn-primary" :disabled="!podeSubmeter" @click="submeter">
                        {{ aTrancar ? 'Trancar' : 'Reactivar' }}
                    </button>
                </div>
            </div>
        </div>
    </div>
</template>
