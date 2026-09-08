<script setup>
import { reactive } from 'vue';
import { router } from '@inertiajs/vue3';
import { toast } from 'vue-sonner';
import AppLayout from '@/Layouts/AppLayout.vue';
import { can } from '@/Composables/usePermissoes';
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

function todosMarcadosNaColuna(acaoId) {
    return props.modulos.every((modulo) => !!estado[chave(modulo.id, acaoId)]);
}

function alternarColuna(acaoId) {
    const marcar = !todosMarcadosNaColuna(acaoId);
    props.modulos.forEach((modulo) => {
        estado[chave(modulo.id, acaoId)] = marcar;
    });
}

function todosMarcadosNaLinha(moduloId) {
    return props.acoes.every((acao) => !!estado[chave(moduloId, acao.id)]);
}

function alternarLinha(moduloId) {
    const marcar = !todosMarcadosNaLinha(moduloId);
    props.acoes.forEach((acao) => {
        estado[chave(moduloId, acao.id)] = marcar;
    });
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
        onError: (erros) => toast.error(Object.values(erros)[0]),
    });
}
</script>

<template>
    <div class="app-container container-xxl py-6">
        <BotaoVoltar />
        <h1 class="fs-2 fw-bold mb-6">Permissões — {{ perfil.descricao }}</h1>

        <div class="card">
            <div class="card-body">
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
                                        :checked="todosMarcadosNaColuna(acao.id)"
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
                                        :checked="todosMarcadosNaLinha(modulo.id)"
                                        :disabled="!can('autorizacao.editar')"
                                        @change="alternarLinha(modulo.id)"
                                    />
                                    <span>{{ modulo.descricao }}</span>
                                </div>
                            </td>
                            <td v-for="acao in acoes" :key="acao.id" class="text-center">
                                <input
                                    type="checkbox"
                                    class="form-check-input"
                                    :checked="!!estado[`${modulo.id}-${acao.id}`]"
                                    :disabled="!can('autorizacao.editar')"
                                    @change="alternar(modulo.id, acao.id)"
                                />
                            </td>
                        </tr>
                    </tbody>
                </table>

                <div v-if="can('autorizacao.editar')" class="text-end mt-5">
                    <button class="btn btn-primary" @click="guardar">Guardar</button>
                </div>
            </div>
        </div>
    </div>
</template>
