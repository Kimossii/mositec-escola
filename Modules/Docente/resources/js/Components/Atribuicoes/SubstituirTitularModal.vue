<script setup>
import { rotuloTurma } from '@/Support/turma';
import { computed, reactive, watch } from 'vue';
import SelectSolid from '@/Components/Shared/SelectSolid.vue';
import Loader from '@/Components/Shared/Loader.vue';
import ErrosGerais from '../Shared/ErrosGerais.vue';
import { formatarDia, hojeIso, opcoesDeDocentes } from '../../Models/PapelDocente';

const props = defineProps({
    show: { type: Boolean, default: false },
    linha: { type: Object, default: null }, // linha da vista por turma, com `titular`
    turma: { type: Object, default: null },
    docentes: { type: Array, default: () => [] }, // [{ id, nome, tem_acesso }]
    processing: { type: Boolean, default: false },
    errors: { type: Object, default: () => ({}) },
});
const emit = defineEmits(['submit', 'cancelar']);

// O validador partilhado chama `docente_id` ao novo docente; mostra-se no mesmo campo.
const CAMPOS = ['novo_docente_id', 'docente_id', 'ultimo_dia_do_titular', 'motivo', 'data_inicio'];

const opcoesDocente = computed(() => opcoesDeDocentes(props.docentes.filter((d) => d.id !== props.linha?.titular?.docente_id)));

const form = reactive({ novo_docente_id: '', ultimo_dia_do_titular: '', motivo: '' });

const inicioDoNovo = computed(() => {
    if (!form.ultimo_dia_do_titular) return '';
    const [a, m, d] = form.ultimo_dia_do_titular.split('-').map(Number);
    const dia = new Date(a, m - 1, d + 1);
    return `${String(dia.getDate()).padStart(2, '0')}/${String(dia.getMonth() + 1).padStart(2, '0')}/${dia.getFullYear()}`;
});

watch(() => props.show, (show) => {
    if (!show) return;
    form.novo_docente_id = '';
    form.ultimo_dia_do_titular = hojeIso();
    form.motivo = '';
});

function submeter() {
    emit('submit', { ...form, turma_id: props.turma?.id, disciplina_id: props.linha?.disciplina_id });
}
</script>

<template>
    <div v-if="show" class="modal d-block" style="background: rgba(0,0,0,0.5);" @click.self="emit('cancelar')">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content p-6">
                <h3 class="mb-1">Substituir titular</h3>
                <div class="text-muted fs-7 mb-5">{{ linha?.disciplina_nome }} · {{ rotuloTurma(turma) }}</div>
                <form @submit.prevent="submeter">
                    <ErrosGerais :errors="errors" :campos="CAMPOS" />

                    <div class="card border-0 bg-body-secondary mb-7">
                        <div class="card-body py-4 px-5">
                            <div class="text-muted fs-8 fw-bold text-uppercase mb-1">Titular actual</div>
                            <div class="fw-bold">{{ linha?.titular?.docente_nome }}</div>
                            <div class="text-muted fs-7">desde {{ formatarDia(linha?.titular?.data_inicio) }}</div>
                        </div>
                    </div>

                    <div class="fv-row mb-7">
                        <label class="required fw-semibold fs-6 mb-2">Novo titular</label>
                        <SelectSolid v-model="form.novo_docente_id" :options="opcoesDocente" placeholder="Seleccione o docente" searchable />
                        <div class="text-danger fs-7 mt-1" v-if="errors.novo_docente_id || errors.docente_id">{{ errors.novo_docente_id || errors.docente_id }}</div>
                    </div>

                    <div class="fv-row mb-7">
                        <label class="required fw-semibold fs-6 mb-2">Último dia do titular actual</label>
                        <input v-model="form.ultimo_dia_do_titular" type="date" class="form-control form-control-solid" />
                        <div class="text-danger fs-7 mt-1" v-if="errors.ultimo_dia_do_titular || errors.data_inicio">{{ errors.ultimo_dia_do_titular || errors.data_inicio }}</div>
                        <div class="text-muted fs-8 mt-1" v-else-if="inicioDoNovo">O novo titular começa a {{ inicioDoNovo }}.</div>
                    </div>

                    <div class="fv-row mb-7">
                        <label class="required fw-semibold fs-6 mb-2">Motivo</label>
                        <textarea v-model="form.motivo" class="form-control form-control-solid" rows="3" maxlength="500"></textarea>
                        <div class="text-danger fs-7 mt-1" v-if="errors.motivo">{{ errors.motivo }}</div>
                    </div>

                    <div class="text-end">
                        <button type="button" class="btn btn-light-danger me-2" :disabled="processing" @click="emit('cancelar')">Cancelar</button>
                        <button type="submit" class="btn btn-primary" :disabled="processing">
                            <span v-if="processing">A substituir... <Loader size="0.3px" class="align-middle ms-2" /></span>
                            <span v-else>Substituir</span>
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>
</template>
