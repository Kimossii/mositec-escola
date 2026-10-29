<script setup>
import { reactive, watch } from 'vue';
import SelectSolid from '@/Components/Shared/SelectSolid.vue';
import Loader from '@/Components/Shared/Loader.vue';
import ErrosGerais from '../Shared/ErrosGerais.vue';
import { SEXO, SEXO_OPCOES } from '../../Models/Docente';

/** Criar e editar os dados pessoais do docente. O estado muda-se pela acção Ativar/Desativar, não aqui. */
const props = defineProps({
    show: { type: Boolean, default: false },
    docente: { type: Object, default: null }, // linha da lista (edição) ou null (criação)
    processing: { type: Boolean, default: false },
    errors: { type: Object, default: () => ({}) },
});
const emit = defineEmits(['submit', 'cancelar']);

const CAMPOS = ['nome_completo', 'numero_identificacao', 'telefone', 'telefone_alternativo', 'email', 'sexo', 'data_nascimento'];

const form = reactive({
    nome_completo: '',
    numero_identificacao: '',
    telefone: '',
    telefone_alternativo: '',
    email: '',
    sexo: SEXO.NAO_ESPECIFICADO,
    data_nascimento: '',
});

watch(() => props.show, (show) => {
    if (!show) return;
    const d = props.docente;
    form.nome_completo = d?.nome ?? '';
    form.numero_identificacao = d?.bi ?? '';
    form.telefone = d?.telefone ?? '';
    form.telefone_alternativo = d?.telefone_alternativo ?? '';
    form.email = d?.email ?? '';
    form.sexo = d?.sexo ?? SEXO.NAO_ESPECIFICADO;
    form.data_nascimento = d?.data_nascimento ?? '';
});

// Opcionais vazios vão como null (o servidor guarda «sem valor», não texto vazio).
function submeter() {
    const vazioANull = (v) => (typeof v === 'string' && v.trim() === '' ? null : v);
    emit('submit', Object.fromEntries(Object.entries(form).map(([k, v]) => [k, vazioANull(v)])));
}
</script>

<template>
    <div v-if="show" class="modal d-block" style="background: rgba(0,0,0,0.5);" @click.self="emit('cancelar')">
        <div class="modal-dialog modal-dialog-centered modal-lg">
            <div class="modal-content p-6">
                <h3 class="mb-5">{{ docente ? 'Editar Docente' : 'Novo Docente' }}</h3>
                <form @submit.prevent="submeter">
                    <ErrosGerais :errors="errors" :campos="CAMPOS" />

                    <div class="fv-row mb-7">
                        <label class="required fw-semibold fs-6 mb-2">Nome completo</label>
                        <input v-model="form.nome_completo" type="text" class="form-control form-control-solid" placeholder="Nome completo do docente" />
                        <div class="text-danger fs-7 mt-1" v-if="errors.nome_completo">{{ errors.nome_completo }}</div>
                    </div>

                    <div class="row">
                        <div class="col-md-6 fv-row mb-7">
                            <label class="required fw-semibold fs-6 mb-2">Nº de identificação (BI)</label>
                            <input v-model="form.numero_identificacao" type="text" class="form-control form-control-solid" placeholder="ex: 001234567LA045" />
                            <div class="text-danger fs-7 mt-1" v-if="errors.numero_identificacao">{{ errors.numero_identificacao }}</div>
                        </div>
                        <div class="col-md-6 fv-row mb-7">
                            <label class="fw-semibold fs-6 mb-2">Email</label>
                            <input v-model="form.email" type="email" class="form-control form-control-solid" placeholder="exemplo@dominio.com" />
                            <div class="text-danger fs-7 mt-1" v-if="errors.email">{{ errors.email }}</div>
                            <div class="text-muted fs-8 mt-1" v-else>Email de contacto; o de acesso define-se em «Criar acesso».</div>
                        </div>
                    </div>

                    <div class="row">
                        <div class="col-md-6 fv-row mb-7">
                            <label class="fw-semibold fs-6 mb-2">Telefone</label>
                            <input v-model="form.telefone" type="tel" class="form-control form-control-solid" placeholder="ex: 923 000 000" />
                            <div class="text-danger fs-7 mt-1" v-if="errors.telefone">{{ errors.telefone }}</div>
                        </div>
                        <div class="col-md-6 fv-row mb-7">
                            <label class="fw-semibold fs-6 mb-2">Telefone alternativo</label>
                            <input v-model="form.telefone_alternativo" type="tel" class="form-control form-control-solid" />
                            <div class="text-danger fs-7 mt-1" v-if="errors.telefone_alternativo">{{ errors.telefone_alternativo }}</div>
                        </div>
                    </div>

                    <div class="row">
                        <div class="col-md-6 fv-row mb-7">
                            <label class="fw-semibold fs-6 mb-2">Sexo</label>
                            <SelectSolid v-model="form.sexo" :options="SEXO_OPCOES" />
                            <div class="text-danger fs-7 mt-1" v-if="errors.sexo">{{ errors.sexo }}</div>
                        </div>
                        <div class="col-md-6 fv-row mb-7">
                            <label class="fw-semibold fs-6 mb-2">Data de nascimento</label>
                            <input v-model="form.data_nascimento" type="date" class="form-control form-control-solid" />
                            <div class="text-danger fs-7 mt-1" v-if="errors.data_nascimento">{{ errors.data_nascimento }}</div>
                        </div>
                    </div>

                    <div class="text-end">
                        <button type="button" class="btn btn-light-danger me-2" :disabled="processing" @click="emit('cancelar')">Cancelar</button>
                        <button type="submit" class="btn btn-primary" :disabled="processing">
                            <span v-if="processing">A guardar... <Loader size="0.3px" class="align-middle ms-2" /></span>
                            <span v-else>Guardar</span>
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>
</template>
