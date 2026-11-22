<script setup>
import { computed } from 'vue';
import { Link, usePage } from '@inertiajs/vue3';

// Faixa de aviso para o administrador. A prop partilhada `licenca` só existe para ele e só em modo de aviso.
const page = usePage();
const licenca = computed(() => page.props.licenca ?? null);

const texto = computed(() => {
    const l = licenca.value;
    if (!l) return '';
    const bloqueio = new Date(l.bloqueia_em).toLocaleDateString('pt-PT');
    const dias = l.dias_restantes;
    return `A licença está a terminar: o acesso será bloqueado em ${bloqueio} (${dias === 1 ? 'falta 1 dia' : `faltam ${dias} dias`}).`;
});
</script>

<template>
    <div v-if="licenca" class="app-container container-xxl pt-4">
        <div class="alert alert-warning d-flex flex-wrap align-items-center gap-3 mb-0" role="alert">
            <i class="ki-duotone ki-information-5 fs-2"><span class="path1"></span><span class="path2"></span><span class="path3"></span></i>
            <div class="flex-grow-1">{{ texto }}</div>
            <Link href="/licenca" class="btn btn-sm btn-warning">Renovar licença</Link>
        </div>
    </div>
</template>
