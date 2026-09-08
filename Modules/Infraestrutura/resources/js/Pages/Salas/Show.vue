<script setup>
import { ref } from 'vue';
import { router } from '@inertiajs/vue3';
import { toast } from 'vue-sonner';
import AppLayout from '@/Layouts/AppLayout.vue';
import { can } from '@/Composables/usePermissoes';
import BotaoVoltar from '@/Components/Shared/BotaoVoltar.vue';
import SalaStatusBadge from '../../Components/Sala/SalaStatusBadge.vue';
import SalaFormModal from '../../Components/Sala/SalaFormModal.vue';
import { tipoSalaLabel } from '../../Models/Sala';

const props = defineProps({
    sala: { type: Object, required: true },
});
defineOptions({ layout: AppLayout });

const editModalAberto = ref(false);
const editProcessing = ref(false);
const editErrors = ref({});

function abrirEdicao() {
    editErrors.value = {};
    editModalAberto.value = true;
}

function fecharEdicao() {
    editModalAberto.value = false;
}

function guardar(payload) {
    editProcessing.value = true;
    editErrors.value = {};
    router.put(`/salas/${props.sala.id}`, payload, {
        preserveScroll: true,
        onSuccess: () => {
            toast.success('Sala atualizada com sucesso.');
            fecharEdicao();
        },
        onError: (erros) => {
            editErrors.value = erros;
            toast.error(Object.values(erros)[0]);
        },
        onFinish: () => {
            editProcessing.value = false;
        },
    });
}
</script>

<template>
    <div class="app-container container-xxl py-6">
        <BotaoVoltar />

        <div class="card">
            <div class="card-body d-flex justify-content-between align-items-start">
                <div>
                    <h1 class="fs-2 fw-bold mb-2">{{ sala.nome }}</h1>
                    <div class="text-muted fs-6 mb-3">Código: {{ sala.codigo }}</div>
                    <SalaStatusBadge :estado="sala.estado" :estado-descricao="sala.estado_descricao" />
                </div>
                <button v-if="can('infraestrutura.editar')" class="btn btn-light-primary" @click="abrirEdicao">Editar</button>
            </div>

            <div class="card-body border-top pt-6">
                <div class="row">
                    <div class="col-md-4 mb-6">
                        <div class="text-muted fs-7 text-uppercase fw-bold mb-1">Tipo</div>
                        <div class="fs-6">{{ tipoSalaLabel(sala.tipo) }}</div>
                    </div>
                    <div class="col-md-4 mb-6">
                        <div class="text-muted fs-7 text-uppercase fw-bold mb-1">Capacidade</div>
                        <div class="fs-6">{{ sala.capacidade ?? '—' }}</div>
                    </div>
                    <div class="col-md-4 mb-6">
                        <div class="text-muted fs-7 text-uppercase fw-bold mb-1">Localização</div>
                        <div class="fs-6">{{ sala.localizacao ?? '—' }}</div>
                    </div>
                </div>
                <div class="row">
                    <div class="col-12">
                        <div class="text-muted fs-7 text-uppercase fw-bold mb-1">Observações</div>
                        <div class="fs-6">{{ sala.observacoes ?? '—' }}</div>
                    </div>
                </div>
            </div>
        </div>

        <SalaFormModal
            :show="editModalAberto"
            :sala="sala"
            :processing="editProcessing"
            :errors="editErrors"
            @submit="guardar"
            @cancelar="fecharEdicao"
        />
    </div>
</template>
