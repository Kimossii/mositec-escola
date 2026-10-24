<script setup>
import { ref } from 'vue';

defineProps({
    modelValue: { type: String, default: '' },
    rotulo: { type: String, required: true },
    placeholder: { type: String, default: '' },
    autocomplete: { type: String, default: 'off' },
    erro: { type: String, default: null },
});
defineEmits(['update:modelValue']);

const visivel = ref(false);
</script>

<template>
    <div class="fv-row mb-7">
        <label class="required fw-semibold fs-6 mb-2">{{ rotulo }}</label>
        <div class="position-relative">
            <input
                :value="modelValue"
                :type="visivel ? 'text' : 'password'"
                :autocomplete="autocomplete"
                :placeholder="placeholder"
                class="form-control form-control-solid"
                :class="{ 'is-invalid': erro }"
                style="padding-right: 3rem;"
                @input="$emit('update:modelValue', $event.target.value)"
            />
            <button
                type="button"
                class="btn btn-icon btn-sm position-absolute top-50 translate-middle-y end-0 me-2 text-gray-500"
                tabindex="-1"
                :title="visivel ? 'Ocultar' : 'Mostrar'"
                @click="visivel = !visivel"
            >
                <i v-if="visivel" class="ki-duotone ki-eye-slash fs-3"><span class="path1"></span><span class="path2"></span><span class="path3"></span><span class="path4"></span></i>
                <i v-else class="ki-duotone ki-eye fs-3"><span class="path1"></span><span class="path2"></span><span class="path3"></span></i>
            </button>
        </div>
        <div v-if="erro" class="text-danger fs-7 mt-1">{{ erro }}</div>
    </div>
</template>
