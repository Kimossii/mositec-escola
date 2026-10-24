<script setup>
import { computed, reactive, ref } from 'vue';
import { router } from '@inertiajs/vue3';
import { toast } from 'vue-sonner';
import AppLayout from '@/Layouts/AppLayout.vue';
import { can } from '@/Composables/usePermissoes';
import BotaoVoltar from '@/Components/Shared/BotaoVoltar.vue';
import SelectSolid from '@/Components/Shared/SelectSolid.vue';
import { agruparPorMenu } from '../Support/menusDosModulos';

const props = defineProps({
    utilizador: { type: Object, required: true },
    perfis: { type: Array, required: true },
    perfisAtribuidos: { type: Array, required: true },
    modulos: { type: Array, required: true },
    acoes: { type: Array, required: true },
    permitidasPeloPerfil: { type: Array, required: true },
    overrides: { type: Array, required: true },
});
defineOptions({ layout: AppLayout });

const chave = (moduloId, acaoId) => `${moduloId}-${acaoId}`;

// Cada módulo traz os ids das acções que lhe são aplicáveis (modulo.acoes);
// as restantes células nem se mostram nem entram nos totais de linha/coluna.
const aplicavel = (modulo, acaoId) => modulo.acoes.includes(acaoId);
const acoesDe = (modulo) => props.acoes.filter((acao) => aplicavel(modulo, acao.id));
const modulosDa = (acaoId) => props.modulos.filter((modulo) => aplicavel(modulo, acaoId));
const moduloPorId = (moduloId) => props.modulos.find((m) => m.id === moduloId);

// overridesEstado só guarda as células que o admin decidiu explicitamente
// (já eram override do utilizador, ou tocadas nesta sessão). 1 = concedido,
// 0 = negado — só estes dois valores, nunca "herda" nem null. Uma célula sem
// entrada aqui usa o que os perfis atribuídos já dão por padrão (ver
// permiteDefault) — assim "o perfil vence por defeito, overrides são só a
// excepção" continua verdadeiro depois de guardar.
const overridesEstado = reactive(
    Object.fromEntries(
        props.overrides.map((o) => [chave(o.modulo_id, o.acao_id), o.permitido ? 1 : 0]),
    ),
);

function permiteDefault(moduloId, acaoId) {
    return props.permitidasPeloPerfil.some((p) => p.modulo_id === moduloId && p.acao_id === acaoId);
}

function estadoCelula(moduloId, acaoId) {
    const k = chave(moduloId, acaoId);
    if (k in overridesEstado) return overridesEstado[k];
    return permiteDefault(moduloId, acaoId) ? 1 : 0;
}

function proximoEstado(moduloId, acaoId) {
    const k = chave(moduloId, acaoId);
    overridesEstado[k] = estadoCelula(moduloId, acaoId) === 1 ? 0 : 1;
}

function todosConcedidosNaColuna(acaoId) {
    return modulosDa(acaoId).every((modulo) => estadoCelula(modulo.id, acaoId) === 1);
}

function alternarColuna(acaoId) {
    const marcar = todosConcedidosNaColuna(acaoId) ? 0 : 1;
    modulosDa(acaoId).forEach((modulo) => {
        overridesEstado[chave(modulo.id, acaoId)] = marcar;
    });
}

function todosConcedidosNaLinha(moduloId) {
    return acoesDe(moduloPorId(moduloId)).every((acao) => estadoCelula(moduloId, acao.id) === 1);
}

function alternarLinha(moduloId) {
    const marcar = todosConcedidosNaLinha(moduloId) ? 0 : 1;
    acoesDe(moduloPorId(moduloId)).forEach((acao) => {
        overridesEstado[chave(moduloId, acao.id)] = marcar;
    });
}

// Vista agrupada pelo menu do sistema (como nos Perfis), com pesquisa e menus recolhíveis.
const pesquisa = ref('');
const menus = computed(() => {
    const termo = pesquisa.value.trim().toLowerCase();

    return agruparPorMenu(props.modulos)
        .map((menu) => ({
            ...menu,
            secoes: menu.secoes
                .map((secao) => ({ ...secao, modulos: secao.modulos.filter((m) => termo === '' || `${m.caminho} ${m.descricao}`.toLowerCase().includes(termo)) }))
                .filter((secao) => secao.modulos.length > 0),
        }))
        .filter((menu) => menu.secoes.length > 0)
        .map((menu) => {
            const modulos = menu.secoes.flatMap((sec) => sec.modulos);

            return { ...menu, modulos, colunas: props.acoes.filter((acao) => modulos.some((m) => aplicavel(m, acao.id))) };
        });
});
const recolhidos = reactive(new Set());
const estaRecolhido = (menu) => pesquisa.value.trim() === '' && recolhidos.has(menu.menu);
function alternarRecolhido(menu) {
    if (recolhidos.has(menu.menu)) recolhidos.delete(menu.menu);
    else recolhidos.add(menu.menu);
}
const todosRecolhidos = computed(() => menus.value.length > 0 && menus.value.every((m) => recolhidos.has(m.menu)));
const alternarTodos = () => (todosRecolhidos.value ? recolhidos.clear() : menus.value.forEach((m) => recolhidos.add(m.menu)));
const diferenteDoPerfil = (moduloId, acaoId) => estadoCelula(moduloId, acaoId) === 1 !== permiteDefault(moduloId, acaoId);
const totalDiferencas = computed(() => props.modulos.reduce((t, m) => t + acoesDe(m).filter((a) => diferenteDoPerfil(m.id, a.id)).length, 0));
const concedidasDe = (modulos) => modulos.reduce((t, m) => t + acoesDe(m).filter((a) => estadoCelula(m.id, a.id) === 1).length, 0);
const totalDe = (modulos) => modulos.reduce((t, m) => t + acoesDe(m).length, 0);
const colunaCompleta = (menu, acaoId) => menu.modulos.filter((m) => aplicavel(m, acaoId)).every((m) => estadoCelula(m.id, acaoId) === 1);
function alternarColunaDoMenu(menu, acaoId) {
    const marcar = colunaCompleta(menu, acaoId) ? 0 : 1;
    menu.modulos.filter((m) => aplicavel(m, acaoId)).forEach((m) => { overridesEstado[chave(m.id, acaoId)] = marcar; });
}

const novoPerfilId = ref('');
const opcoesPerfil = computed(() => props.perfis.map((p) => ({ value: p.id, label: p.descricao })));

function atribuirPerfil() {
    if (!novoPerfilId.value) return;

    router.post(`/permissoes/utilizadores/${props.utilizador.id}/perfis`, { role_id: novoPerfilId.value }, {
        preserveScroll: true,
        onSuccess: () => toast.success('Perfil atribuído.'),
        onError: (erros) => toast.error(Object.values(erros)[0]),
    });
}

function removerPerfil(roleId) {
    router.delete(`/permissoes/utilizadores/${props.utilizador.id}/perfis/${roleId}`, {
        preserveScroll: true,
        onSuccess: () => toast.success('Perfil removido.'),
        onError: (erros) => toast.error(Object.values(erros)[0]),
    });
}

function guardarOverrides() {
    // Pares inaplicáveis (ocultos na grelha) não se reenviam: ao guardar, o servidor substitui tudo e limpa-os.
    const celulas = Object.entries(overridesEstado).map(([k, valor]) => {
        const [modulo_id, acao_id] = k.split('-').map(Number);
        return { modulo_id, acao_id, permitido: valor === 1 };
    }).filter(({ modulo_id, acao_id }) => aplicavel(moduloPorId(modulo_id), acao_id));

    router.put(`/permissoes/utilizadores/${props.utilizador.id}/permissoes`, { celulas }, {
        preserveScroll: true,
        onSuccess: () => toast.success('Permissões do utilizador atualizadas.'),
        onError: (erros) => toast.error(Object.values(erros)[0]),
    });
}
</script>

<template>
    <div class="app-container container-xxl py-6">
        <BotaoVoltar />
        <h1 class="fs-2 fw-bold mb-6">Permissões — {{ utilizador.name }}</h1>

        <div class="card mb-6">
            <div class="card-body">
                <h3 class="fs-5 mb-4">Perfis atribuídos</h3>
                <div class="d-flex flex-wrap gap-2 mb-4">
                    <span v-for="roleId in perfisAtribuidos" :key="roleId" class="badge badge-light-primary fs-7">
                        {{ perfis.find((p) => p.id === roleId)?.descricao }}
                        <a v-if="can('autorizacao.editar')" href="#" class="ms-2 text-danger" @click.prevent="removerPerfil(roleId)">&times;</a>
                    </span>
                </div>
                <div v-if="can('autorizacao.editar')" class="d-flex gap-2">
                    <div class="min-w-250px"><SelectSolid v-model="novoPerfilId" :options="opcoesPerfil" placeholder="Selecione um perfil..." /></div>
                    <button class="btn btn-light-primary" @click="atribuirPerfil">Atribuir</button>
                </div>
            </div>
        </div>

        <div class="d-flex flex-wrap justify-content-between align-items-end gap-4 mb-4">
            <div>
                <h3 class="fs-3 fw-bold mb-1">Permissões individuais</h3>
                <div class="text-muted fs-6">
                    <span class="badge fs-6 me-2" :class="totalDiferencas ? 'badge-light-warning' : 'badge-light'">{{ totalDiferencas }} diferente(s) do perfil</span>
                    Clique numa célula para alternar entre <strong class="text-success">Concedido</strong> e <strong class="text-danger">Negado</strong>. O ponto ● marca o que difere do perfil.
                </div>
            </div>
            <div class="d-flex gap-3 align-items-center">
                <button type="button" class="btn btn-light" @click="alternarTodos">{{ todosRecolhidos ? 'Expandir tudo' : 'Recolher tudo' }}</button>
                <input v-model="pesquisa" type="search" class="form-control form-control-solid w-250px" placeholder="Pesquisar módulo ou menu…" />
                <button v-if="can('autorizacao.editar')" class="btn btn-primary" @click="guardarOverrides">Guardar</button>
            </div>
        </div>

        <div v-if="menus.length === 0" class="card"><div class="card-body text-center text-muted py-10">Nenhum módulo corresponde à pesquisa.</div></div>

        <div v-for="menu in menus" :key="menu.menu" class="card mb-6 permissoes-menu">
            <div class="card-header min-h-auto py-4 permissoes-menu-cabecalho">
                <div class="card-title m-0">
                    <div><div class="text-muted fs-7 fw-semibold text-uppercase">Menu</div><h3 class="fs-2 fw-bold m-0">{{ menu.menu }}</h3></div>
                </div>
                <div class="card-toolbar gap-3">
                    <span class="badge badge-light fs-6">{{ concedidasDe(menu.modulos) }} / {{ totalDe(menu.modulos) }}</span>
                    <button type="button" class="btn btn-sm btn-light-primary" :aria-expanded="!estaRecolhido(menu)" @click="alternarRecolhido(menu)">
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
                                        <input type="checkbox" class="form-check-input permissao-check" title="Conceder/negar toda a coluna neste menu" :checked="colunaCompleta(menu, acao.id)" :disabled="!can('autorizacao.editar')" @change="alternarColunaDoMenu(menu, acao.id)" />
                                    </div>
                                </th>
                            </tr>
                        </thead>
                        <tbody>
                            <template v-for="secao in menu.secoes" :key="secao.titulo ?? '-'">
                                <tr v-if="secao.titulo" class="permissoes-secao"><td :colspan="menu.colunas.length + 1">{{ secao.titulo }}</td></tr>
                                <tr v-for="modulo in secao.modulos" :key="modulo.id" class="permissoes-linha">
                                    <td :class="{ 'ps-4': secao.titulo }">
                                        <div class="d-flex align-items-center gap-3">
                                            <input type="checkbox" class="form-check-input permissao-check" title="Conceder/negar toda a linha" :checked="todosConcedidosNaLinha(modulo.id)" :disabled="!can('autorizacao.editar')" @change="alternarLinha(modulo.id)" />
                                            <div><div class="fw-semibold text-gray-900 fs-5">{{ modulo.entrada }}</div><div class="text-muted fs-7">{{ modulo.caminho }}</div></div>
                                        </div>
                                    </td>
                                    <td v-for="acao in menu.colunas" :key="acao.id" class="text-center">
                                        <button
                                            v-if="aplicavel(modulo, acao.id)" type="button" class="btn btn-sm celula position-relative"
                                            :class="estadoCelula(modulo.id, acao.id) === 1 ? 'btn-light-success' : 'btn-light-danger'"
                                            :title="diferenteDoPerfil(modulo.id, acao.id) ? 'Diferente do perfil' : 'Igual ao perfil'"
                                            :disabled="!can('autorizacao.editar')" @click="proximoEstado(modulo.id, acao.id)"
                                        >
                                            {{ estadoCelula(modulo.id, acao.id) === 1 ? '✓' : '✕' }}
                                            <span v-if="diferenteDoPerfil(modulo.id, acao.id)" class="ponto">●</span>
                                        </button>
                                        <span v-else class="text-muted">&mdash;</span>
                                    </td>
                                </tr>
                            </template>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <div v-if="can('autorizacao.editar')" class="text-end mt-2">
            <button class="btn btn-primary" @click="guardarOverrides">Guardar permissões</button>
        </div>
    </div>
</template>

<style scoped>
.permissao-check { width: 1.35rem; height: 1.35rem; border: 2px solid #64748B !important; background-color: transparent !important; cursor: pointer; }
.permissao-check:checked { background-color: #166534 !important; border-color: #166534 !important; }
.permissao-check:disabled { cursor: not-allowed; opacity: 0.6; }
.celula { min-width: 3rem; font-weight: 700; font-size: 1.1rem; }
.ponto { position: absolute; top: -4px; right: -2px; font-size: 0.7rem; color: #d97706; }
.seta { display: inline-block; margin-left: 0.35rem; transition: transform 0.15s ease; }
.seta--recolhida { transform: rotate(-90deg); }
.permissoes-menu-cabecalho { border-left: 4px solid #166534; border-top-left-radius: 0.475rem; }
.permissoes-tabela thead th { border-bottom: 2px solid rgba(100, 116, 139, 0.35); white-space: nowrap; }
.permissoes-secao td { background: rgba(100, 116, 139, 0.12); font-size: 0.9rem; font-weight: 700; letter-spacing: 0.06em; text-transform: uppercase; padding: 0.4rem 0.75rem; }
.permissoes-linha td { border-bottom: 1px dashed rgba(100, 116, 139, 0.3); padding-top: 0.6rem; padding-bottom: 0.6rem; }
.permissoes-linha:hover td { background: rgba(22, 101, 52, 0.05); }
</style>
