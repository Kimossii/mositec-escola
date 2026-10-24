<script setup>
import { computed } from 'vue';
import SelectSolid from '@/Components/Shared/SelectSolid.vue';

const props = defineProps({
    provincia: { type: String, default: '' },
    municipio: { type: String, default: '' },
    provincias: { type: Array, default: () => [] }, // [{ value, label }]
    municipiosPorProvincia: { type: Object, default: () => ({}) }, // { [provincia]: [municipio, ...] }
    labelProvincia: { type: String, default: 'Província' },
    labelMunicipio: { type: String, default: 'Município' },
    erroProvincia: { type: String, default: null },
    erroMunicipio: { type: String, default: null },
});

const emit = defineEmits(['update:provincia', 'update:municipio']);

// Ao trocar de província o município perde a validade e é limpo.
const provinciaSelecionada = computed({
    get: () => props.provincia,
    set: (valor) => {
        emit('update:provincia', valor);
        emit('update:municipio', '');
    },
});

const municipioSelecionado = computed({
    get: () => props.municipio,
    set: (valor) => emit('update:municipio', valor),
});

const opcoesMunicipio = computed(() =>
    (props.municipiosPorProvincia?.[props.provincia] ?? []).map((municipio) => ({ value: municipio, label: municipio })),
);
</script>

<template>
    <div class="row">
        <div class="col-md-6 fv-row mb-0">
            <label class="fw-semibold fs-6 mb-2">{{ labelProvincia }}</label>
            <SelectSolid v-model="provinciaSelecionada" :options="provincias" searchable :altura-maxima="180" />
            <div v-if="erroProvincia" class="text-danger fs-7 mt-1">{{ erroProvincia }}</div>
        </div>
        <div class="col-md-6 fv-row mb-0">
            <label class="fw-semibold fs-6 mb-2">{{ labelMunicipio }}</label>
            <SelectSolid v-model="municipioSelecionado" :options="opcoesMunicipio" searchable :altura-maxima="180" />
            <div v-if="erroMunicipio" class="text-danger fs-7 mt-1">{{ erroMunicipio }}</div>
        </div>
    </div>
</template>
