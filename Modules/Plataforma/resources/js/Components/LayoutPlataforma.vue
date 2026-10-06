<script setup>
import { ref, watch } from 'vue';
import { router, usePage } from '@inertiajs/vue3';
import { toast } from 'vue-sonner';
import Loader from '@/Components/Shared/Loader.vue';
import { useCsrfPlataforma } from '../Composables/useCsrfPlataforma';

// Topo simples do painel da Plataforma: sem Header nem Sidebar da escola.
useCsrfPlataforma();

const page = usePage();
const aTerminar = ref(false);

// Mensagens de sucesso vêm do backend (flash); sem texto de reserva.
watch(
    () => page.props.flash?.success,
    (mensagem) => {
        if (mensagem) toast.success(mensagem);
    },
    { immediate: true },
);

function terminarSessao() {
    aTerminar.value = true;
    router.post('/plataforma/logout', {}, {
        onFinish: () => {
            aTerminar.value = false;
        },
    });
}
</script>

<template>
    <div class="d-flex flex-column min-vh-100">
        <header
            class="d-flex flex-stack flex-wrap gap-3 px-6 px-lg-10 py-4"
            style="background-color: #0F172A;"
        >
            <div class="d-flex align-items-center gap-4">
                <img
                    alt="MosiTec"
                    src="/themes/metronic/assets/media/logos/mosi-logo-branco.png"
                    class="h-30px"
                />
                <span class="text-white fw-bold fs-5">Plataforma</span>
            </div>

            <div class="d-flex align-items-center gap-5">
                <span v-if="page.props.auth?.superAdmin" class="text-white fs-6 opacity-75">
                    {{ page.props.auth.superAdmin.name }}
                </span>
                <button type="button" class="btn btn-sm btn-light" :disabled="aTerminar" @click="terminarSessao">
                    <span v-if="!aTerminar">Terminar sessão</span>
                    <span v-else>
                        A terminar...
                        <Loader size="0.3px" class="align-middle ms-2" />
                    </span>
                </button>
            </div>
        </header>

        <main class="flex-grow-1 container-xxl py-10">
            <slot />
        </main>
    </div>
</template>
