<script setup>
import { computed } from 'vue';
import PainelDeMarca from '../Components/PainelDeMarca.vue';

const props = defineProps({
    // confirmado | ja_confirmado | invalido
    resultado: { type: String, required: true },
});

const estados = {
    confirmado: { icone: 'ki-check-circle', cor: 'success', titulo: 'Email confirmado', texto: 'Obrigado. O seu email de acesso ficou confirmado.' },
    ja_confirmado: { icone: 'ki-check-circle', cor: 'success', titulo: 'Email já confirmado', texto: 'Este email já estava confirmado. Não precisa de fazer mais nada.' },
    invalido: { icone: 'ki-cross-circle', cor: 'danger', titulo: 'Ligação inválida', texto: 'Esta ligação não é válida ou já não é a mais recente. Peça uma nova confirmação nas Definições da sua conta.' },
};

const estado = computed(() => estados[props.resultado] ?? estados.invalido);
</script>

<template>
    <div class="d-flex flex-column flex-lg-row flex-column-fluid" style="min-height: 100vh;">
        <div class="d-flex flex-column flex-lg-row-fluid w-lg-50 p-10 order-2 order-lg-1">
            <div class="d-flex flex-center flex-column flex-lg-row-fluid">
                <div class="w-lg-500px p-10 text-center">
                    <span class="symbol symbol-65px mb-5">
                        <span :class="`symbol-label bg-light-${estado.cor}`">
                            <i :class="`ki-duotone ${estado.icone} fs-2x text-${estado.cor}`"><span class="path1"></span><span class="path2"></span></i>
                        </span>
                    </span>
                    <h1 class="text-dark fw-bolder mb-3">{{ estado.titulo }}</h1>
                    <div class="text-gray-500 fw-semibold fs-6 mb-8">{{ estado.texto }}</div>
                    <a href="/" class="btn btn-primary">Ir para a aplicação</a>
                </div>
            </div>
        </div>

        <PainelDeMarca />
    </div>
</template>
