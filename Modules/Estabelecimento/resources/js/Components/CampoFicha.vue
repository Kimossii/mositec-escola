<script setup>
import { computed } from 'vue';

const props = defineProps({
    label: { type: String, required: true },
    modelValue: { type: [String, Number], default: '' },
    editing: { type: Boolean, default: false },
    type: { type: String, default: 'text' }, // text | email | number | textarea | select
    options: { type: Array, default: () => [] }, // [{ value, label }]
    error: { type: String, default: null },
    placeholder: { type: String, default: '' },
    required: { type: Boolean, default: false },
    icon: { type: String, default: null }, // ex: 'ki-sms'
    iconPaths: { type: Number, default: 2 },
});

defineEmits(['update:modelValue']);

const valorExibido = computed(() => {
    if (props.type === 'select') {
        return props.options.find((opcao) => opcao.value === props.modelValue)?.label ?? '';
    }
    return props.modelValue;
});

const temCampoComIcone = computed(() => props.icon && props.type !== 'textarea');
</script>

<template>
    <div class="ficha-campo">
        <span class="ficha-rotulo" :class="{ required: editing && required }">
            <i v-if="icon" class="ki-duotone ficha-rotulo__icone" :class="icon">
                <span v-for="n in iconPaths" :key="n" :class="`path${n}`"></span>
            </i>
            {{ label }}
        </span>

        <template v-if="editing">
            <textarea
                v-if="type === 'textarea'"
                class="form-control form-control-solid ficha-input"
                rows="3"
                :placeholder="placeholder"
                :value="modelValue"
                @input="$emit('update:modelValue', $event.target.value)"
            ></textarea>

            <div v-else class="ficha-input-wrap" :class="{ 'ficha-input-wrap--icone': temCampoComIcone }">
                <i v-if="temCampoComIcone" class="ki-duotone ficha-input-wrap__icone" :class="icon">
                    <span v-for="n in iconPaths" :key="n" :class="`path${n}`"></span>
                </i>

                <select
                    v-if="type === 'select'"
                    class="form-select form-select-solid ficha-input"
                    :value="modelValue"
                    @change="$emit('update:modelValue', Number($event.target.value))"
                >
                    <option v-for="opcao in options" :key="opcao.value" :value="opcao.value">{{ opcao.label }}</option>
                </select>

                <input
                    v-else
                    :type="type"
                    class="form-control form-control-solid ficha-input"
                    :placeholder="placeholder"
                    :value="modelValue"
                    @input="$emit('update:modelValue', $event.target.value)"
                />
            </div>

            <span class="text-danger fs-8 mt-1" v-if="error">{{ error }}</span>
        </template>

        <span v-else class="ficha-valor" :class="{ 'ficha-valor--vazio': !valorExibido && valorExibido !== 0 }">
            {{ (valorExibido || valorExibido === 0) ? valorExibido : 'Não definido' }}
        </span>
    </div>
</template>

<style scoped>
.ficha-campo {
    display: flex;
    flex-direction: column;
    gap: 0.4rem;
    padding-block: 0.65rem;
    border-bottom: 1px solid var(--bs-gray-200);
}

.ficha-rotulo {
    display: inline-flex;
    align-items: center;
    gap: 0.4rem;
    font-size: 0.75rem;
    font-weight: 700;
    letter-spacing: 0.04em;
    text-transform: uppercase;
    color: var(--bs-gray-900);
}

.ficha-rotulo__icone {
    font-size: 0.9rem;
    color: var(--bs-gray-700);
}

.ficha-valor {
    font-size: 0.975rem;
    font-weight: 500;
    color: var(--bs-gray-900);
    word-break: break-word;
}

.ficha-valor--vazio {
    color: var(--bs-gray-500);
    font-weight: 400;
    font-style: italic;
}

.ficha-input {
    max-width: 420px;
}

.ficha-input-wrap {
    position: relative;
    max-width: 420px;
}

.ficha-input-wrap--icone .ficha-input {
    padding-left: 2.75rem;
}

.ficha-input-wrap__icone {
    position: absolute;
    top: 50%;
    left: 0.9rem;
    transform: translateY(-50%);
    font-size: 1.1rem;
    color: var(--bs-gray-700);
    pointer-events: none;
    transition: color 0.15s ease;
}

.ficha-input-wrap:focus-within .ficha-input-wrap__icone {
    color: var(--bs-success);
}

.ficha-input-wrap:focus-within .ficha-input {
    box-shadow: 0 0 0 3px rgba(80, 205, 137, 0.18);
}
</style>
