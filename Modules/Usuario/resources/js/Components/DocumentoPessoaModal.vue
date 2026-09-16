<script setup>
import { computed, nextTick, ref, watch } from 'vue';
import axios from 'axios';
import { router } from '@inertiajs/vue3';
import { toast } from 'vue-sonner';
import SelectSolid from '@/Components/Shared/SelectSolid.vue';
import ConfirmModal from '@/Components/Shared/ConfirmModal.vue';
import { ESTADO, estadoBadgeClass } from '../Models/Estado';

const props = defineProps({
    show: { type: Boolean, default: false },
    dadosPessoaId: { type: [Number, String], default: null },
    nomePessoa: { type: String, default: '' },
});
const emit = defineEmits(['fechar']);

function baseUrl() {
    return `/dados-pessoais/${props.dadosPessoaId}/documentos`;
}

const carregando = ref(false);
const documentos = ref([]);
const tipos = ref([]);

async function carregar() {
    carregando.value = true;
    try {
        const [listaResp, tiposResp] = await Promise.all([
            axios.get(baseUrl()),
            axios.get('/tipos-documentos'),
        ]);
        documentos.value = listaResp.data.documentos;
        tipos.value = tiposResp.data.tipos;
    } catch {
        toast.error('Não foi possível carregar os documentos desta pessoa.');
    } finally {
        carregando.value = false;
        // O dropdown "Ações" só existe a partir daqui — o KTMenu global
        // (app.js) só liga o clique de novos triggers depois de uma
        // navegação Inertia, e este carregamento é um axios.get() à parte.
        nextTick(() => window.KTMenu?.init());
    }
}

watch(() => props.show, (show) => {
    if (!show) return;
    formAberto.value = false;
    resetForm();
    carregar();
});

function formatarData(data) {
    if (!data) return '—';
    const [ano, mes, dia] = data.slice(0, 10).split('-');
    return `${dia}/${mes}/${ano}`;
}

// --- Adicionar documento ---
const formAberto = ref(false);
const enviando = ref(false);
const form = ref({
    tipo_documento_id: '',
    numero_documento: '',
    data_emissao: '',
    data_validade: '',
    observacoes: '',
    ficheiro: null,
});

const opcoesTipos = computed(() => tipos.value.map((tipo) => ({ value: tipo.id, label: tipo.nome })));

function resetForm() {
    form.value = {
        tipo_documento_id: '',
        numero_documento: '',
        data_emissao: '',
        data_validade: '',
        observacoes: '',
        ficheiro: null,
    };
}

function abrirForm() {
    formAberto.value = true;
    resetForm();
}

function onFileChange(event) {
    form.value.ficheiro = event.target.files?.[0] ?? null;
}

function adicionar() {
    if (!form.value.tipo_documento_id || !form.value.ficheiro) return;

    enviando.value = true;
    router.post(baseUrl(), { ...form.value }, {
        forceFormData: true,
        preserveScroll: true,
        onSuccess: () => {
            toast.success('Documento adicionado com sucesso.');
            formAberto.value = false;
            carregar();
        },
        onError: (erros) => toast.error(Object.values(erros)[0]),
        onFinish: () => {
            enviando.value = false;
        },
    });
}

// --- Alternar estado ---
const documentoParaAlternarEstado = ref(null);
const alterandoEstado = ref(false);

function pedirAlteracaoEstado(documento) {
    documentoParaAlternarEstado.value = documento;
}

function cancelarAlteracaoEstado() {
    documentoParaAlternarEstado.value = null;
}

function confirmarAlteracaoEstado() {
    alterandoEstado.value = true;
    const documento = documentoParaAlternarEstado.value;
    router.patch(`/documentos-pessoa/${documento.id}/estado`, {}, {
        preserveScroll: true,
        onSuccess: () => {
            toast.success('Estado do documento atualizado com sucesso.');
            carregar();
        },
        onError: (erros) => toast.error(Object.values(erros)[0]),
        onFinish: () => {
            alterandoEstado.value = false;
            cancelarAlteracaoEstado();
        },
    });
}

// --- Eliminar ---
const documentoParaEliminar = ref(null);
const eliminando = ref(false);

function pedirEliminacao(documento) {
    documentoParaEliminar.value = documento;
}

function cancelarEliminacao() {
    documentoParaEliminar.value = null;
}

function confirmarEliminacao() {
    eliminando.value = true;
    const documento = documentoParaEliminar.value;
    router.delete(`/documentos-pessoa/${documento.id}`, {
        preserveScroll: true,
        onSuccess: () => {
            toast.success('Documento eliminado com sucesso.');
            carregar();
        },
        onError: (erros) => toast.error(Object.values(erros)[0]),
        onFinish: () => {
            eliminando.value = false;
            cancelarEliminacao();
        },
    });
}
</script>

<template>
    <div v-if="show" class="modal d-block" style="background: rgba(0,0,0,0.5);" @click.self="emit('fechar')">
        <div class="modal-dialog modal-dialog-centered modal-lg">
            <div class="modal-content p-6">
                <div class="d-flex justify-content-between align-items-center mb-5">
                    <h3 class="mb-0">Documentos{{ nomePessoa ? ` — ${nomePessoa}` : '' }}</h3>
                    <button type="button" class="btn-close" @click="emit('fechar')"></button>
                </div>

                <div v-if="carregando" class="text-center text-muted py-6">A carregar...</div>

                <template v-else>
                    <div class="table-responsive mb-4">
                        <table class="table align-middle table-row-dashed fs-6 gy-4 mb-0">
                            <thead>
                                <tr class="text-start text-muted fw-bold fs-7 text-uppercase gs-0">
                                    <th>Ficheiro</th>
                                    <th>Tipo</th>
                                    <th>Nº Documento</th>
                                    <th>Validade</th>
                                    <th class="min-w-100px">Estado</th>
                                    <th class="text-end min-w-125px">Ações</th>
                                </tr>
                            </thead>
                            <tbody class="text-gray-600 fw-semibold">
                                <tr v-if="!documentos.length">
                                    <td colspan="6" class="text-center text-muted py-6">Nenhum documento adicionado.</td>
                                </tr>
                                <tr v-for="documento in documentos" :key="documento.id">
                                    <td>{{ documento.nome_original }}</td>
                                    <td>{{ documento.tipo_documento?.nome ?? '—' }}</td>
                                    <td>{{ documento.numero_documento ?? '—' }}</td>
                                    <td>{{ formatarData(documento.data_validade) }}</td>
                                    <td>
                                        <div class="badge fw-bold" :class="estadoBadgeClass(documento.estado)">
                                            {{ documento.estado_descricao }}
                                        </div>
                                    </td>
                                    <td class="text-end">
                                        <a
                                            href="#"
                                            class="btn btn-light btn-active-light-primary btn-flex btn-center btn-sm"
                                            data-kt-menu-trigger="click"
                                            data-kt-menu-placement="bottom-end"
                                        >
                                            Ações
                                            <i class="ki-duotone ki-down fs-5 ms-1"></i>
                                        </a>
                                        <div class="menu menu-sub menu-sub-dropdown menu-column menu-rounded menu-gray-600 menu-state-bg-light-primary fw-semibold fs-7 w-200px py-4" data-kt-menu="true">
                                            <div class="menu-item px-3">
                                                <a :href="`/documentos-pessoa/${documento.id}/visualizar`" class="menu-link px-3" target="_blank">
                                                    Visualizar
                                                </a>
                                            </div>
                                            <div class="menu-item px-3">
                                                <a :href="`/documentos-pessoa/${documento.id}/download`" class="menu-link px-3" target="_blank">
                                                    Download
                                                </a>
                                            </div>
                                            <div class="menu-item px-3">
                                                <a href="#" class="menu-link px-3" @click.prevent="pedirAlteracaoEstado(documento)">
                                                    Marcar como {{ documento.estado === ESTADO.ATIVO ? 'Inactivo' : 'Activo' }}
                                                </a>
                                            </div>
                                            <div class="menu-item px-3">
                                                <a href="#" class="menu-link px-3 text-danger" @click.prevent="pedirEliminacao(documento)">
                                                    Eliminar
                                                </a>
                                            </div>
                                        </div>
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>

                    <div v-if="!formAberto">
                        <button type="button" class="btn btn-sm btn-light-primary" @click="abrirForm">
                            + Adicionar Documento
                        </button>
                    </div>
                    <div v-else class="border rounded p-4">
                        <div class="row g-3 mb-3">
                            <div class="col-md-6">
                                <label class="fw-semibold fs-7 mb-1">Tipo de Documento</label>
                                <SelectSolid v-model="form.tipo_documento_id" :options="opcoesTipos" searchable placeholder="Selecione o tipo" />
                            </div>
                            <div class="col-md-6">
                                <label class="fw-semibold fs-7 mb-1">Nº Documento</label>
                                <input v-model="form.numero_documento" type="text" class="form-control form-control-solid" />
                            </div>
                            <div class="col-md-6">
                                <label class="fw-semibold fs-7 mb-1">Data de Emissão</label>
                                <input v-model="form.data_emissao" type="date" class="form-control form-control-solid" />
                            </div>
                            <div class="col-md-6">
                                <label class="fw-semibold fs-7 mb-1">Data de Validade</label>
                                <input v-model="form.data_validade" type="date" class="form-control form-control-solid" />
                            </div>
                            <div class="col-12">
                                <label class="fw-semibold fs-7 mb-1">Observações</label>
                                <textarea v-model="form.observacoes" class="form-control form-control-solid" rows="2"></textarea>
                            </div>
                            <div class="col-12">
                                <label class="required fw-semibold fs-7 mb-1">Ficheiro</label>
                                <input
                                    type="file"
                                    class="form-control form-control-solid"
                                    accept="application/pdf,image/jpeg,image/png"
                                    @change="onFileChange"
                                />
                                <div class="text-muted fs-8 mt-1">PDF, JPG ou PNG, até 2MB.</div>
                            </div>
                        </div>
                        <div class="d-flex justify-content-end gap-2">
                            <button type="button" class="btn btn-light" :disabled="enviando" @click="formAberto = false">
                                Cancelar
                            </button>
                            <button
                                type="button"
                                class="btn btn-primary"
                                :disabled="enviando || !form.tipo_documento_id || !form.ficheiro"
                                @click="adicionar"
                            >
                                Adicionar
                            </button>
                        </div>
                    </div>
                </template>
            </div>
        </div>

        <ConfirmModal
            :show="!!documentoParaAlternarEstado"
            titulo="Alterar Estado do Documento"
            :mensagem="`Marcar '${documentoParaAlternarEstado?.nome_original}' como '${documentoParaAlternarEstado?.estado === ESTADO.ATIVO ? 'Inactivo' : 'Activo'}'?`"
            texto-confirmar="Confirmar"
            :processando="alterandoEstado"
            @confirmar="confirmarAlteracaoEstado"
            @cancelar="cancelarAlteracaoEstado"
        />

        <ConfirmModal
            :show="!!documentoParaEliminar"
            titulo="Eliminar Documento"
            :mensagem="`Eliminar o documento '${documentoParaEliminar?.nome_original}'? Esta ação não pode ser desfeita.`"
            texto-confirmar="Eliminar"
            :processando="eliminando"
            @confirmar="confirmarEliminacao"
            @cancelar="cancelarEliminacao"
        />
    </div>
</template>
