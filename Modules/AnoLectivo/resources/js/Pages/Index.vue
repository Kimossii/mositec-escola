<script setup>
import { computed, ref } from 'vue';
import { Deferred, router } from '@inertiajs/vue3';
import { toast } from 'vue-sonner';
import AppLayout from '@/Layouts/AppLayout.vue';
import { can } from '@/Composables/usePermissoes';
import AcaoIcone from '@/Components/Shared/AcaoIcone.vue';
import ConfirmModal from '@/Components/Shared/ConfirmModal.vue';
import AnoLectivoStatusBadge from '../Components/AnoLectivoStatusBadge.vue';
import AnoLectivoFormModal from '../Components/AnoLectivoFormModal.vue';
import { ESTADO_ANO_LECTIVO } from '../Models/AnoLectivo';

const props = defineProps({
    anoLectivos: { type: Array, required: true },
    contagens: { type: Object, default: null }, // diferido: { [id]: { matriculas, turmas } }
});
defineOptions({ layout: AppLayout });

// --- Visão geral ---
const DIA = 86400000;
const dia = (d) => new Date(`${d}T00:00:00`).getTime();
const hoje = () => { const d = new Date(); d.setHours(0, 0, 0, 0); return d.getTime(); };
const formatarData = (d) => new Intl.DateTimeFormat('pt-PT', { day: 'numeric', month: 'short', year: 'numeric' }).format(new Date(`${d}T00:00:00`));
const contagem = (id) => props.contagens?.[id] ?? { matriculas: 0, turmas: 0 };

/** Duração, percentagem decorrida e dias em falta de um ano lectivo. */
function decurso(ano) {
    const inicio = dia(ano.data_inicio);
    const fim = dia(ano.data_fim);
    const total = Math.max(1, Math.round((fim - inicio) / DIA));
    const decorridos = Math.round((hoje() - inicio) / DIA);

    return {
        total,
        percentagem: Math.min(100, Math.max(0, Math.round((decorridos / total) * 100))),
        faltam: Math.round((fim - hoje()) / DIA),
        porComecar: decorridos < 0 ? -decorridos : 0,
        meses: Math.max(1, Math.round(total / 30)),
    };
}

const activo = computed(() => props.anoLectivos.find((a) => a.estado === ESTADO_ANO_LECTIVO.ATIVO) ?? null);
const decursoActivo = computed(() => (activo.value ? decurso(activo.value) : null));
const proximo = computed(() => props.anoLectivos.filter((a) => a.estado === 0).sort((a, b) => dia(a.data_inicio) - dia(b.data_inicio))[0] ?? null);

const cartoes = computed(() => [
    { rotulo: 'Anos lectivos', valor: String(props.anoLectivos.length), nota: `${props.anoLectivos.filter((a) => a.estado === 2).length} encerrado(s)`, cor: 'primary' },
    { rotulo: 'Ano activo', valor: activo.value?.nome ?? '—', nota: decursoActivo.value ? `${decursoActivo.value.percentagem}% decorrido` : 'nenhum activo', cor: 'success' },
    { rotulo: 'Matrículas activas', valor: String(activo.value ? contagem(activo.value.id).matriculas : 0), nota: 'no ano activo', cor: 'info' },
    { rotulo: 'Turmas', valor: String(activo.value ? contagem(activo.value.id).turmas : 0), nota: 'no ano activo', cor: 'warning' },
]);

const filtroEstado = ref('todos');
const estados = [
    { valor: 'todos', texto: 'Todos' },
    { valor: ESTADO_ANO_LECTIVO.ATIVO, texto: 'Activo' },
    { valor: 0, texto: 'Planeado' },
    { valor: 2, texto: 'Encerrado' },
];
const listados = computed(() => props.anoLectivos.filter((a) => filtroEstado.value === 'todos' || a.estado === filtroEstado.value));

// --- Criar Ano Lectivo ---
const modalAberto = ref(false);
const processing = ref(false);
const errors = ref({});

function abrirCriacao() {
    errors.value = {};
    modalAberto.value = true;
}

function fecharModal() {
    modalAberto.value = false;
}

function criar(payload) {
    processing.value = true;
    errors.value = {};
    router.post('/ano-lectivos', payload, {
        preserveScroll: true,
        onSuccess: () => {
            toast.success('Ano Lectivo criado com sucesso.');
            fecharModal();
        },
        onError: (erros) => {
            errors.value = erros;
            toast.error(Object.values(erros)[0]);
        },
        onFinish: () => {
            processing.value = false;
        },
    });
}

// --- Alterar estado (Activar / Encerrar) ---
const anoLectivoParaAlterarEstado = ref(null);
const novoEstado = ref(null);
const alterandoEstado = ref(false);
// Encerrar com matrículas Activa/Pendente por resolver exige um segundo
// clique de confirmação — o backend recusa da primeira vez e devolve a
// contagem; mostramos essa mensagem e só no clique seguinte é que enviamos
// confirmar_encerramento_matriculas=true.
const confirmarEncerramentoMatriculas = ref(false);
const mensagemAlterarEstado = ref('');

function pedirActivacao(anoLectivo) {
    anoLectivoParaAlterarEstado.value = anoLectivo;
    novoEstado.value = ESTADO_ANO_LECTIVO.ATIVO;
    confirmarEncerramentoMatriculas.value = false;
    mensagemAlterarEstado.value = `Activar o Ano Lectivo ${anoLectivo.nome}?`;
}

function pedirEncerramento(anoLectivo) {
    anoLectivoParaAlterarEstado.value = anoLectivo;
    novoEstado.value = ESTADO_ANO_LECTIVO.ENCERRADO;
    confirmarEncerramentoMatriculas.value = false;
    mensagemAlterarEstado.value = `Encerrar o Ano Lectivo ${anoLectivo.nome}?`;
}

function cancelarAlteracaoEstado() {
    anoLectivoParaAlterarEstado.value = null;
    novoEstado.value = null;
    confirmarEncerramentoMatriculas.value = false;
    mensagemAlterarEstado.value = '';
}

function confirmarAlteracaoEstado() {
    alterandoEstado.value = true;
    router.patch(`/ano-lectivos/${anoLectivoParaAlterarEstado.value.id}/estado`, {
        estado: novoEstado.value,
        confirmar_encerramento_matriculas: confirmarEncerramentoMatriculas.value,
    }, {
        preserveScroll: true,
        onSuccess: () => {
            toast.success(novoEstado.value === ESTADO_ANO_LECTIVO.ATIVO ? 'Ano Lectivo activado.' : 'Ano Lectivo encerrado.');
            cancelarAlteracaoEstado();
        },
        onError: (erros) => {
            if (erros.ano_lectivo && !confirmarEncerramentoMatriculas.value) {
                mensagemAlterarEstado.value = erros.ano_lectivo;
                confirmarEncerramentoMatriculas.value = true;
                return;
            }
            toast.error(erros.ano_lectivo ?? Object.values(erros)[0]);
            cancelarAlteracaoEstado();
        },
        onFinish: () => {
            alterandoEstado.value = false;
        },
    });
}

// --- Eliminar ---
const anoLectivoParaEliminar = ref(null);
const eliminando = ref(false);

function pedirEliminacao(anoLectivo) {
    anoLectivoParaEliminar.value = anoLectivo;
}

function cancelarEliminacao() {
    anoLectivoParaEliminar.value = null;
}

function confirmarEliminacao() {
    eliminando.value = true;
    router.delete(`/ano-lectivos/${anoLectivoParaEliminar.value.id}`, {
        preserveScroll: true,
        onSuccess: () => toast.success('Ano Lectivo eliminado com sucesso.'),
        onError: (erros) => toast.error(Object.values(erros)[0]),
        onFinish: () => {
            eliminando.value = false;
            anoLectivoParaEliminar.value = null;
        },
    });
}
</script>

<template>
    <div class="app-container container-xxl py-6">
        <div class="d-flex justify-content-between align-items-center mb-6">
            <h1 class="fs-2 fw-bold">Anos Lectivos</h1>
            <button v-if="can('ano-lectivo.criar')" class="btn btn-primary" @click="abrirCriacao">Novo Ano Lectivo</button>
        </div>

        <!-- Ano activo: linha do tempo -->
        <div v-if="activo" class="hero-ano mb-6">
            <div class="d-flex flex-wrap align-items-start justify-content-between gap-4">
                <div>
                    <div class="hero-ano__eyebrow">Ano lectivo em curso</div>
                    <a :href="`/ano-lectivos/${activo.id}`" class="hero-ano__nome">{{ activo.nome }}</a>
                    <div class="hero-ano__datas">{{ formatarData(activo.data_inicio) }} → {{ formatarData(activo.data_fim) }} · {{ decursoActivo.meses }} meses</div>
                </div>
                <div class="text-end">
                    <div class="hero-ano__grande">{{ decursoActivo.faltam > 0 ? decursoActivo.faltam : 0 }}</div>
                    <div class="hero-ano__datas">{{ decursoActivo.faltam > 0 ? 'dias até ao fim' : 'prazo terminado' }}</div>
                </div>
            </div>
            <div class="hero-ano__barra"><span :style="{ width: `${decursoActivo.percentagem}%` }"></span><i :style="{ left: `${decursoActivo.percentagem}%` }"></i></div>
            <div class="d-flex justify-content-between hero-ano__datas">
                <span>Início</span><span class="fw-bold" style="color: #86efac">{{ decursoActivo.percentagem }}% decorrido</span><span>Fim</span>
            </div>
        </div>
        <div v-else class="card mb-6 border-0 bg-body-secondary">
            <div class="card-body d-flex flex-wrap align-items-center justify-content-between gap-3 py-5">
                <div class="d-flex align-items-center gap-3">
                    <i class="ki-duotone ki-calendar-tick fs-2x text-warning"><span class="path1"></span><span class="path2"></span><span class="path3"></span><span class="path4"></span><span class="path5"></span><span class="path6"></span></i>
                    <div>
                        <div class="fw-bold">Nenhum ano lectivo activo</div>
                        <div class="text-muted fs-7">{{ proximo ? `O próximo é ${proximo.nome}, a começar em ${formatarData(proximo.data_inicio)}.` : 'Crie um ano lectivo para começar.' }}</div>
                    </div>
                </div>
                <button v-if="proximo && can('ano-lectivo.editar')" class="btn btn-sm btn-success" @click="pedirActivacao(proximo)">Activar {{ proximo.nome }}</button>
            </div>
        </div>

        <Deferred data="contagens">
            <template #fallback>
                <div class="row g-4 mb-6">
                    <div v-for="n in 4" :key="n" class="col-12 col-sm-6 col-xl-3">
                        <div class="card h-100"><div class="card-body py-5 placeholder-glow">
                            <span class="placeholder col-5 mb-3"></span><span class="placeholder col-9 h-30px d-block mb-3 rounded"></span><span class="placeholder col-7"></span>
                        </div></div>
                    </div>
                </div>
            </template>
            <div class="row g-4 mb-6">
                <div v-for="c in cartoes" :key="c.rotulo" class="col-12 col-sm-6 col-xl-3">
                    <div class="card h-100 border-start border-4" :class="`border-${c.cor}`"><div class="card-body py-5">
                        <div class="text-muted fs-8 fw-bold text-uppercase mb-1">{{ c.rotulo }}</div>
                        <div class="fs-2 fw-bolder lh-1" :class="`text-${c.cor}`">{{ c.valor }}</div>
                        <div class="text-muted fs-7 mt-3">{{ c.nota }}</div>
                    </div></div>
                </div>
            </div>
        </Deferred>

        <div class="d-flex flex-wrap gap-2 mb-4">
            <button
                v-for="e in estados"
                :key="e.valor"
                type="button"
                class="btn btn-sm"
                :class="filtroEstado === e.valor ? 'btn-success' : 'btn-light'"
                @click="filtroEstado = e.valor"
            >{{ e.texto }}</button>
        </div>

        <div class="card">
            <div class="card-body p-0">
                <table class="table align-middle table-row-dashed table-hover fs-6 gy-5 mb-0">
                    <thead>
                        <tr class="text-start text-muted fw-bold fs-7 text-uppercase gs-0">
                            <th class="min-w-150px ps-6">Ano lectivo</th>
                            <th class="min-w-250px">Período</th>
                            <th class="min-w-100px">Matrículas</th>
                            <th class="min-w-100px">Turmas</th>
                            <th class="min-w-100px">Estado</th>
                            <th class="text-end min-w-125px pe-6">Ações</th>
                        </tr>
                    </thead>
                    <tbody class="text-gray-600 fw-semibold">
                        <tr v-if="listados.length === 0">
                            <td colspan="6" class="text-center text-muted py-6">{{ anoLectivos.length === 0 ? 'Nenhum Ano Lectivo criado.' : 'Nenhum ano lectivo com este estado.' }}</td>
                        </tr>
                        <tr v-for="anoLectivo in listados" :key="anoLectivo.id" :class="{ 'bg-light-success bg-opacity-25': anoLectivo.estado === 1 }">
                            <td class="ps-6">
                                <a :href="`/ano-lectivos/${anoLectivo.id}`" class="fw-bold text-gray-800 text-hover-primary fs-5">{{ anoLectivo.nome }}</a>
                            </td>
                            <td>
                                <div class="text-gray-800">{{ formatarData(anoLectivo.data_inicio) }} — {{ formatarData(anoLectivo.data_fim) }}</div>
                                <div class="d-flex align-items-center gap-3 mt-2">
                                    <div class="progress h-6px w-150px flex-shrink-0"><div class="progress-bar" :class="anoLectivo.estado === 2 ? 'bg-gray-400' : 'bg-success'" :style="{ width: `${anoLectivo.estado === 0 ? 0 : decurso(anoLectivo).percentagem}%` }"></div></div>
                                    <span class="text-muted fs-8">
                                        <template v-if="anoLectivo.estado === 2">terminado</template>
                                        <template v-else-if="decurso(anoLectivo).porComecar">começa em {{ decurso(anoLectivo).porComecar }} dia(s)</template>
                                        <template v-else>{{ decurso(anoLectivo).percentagem }}% · {{ Math.max(0, decurso(anoLectivo).faltam) }} dia(s) em falta</template>
                                    </span>
                                </div>
                            </td>
                            <td><span v-if="contagens" class="badge badge-light-info fs-7">{{ contagem(anoLectivo.id).matriculas }}</span><span v-else class="placeholder-glow"><span class="placeholder col-6 rounded"></span></span></td>
                            <td><span v-if="contagens" class="badge badge-light-warning fs-7">{{ contagem(anoLectivo.id).turmas }}</span><span v-else class="placeholder-glow"><span class="placeholder col-6 rounded"></span></span></td>
                            <td>
                                <AnoLectivoStatusBadge :estado="anoLectivo.estado" :estado-descricao="anoLectivo.estado_descricao" />
                            </td>
                            <td class="text-end pe-6">
                                <a href="#" class="btn btn-light btn-active-light-primary btn-flex btn-center btn-sm" data-kt-menu-trigger="click" data-kt-menu-placement="bottom-end">
                                    Ações
                                    <i class="ki-duotone ki-down fs-5 ms-1"></i>
                                </a>
                                <div class="menu menu-sub menu-sub-dropdown menu-column menu-rounded menu-gray-600 menu-state-bg-light-primary fw-semibold fs-7 w-175px py-4" data-kt-menu="true">
                                    <div class="menu-item px-3">
                                        <a :href="`/ano-lectivos/${anoLectivo.id}`" class="menu-link px-3">
                                            <AcaoIcone acao="visualizar" class="me-2" />
                                            Ver
                                        </a>
                                    </div>
                                    <div v-if="anoLectivo.estado === 0 && can('ano-lectivo.editar')" class="menu-item px-3">
                                        <a href="#" class="menu-link px-3" @click.prevent="pedirActivacao(anoLectivo)">
                                            <AcaoIcone acao="ativar" class="me-2" />
                                            Activar
                                        </a>
                                    </div>
                                    <div v-if="anoLectivo.estado === 1 && can('ano-lectivo.editar')" class="menu-item px-3">
                                        <a href="#" class="menu-link px-3" @click.prevent="pedirEncerramento(anoLectivo)">
                                            <AcaoIcone acao="encerrar" class="me-2" />
                                            Encerrar
                                        </a>
                                    </div>
                                    <div v-if="can('ano-lectivo.eliminar')" class="menu-item px-3">
                                        <a href="#" class="menu-link px-3 text-danger" @click.prevent="pedirEliminacao(anoLectivo)">
                                            <AcaoIcone acao="eliminar" class="me-2" />
                                            Eliminar
                                        </a>
                                    </div>
                                </div>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>

        <AnoLectivoFormModal
            :show="modalAberto"
            :processing="processing"
            :errors="errors"
            @submit="criar"
            @cancelar="fecharModal"
        />

        <ConfirmModal
            :show="!!anoLectivoParaAlterarEstado"
            titulo="Alterar estado"
            :mensagem="mensagemAlterarEstado"
            :texto-confirmar="confirmarEncerramentoMatriculas ? 'Confirmar Encerramento' : 'Confirmar'"
            :processando="alterandoEstado"
            @confirmar="confirmarAlteracaoEstado"
            @cancelar="cancelarAlteracaoEstado"
        />

        <ConfirmModal
            :show="!!anoLectivoParaEliminar"
            titulo="Eliminar Ano Lectivo"
            :mensagem="`Tem certeza que deseja eliminar o Ano Lectivo ${anoLectivoParaEliminar?.nome}?`"
            :processando="eliminando"
            @confirmar="confirmarEliminacao"
            @cancelar="cancelarEliminacao"
        />
    </div>
</template>

<style scoped>
.hero-ano {
    padding: 1.6rem 1.9rem;
    border-radius: 1rem;
    color: #f8fafc;
    background: linear-gradient(120deg, #0f172a 0%, #12302f 60%, #166534 140%);
}
.hero-ano__eyebrow { font-size: 0.7rem; font-weight: 600; letter-spacing: 0.1em; text-transform: uppercase; color: #86efac; }
.hero-ano__nome { display: block; font-size: 2rem; font-weight: 700; line-height: 1.15; color: #fff; text-decoration: none; margin: 0.15rem 0; }
.hero-ano__nome:hover { color: #bbf7d0; }
.hero-ano__datas { font-size: 0.82rem; color: rgba(248, 250, 252, 0.7); }
.hero-ano__grande { font-size: 2.6rem; font-weight: 800; line-height: 1; color: #86efac; }
.hero-ano__barra { position: relative; height: 10px; border-radius: 999px; background: rgba(248, 250, 252, 0.15); margin: 1.25rem 0 0.5rem; }
.hero-ano__barra span { display: block; height: 100%; border-radius: 999px; background: linear-gradient(90deg, #22c55e, #86efac); }
.hero-ano__barra i { position: absolute; top: 50%; width: 16px; height: 16px; border-radius: 50%; background: #fff; border: 3px solid #22c55e; transform: translate(-50%, -50%); box-shadow: 0 0 0 4px rgba(34, 197, 94, 0.25); }
</style>
