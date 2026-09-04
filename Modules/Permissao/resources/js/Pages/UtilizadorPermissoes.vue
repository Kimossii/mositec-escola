<script setup>
import { reactive, ref } from 'vue';
import { router } from '@inertiajs/vue3';
import { toast } from 'vue-sonner';
import AppLayout from '@/Layouts/AppLayout.vue';
import BotaoVoltar from '@/Components/Shared/BotaoVoltar.vue';

const props = defineProps({
    utilizador: { type: Object, required: true },
    perfis: { type: Array, required: true },
    perfisAtribuidos: { type: Array, required: true },
    modulos: { type: Array, required: true },
    acoes: { type: Array, required: true },
    overrides: { type: Array, required: true },
});
defineOptions({ layout: AppLayout });

const chave = (moduloId, acaoId) => `${moduloId}-${acaoId}`;

// 1 = concedido, 0 = negado — apenas estes dois estados existem.
// Uma célula sem override guardado ainda arranca como Negado (o padrão seguro);
// não há um terceiro estado "herda" nem valor nulo em lado nenhum.
const overridesEstado = reactive(
    Object.fromEntries(
        props.modulos.flatMap((modulo) =>
            props.acoes.map((acao) => {
                const k = chave(modulo.id, acao.id);
                const existente = props.overrides.find((o) => o.modulo_id === modulo.id && o.acao_id === acao.id);
                return [k, existente?.permitido ? 1 : 0];
            }),
        ),
    ),
);

function estadoCelula(moduloId, acaoId) {
    return overridesEstado[chave(moduloId, acaoId)];
}

function proximoEstado(moduloId, acaoId) {
    const k = chave(moduloId, acaoId);
    overridesEstado[k] = overridesEstado[k] === 1 ? 0 : 1;
}

const novoPerfilId = ref('');

function atribuirPerfil() {
    if (!novoPerfilId.value) return;

    router.post(`/permissoes/utilizadores/${props.utilizador.id}/perfis`, { role_id: novoPerfilId.value }, {
        preserveScroll: true,
        onSuccess: () => toast.success('Perfil atribuído.'),
        onError: () => toast.error('Não foi possível atribuir o perfil.'),
    });
}

function removerPerfil(roleId) {
    router.delete(`/permissoes/utilizadores/${props.utilizador.id}/perfis/${roleId}`, {
        preserveScroll: true,
        onSuccess: () => toast.success('Perfil removido.'),
        onError: () => toast.error('Não foi possível remover o perfil.'),
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
        onError: () => toast.error('Não foi possível guardar as permissões.'),
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
                        <a href="#" class="ms-2 text-danger" @click.prevent="removerPerfil(roleId)">&times;</a>
                    </span>
                </div>
                <div class="d-flex gap-2">
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
                                {{ acao.nome }}
                            </th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="modulo in modulos" :key="modulo.id">
                            <td>{{ modulo.descricao }}</td>
                            <td v-for="acao in acoes" :key="acao.id" class="text-center">
                                <button
                                    type="button"
                                    class="btn btn-sm"
                                    :class="{
                                        'btn-light-success': estadoCelula(modulo.id, acao.id) === 1,
                                        'btn-light-danger': estadoCelula(modulo.id, acao.id) === 0,
                                    }"
                                    @click="proximoEstado(modulo.id, acao.id)"
                                >
                                    {{ estadoCelula(modulo.id, acao.id) === 1 ? 'Concedido' : 'Negado' }}
                                </button>
                            </td>
                        </tr>
                    </tbody>
                </table>

                <div class="text-end mt-5">
                    <button class="btn btn-primary" @click="guardarOverrides">Guardar</button>
                </div>
            </div>
        </div>
    </div>
</template>
