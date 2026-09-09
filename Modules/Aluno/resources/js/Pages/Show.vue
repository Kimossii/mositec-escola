<script setup>
import { ref } from 'vue';
import { router } from '@inertiajs/vue3';
import { toast } from 'vue-sonner';
import AppLayout from '@/Layouts/AppLayout.vue';
import { can } from '@/Composables/usePermissoes';
import BotaoVoltar from '@/Components/Shared/BotaoVoltar.vue';
import EstadoBadge from '../Components/Shared/EstadoBadge.vue';
import AlunoFormModal from '../Components/AlunoFormModal.vue';

const props = defineProps({
    aluno: { type: Object, required: true },
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

function formatarData(data) {
    if (!data) return '—';
    const [ano, mes, dia] = data.slice(0, 10).split('-');
    return `${dia}/${mes}/${ano}`;
}

function guardar(payload) {
    processing.value = true;
    errors.value = {};
    router.put(`/alunos/${props.aluno.id}`, payload, {
        preserveScroll: true,
        onSuccess: () => {
            toast.success('Aluno atualizado com sucesso.');
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
        <BotaoVoltar href="/alunos" class="mb-4" />

        <div class="d-flex justify-content-between align-items-center mb-6">
            <div>
                <h1 class="fs-2 fw-bold mb-1">{{ aluno.dados_pessoa?.nome_completo }}</h1>
                <span class="text-muted">Matrícula: {{ aluno.numero_matricula }}</span>
            </div>
            <button v-if="can('aluno.editar')" class="btn btn-primary" @click="abrirEdicao">Editar</button>
        </div>

        <div class="card">
            <div class="card-body">
                <div class="row mb-4">
                    <div class="col-md-3 fw-bold text-muted">Estado</div>
                    <div class="col-md-9">
                        <EstadoBadge :estado="aluno.estado" :estado-descricao="aluno.estado_descricao" />
                    </div>
                </div>
                <div class="row mb-4">
                    <div class="col-md-3 fw-bold text-muted">Email</div>
                    <div class="col-md-9">{{ aluno.dados_pessoa?.email ?? '—' }}</div>
                </div>
                <div class="row mb-4">
                    <div class="col-md-3 fw-bold text-muted">Telefone</div>
                    <div class="col-md-9">{{ aluno.dados_pessoa?.telefone ?? '—' }}</div>
                </div>
                <div class="row mb-4">
                    <div class="col-md-3 fw-bold text-muted">Data de nascimento</div>
                    <div class="col-md-9">{{ formatarData(aluno.dados_pessoa?.data_nascimento) }}</div>
                </div>
                <div class="row mb-4">
                    <div class="col-md-3 fw-bold text-muted">Número de identificação</div>
                    <div class="col-md-9">{{ aluno.dados_pessoa?.numero_identificacao ?? '—' }}</div>
                </div>
            </div>
        </div>

        <AlunoFormModal
            :show="modalAberto"
            :aluno="aluno"
            :processing="processing"
            :errors="errors"
            @submit="guardar"
            @cancelar="fecharModal"
        />
    </div>
</template>
