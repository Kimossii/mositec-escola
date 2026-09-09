<script setup>
import { ref } from 'vue';
import { router } from '@inertiajs/vue3';
import { toast } from 'vue-sonner';
import AppLayout from '@/Layouts/AppLayout.vue';
import { can } from '@/Composables/usePermissoes';
import BotaoVoltar from '@/Components/Shared/BotaoVoltar.vue';
import EstadoBadge from '../Components/Shared/EstadoBadge.vue';
import CursoFormModal from '../Components/CursoFormModal.vue';

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

        <div class="card">
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

        <CursoFormModal
            :show="modalAberto"
            :curso="curso"
            :processing="processing"
            :errors="errors"
            @submit="guardar"
            @cancelar="fecharModal"
        />
    </div>
</template>
