<script setup>
import { ref } from 'vue';

const modelValue = defineModel({ default: '' });

defineProps({
    placeholder: { type: String, default: '' },
    name: { type: String, default: 'password' },
    autocomplete: { type: String, default: 'new-password' },
    invalid: { type: Boolean, default: false },
});

const visivel = ref(false);
</script>

<template>
    <div class="position-relative">
        <input
            v-model="modelValue"
            :type="visivel ? 'text' : 'password'"
            :name="name"
            :placeholder="placeholder"
            :autocomplete="autocomplete"
            class="form-control form-control-solid mb-3 mb-lg-0"
            :class="{ 'is-invalid': invalid }"
            style="padding-right: 3rem;"
        />
        <button
            type="button"
            class="btn btn-icon btn-sm position-absolute top-50 translate-middle-y end-0 me-2 text-gray-500"
            tabindex="-1"
            @click="visivel = !visivel"
        >
            <i v-if="visivel" class="ki-duotone ki-eye-slash fs-2">
                <span class="path1"></span>
                <span class="path2"></span>
                <span class="path3"></span>
                <span class="path4"></span>
            </i>
            <i v-else class="ki-duotone ki-eye fs-2">
                <span class="path1"></span>
                <span class="path2"></span>
                <span class="path3"></span>
            </i>
        </button>
    </div>
</template>
