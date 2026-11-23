<script setup>
import { reactive, ref, watch } from 'vue';
import { Link, router } from '@inertiajs/vue3';
import { toast } from 'vue-sonner';
import AppLayout from '@/Layouts/AppLayout.vue';
import EstadoVazio from '@/Components/Shared/EstadoVazio.vue';
import Loader from '@/Components/Shared/Loader.vue';
import SelectSolid from '@/Components/Shared/SelectSolid.vue';
import { computed } from 'vue';
import { can } from '@/Composables/usePermissoes';
import { formatarDia } from '../Support/frequencia';

/** Vista da direcção: aulas previstas dos últimos 7 dias sem chamada, e a janela de correcção da escola. */
const props = defineProps({
    pendentes: { type: Array, required: true },
    filtros: { type: Object, default: () => ({}) },
    opcoes: { type: Object, required: true }, // { turmas: [{ id, rotulo }] }
    janelaDias: { type: Number, default: 7 },
    podeConfigurar: { type: Boolean, default: false },
});
defineOptions({ layout: AppLayout });

const estado = reactive({ turma_id: props.filtros.turma_id ? Number(props.filtros.turma_id) : '' });
const opcoesTurma = computed(() => [{ value: '', label: 'Todas as turmas' }, ...props.opcoes.turmas.map((t) => ({ value: t.id, label: t.rotulo }))]);

watch(() => estado.turma_id, (valor) => {
    router.get('/frequencia/pendentes', valor === '' ? {} : { turma_id: valor }, { preserveState: true, preserveScroll: true, replace: true });
});

const janela = ref(props.janelaDias);
const processing = ref(false);
const erro = ref(null);

function guardarJanela() {
    processing.value = true;
    erro.value = null;
    router.put('/frequencia/definicoes', { janela_correccao_dias: janela.value }, {
        preserveScroll: true,
        onSuccess: () => toast.success('Janela de correcção actualizada.'),
        onError: (erros) => {
            erro.value = Object.values(erros)[0];
            toast.error(erro.value);
        },
        onFinish: () => { processing.value = false; },
    });
}

const tempo = (p) => p.horario_nome ?? 'Chamada do dia';

// A secretaria (corrigir-fora-prazo) abre a chamada de qualquer aula; o professor, as suas.
const podeAbrir = can('frequencia.corrigir-fora-prazo') || can('frequencia.criar');
const ligacao = (p) => {
    const q = new URLSearchParams({ turma_id: p.turma_id, data: p.data });
    if (p.horario_id) q.set('horario_id', p.horario_id);
    return `/frequencia/chamada?${q.toString()}`;
};
</script>

<template>
    <div class="app-container container-xxl py-6">
        <div class="d-flex flex-wrap justify-content-between align-items-center gap-3 mb-6">
            <h1 class="fs-2 fw-bold mb-0">Chamadas por fazer</h1>
            <div class="w-250px">
                <SelectSolid v-model="estado.turma_id" :options="opcoesTurma" placeholder="Todas as turmas" searchable />
            </div>
        </div>

        <div class="card mb-6">
            <div class="card-body py-4 text-muted fs-7">Aulas previstas nos últimos 7 dias, até hoje, em que ainda não foi aberta a chamada nem marcada como não leccionada.</div>
        </div>

        <div class="card mb-6">
            <div class="card-body p-0">
                <EstadoVazio v-if="!pendentes.length" titulo="Nada por fazer" descricao="Todas as chamadas dos últimos 7 dias estão feitas ou tratadas." />

                <div v-else class="table-responsive">
                    <table class="table table-row-bordered align-middle gs-4 gy-3 mb-0">
                        <thead>
                            <tr class="fw-bold fs-7 text-muted text-uppercase"><th>Data</th><th>Turma</th><th>Aula</th><th>Docente</th><th></th></tr>
                        </thead>
                        <tbody>
                            <tr v-for="p in pendentes" :key="`${p.turma_id}-${p.data}-${p.horario_id ?? 'dia'}`">
                                <td class="text-nowrap">{{ formatarDia(p.data) }}</td>
                                <td>{{ p.turma_rotulo }}</td>
                                <td>{{ p.disciplina_nome ?? 'Chamada do dia' }} <span class="text-muted fs-8">· {{ tempo(p) }}</span></td>
                                <td :class="p.sem_docente ? 'text-danger fw-semibold' : ''">{{ p.docente_nome ?? 'Sem docente' }}</td>
                                <td class="text-end"><Link v-if="podeAbrir" :href="ligacao(p)" class="btn btn-sm btn-light-primary">Abrir chamada</Link></td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <div v-if="podeConfigurar" class="card">
            <div class="card-body">
                <h3 class="fs-5 fw-bold mb-1">Janela de correcção</h3>
                <div class="text-muted fs-7 mb-4">Até quantos dias depois da aula o professor ainda corrige a chamada. Depois disso só a secretaria, com motivo.</div>
                <div class="d-flex flex-wrap align-items-center gap-3">
                    <input v-model.number="janela" type="number" min="1" max="60" aria-label="Dias" class="form-control form-control-solid w-100px" />
                    <span class="text-muted">dias</span>
                    <button type="button" class="btn btn-success" :disabled="processing || janela === janelaDias" @click="guardarJanela">
                        <span v-if="processing">A guardar... <Loader size="0.3px" class="align-middle ms-2" /></span>
                        <span v-else>Guardar</span>
                    </button>
                </div>
                <div v-if="erro" class="text-danger fs-7 mt-2">{{ erro }}</div>
            </div>
        </div>
    </div>
</template>
