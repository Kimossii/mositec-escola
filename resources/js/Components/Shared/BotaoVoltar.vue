<script setup>
import { router } from '@inertiajs/vue3';

const props = defineProps({
    href: { type: String, default: null },
});

function voltar() {
    if (props.href) {
        router.visit(props.href);
        return;
    }

    window.history.back();
}
</script>

<template>
    <button type="button" class="btn btn-sm btn-light-primary d-inline-flex align-items-center mb-4" @click="voltar">
        <i class="ki-duotone ki-arrow-left fs-4 me-1">
            <span class="path1"></span>
            <span class="path2"></span>
        </i>
        Voltar
    </button>
</template>
