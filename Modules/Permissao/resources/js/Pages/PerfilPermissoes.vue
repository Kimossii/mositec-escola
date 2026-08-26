<script setup>
import { reactive } from 'vue';
import { router } from '@inertiajs/vue3';
import { toast } from 'vue-sonner';
import AppLayout from '@/Layouts/AppLayout.vue';
import BotaoVoltar from '@/Components/Shared/BotaoVoltar.vue';

const props = defineProps({
    perfil: { type: Object, required: true },
    modulos: { type: Array, required: true },
    acoes: { type: Array, required: true },
    marcadas: { type: Array, required: true },
});
defineOptions({ layout: AppLayout });

const chave = (moduloId, acaoId) => `${moduloId}-${acaoId}`;

const estado = reactive(
    Object.fromEntries(
        props.marcadas.map((m) => [chave(m.modulo_id, m.acao_id), true]),
    ),
);

function alternar(moduloId, acaoId) {
    const k = chave(moduloId, acaoId);
    estado[k] = !estado[k];
}

function guardar() {
    const celulas = Object.entries(estado)
        .filter(([, marcado]) => marcado)
        .map(([k]) => {
            const [modulo_id, acao_id] = k.split('-').map(Number);
            return { modulo_id, acao_id };
        });

    router.put(`/permissoes/perfis/${props.perfil.id}/permissoes`, { celulas }, {
        preserveScroll: true,
        onSuccess: () => toast.success('Permissões do perfil atualizadas.'),
        onError: () => toast.error('Não foi possível guardar as permissões.'),
    });
}
</script>

<template>
    <div class="app-container container-xxl py-6">
        <BotaoVoltar />
        <h1 class="fs-2 fw-bold mb-6">Permissões — {{ perfil.descricao }}</h1>

        <div class="card">
            <div class="card-body">
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
                                <input
                                    type="checkbox"
                                    class="form-check-input"
                                    :checked="!!estado[`${modulo.id}-${acao.id}`]"
                                    @change="alternar(modulo.id, acao.id)"
                                />
                            </td>
                        </tr>
                    </tbody>
                </table>

                <div class="text-end mt-5">
                    <button class="btn btn-primary" @click="guardar">Guardar</button>
                </div>
            </div>
        </div>
    </div>
</template>
