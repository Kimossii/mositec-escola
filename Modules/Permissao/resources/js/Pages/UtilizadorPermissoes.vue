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
    herdadas: { type: Array, required: true },
    overrides: { type: Array, required: true },
});
defineOptions({ layout: AppLayout });

const chave = (moduloId, acaoId) => `${moduloId}-${acaoId}`;

// -1 = herda, 1 = concede, 0 = nega
const overridesEstado = reactive(
    Object.fromEntries(
        props.overrides.map((o) => [chave(o.modulo_id, o.acao_id), o.permitido ? 1 : 0]),
    ),
);

function estadoCelula(moduloId, acaoId) {
    return overridesEstado[chave(moduloId, acaoId)] ?? -1;
}

function proximoEstado(moduloId, acaoId) {
    const k = chave(moduloId, acaoId);
    const atual = overridesEstado[k] ?? -1;
    const seguinte = atual === -1 ? 1 : atual === 1 ? 0 : -1;

    if (seguinte === -1) {
        delete overridesEstado[k];
    } else {
        overridesEstado[k] = seguinte;
    }
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
                    Clique numa célula para alternar entre Herda (cinza), Concede (verde) e Nega (vermelho).
                    "Herda" usa o que os perfis atribuídos já dão por padrão.
                </p>
                <table class="table align-middle">
                    <thead>
                        <tr>
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
                                        'btn-light': estadoCelula(modulo.id, acao.id) === -1,
                                        'btn-light-success': estadoCelula(modulo.id, acao.id) === 1,
                                        'btn-light-danger': estadoCelula(modulo.id, acao.id) === 0,
                                    }"
                                    @click="proximoEstado(modulo.id, acao.id)"
                                >
                                    {{ estadoCelula(modulo.id, acao.id) === -1 ? 'Herda' : estadoCelula(modulo.id, acao.id) === 1 ? 'Concede' : 'Nega' }}
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
