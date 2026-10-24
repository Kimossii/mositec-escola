<script setup>
/** Cartão de secção da ficha: ícone suave, título e subtítulo opcional. */
defineProps({
    titulo: { type: String, required: true },
    subtitulo: { type: String, default: '' },
    icone: { type: String, default: 'ki-element-11' },
    paths: { type: Number, default: 4 },
});
</script>

<template>
    <div class="card mb-6">
        <div class="card-header min-h-auto py-5">
            <div class="d-flex align-items-center gap-3">
                <span class="symbol symbol-35px"><span class="symbol-label bg-light-success">
                    <i class="ki-duotone fs-3 text-success" :class="icone"><span v-for="n in paths" :key="n" :class="`path${n}`"></span></i>
                </span></span>
                <div>
                    <h3 class="fs-5 fw-bold m-0">{{ titulo }}</h3>
                    <div v-if="subtitulo" class="text-muted fs-8">{{ subtitulo }}</div>
                </div>
            </div>
        </div>
        <div class="card-body pt-2"><slot /></div>
    </div>
</template>
