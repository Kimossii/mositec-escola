<script setup>
import { computed } from 'vue'
import { Link, usePage } from '@inertiajs/vue3'

// Mesma fonte do "Precisa de atenção" do Dashboard (prop partilhada `alertas`, diferida e por permissões).
const alertas = computed(() => usePage().props.alertas)
const carregado = computed(() => Array.isArray(alertas.value))
</script>

<template>
    <div class="menu menu-sub menu-sub-dropdown menu-column w-350px w-lg-375px" data-kt-menu="true" id="kt_menu_notifications">
        <div
            class="d-flex flex-column bgi-no-repeat rounded-top"
            style="background-image: url('/themes/metronic/assets/media/misc/menu-header-bg.jpg')"
        >
            <h3 class="text-white fw-semibold px-9 mt-10 mb-6">
                Notificações
                <span v-if="carregado && alertas.length" class="fs-8 opacity-75 ps-3">{{ alertas.length }} a precisar de atenção</span>
            </h3>
        </div>

        <div class="px-6 py-3 scroll-y mh-350px">
            <div v-if="!carregado" class="placeholder-glow py-3">
                <span v-for="n in 3" :key="n" class="placeholder col-12 rounded mb-3 d-block"></span>
            </div>
            <div v-else-if="!alertas.length" class="text-muted text-center py-10">Tudo em dia. Nada a assinalar.</div>
            <template v-else>
            <Link
                v-for="item in alertas"
                :key="item.rotulo"
                :href="item.link"
                class="d-flex align-items-center justify-content-between py-4 border-bottom border-gray-200 text-gray-800 text-hover-primary"
            >
                <span class="d-flex align-items-center">
                    <span class="bullet bullet-dot me-3" :class="`bg-${item.cor}`"></span>
                    <span class="fw-semibold fs-6">{{ item.rotulo }}</span>
                </span>
                <span class="badge badge-light-primary fs-7">{{ item.quantidade }}</span>
            </Link>
            </template>
        </div>
    </div>
</template>
