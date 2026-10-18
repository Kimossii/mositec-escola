<script setup>
import { computed, reactive, ref, watch } from 'vue';
import SelectSolid from '@/Components/Shared/SelectSolid.vue';
import { mesesTocados, rotuloMes } from '../../Support/pausas';

const props = defineProps({
    show: { type: Boolean, default: false },
    pausa: { type: Object, default: null },
    anosLectivos: { type: Array, required: true },
    processing: { type: Boolean, default: false },
    errors: { type: Object, default: () => ({}) },
});
const emit = defineEmits(['submit', 'cancelar']);

const TIPOS = [
    { value: true, label: 'Cobra propina (pausa informativa, sem efeito)' },
    { value: false, label: 'Não cobra propina (isenta os meses tocados)' },
];

const form = reactive({
    ano_lectivo_id: '',
    descricao: '',
    data_inicio: '',
    data_fim: '',
    cobra_propina: true,
});

const escolhas = reactive({});

const opcoesAno = computed(() => props.anosLectivos.map((a) => ({ value: a.id, label: a.nome })));
const anoSelecionado = computed(() => props.anosLectivos.find((a) => a.id === form.ano_lectivo_id));

const tocados = computed(() => mesesTocados(form.data_inicio, form.data_fim));
const inteiros = computed(() => tocados.value.filter((m) => m.completo));
const parciais = computed(() => tocados.value.filter((m) => !m.completo));

const chave = (m) => `${m.ano}-${m.mes}`;

watch(() => props.show, (show) => {
    if (!show) return;

    form.ano_lectivo_id = props.pausa?.ano_lectivo_id ?? props.anosLectivos[0]?.id ?? '';
    form.descricao = props.pausa?.descricao ?? '';
    form.data_inicio = props.pausa?.data_inicio ?? '';
    form.data_fim = props.pausa?.data_fim ?? '';
    form.cobra_propina = props.pausa?.cobra_propina ?? true;

    Object.keys(escolhas).forEach((k) => delete escolhas[k]);
    (props.pausa?.meses ?? []).forEach((m) => {
        escolhas[chave(m)] = m.isento;
    });
});

// Meses parciais novos (mudança de datas) nascem desmarcados (cobra).
watch(parciais, (lista) => {
    lista.forEach((m) => {
        if (!(chave(m) in escolhas)) escolhas[chave(m)] = false;
    });
}, { deep: true });

function submeter() {
    const payload = {
        descricao: form.descricao,
        data_inicio: form.data_inicio,
        data_fim: form.data_fim,
        cobra_propina: form.cobra_propina,
        meses: parciais.value.map((m) => ({ ano: m.ano, mes: m.mes, isento: escolhas[chave(m)] ?? false })),
    };

    if (!props.pausa) payload.ano_lectivo_id = form.ano_lectivo_id;

    emit('submit', payload);
}
</script>

<template>
    <div v-if="show" class="modal d-block" style="background: rgba(0,0,0,0.5); overflow-y: auto;" @click.self="!processing && emit('cancelar')">
        <div class="modal-dialog modal-dialog-centered modal-xl">
            <div class="modal-content p-6">
                <h3 class="mb-5">{{ pausa ? 'Editar Pausa de Cobrança' : 'Nova Pausa de Cobrança' }}</h3>

                <form @submit.prevent="submeter">
                    <div class="row">
                        <div class="col-md-6 fv-row mb-7">
                            <label class="required fw-semibold fs-6 mb-2">Descrição</label>
                            <input v-model="form.descricao" type="text" class="form-control form-control-solid" maxlength="200" placeholder="ex: Pausa de cobrança de Dezembro" />
                            <div v-if="errors.descricao" class="text-danger fs-7 mt-1">{{ errors.descricao }}</div>
                        </div>
                        <div class="col-md-6 fv-row mb-7">
                            <label class="required fw-semibold fs-6 mb-2">Ano Lectivo</label>
                            <input v-if="pausa" type="text" class="form-control form-control-solid" :value="anoSelecionado?.nome ?? ''" disabled />
                            <SelectSolid v-else v-model="form.ano_lectivo_id" :options="opcoesAno" />
                            <div v-if="errors.ano_lectivo_id" class="text-danger fs-7 mt-1">{{ errors.ano_lectivo_id }}</div>
                        </div>
                    </div>

                    <div class="row">
                        <div class="col-md-4 fv-row mb-7">
                            <label class="required fw-semibold fs-6 mb-2">Data de início</label>
                            <input v-model="form.data_inicio" type="date" class="form-control form-control-solid" :min="anoSelecionado?.data_inicio" :max="anoSelecionado?.data_fim" />
                            <div v-if="errors.data_inicio" class="text-danger fs-7 mt-1">{{ errors.data_inicio }}</div>
                        </div>
                        <div class="col-md-4 fv-row mb-7">
                            <label class="required fw-semibold fs-6 mb-2">Data de fim</label>
                            <input v-model="form.data_fim" type="date" class="form-control form-control-solid" :min="anoSelecionado?.data_inicio" :max="anoSelecionado?.data_fim" />
                            <div v-if="errors.data_fim" class="text-danger fs-7 mt-1">{{ errors.data_fim }}</div>
                        </div>
                        <div class="col-md-4 fv-row mb-7">
                            <label class="required fw-semibold fs-6 mb-2">Tipo</label>
                            <SelectSolid v-model="form.cobra_propina" :options="TIPOS" />
                        </div>
                    </div>

                    <div v-if="!form.cobra_propina && tocados.length" class="alert alert-warning py-3 fs-7 mb-5">
                        <div class="fw-semibold mb-2">Meses tocados pela pausa</div>
                        <div class="mb-1">
                            Os meses cobertos por inteiro ficam <strong>isentos</strong> (sem cobrança). Nos meses só parcialmente
                            cobertos, escolha se não os quer cobrar.
                        </div>
                        <ul class="list-unstyled mb-2">
                            <li v-for="m in inteiros" :key="chave(m)" class="d-flex align-items-center gap-2">
                                <i class="ki-duotone ki-check-circle text-success fs-4"><span class="path1"></span><span class="path2"></span></i>
                                {{ rotuloMes(m.mes) }} {{ m.ano }} — isento
                            </li>
                        </ul>
                        <div v-for="m in parciais" :key="chave(m)" class="form-check form-check-custom form-check-solid mb-1">
                            <input :id="'mes-' + chave(m)" v-model="escolhas[chave(m)]" class="form-check-input" type="checkbox" />
                            <label :for="'mes-' + chave(m)" class="form-check-label">Não cobrar {{ rotuloMes(m.mes) }} {{ m.ano }} (mês parcial)</label>
                        </div>
                    </div>

                    <div class="text-end">
                        <button type="button" class="btn btn-light-danger me-2" :disabled="processing" @click="emit('cancelar')">Cancelar</button>
                        <button type="submit" class="btn btn-primary" :disabled="processing">{{ processing ? 'A guardar…' : 'Guardar' }}</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
</template>
