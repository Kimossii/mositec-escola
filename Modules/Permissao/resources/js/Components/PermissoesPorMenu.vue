<script setup>
import { computed, reactive } from 'vue';
import { agruparPorMenu } from '../Support/menusDosModulos';

/**
 * Permissões concedidas, só leitura, agrupadas pelo menu do sistema a que cada módulo pertence (barra verde, caminho
 * do módulo, botão Ocultar/Mostrar por menu). Partilhado pela lista de perfis e pelo assistente de novo utilizador.
 */
const props = defineProps({
    // [{ modulo: 'Descrição do módulo', slug: 'aluno', acao: 'ver' }]: só o que está concedido
    permissoes: { type: Array, required: true },
    // nomes das acções, pela ordem das colunas
    acoes: { type: Array, required: true },
    // total de módulos existentes (para a contagem «N sem acesso»); opcional
    totalModulos: { type: Number, default: null },
});

const menus = computed(() => {
    const porModulo = new Map();

    for (const { modulo, slug, acao } of props.permissoes) {
        if (!porModulo.has(modulo)) porModulo.set(modulo, { id: modulo, slug, descricao: modulo, acoesDoPerfil: [] });
        porModulo.get(modulo).acoesDoPerfil.push(acao);
    }

    return agruparPorMenu([...porModulo.values()]).map((menu) => {
        const modulos = menu.secoes.flatMap((secao) => secao.modulos);
        const colunas = props.acoes.filter((acao) => modulos.some((m) => m.acoesDoPerfil.includes(acao)));

        return { ...menu, modulos, colunas, total: modulos.reduce((t, m) => t + m.acoesDoPerfil.length, 0) };
    });
});

const modulosComAcesso = computed(() => new Set(props.permissoes.map((p) => p.modulo)).size);

const recolhidos = reactive(new Set());
const estaRecolhido = (menu) => recolhidos.has(menu);
function alternar(menu) {
    if (recolhidos.has(menu)) recolhidos.delete(menu);
    else recolhidos.add(menu);
}
</script>

<template>
    <div>
        <div v-if="!permissoes.length" class="p-6 text-muted fs-6">Nenhuma permissão atribuída.</div>

        <template v-else>
            <div v-if="totalModulos !== null" class="fs-7 text-muted mb-3">
                <span class="text-success fw-bold">{{ modulosComAcesso }}</span> {{ modulosComAcesso === 1 ? 'módulo com acesso' : 'módulos com acesso' }} ·
                <span class="fw-bold">{{ Math.max(0, totalModulos - modulosComAcesso) }}</span> sem acesso
            </div>

            <div v-for="menu in menus" :key="menu.menu" class="perfil-menu mb-4">
                <div class="d-flex justify-content-between align-items-center mb-2">
                    <div>
                        <span class="text-muted fs-7 fw-semibold text-uppercase">Menu</span>
                        <span class="fs-3 fw-bold text-gray-900 ms-2">{{ menu.menu }}</span>
                        <span class="badge badge-light fs-7 ms-2">{{ menu.total }} permissões</span>
                    </div>
                    <button type="button" class="btn btn-sm btn-light-primary" :aria-expanded="!estaRecolhido(menu.menu)" @click="alternar(menu.menu)">
                        {{ estaRecolhido(menu.menu) ? 'Mostrar' : 'Ocultar' }}
                        <span class="seta" :class="{ 'seta--recolhida': estaRecolhido(menu.menu) }">&#9662;</span>
                    </button>
                </div>

                <div v-show="!estaRecolhido(menu.menu)" class="table-responsive">
                    <table class="table table-permissoes align-middle mb-0 fs-5">
                        <thead>
                            <tr class="text-muted fw-bold fs-7 text-uppercase">
                                <th class="min-w-250px">Módulo</th>
                                <th v-for="acao in menu.colunas" :key="acao" class="text-center text-capitalize">{{ acao }}</th>
                            </tr>
                        </thead>
                        <tbody class="text-gray-800 fw-semibold">
                            <template v-for="secao in menu.secoes" :key="secao.titulo ?? '-'">
                                <tr v-if="secao.titulo" class="perfil-secao"><td :colspan="menu.colunas.length + 1">{{ secao.titulo }}</td></tr>
                                <tr v-for="modulo in secao.modulos" :key="modulo.id">
                                    <td :class="{ 'ps-4': secao.titulo }">
                                        <div class="fw-bold fs-5 text-gray-900">{{ modulo.entrada }}</div>
                                        <div class="text-muted fs-7 fw-semibold">{{ modulo.caminho }}</div>
                                    </td>
                                    <td v-for="acao in menu.colunas" :key="acao" class="text-center">
                                        <span v-if="modulo.acoesDoPerfil.includes(acao)" class="badge badge-circle badge-success">
                                            <i class="ki-duotone ki-check fs-3 text-white"></i>
                                        </span>
                                        <span v-else class="text-muted">&mdash;</span>
                                    </td>
                                </tr>
                            </template>
                        </tbody>
                    </table>
                </div>
            </div>
        </template>
    </div>
</template>

<style scoped>
.seta { display: inline-block; margin-left: 0.35rem; transition: transform 0.15s ease; }
.seta--recolhida { transform: rotate(-90deg); }
.perfil-menu { border-left: 4px solid #166534; padding-left: 1rem; }
.perfil-secao td {
    background: rgba(100, 116, 139, 0.12);
    font-size: 0.9rem;
    font-weight: 700;
    letter-spacing: 0.06em;
    text-transform: uppercase;
    padding: 0.4rem 0.75rem;
}
.table-permissoes thead th { border-bottom: 1px solid rgba(100, 116, 139, 0.35); }
.table-permissoes tbody td { border-bottom: 1px dashed rgba(100, 116, 139, 0.3); }
</style>
