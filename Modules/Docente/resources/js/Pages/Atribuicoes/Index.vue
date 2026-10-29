<script setup>
import { computed, reactive, ref, watch } from 'vue';
import { router } from '@inertiajs/vue3';
import { toast } from 'vue-sonner';
import AppLayout from '@/Layouts/AppLayout.vue';
import FiltrosRecolhiveis from '@/Components/Shared/FiltrosRecolhiveis.vue';
import IndicadoresDiferidos from '@/Components/Shared/IndicadoresDiferidos.vue';
import MenuExportar from '@/Components/Shared/MenuExportar.vue';
import ConfirmModal from '@/Components/Shared/ConfirmModal.vue';
import SelectSolid from '@/Components/Shared/SelectSolid.vue';
import { can } from '@/Composables/usePermissoes';
import VistaPorTurma from '../../Components/Atribuicoes/VistaPorTurma.vue';
import VistaLista from '../../Components/Atribuicoes/VistaLista.vue';
import AtribuirDocenteModal from '../../Components/Atribuicoes/AtribuirDocenteModal.vue';
import SubstituirTitularModal from '../../Components/Atribuicoes/SubstituirTitularModal.vue';
import EditarAtribuicaoModal from '../../Components/Atribuicoes/EditarAtribuicaoModal.vue';
import EncerrarAtribuicaoModal from '../../Components/Atribuicoes/EncerrarAtribuicaoModal.vue';
import { PAPEL, VIGENCIA } from '../../Models/PapelDocente';

const props = defineProps({
    anoLectivoId: { type: Number, default: null },
    vista: { type: String, default: 'turma' }, // 'turma' | 'lista'
    porTurma: { type: Object, default: null }, // { linhas, motivo_vazio } só com turma_id
    atribuicoes: { type: Object, required: true }, // paginator
    indicadores: { type: Object, default: null }, // diferido: { em_vigor, disciplinas_sem_titular }
    filtros: { type: Object, default: () => ({}) },
    opcoes: { type: Object, required: true }, // { anosLectivos, turmas, docentes, disciplinas }
});
defineOptions({ layout: AppLayout });

const URL_BASE = '/docentes/atribuicoes';
const id = (v) => (v === undefined || v === null || v === '' ? '' : Number(v));

const opcoesAno = computed(() => props.opcoes.anosLectivos.map((a) => ({ value: a.id, label: a.activo ? `${a.nome} (activo)` : a.nome })));
const opcoesTurma = computed(() => props.opcoes.turmas.map((t) => ({ value: t.id, label: t.codigo ? `${t.nome} (${t.codigo})` : t.nome })));
const opcoesTurmaFiltro = computed(() => [{ value: '', label: 'Todas as turmas' }, ...opcoesTurma.value]);
const opcoesDisciplinaFiltro = computed(() => [{ value: '', label: 'Todas as disciplinas' }, ...(props.opcoes.disciplinas ?? []).map((d) => ({ value: d.id, label: d.nome }))]);
// Ids dos docentes activos ainda sem conta: as linhas assinalam-nos (não poderão lançar notas enquanto não tiverem acesso).
const docentesSemAcesso = computed(() => props.opcoes.docentes.filter((d) => !d.tem_acesso).map((d) => d.id));
const opcoesDocenteFiltro = computed(() => [{ value: '', label: 'Todos os docentes' }, ...props.opcoes.docentes.map((d) => ({ value: d.id, label: d.nome }))]);
const opcoesPapel = [
    { value: '', label: 'Todos os papéis' },
    { value: PAPEL.TITULAR, label: 'Titular' },
    { value: PAPEL.SUBSTITUTO, label: 'Substituto' },
];
const opcoesVigencia = [
    { value: '', label: 'Todos os estados' },
    { value: VIGENCIA.EM_CURSO, label: 'Em curso' },
    { value: VIGENCIA.AGENDADA, label: 'Agendada' },
    { value: VIGENCIA.TERMINADA, label: 'Terminada' },
];

const VISTAS = [
    { valor: 'turma', texto: 'Por turma', icone: 'ki-people', paths: 5 },
    { valor: 'lista', texto: 'Lista', icone: 'ki-row-horizontal', paths: 2 },
];

const estado = reactive({
    ano_lectivo_id: id(props.anoLectivoId),
    vista: props.vista,
    turma_id: id(props.filtros.turma_id),
    docente_id: id(props.filtros.docente_id),
    disciplina_id: id(props.filtros.disciplina_id),
    papel: id(props.filtros.papel),
    estado_vigencia: props.filtros.estado_vigencia ?? '',
    pesquisa: props.filtros.pesquisa ?? '',
});

const turmaEscolhida = computed(() => props.opcoes.turmas.find((t) => t.id === estado.turma_id) ?? null);
const filtrosAtivos = computed(() => ['turma_id', 'docente_id', 'disciplina_id', 'papel', 'estado_vigencia'].filter((c) => estado[c] !== '').length);
const comFiltros = computed(() => filtrosAtivos.value > 0 || estado.pesquisa !== '');
const limparFiltros = () => Object.assign(estado, { turma_id: '', docente_id: '', disciplina_id: '', papel: '', estado_vigencia: '' });

const parametros = () => Object.fromEntries(Object.entries(estado).filter(([, v]) => v !== ''));
const paramsExportacao = computed(() => {
    const { vista, ...resto } = parametros();
    return resto;
});

// O ano muda as turmas e as disciplinas disponíveis: as escolhidas deixam de fazer sentido.
watch(() => estado.ano_lectivo_id, () => { estado.turma_id = ''; estado.disciplina_id = ''; });

let debounceId = null;
watch(estado, () => {
    clearTimeout(debounceId);
    debounceId = setTimeout(() => {
        router.get(URL_BASE, parametros(), { preserveState: true, preserveScroll: true, replace: true });
    }, 300);
});

const montarIndicadores = (i) => [
    {
        rotulo: 'Disciplinas sem titular',
        valor: String(i.disciplinas_sem_titular),
        nota: i.disciplinas_sem_titular ? 'em falta em todas as turmas do ano' : 'todas as disciplinas cobertas',
        cor: i.disciplinas_sem_titular ? 'danger' : 'success',
    },
    { rotulo: 'Atribuições em vigor', valor: String(i.em_vigor), nota: 'titulares e substitutos hoje', cor: 'primary' },
    { rotulo: 'Turmas activas', valor: String(props.opcoes.turmas.length), nota: 'no ano lectivo escolhido', cor: 'info' },
];

// ── Modais ──
const modal = ref(null); // 'atribuir' | 'substituir' | 'editar' | 'encerrar'
const linhaAlvo = ref(null);
const atribuicaoAlvo = ref(null);
const processing = ref(false);
const errors = ref({});

const contextoAlvo = computed(() => {
    const disciplina = linhaAlvo.value?.disciplina_nome ?? atribuicaoAlvo.value?.disciplina_nome;
    const turma = atribuicaoAlvo.value?.turma_nome ?? turmaEscolhida.value?.nome;
    return [disciplina, turma].filter(Boolean).join(' · ');
});

function abrir(tipo, atribuicao = null, linha = null) {
    errors.value = {};
    atribuicaoAlvo.value = atribuicao;
    linhaAlvo.value = linha;
    modal.value = tipo;
}

function fechar() {
    modal.value = null;
}

function enviar(metodo, url, dados, sucesso) {
    processing.value = true;
    errors.value = {};
    router[metodo](url, dados, {
        preserveScroll: true,
        onSuccess: () => {
            toast.success(sucesso);
            fechar();
        },
        onError: (erros) => {
            errors.value = erros;
            toast.error(Object.values(erros)[0]);
        },
        onFinish: () => { processing.value = false; },
    });
}

const atribuir = (dados) => enviar('post', URL_BASE, dados, 'Docente atribuído com sucesso.');
const substituir = (dados) => enviar('post', `${URL_BASE}/substituir`, dados, 'Titular substituído com sucesso.');
const actualizar = (dados) => enviar('put', `${URL_BASE}/${atribuicaoAlvo.value.id}`, dados, 'Vigência actualizada com sucesso.');
const encerrar = (dados) => enviar('patch', `${URL_BASE}/${atribuicaoAlvo.value.id}/encerrar`, dados, 'Atribuição encerrada com sucesso.');

const paraEliminar = ref(null);
const eliminando = ref(false);

function confirmarEliminacao() {
    eliminando.value = true;
    router.delete(`${URL_BASE}/${paraEliminar.value.id}`, {
        preserveScroll: true,
        onSuccess: () => toast.success('Atribuição eliminada com sucesso.'),
        onError: (erros) => toast.error(Object.values(erros)[0]),
        onFinish: () => {
            eliminando.value = false;
            paraEliminar.value = null;
        },
    });
}
</script>

<template>
    <div class="app-container container-xxl py-6">
        <div class="d-flex flex-wrap justify-content-between align-items-center gap-3 mb-6">
            <h1 class="fs-2 fw-bold mb-0">Atribuições docentes</h1>
            <div class="d-flex flex-wrap align-items-center gap-3">
                <div class="w-200px">
                    <SelectSolid v-model="estado.ano_lectivo_id" :options="opcoesAno" placeholder="Ano lectivo" />
                </div>
                <MenuExportar v-if="estado.vista === 'lista' && can('atribuicao-docente.exportar')" :url="`${URL_BASE}/exportar`" :params="paramsExportacao" />
            </div>
        </div>

        <div v-if="!opcoes.anosLectivos.length" class="card border-0 bg-body-secondary">
            <div class="card-body d-flex align-items-center gap-3 py-5">
                <i class="ki-duotone ki-calendar-tick fs-2x text-warning"><span class="path1"></span><span class="path2"></span><span class="path3"></span><span class="path4"></span><span class="path5"></span><span class="path6"></span></i>
                <div>
                    <div class="fw-bold">Nenhum ano lectivo</div>
                    <div class="text-muted fs-7">Crie um ano lectivo e as suas turmas antes de atribuir docentes.</div>
                </div>
            </div>
        </div>

        <template v-else>
            <IndicadoresDiferidos :indicadores="indicadores" :montar="montarIndicadores" :quantidade="3" />

            <div class="d-flex mb-6">
                <div class="d-inline-flex p-1 rounded bg-body-secondary" role="group" aria-label="Vista das atribuições">
                    <button
                        v-for="o in VISTAS"
                        :key="o.valor"
                        type="button"
                        class="btn btn-sm d-flex align-items-center px-4 border-0"
                        :class="estado.vista === o.valor ? 'btn-success shadow-sm' : 'btn-transparent text-gray-600'"
                        :aria-pressed="estado.vista === o.valor"
                        @click="estado.vista = o.valor"
                    >
                        <i class="ki-duotone fs-4 me-2" :class="o.icone"><span v-for="n in o.paths" :key="n" :class="`path${n}`"></span></i>{{ o.texto }}
                    </button>
                </div>
            </div>

            <template v-if="estado.vista === 'turma'">
                <div v-if="opcoes.turmas.length" class="card mb-6">
                    <div class="card-body py-4">
                        <div class="row g-4 align-items-end">
                            <div class="col-12 col-md-6 col-xl-4">
                                <label class="fw-semibold fs-7 text-muted mb-1">Turma</label>
                                <SelectSolid v-model="estado.turma_id" :options="opcoesTurma" placeholder="Seleccione a turma" searchable />
                            </div>
                        </div>
                    </div>
                </div>

                <VistaPorTurma
                    :por-turma="estado.turma_id !== '' ? porTurma : null"
                    :turma="turmaEscolhida"
                    :sem-turmas="!opcoes.turmas.length"
                    :docentes-sem-acesso="docentesSemAcesso"
                    @atribuir="(linha) => abrir('atribuir', null, linha)"
                    @substituir="(linha) => abrir('substituir', null, linha)"
                    @editar="(a, linha) => abrir('editar', a, linha)"
                    @encerrar="(a, linha) => abrir('encerrar', a, linha)"
                    @eliminar="(a) => (paraEliminar = a)"
                />
            </template>

            <template v-else>
                <FiltrosRecolhiveis v-model="estado.pesquisa" placeholder="Pesquisar por docente, disciplina ou turma" :ativos="filtrosAtivos" @limpar="limparFiltros">
                    <div class="col-12 col-md-6 col-xl-4">
                        <label class="fw-semibold fs-7 text-muted mb-1">Turma</label>
                        <SelectSolid v-model="estado.turma_id" :options="opcoesTurmaFiltro" searchable />
                    </div>
                    <div class="col-12 col-md-6 col-xl-4">
                        <label class="fw-semibold fs-7 text-muted mb-1">Docente</label>
                        <SelectSolid v-model="estado.docente_id" :options="opcoesDocenteFiltro" searchable />
                    </div>
                    <div class="col-12 col-md-6 col-xl-4">
                        <label class="fw-semibold fs-7 text-muted mb-1">Disciplina</label>
                        <SelectSolid v-model="estado.disciplina_id" :options="opcoesDisciplinaFiltro" searchable />
                    </div>
                    <div class="col-12 col-md-6 col-xl-6">
                        <label class="fw-semibold fs-7 text-muted mb-1">Papel</label>
                        <SelectSolid v-model="estado.papel" :options="opcoesPapel" />
                    </div>
                    <div class="col-12 col-md-6 col-xl-6">
                        <label class="fw-semibold fs-7 text-muted mb-1">Estado</label>
                        <SelectSolid v-model="estado.estado_vigencia" :options="opcoesVigencia" />
                    </div>
                </FiltrosRecolhiveis>

                <VistaLista
                    :atribuicoes="atribuicoes"
                    :com-filtros="comFiltros"
                    :docentes-sem-acesso="docentesSemAcesso"
                    @editar="(a) => abrir('editar', a)"
                    @encerrar="(a) => abrir('encerrar', a)"
                    @eliminar="(a) => (paraEliminar = a)"
                />
            </template>
        </template>

        <AtribuirDocenteModal
            :show="modal === 'atribuir'"
            :linha="linhaAlvo"
            :turma="turmaEscolhida"
            :docentes="opcoes.docentes"
            :processing="processing"
            :errors="errors"
            @submit="atribuir"
            @cancelar="fechar"
        />
        <SubstituirTitularModal
            :show="modal === 'substituir'"
            :linha="linhaAlvo"
            :turma="turmaEscolhida"
            :docentes="opcoes.docentes"
            :processing="processing"
            :errors="errors"
            @submit="substituir"
            @cancelar="fechar"
        />
        <EditarAtribuicaoModal
            :show="modal === 'editar'"
            :atribuicao="atribuicaoAlvo"
            :contexto="contextoAlvo"
            :processing="processing"
            :errors="errors"
            @submit="actualizar"
            @cancelar="fechar"
        />
        <EncerrarAtribuicaoModal
            :show="modal === 'encerrar'"
            :atribuicao="atribuicaoAlvo"
            :contexto="contextoAlvo"
            :processing="processing"
            :errors="errors"
            @submit="encerrar"
            @cancelar="fechar"
        />

        <ConfirmModal
            :show="!!paraEliminar"
            titulo="Eliminar atribuição"
            :mensagem="`Eliminar a atribuição agendada de ${paraEliminar?.docente_nome}? Só é possível porque ainda não começou.`"
            texto-confirmar="Eliminar"
            :processando="eliminando"
            @confirmar="confirmarEliminacao"
            @cancelar="paraEliminar = null"
        />
    </div>
</template>
