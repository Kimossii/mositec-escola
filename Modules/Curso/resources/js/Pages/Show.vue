<script setup>
import { ref } from 'vue';
import { router } from '@inertiajs/vue3';
import { toast } from 'vue-sonner';
import AppLayout from '@/Layouts/AppLayout.vue';
import { can } from '@/Composables/usePermissoes';
import BotaoVoltar from '@/Components/Shared/BotaoVoltar.vue';
import EstadoBadge from '../Components/Shared/EstadoBadge.vue';
import CursoFormModal from '../Components/CursoFormModal.vue';
import NovoPlanoCurricularModal from '../Components/PlanosCurriculares/NovoPlanoCurricularModal.vue';

const props = defineProps({
    curso: { type: Object, required: true },
});
defineOptions({ layout: AppLayout });

const modalAberto = ref(false);
const processing = ref(false);
const errors = ref({});

function abrirEdicao() {
    errors.value = {};
    modalAberto.value = true;
}

function fecharModal() {
    modalAberto.value = false;
}

function guardar(payload) {
    processing.value = true;
    errors.value = {};
    router.put(`/cursos/${props.curso.id}`, payload, {
        preserveScroll: true,
        onSuccess: () => {
            toast.success('Curso atualizado com sucesso.');
            fecharModal();
        },
        onError: (erros) => {
            errors.value = erros;
            toast.error(Object.values(erros)[0]);
        },
        onFinish: () => {
            processing.value = false;
        },
    });
}

// ========== Planos Curriculares ==========
const planoModalAberto = ref(false);
const planoProcessing = ref(false);
const planoErrors = ref({});

function abrirCriacaoPlano() {
    planoErrors.value = {};
    planoModalAberto.value = true;
}

function fecharPlanoModal() {
    planoModalAberto.value = false;
}

function guardarPlano(payload) {
    planoProcessing.value = true;
    planoErrors.value = {};
    router.post('/planos-curriculares', { ...payload, curso_id: props.curso.id }, {
        preserveScroll: true,
        onSuccess: () => {
            toast.success('Plano curricular criado com sucesso.');
            fecharPlanoModal();
        },
        onError: (erros) => {
            planoErrors.value = erros;
            toast.error(Object.values(erros)[0]);
        },
        onFinish: () => {
            planoProcessing.value = false;
        },
    });
}
</script>

<template>
    <div class="app-container container-xxl py-6">
        <BotaoVoltar href="/cursos" class="mb-4" />

        <div class="d-flex justify-content-between align-items-center mb-6">
            <div>
                <h1 class="fs-2 fw-bold mb-1">{{ curso.nome }}</h1>
                <span class="text-muted">Código: {{ curso.codigo }}</span>
            </div>
            <button v-if="can('curso.editar')" class="btn btn-primary" @click="abrirEdicao">Editar</button>
        </div>

        <div class="card mb-6">
            <div class="card-body">
                <div class="row mb-4">
                    <div class="col-md-3 fw-bold text-muted">Estado</div>
                    <div class="col-md-9">
                        <EstadoBadge :estado="curso.estado" :estado-descricao="curso.estado_descricao" />
                    </div>
                </div>
                <div class="row mb-4">
                    <div class="col-md-3 fw-bold text-muted">Descrição</div>
                    <div class="col-md-9">{{ curso.descricao ?? '—' }}</div>
                </div>
            </div>
        </div>

        <div v-if="can('plano-curricular.ver')" class="card">
            <div class="card-header d-flex justify-content-between align-items-center">
                <h3 class="card-title fw-bold">Planos Curriculares</h3>
                <button v-if="can('plano-curricular.criar')" class="btn btn-primary btn-sm" @click="abrirCriacaoPlano">Novo Plano</button>
            </div>
            <div class="card-body p-0">
                <table class="table align-middle table-row-dashed table-hover fs-6 gy-5 mb-0">
                    <thead>
                        <tr class="text-start text-muted fw-bold fs-7 text-uppercase gs-0">
                            <th class="min-w-100px">Código</th>
                            <th class="min-w-200px">Nome</th>
                            <th class="min-w-125px">Estado</th>
                            <th class="text-end min-w-100px">Ações</th>
                        </tr>
                    </thead>
                    <tbody class="text-gray-600 fw-semibold">
                        <tr v-if="curso.planos_curriculares.length === 0">
                            <td colspan="4" class="text-center text-muted py-6">Nenhum plano curricular criado para este curso.</td>
                        </tr>
                        <tr v-for="plano in curso.planos_curriculares" :key="plano.id">
                            <td>
                                <a :href="`/planos-curriculares/${plano.id}`" class="text-gray-800 text-hover-primary">{{ plano.codigo }}</a>
                            </td>
                            <td>{{ plano.nome }}</td>
                            <td>
                                <EstadoBadge :estado="plano.estado" :estado-descricao="plano.estado_descricao" />
                            </td>
                            <td class="text-end">
                                <a :href="`/planos-curriculares/${plano.id}`" class="btn btn-light btn-sm">Ver</a>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>

        <CursoFormModal
            :show="modalAberto"
            :curso="curso"
            :processing="processing"
            :errors="errors"
            @submit="guardar"
            @cancelar="fecharModal"
        />

        <NovoPlanoCurricularModal
            :show="planoModalAberto"
            :processing="planoProcessing"
            :errors="planoErrors"
            @submit="guardarPlano"
            @cancelar="fecharPlanoModal"
        />
    </div>
</template>
