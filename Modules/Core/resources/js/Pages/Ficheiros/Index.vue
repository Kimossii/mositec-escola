<script setup>
import AcaoIcone from '@/Components/Shared/AcaoIcone.vue';
import { computed, onBeforeUnmount, onMounted, reactive, ref } from 'vue';
import { router } from '@inertiajs/vue3';
import { toast } from 'vue-sonner';
import AppLayout from '@/Layouts/AppLayout.vue';
import ConfirmModal from '@/Components/Shared/ConfirmModal.vue';
import FicheiroLinha from '../../Components/Ficheiros/FicheiroLinha.vue';
import PartilharModal from '../../Components/Ficheiros/PartilharModal.vue';
import { formatarTamanho, formatarData } from '../../Support/ficheiros';

const props = defineProps({
    pedidos: { type: Array, required: true }, // ficheiros do utilizador; a «pasta» é o título da exportação
    partilhados: { type: Array, default: () => [] }, // ficheiros que colegas partilharam comigo
});
defineOptions({ layout: AppLayout });

const pesquisa = ref('');
const pastaAberta = ref(null); // nome da pasta em que se entrou; null = lista de pastas
const expandidas = reactive(new Set()); // pastas abertas «em colapso», sem sair da lista
const seleccionados = reactive(new Set());
const paraEliminar = ref([]); // ids à espera de confirmação
const partilha = ref(null); // { titulo, ids, referencia, actuais } do modal de partilha
const PASTA_RECEBIDOS = 'Partilhados comigo';
const eliminando = ref(false);

const termo = computed(() => pesquisa.value.trim().toLowerCase());
const bate = (f) => !termo.value || f.nome.toLowerCase().includes(termo.value) || f.titulo.toLowerCase().includes(termo.value);

const pastas = computed(() => {
    const mapa = new Map();
    for (const f of props.pedidos.filter(bate)) {
        if (!mapa.has(f.titulo)) mapa.set(f.titulo, { nome: f.titulo, ficheiros: [], tamanho: 0, ultimo: null, preparar: 0 });
        const p = mapa.get(f.titulo);
        p.ficheiros.push(f);
        p.tamanho += f.estado === 1 ? f.tamanho : 0;
        p.preparar += f.estado === 0 ? 1 : 0;
        p.ultimo = !p.ultimo || f.criado_em > p.ultimo ? f.criado_em : p.ultimo;
    }

    return [...mapa.values()].sort((a, b) => (b.ultimo ?? '').localeCompare(a.ultimo ?? ''));
});
const recebidos = computed(() => props.partilhados.filter(bate));
const pasta = computed(() => (pastaAberta.value === PASTA_RECEBIDOS ? { nome: PASTA_RECEBIDOS, ficheiros: recebidos.value, recebidos: true } : pastas.value.find((p) => p.nome === pastaAberta.value) ?? null));

function abrirPartilha(titulo, ficheiros) {
    const prontos = ficheiros.filter((f) => f.partilhavel);
    if (!prontos.length) return;
    const nomes = new Map();
    prontos.forEach((f) => (f.partilhado_com ?? []).forEach((u) => nomes.set(u.id, u.nome)));
    partilha.value = { titulo, ids: prontos.map((f) => f.id), referencia: prontos[0].id, actuais: [...nomes].map(([id, nome]) => ({ id, nome })) };
}

const totais = computed(() => ({
    ficheiros: props.pedidos.length,
    tamanho: props.pedidos.reduce((s, f) => s + (f.estado === 1 ? f.tamanho : 0), 0),
    preparar: props.pedidos.filter((f) => f.estado === 0).length,
}));

const alternarPasta = (nome) => (expandidas.has(nome) ? expandidas.delete(nome) : expandidas.add(nome));
const entrar = (nome) => { pastaAberta.value = nome; seleccionados.clear(); };
const sair = () => { pastaAberta.value = null; seleccionados.clear(); };
const alternar = (id) => (seleccionados.has(id) ? seleccionados.delete(id) : seleccionados.add(id));

const idsDaVista = computed(() => (pasta.value ? pasta.value.ficheiros : pastas.value.filter((p) => expandidas.has(p.nome)).flatMap((p) => p.ficheiros)).filter((f) => f.estado !== 0).map((f) => f.id));
const todosSeleccionados = computed(() => idsDaVista.value.length > 0 && idsDaVista.value.every((id) => seleccionados.has(id)));
const alternarTodos = () => (todosSeleccionados.value ? idsDaVista.value.forEach((id) => seleccionados.delete(id)) : idsDaVista.value.forEach((id) => seleccionados.add(id)));

const pedirEliminar = (ids) => { paraEliminar.value = ids; };
const idsTerminados = (p) => p.ficheiros.filter((f) => f.estado !== 0).map((f) => f.id);
const mensagemEliminar = computed(() => (paraEliminar.value.length === 1 ? 'Vai eliminar 1 ficheiro de forma definitiva.' : `Vai eliminar ${paraEliminar.value.length} ficheiros de forma definitiva.`) + ' Pode voltar a pedir a exportação quando quiser.');

function eliminar() {
    eliminando.value = true;
    router.delete('/ficheiros', {
        data: { ids: paraEliminar.value },
        preserveScroll: true,
        onSuccess: () => { seleccionados.clear(); paraEliminar.value = []; if (pastaAberta.value && !pastas.value.some((p) => p.nome === pastaAberta.value)) sair(); },
        onError: () => toast.error('Não foi possível eliminar.'),
        onFinish: () => { eliminando.value = false; },
    });
}

// Enquanto houver ficheiros a preparar, volta a perguntar ao servidor de 4 em 4 segundos.
let relogio = null;
onMounted(() => {
    relogio = setInterval(() => {
        if (totais.value.preparar > 0) router.reload({ only: ['pedidos'] });
    }, 4000);
});
onBeforeUnmount(() => clearInterval(relogio));
</script>

<template>
    <div class="app-container container-xxl py-6">
        <!-- Cabeçalho, como o gestor de ficheiros do tema -->
        <div class="card card-flush mb-8">
            <div class="card-header pt-8 pb-6">
                <div class="d-flex align-items-center">
                    <div class="symbol symbol-circle me-5">
                        <div class="symbol-label bg-transparent text-primary border border-secondary border-dashed">
                            <i class="ki-duotone ki-folder fs-2x text-primary"><span class="path1"></span><span class="path2"></span></i>
                        </div>
                    </div>
                    <div class="d-flex flex-column">
                        <h2 class="mb-1">Os meus ficheiros</h2>
                        <div class="text-muted fw-bold">
                            {{ totais.ficheiros }} {{ totais.ficheiros === 1 ? 'ficheiro' : 'ficheiros' }}
                            <span class="mx-3">|</span> {{ formatarTamanho(totais.tamanho) }}
                            <template v-if="totais.preparar"><span class="mx-3">|</span><span class="text-warning">{{ totais.preparar }} a preparar</span></template>
                        </div>
                        <div class="text-muted fs-8 mt-1">Exportações grandes preparadas em segundo plano. Ficam disponíveis 7 dias e guardamos os seus últimos 20.</div>
                    </div>
                </div>
            </div>
        </div>

        <div class="card card-flush">
            <div class="card-header pt-8">
                <div class="card-title">
                    <div class="d-flex align-items-center position-relative my-1">
                        <AcaoIcone acao="pesquisar" tamanho="fs-3" herda-cor class="position-absolute ms-6" />
                        <input v-model="pesquisa" type="text" class="form-control form-control-solid w-250px ps-15" placeholder="Pesquisar ficheiros" />
                    </div>
                </div>
                <div class="card-toolbar">
                    <div v-if="seleccionados.size" class="d-flex align-items-center">
                        <div class="fw-bold me-5"><span class="me-2">{{ seleccionados.size }}</span>seleccionado(s)</div>
                        <button type="button" class="btn btn-danger" @click="pedirEliminar([...seleccionados])">Eliminar seleccionados</button>
                    </div>
                </div>
            </div>

            <div class="card-body">
                <!-- Caminho + contador -->
                <div class="d-flex flex-stack mb-5">
                    <div class="badge badge-lg badge-light-primary">
                        <div class="d-flex align-items-center flex-wrap">
                            <i class="ki-duotone ki-abstract-32 fs-2 text-primary me-3"><span class="path1"></span><span class="path2"></span></i>
                            <a href="#" @click.prevent="sair">Os meus ficheiros</a>
                            <template v-if="pasta">
                                <i class="ki-duotone ki-right fs-2 text-primary mx-1"></i>
                                {{ pasta.nome }}
                            </template>
                        </div>
                    </div>
                    <div class="badge badge-lg badge-primary">{{ pasta ? `${pasta.ficheiros.length} ficheiros` : `${pastas.length + (recebidos.length ? 1 : 0)} pastas` }}</div>
                </div>

                <div v-if="!pastas.length && !recebidos.length" class="text-center py-15">
                    <i class="ki-duotone ki-folder-up fs-3x text-gray-400 mb-4"><span class="path1"></span><span class="path2"></span></i>
                    <div class="fw-bold fs-5 text-gray-700 mb-1">{{ termo ? 'Nenhum ficheiro corresponde à pesquisa.' : 'Ainda não tem ficheiros.' }}</div>
                    <div v-if="!termo" class="text-muted">Quando pedir uma exportação grande (PDF, ZIP ou listas muito longas), aparece aqui, organizada por pasta.</div>
                </div>

                <div v-else class="table-responsive">
                    <table class="table align-middle table-row-dashed fs-6 gy-5 mb-0">
                        <thead>
                            <tr class="text-start text-gray-400 fw-bold fs-7 text-uppercase gs-0">
                                <th class="w-10px pe-2">
                                    <div v-if="pasta || expandidas.size" class="form-check form-check-sm form-check-custom form-check-solid me-3">
                                        <input class="form-check-input" type="checkbox" :checked="todosSeleccionados" @change="alternarTodos" />
                                    </div>
                                </th>
                                <th class="min-w-250px">Nome</th>
                                <th>Formato</th>
                                <th>Tamanho</th>
                                <th>Linhas</th>
                                <th class="min-w-125px">Criado em</th>
                                <th class="w-75px"></th>
                            </tr>
                        </thead>
                        <tbody class="fw-semibold text-gray-600">
                            <!-- Dentro de uma pasta -->
                            <template v-if="pasta">
                                <FicheiroLinha v-for="f in pasta.ficheiros" :key="f.id" :ficheiro="f" :recebido="!!pasta.recebidos" :selecionado="seleccionados.has(f.id)" @alternar="alternar" @eliminar="(id) => pedirEliminar([id])" @partilhar="(fx) => abrirPartilha(fx.titulo, [fx])" />
                            </template>

                            <!-- Lista de pastas (abre-se em colapso ou entra-se) -->
                            <template v-else>
                                <tr v-if="recebidos.length">
                                    <td></td>
                                    <td>
                                        <div class="d-flex align-items-center">
                                            <i class="ki-duotone ki-share fs-2x text-success me-4"><span class="path1"></span><span class="path2"></span><span class="path3"></span></i>
                                            <a href="#" class="text-gray-800 text-hover-primary fw-bold" @click.prevent="entrar(PASTA_RECEBIDOS)">{{ PASTA_RECEBIDOS }}</a>
                                        </div>
                                    </td>
                                    <td><span class="text-muted">{{ recebidos.length }} {{ recebidos.length === 1 ? 'ficheiro' : 'ficheiros' }}</span></td>
                                    <td></td><td></td><td></td>
                                    <td class="text-end"><button type="button" class="btn btn-sm btn-light-primary" @click="entrar(PASTA_RECEBIDOS)">Abrir</button></td>
                                </tr>
                                <template v-for="p in pastas" :key="p.nome">
                                    <tr>
                                        <td></td>
                                        <td>
                                            <div class="d-flex align-items-center">
                                                <button type="button" class="btn btn-sm btn-icon btn-light me-3" :title="expandidas.has(p.nome) ? 'Fechar' : 'Abrir aqui'" @click="alternarPasta(p.nome)">
                                                    <i class="ki-duotone fs-5" :class="expandidas.has(p.nome) ? 'ki-down' : 'ki-right'"></i>
                                                </button>
                                                <i class="ki-duotone fs-2x text-primary me-4" :class="expandidas.has(p.nome) ? 'ki-folder-down' : 'ki-folder'"><span class="path1"></span><span class="path2"></span></i>
                                                <a href="#" class="text-gray-800 text-hover-primary fw-bold" @click.prevent="entrar(p.nome)">{{ p.nome }}</a>
                                                <span v-if="p.preparar" class="badge badge-light-warning ms-3">{{ p.preparar }} a preparar</span>
                                            </div>
                                        </td>
                                        <td><span class="text-muted">{{ p.ficheiros.length }} {{ p.ficheiros.length === 1 ? 'ficheiro' : 'ficheiros' }}</span></td>
                                        <td>{{ formatarTamanho(p.tamanho) }}</td>
                                        <td></td>
                                        <td>{{ formatarData(p.ultimo) }}</td>
                                        <td class="text-end text-nowrap">
                                            <button type="button" class="btn btn-sm btn-light-primary me-2" @click="entrar(p.nome)">Abrir</button>
                                            <a v-if="p.ficheiros.some((f) => f.estado === 1)" :href="`/ficheiros/pasta/descarregar?titulo=${encodeURIComponent(p.nome)}`" class="btn btn-sm btn-icon btn-light btn-active-light-primary" title="Descarregar a pasta (ZIP)">
                                                <AcaoIcone acao="descarregar" />
                                            </a>
                                            <button v-if="p.ficheiros.some((f) => f.partilhavel)" type="button" class="btn btn-sm btn-icon btn-light btn-active-light-primary ms-2" title="Partilhar a pasta" @click="abrirPartilha(p.nome, p.ficheiros)">
                                                <AcaoIcone acao="partilhar" />
                                            </button>
                                            <button v-if="idsTerminados(p).length" type="button" class="btn btn-sm btn-icon btn-light btn-active-light-danger ms-2" title="Eliminar a pasta" @click="pedirEliminar(idsTerminados(p))">
                                                <AcaoIcone acao="eliminar" />
                                            </button>
                                        </td>
                                    </tr>
                                    <template v-if="expandidas.has(p.nome)">
                                        <FicheiroLinha v-for="f in p.ficheiros" :key="f.id" :ficheiro="f" recuo :selecionado="seleccionados.has(f.id)" @alternar="alternar" @eliminar="(id) => pedirEliminar([id])" @partilhar="(fx) => abrirPartilha(fx.titulo, [fx])" />
                                    </template>
                                </template>
                            </template>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <PartilharModal :show="!!partilha" :titulo="partilha?.titulo ?? ''" :ids="partilha?.ids ?? []" :referencia="partilha?.referencia ?? null" :actuais="partilha?.actuais ?? []" @fechar="partilha = null" />

        <ConfirmModal
            :show="paraEliminar.length > 0"
            titulo="Eliminar ficheiros"
            :mensagem="mensagemEliminar"
            texto-confirmar="Eliminar"
            :processando="eliminando"
            @confirmar="eliminar"
            @cancelar="paraEliminar = []"
        />
    </div>
</template>
