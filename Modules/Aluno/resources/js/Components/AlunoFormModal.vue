<script setup>
import { computed, reactive, ref, watch } from 'vue';
import SelectSolid from '@/Components/Shared/SelectSolid.vue';
import { ESTADO } from '../Models/Estado';

const props = defineProps({
    show: { type: Boolean, default: false },
    aluno: { type: Object, default: null },
    processing: { type: Boolean, default: false },
    errors: { type: Object, default: () => ({}) },
});
const emit = defineEmits(['submit', 'cancelar']);

const SEXO_OPCOES = [
    { value: 0, label: 'Não especificado' },
    { value: 1, label: 'Masculino' },
    { value: 2, label: 'Feminino' },
];

const ESTADO_OPCOES = [
    { value: ESTADO.ATIVO, label: 'Ativo' },
    { value: ESTADO.INATIVO, label: 'Inativo' },
];

const form = reactive({
    nome_completo: '',
    email: '',
    telefone: '',
    telefone_alternativo: '',
    data_nascimento: '',
    sexo: 0,
    numero_identificacao: '',
    estado: ESTADO.ATIVO,
    foto: null,
});

const previewFoto = ref(null);
const previewSrc = computed(() => previewFoto.value ?? props.aluno?.foto_url ?? null);

watch(() => props.show, (show) => {
    if (!show) return;
    const pessoa = props.aluno?.dados_pessoa ?? {};
    form.nome_completo = pessoa.nome_completo ?? '';
    form.email = pessoa.email ?? '';
    form.telefone = pessoa.telefone ?? '';
    form.telefone_alternativo = pessoa.telefone_alternativo ?? '';
    form.data_nascimento = pessoa.data_nascimento?.slice(0, 10) ?? '';
    form.sexo = pessoa.sexo ?? 0;
    form.numero_identificacao = pessoa.numero_identificacao ?? '';
    form.estado = props.aluno?.estado ?? ESTADO.ATIVO;
    form.foto = null;
    previewFoto.value = null;
});

function onFotoChange(event) {
    const ficheiro = event.target.files?.[0] ?? null;
    form.foto = ficheiro;
    previewFoto.value = ficheiro ? URL.createObjectURL(ficheiro) : null;
}

function submeter() {
    const payload = { ...form };
    if (!props.aluno) {
        delete payload.estado;
    }
    if (!payload.foto) {
        delete payload.foto;
    }
    emit('submit', payload);
}
</script>

<template>
    <div v-if="show" class="modal d-block" style="background: rgba(0,0,0,0.5);" @click.self="emit('cancelar')">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content p-6">
                <h3 class="mb-5">{{ aluno ? 'Editar Aluno' : 'Novo Aluno' }}</h3>
                <form @submit.prevent="submeter">
                    <div class="fv-row mb-7 d-flex align-items-center gap-4">
                        <div class="symbol symbol-circle symbol-75px overflow-hidden">
                            <div class="symbol-label bg-light-primary">
                                <img v-if="previewSrc" :src="previewSrc" alt="Foto do aluno" class="w-100 h-100 object-fit-cover" />
                                <i v-else class="ki-duotone ki-picture fs-2x text-primary"><span class="path1"></span><span class="path2"></span></i>
                            </div>
                        </div>
                        <div>
                            <label class="fw-semibold fs-6 mb-2">Foto</label>
                            <input
                                type="file"
                                class="form-control form-control-solid"
                                accept="image/png,image/jpeg,image/webp"
                                @change="onFotoChange"
                            />
                            <div class="text-muted fs-8 mt-1">PNG, JPG ou WEBP, até 2MB.</div>
                            <div class="text-danger fs-7 mt-1" v-if="errors.foto">{{ errors.foto }}</div>
                        </div>
                    </div>

                    <div class="fv-row mb-7">
                        <label class="required fw-semibold fs-6 mb-2">Nome completo</label>
                        <input v-model="form.nome_completo" type="text" class="form-control form-control-solid" />
                        <div class="text-danger fs-7 mt-1" v-if="errors.nome_completo">{{ errors.nome_completo }}</div>
                    </div>

                    <div class="fv-row mb-7">
                        <label class="fw-semibold fs-6 mb-2">Email</label>
                        <input v-model="form.email" type="email" class="form-control form-control-solid" />
                        <div class="text-danger fs-7 mt-1" v-if="errors.email">{{ errors.email }}</div>
                    </div>

                    <div class="fv-row mb-7">
                        <label class="fw-semibold fs-6 mb-2">Sexo</label>
                        <SelectSolid v-model="form.sexo" :options="SEXO_OPCOES" />
                    </div>

                     <div class="row">
                        <div class="col-md-6 fv-row mb-7">
                            <label class="required fw-semibold fs-6 mb-2">Data de nascimento</label>
                            <input v-model="form.data_nascimento" type="date" class="form-control form-control-solid" required />
                            <div class="text-danger fs-7 mt-1" v-if="errors.data_nascimento">{{ errors.data_nascimento }}</div>
                        </div>
                        <div class="col-md-6 fv-row mb-7">
                            <label class="required fw-semibold fs-6 mb-2">Número de identificação</label>
                            <input v-model="form.numero_identificacao" type="text" class="form-control form-control-solid" />
                            <div class="text-danger fs-7 mt-1" v-if="errors.numero_identificacao">{{ errors.numero_identificacao }}</div>
                        </div>
                    </div>

                    <div class="row">
                        <div class="col-md-6 fv-row mb-7">
                            <label class="fw-semibold fs-6 mb-2">Telefone Principal</label>
                            <input v-model="form.telefone" type="text" class="form-control form-control-solid" />
                        </div>
                        <div class="col-md-6 fv-row mb-7">
                            <label class="fw-semibold fs-6 mb-2">Telefone Alternativo</label>
                            <input v-model="form.telefone_alternativo" type="text" class="form-control form-control-solid" />
                        </div>
                    </div>



                    <div v-if="aluno" class="fv-row mb-7">
                        <label class="required fw-semibold fs-6 mb-2">Estado</label>
                        <SelectSolid v-model="form.estado" :options="ESTADO_OPCOES" />
                        <div class="text-danger fs-7 mt-1" v-if="errors.estado">{{ errors.estado }}</div>
                    </div>

                    <div class="text-end">
                        <button type="button" class="btn btn-light-danger me-2" :disabled="processing" @click="emit('cancelar')">
                            Cancelar
                        </button>
                        <button type="submit" class="btn btn-primary" :disabled="processing">Guardar</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
</template>
