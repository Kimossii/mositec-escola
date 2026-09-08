<script setup>
import { ref } from 'vue';
import { router } from '@inertiajs/vue3';
import { toast } from 'vue-sonner';
import AppLayout from '@/Layouts/AppLayout.vue';
import { can } from '@/Composables/usePermissoes';
import BotaoVoltar from '@/Components/Shared/BotaoVoltar.vue';
import AcaoIcone from '@/Components/Shared/AcaoIcone.vue';
import EstadoBadge from '../../Components/Shared/EstadoBadge.vue';
import TurmaFormModal from '../../Components/Turma/TurmaFormModal.vue';
import AssociarSalaModal from '../../Components/Turma/AssociarSalaModal.vue';

const props = defineProps({
    turma: { type: Object, required: true },
    salas: { type: Array, required: true },
    anoLectivos: { type: Array, required: true },
    niveisAcademicos: { type: Array, required: true },
    turnos: { type: Array, required: true },
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
    router.put(`/turmas/${props.turma.id}`, payload, {
        preserveScroll: true,
        onSuccess: () => {
            toast.success('Turma atualizada com sucesso.');
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

const associarModalAberto = ref(false);
const associarProcessing = ref(false);
const associarErrors = ref({});

function abrirAssociarSala() {
    associarErrors.value = {};
    associarModalAberto.value = true;
}

function fecharAssociarSala() {
    associarModalAberto.value = false;
}

function associarSala(payload) {
    associarProcessing.value = true;
    associarErrors.value = {};
    router.post(`/turmas/${props.turma.id}/salas`, payload, {
        preserveScroll: true,
        onSuccess: () => {
            toast.success('Sala associada à turma com sucesso.');
            fecharAssociarSala();
        },
        onError: (erros) => {
            associarErrors.value = erros;
            toast.error(Object.values(erros)[0]);
        },
        onFinish: () => {
            associarProcessing.value = false;
        },
    });
}

const encerrando = ref(null);

function encerrarSala(turmaSala) {
    encerrando.value = turmaSala.id;
    router.patch(`/turmas/${props.turma.id}/salas/${turmaSala.id}/encerrar`, {
        fim: new Date().toISOString().slice(0, 10),
    }, {
        preserveScroll: true,
        onSuccess: () => toast.success('Associação da sala encerrada com sucesso.'),
        onError: (erros) => toast.error(Object.values(erros)[0]),
        onFinish: () => {
            encerrando.value = null;
        },
    });
}
</script>

<template>
    <div class="app-container container-xxl py-6">
        <BotaoVoltar />

        <div class="card mb-6">
            <div class="card-body d-flex justify-content-between align-items-start">
                <div>
                    <h1 class="fs-2 fw-bold mb-2">{{ turma.nome }}</h1>
                    <div class="text-muted fs-6 mb-3">Código: {{ turma.codigo }}</div>
                    <EstadoBadge :estado="turma.estado" :estado-descricao="turma.estado_descricao" />
                </div>
                <button v-if="can('turmas.editar')" class="btn btn-light-primary" @click="abrirEdicao">Editar</button>
            </div>

            <div class="card-body border-top pt-6">
                <div class="row">
                    <div class="col-md-4 mb-6">
                        <div class="text-muted fs-7 text-uppercase fw-bold mb-1">Ano Lectivo</div>
                        <div class="fs-6">{{ turma.ano_lectivo?.nome ?? '—' }}</div>
                    </div>
                    <div class="col-md-4 mb-6">
                        <div class="text-muted fs-7 text-uppercase fw-bold mb-1">Nível Académico</div>
                        <div class="fs-6">{{ turma.nivel_academico?.nome ?? '—' }}</div>
                    </div>
                    <div class="col-md-4 mb-6">
                        <div class="text-muted fs-7 text-uppercase fw-bold mb-1">Turno</div>
                        <div class="fs-6">{{ turma.turno?.nome ?? '—' }}</div>
                    </div>
                </div>
            </div>
        </div>

        <div class="card">
            <div class="card-header">
                <h3 class="card-title fw-bold">Salas</h3>
                <div class="card-toolbar">
                    <button v-if="can('turmas.editar')" class="btn btn-sm btn-primary" @click="abrirAssociarSala">
                        Associar Sala
                    </button>
                </div>
            </div>
            <div class="card-body p-0">
                <table class="table align-middle table-row-dashed table-hover fs-6 gy-5 mb-0">
                    <thead>
                        <tr class="text-start text-muted fw-bold fs-7 text-uppercase gs-0">
                            <th class="min-w-150px">Sala</th>
                            <th class="min-w-100px">Início</th>
                            <th class="min-w-100px">Fim</th>
                            <th class="text-end min-w-100px">Ações</th>
                        </tr>
                    </thead>
                    <tbody class="text-gray-600 fw-semibold">
                        <tr v-if="!turma.turma_salas?.length">
                            <td colspan="4" class="text-center text-muted py-6">Nenhuma sala associada.</td>
                        </tr>
                        <tr v-for="turmaSala in turma.turma_salas" :key="turmaSala.id">
                            <td>{{ turmaSala.sala?.codigo }} — {{ turmaSala.sala?.nome }}</td>
                            <td>{{ turmaSala.inicio }}</td>
                            <td>{{ turmaSala.fim ?? '—' }}</td>
                            <td class="text-end">
                                <button
                                    v-if="can('turmas.editar') && !turmaSala.fim"
                                    class="btn btn-sm btn-light-danger"
                                    :disabled="encerrando === turmaSala.id"
                                    @click="encerrarSala(turmaSala)"
                                >
                                    <AcaoIcone acao="encerrar" class="me-1" />
                                    Encerrar
                                </button>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>

        <TurmaFormModal
            :show="editModalAberto"
            :turma="turma"
            :ano-lectivos="anoLectivos"
            :niveis-academicos="niveisAcademicos"
            :turnos="turnos"
            :processing="editProcessing"
            :errors="editErrors"
            @submit="guardar"
            @cancelar="fecharEdicao"
        />

        <AssociarSalaModal
            :show="associarModalAberto"
            :salas="salas"
            :processing="associarProcessing"
            :errors="associarErrors"
            @submit="associarSala"
            @cancelar="fecharAssociarSala"
        />
    </div>
</template>
