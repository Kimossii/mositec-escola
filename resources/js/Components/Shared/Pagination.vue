<script setup>
// Espera o formato `links` do paginator do Laravel: [{ url, label, active }],
// já com "Previous"/"Next" incluídos — por isso só há algo a mostrar quando
// há mais do que 1 página (Previous + 1 página + Next = 3 entradas).
import { router } from '@inertiajs/vue3';

const props = defineProps({
    links: { type: Array, required: true },
});

function ir(url) {
    if (!url) return;
    router.get(url, {}, { preserveState: true, preserveScroll: true });
}
</script>

<template>
    <ul v-if="links.length > 3" class="pagination">
        <li
            v-for="(link, indice) in links"
            :key="indice"
            class="page-item"
            :class="{ active: link.active, disabled: !link.url }"
        >
            <a href="#" class="page-link" v-html="link.label" @click.prevent="ir(link.url)"></a>
        </li>
    </ul>
</template>
