<script setup>
import { reactive, ref } from 'vue';
import { router } from '@inertiajs/vue3';
import { toast } from 'vue-sonner';
import AppLayout from '@/Layouts/AppLayout.vue';
import { can } from '@/Composables/usePermissoes';
import BotaoVoltar from '@/Components/Shared/BotaoVoltar.vue';

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
    return props.modulos.every((modulo) => estadoCelula(modulo.id, acaoId) === 1);
}

function alternarColuna(acaoId) {
    const marcar = todosConcedidosNaColuna(acaoId) ? 0 : 1;
    props.modulos.forEach((modulo) => {
        overridesEstado[chave(modulo.id, acaoId)] = marcar;
    });
}

function todosConcedidosNaLinha(moduloId) {
    return props.acoes.every((acao) => estadoCelula(moduloId, acao.id) === 1);
}

function alternarLinha(moduloId) {
    const marcar = todosConcedidosNaLinha(moduloId) ? 0 : 1;
    props.acoes.forEach((acao) => {
        overridesEstado[chave(moduloId, acao.id)] = marcar;
    });
}

const novoPerfilId = ref('');

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
    const celulas = Object.entries(overridesEstado).map(([k, valor]) => {
        const [modulo_id, acao_id] = k.split('-').map(Number);
        return { modulo_id, acao_id, permitido: valor === 1 };
    });

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
                    <select v-model="novoPerfilId" class="form-select form-select-solid w-auto">
                        <option value="">Selecione um perfil...</option>
                        <option v-for="perfil in perfis" :key="perfil.id" :value="perfil.id">{{ perfil.descricao }}</option>
                    </select>
                    <button class="btn btn-light-primary" @click="atribuirPerfil">Atribuir</button>
                </div>
            </div>
        </div>

        <div class="card">
            <div class="card-body">
                <h3 class="fs-5 mb-4">Permissões individuais</h3>
                <p class="text-muted fs-7">
                    Clique numa célula para alternar entre Concedido (verde) e Negado (vermelho).
                </p>
                <table class="table align-middle table-row-dashed table-hover fs-6 gy-5">
                    <thead>
                        <tr class="text-start text-muted fw-bold fs-7 text-uppercase gs-0">
                            <th>Módulo</th>
                            <th v-for="acao in acoes" :key="acao.id" class="text-center text-capitalize">
                                <div class="d-flex flex-column align-items-center gap-1">
                                    <span>{{ acao.nome }}</span>
                                    <input
                                        type="checkbox"
                                        class="form-check-input"
                                        title="Marcar/desmarcar toda a coluna"
                                        :checked="todosConcedidosNaColuna(acao.id)"
                                        :disabled="!can('autorizacao.editar')"
                                        @change="alternarColuna(acao.id)"
                                    />
                                </div>
                            </th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="modulo in modulos" :key="modulo.id">
                            <td>
                                <div class="d-flex align-items-center gap-2">
                                    <input
                                        type="checkbox"
                                        class="form-check-input"
                                        title="Marcar/desmarcar toda a linha"
                                        :checked="todosConcedidosNaLinha(modulo.id)"
                                        :disabled="!can('autorizacao.editar')"
                                        @change="alternarLinha(modulo.id)"
                                    />
                                    <span>{{ modulo.descricao }}</span>
                                </div>
                            </td>
                            <td v-for="acao in acoes" :key="acao.id" class="text-center">
                                <button
                                    type="button"
                                    class="btn btn-sm"
                                    :class="{
                                        'btn-light-success btn-permissao-concedido': estadoCelula(modulo.id, acao.id) === 1,
                                        'btn-light-danger': estadoCelula(modulo.id, acao.id) === 0,
                                    }"
                                    :disabled="!can('autorizacao.editar')"
                                    @click="proximoEstado(modulo.id, acao.id)"
                                >
                                    {{ estadoCelula(modulo.id, acao.id) === 1 ? 'Concedido' : 'Negado' }}
                                </button>
                            </td>
                        </tr>
                    </tbody>
                </table>

                <div v-if="can('autorizacao.editar')" class="text-end mt-5">
                    <button class="btn btn-primary" @click="guardarOverrides">Guardar</button>
                </div>
            </div>
        </div>
    </div>
</template>
