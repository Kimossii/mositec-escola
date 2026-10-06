<script setup>
// Texto configurável (por omissão, o da escola) para o painel da Plataforma reaproveitar o mesmo painel.
defineProps({
    titulo: { type: String, default: 'Gestão Acadêmica MosiTec' },
    subtitulo: { type: String, default: 'Matrículas, turmas, notas e frequência num só lugar.' },
});
</script>

<template>
    <div
        class="d-flex flex-lg-row-fluid w-lg-50 order-1 order-lg-2"
        style="background-color: #0F172A;"
    >
        <div class="d-flex flex-column flex-center py-15 px-10 w-100">
            <img
                alt="MosiTec"
                src="/themes/metronic/assets/media/logos/mosi-logo-completo-branco.png"
                class="h-60px mb-10"
            />
            <h1 class="d-none d-lg-block text-white fs-2qx fw-bolder text-center mb-7">
                {{ titulo }}
            </h1>
            <div class="d-none d-lg-block text-white fs-base text-center opacity-75">
                {{ subtitulo }}
            </div>
        </div>
    </div>
</template>
