<script setup>
import { ref } from 'vue';
import { router } from '@inertiajs/vue3';
import { toast } from 'vue-sonner';
import AppLayout from '@/Layouts/AppLayout.vue';
import AcaoIcone from '@/Components/Shared/AcaoIcone.vue';
import ConfirmModal from '@/Components/Shared/ConfirmModal.vue';
import HorarioStatusBadge from '../../Components/HorarioStatusBadge.vue';
import HorarioFormModal from '../../Components/HorarioFormModal.vue';

defineProps({
    horarios: { type: Array, required: true },
});
defineOptions({ layout: AppLayout });

// --- Criar / Editar ---
const modalAberto = ref(false);
const horarioEmEdicao = ref(null);
const processing = ref(false);
const errors = ref({});

function abrirCriacao() {
    horarioEmEdicao.value = null;
    errors.value = {};
    modalAberto.value = true;
}

function abrirEdicao(horario) {
    horarioEmEdicao.value = horario;
    errors.value = {};
    modalAberto.value = true;
}

function fecharModal() {
    modalAberto.value = false;
}

function guardar(payload) {
    processing.value = true;
    errors.value = {};

    const url = horarioEmEdicao.value ? `/horarios/${horarioEmEdicao.value.id}` : '/horarios';
    const metodo = horarioEmEdicao.value ? 'put' : 'post';

    router[metodo](url, payload, {
        preserveScroll: true,
        onSuccess: () => {
            toast.success(horarioEmEdicao.value ? 'Horário atualizado com sucesso.' : 'Horário criado com sucesso.');
            fecharModal();
        },
        onError: (erros) => {
            errors.value = erros;
            toast.error(Object.values(erros)[0] ?? 'Não foi possível guardar o horário.');
        },
        onFinish: () => {
            processing.value = false;
        },
    });
}

// --- Eliminar ---
const horarioParaEliminar = ref(null);
const eliminando = ref(false);

function pedirEliminacao(horario) {
    horarioParaEliminar.value = horario;
}

function cancelarEliminacao() {
    horarioParaEliminar.value = null;
}

function confirmarEliminacao() {
    eliminando.value = true;
    router.delete(`/horarios/${horarioParaEliminar.value.id}`, {
        preserveScroll: true,
        onSuccess: () => toast.success('Horário eliminado com sucesso.'),
        onError: (erros) => toast.error(Object.values(erros)[0] ?? 'Não foi possível eliminar o horário.'),
        onFinish: () => {
            eliminando.value = false;
            horarioParaEliminar.value = null;
        },
    });
}
</script>

<template>
    <div class="app-container container-xxl py-6">
        <div class="d-flex justify-content-between align-items-center mb-6">
            <div>
                <h1 class="fs-2 fw-bold mb-1">Horários</h1>
                <p class="text-muted fs-6 mb-0" style="max-width: 640px">
                    Intervalos de tempo reutilizáveis (ex: turnos, blocos do dia) que outros módulos poderão usar para montar os seus próprios horários.
                </p>
            </div>
            <button class="btn btn-primary" @click="abrirCriacao">Novo Horário</button>
        </div>

        <div class="card">
            <div class="card-body p-0">
                <table class="table align-middle table-row-dashed table-hover fs-6 gy-5 mb-0">
                    <thead>
                        <tr class="text-start text-muted fw-bold fs-7 text-uppercase gs-0">
                            <th class="min-w-200px">Nome</th>
                            <th class="min-w-150px">Intervalo</th>
                            <th class="min-w-100px">Estado</th>
                            <th class="text-end min-w-100px">Ações</th>
                        </tr>
                    </thead>
                    <tbody class="text-gray-600 fw-semibold">
                        <tr v-if="horarios.length === 0">
                            <td colspan="4" class="text-center text-muted py-6">Nenhum Horário criado.</td>
                        </tr>
                        <tr v-for="horario in horarios" :key="horario.id">
                            <td>{{ horario.nome }}</td>
                            <td>{{ horario.hora_inicio }} — {{ horario.hora_fim }}</td>
                            <td>
                                <HorarioStatusBadge :estado="horario.estado" :estado-descricao="horario.estado_descricao" />
                            </td>
                            <td class="text-end">
                                <button type="button" class="btn btn-icon btn-sm btn-light-primary me-2" @click="abrirEdicao(horario)">
                                    <AcaoIcone acao="editar" />
                                </button>
                                <button type="button" class="btn btn-icon btn-sm btn-light-danger" @click="pedirEliminacao(horario)">
                                    <AcaoIcone acao="eliminar" />
                                </button>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>

        <HorarioFormModal
            :show="modalAberto"
            :horario="horarioEmEdicao"
            :processing="processing"
            :errors="errors"
            @submit="guardar"
            @cancelar="fecharModal"
        />

        <ConfirmModal
            :show="!!horarioParaEliminar"
            titulo="Eliminar Horário"
            :mensagem="`Tem certeza que deseja eliminar o horário ${horarioParaEliminar?.nome}?`"
            :processando="eliminando"
            @confirmar="confirmarEliminacao"
            @cancelar="cancelarEliminacao"
        />
    </div>
</template>
