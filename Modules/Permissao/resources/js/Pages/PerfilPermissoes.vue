<script setup>
import { computed, reactive, ref } from 'vue';
import { router } from '@inertiajs/vue3';
import { toast } from 'vue-sonner';
import AppLayout from '@/Layouts/AppLayout.vue';
import { can } from '@/Composables/usePermissoes';
import BotaoVoltar from '@/Components/Shared/BotaoVoltar.vue';
import { agruparPorMenu } from '../Support/menusDosModulos';

const props = defineProps({
    perfil: { type: Object, required: true },
    modulos: { type: Array, required: true },
    acoes: { type: Array, required: true },
    marcadas: { type: Array, required: true },
});
defineOptions({ layout: AppLayout });

const podeEditar = computed(() => can('autorizacao.editar'));
const chave = (moduloId, acaoId) => `${moduloId}-${acaoId}`;

// Cada módulo traz os ids das acções que lhe são aplicáveis (modulo.acoes);
// as restantes células nem se mostram nem entram nos totais de linha/coluna.
const aplicavel = (modulo, acaoId) => modulo.acoes.includes(acaoId);
const acoesDe = (modulo) => props.acoes.filter((acao) => aplicavel(modulo, acao.id));
const moduloPorId = (moduloId) => props.modulos.find((m) => m.id === moduloId);

const estado = reactive(Object.fromEntries(props.marcadas.map((m) => [chave(m.modulo_id, m.acao_id), true])));

// Menus recolhidos (só esconde a tabela; o estado das caixas mantém-se e guarda-se na mesma). Com pesquisa activa tudo se abre.
const recolhidos = reactive(new Set());
const estaRecolhido = (menu) => pesquisa.value.trim() === '' && recolhidos.has(menu.menu);
function alternarRecolhido(menu) {
    if (recolhidos.has(menu.menu)) recolhidos.delete(menu.menu);
    else recolhidos.add(menu.menu);
}
const todosRecolhidos = computed(() => menus.value.length > 0 && menus.value.every((m) => recolhidos.has(m.menu)));
function alternarTodos() {
    if (todosRecolhidos.value) recolhidos.clear();
    else menus.value.forEach((m) => recolhidos.add(m.menu));
}

// Pesquisa: filtra por módulo ou por menu.
const pesquisa = ref('');
const menus = computed(() => {
    const termo = pesquisa.value.trim().toLowerCase();

    return agruparPorMenu(props.modulos)
        .map((menu) => ({
            ...menu,
            secoes: menu.secoes
                .map((secao) => ({
                    ...secao,
                    modulos: secao.modulos.filter((m) => termo === '' || `${m.caminho} ${m.descricao}`.toLowerCase().includes(termo)),
                }))
                .filter((secao) => secao.modulos.length > 0),
        }))
        .filter((menu) => menu.secoes.length > 0)
        .map((menu) => {
            const modulos = menu.secoes.flatMap((s) => s.modulos);
            // Colunas do menu: só as acções que algum dos seus módulos usa (menos ruído que a grelha toda).
            const colunas = props.acoes.filter((acao) => modulos.some((m) => aplicavel(m, acao.id)));

            return { ...menu, modulos, colunas };
        });
});

const concedidasDe = (modulos) => modulos.reduce((total, m) => total + acoesDe(m).filter((a) => estado[chave(m.id, a.id)]).length, 0);
const totalDe = (modulos) => modulos.reduce((total, m) => total + acoesDe(m).length, 0);
const totalConcedidas = computed(() => Object.entries(estado).filter(([k, v]) => v && aplicavel(moduloPorId(Number(k.split('-')[0])), Number(k.split('-')[1]))).length);

function alternar(moduloId, acaoId) {
    const k = chave(moduloId, acaoId);
    estado[k] = !estado[k];
}

const colunaCompleta = (menu, acaoId) => menu.modulos.filter((m) => aplicavel(m, acaoId)).every((m) => !!estado[chave(m.id, acaoId)]);
function alternarColuna(menu, acaoId) {
    const marcar = !colunaCompleta(menu, acaoId);
    menu.modulos.filter((m) => aplicavel(m, acaoId)).forEach((m) => { estado[chave(m.id, acaoId)] = marcar; });
}

const linhaCompleta = (modulo) => acoesDe(modulo).every((a) => !!estado[chave(modulo.id, a.id)]);
function alternarLinha(modulo) {
    const marcar = !linhaCompleta(modulo);
    acoesDe(modulo).forEach((a) => { estado[chave(modulo.id, a.id)] = marcar; });
}

const menuCompleto = (menu) => menu.modulos.every((m) => linhaCompleta(m));
function alternarMenu(menu) {
    const marcar = !menuCompleto(menu);
    menu.modulos.forEach((m) => acoesDe(m).forEach((a) => { estado[chave(m.id, a.id)] = marcar; }));
}

function guardar() {
    const celulas = Object.entries(estado)
        .filter(([, marcado]) => marcado)
        .map(([k]) => {
            const [modulo_id, acao_id] = k.split('-').map(Number);
            return { modulo_id, acao_id };
        })
        // Pares inaplicáveis (ocultos na grelha) não se reenviam: ao guardar, o servidor substitui tudo e limpa-os.
        .filter(({ modulo_id, acao_id }) => aplicavel(moduloPorId(modulo_id), acao_id));

    router.put(`/permissoes/perfis/${props.perfil.id}/permissoes`, { celulas }, {
        preserveScroll: true,
        onSuccess: () => toast.success('Permissões do perfil atualizadas.'),
        onError: (erros) => toast.error(Object.values(erros)[0]),
    });
}
</script>

<template>
    <div class="app-container container-xxl py-6">
        <BotaoVoltar />

        <div class="d-flex flex-wrap justify-content-between align-items-end gap-4 mb-6">
            <div>
                <div class="text-muted fs-7 fw-semibold text-uppercase">Perfil</div>
                <h1 class="fs-2 fw-bold mb-1">{{ perfil.descricao }}</h1>
                <div class="text-muted fs-6">
                    <span class="badge badge-light-success fs-6 me-2">{{ totalConcedidas }} permissões concedidas</span>
                    Organizadas pelo menu do sistema a que pertencem.
                </div>
            </div>
            <div class="d-flex gap-3 align-items-center">
                <button type="button" class="btn btn-light" @click="alternarTodos">{{ todosRecolhidos ? 'Expandir tudo' : 'Recolher tudo' }}</button>
                <input v-model="pesquisa" type="search" class="form-control form-control-solid w-250px" placeholder="Pesquisar módulo ou menu…" />
                <button v-if="podeEditar" class="btn btn-primary" @click="guardar">Guardar</button>
            </div>
        </div>

        <div v-if="menus.length === 0" class="card"><div class="card-body text-center text-muted py-10">Nenhum módulo corresponde à pesquisa.</div></div>

        <div v-for="menu in menus" :key="menu.menu" class="card mb-6 permissoes-menu">
            <div class="card-header min-h-auto py-4 permissoes-menu-cabecalho">
                <div class="card-title d-flex align-items-center gap-3 m-0">
                    <input
                        type="checkbox" class="form-check-input permissao-check" title="Marcar/desmarcar o menu inteiro"
                        :checked="menuCompleto(menu)" :disabled="!podeEditar" @change="alternarMenu(menu)"
                    />
                    <div>
                        <div class="text-muted fs-7 fw-semibold text-uppercase">Menu</div>
                        <h3 class="fs-2 fw-bold m-0">{{ menu.menu }}</h3>
                    </div>
                </div>
                <div class="card-toolbar gap-3">
                    <span class="badge badge-light fs-6">{{ concedidasDe(menu.modulos) }} / {{ totalDe(menu.modulos) }}</span>
                    <button
                        type="button" class="btn btn-sm btn-light-primary" :aria-expanded="!estaRecolhido(menu)"
                        :title="estaRecolhido(menu) ? 'Mostrar as permissões deste menu' : 'Esconder as permissões deste menu'"
                        @click="alternarRecolhido(menu)"
                    >
                        {{ estaRecolhido(menu) ? 'Mostrar' : 'Ocultar' }}
                        <span class="seta" :class="{ 'seta--recolhida': estaRecolhido(menu) }">&#9662;</span>
                    </button>
                </div>
            </div>

            <div v-show="!estaRecolhido(menu)" class="card-body pt-0">
                <div class="table-responsive">
                    <table class="table align-middle fs-5 mb-0 permissoes-tabela">
                        <thead>
                            <tr class="text-muted fw-bold fs-7 text-uppercase">
                                <th class="min-w-250px">Módulo</th>
                                <th v-for="acao in menu.colunas" :key="acao.id" class="text-center text-capitalize">
                                    <div class="d-flex flex-column align-items-center gap-1">
                                        <span>{{ acao.nome }}</span>
                                        <input
                                            type="checkbox" class="form-check-input permissao-check" title="Marcar/desmarcar toda a coluna neste menu"
                                            :checked="colunaCompleta(menu, acao.id)" :disabled="!podeEditar" @change="alternarColuna(menu, acao.id)"
                                        />
                                    </div>
                                </th>
                            </tr>
                        </thead>
                        <tbody>
                            <template v-for="secao in menu.secoes" :key="secao.titulo ?? '-'">
                                <tr v-if="secao.titulo" class="permissoes-secao">
                                    <td :colspan="menu.colunas.length + 1">{{ secao.titulo }}</td>
                                </tr>
                                <tr v-for="modulo in secao.modulos" :key="modulo.id" class="permissoes-linha">
                                    <td>
                                        <div class="d-flex align-items-center gap-3" :class="{ 'ps-4': secao.titulo }">
                                            <input
                                                type="checkbox" class="form-check-input permissao-check" title="Marcar/desmarcar toda a linha"
                                                :checked="linhaCompleta(modulo)" :disabled="!podeEditar" @change="alternarLinha(modulo)"
                                            />
                                            <div>
                                                <div class="fw-semibold text-gray-900 fs-5">{{ modulo.entrada }}</div>
                                                <div class="text-muted fs-7">{{ modulo.caminho }}</div>
                                            </div>
                                        </div>
                                    </td>
                                    <td v-for="acao in menu.colunas" :key="acao.id" class="text-center">
                                        <input
                                            v-if="aplicavel(modulo, acao.id)"
                                            type="checkbox" class="form-check-input permissao-check"
                                            :checked="!!estado[`${modulo.id}-${acao.id}`]" :disabled="!podeEditar" @change="alternar(modulo.id, acao.id)"
                                        />
                                        <span v-else class="text-muted">&mdash;</span>
                                    </td>
                                </tr>
                            </template>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <div v-if="podeEditar" class="text-end mt-2">
            <button class="btn btn-primary" @click="guardar">Guardar permissões</button>
        </div>
    </div>
</template>

<style scoped>
/* Caixas bem visíveis nos dois temas: contorno forte quando vazias, verde quando marcadas (como o estado activo). */
.permissao-check {
    width: 1.35rem;
    height: 1.35rem;
    border: 2px solid #64748B !important;
    background-color: transparent !important;
    cursor: pointer;
}
.permissao-check:checked {
    background-color: #166534 !important;
    border-color: #166534 !important;
}
.permissao-check:disabled { cursor: not-allowed; opacity: 0.6; }

.seta { display: inline-block; margin-left: 0.35rem; transition: transform 0.15s ease; }
.seta--recolhida { transform: rotate(-90deg); }
.permissoes-menu-cabecalho { border-left: 4px solid #166534; border-top-left-radius: 0.475rem; }
.permissoes-tabela thead th { border-bottom: 2px solid var(--bs-gray-300, #dee2e6); white-space: nowrap; }
.permissoes-secao td {
    background: var(--bs-body-secondary-bg, rgba(100, 116, 139, 0.12));
    font-size: 0.9rem;
    font-weight: 700;
    letter-spacing: 0.06em;
    text-transform: uppercase;
    padding: 0.4rem 0.75rem;
}
.permissoes-linha td { border-bottom: 1px dashed var(--bs-gray-300, #dee2e6); padding-top: 0.6rem; padding-bottom: 0.6rem; }
.permissoes-linha:hover td { background: rgba(22, 101, 52, 0.05); }
</style>
