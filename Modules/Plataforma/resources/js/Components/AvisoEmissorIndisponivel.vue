<script setup>
// Servidor sem chave privada de emissão (instalações dos clientes): explica-o em vez de esconder os botões sem mais.
defineProps({
    mensagem: { type: String, required: true },
    motivo: { type: String, default: null }, // só quando há chave mas está mal configurada
});
</script>

<template>
    <div class="alert alert-warning d-flex align-items-start gap-3 mb-6" role="alert" data-testid="emissor-indisponivel">
        <i class="ki-duotone ki-information-5 fs-2x text-warning"><span class="path1" /><span class="path2" /><span class="path3" /></i>
        <div>
            <div class="fw-bold mb-1">Emissão de licenças indisponível</div>
            <div>{{ mensagem }}</div>
            <div v-if="motivo" class="text-muted mt-1" data-testid="emissor-motivo">{{ motivo }}</div>
        </div>
    </div>
</template>
