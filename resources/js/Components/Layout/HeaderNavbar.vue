<template>
    <div class="app-navbar flex-shrink-0">

        <!-- Search -->
        <div class="app-navbar-item align-items-stretch ms-1 ms-md-3">
            <HeaderSearch />
        </div>

        <!-- Notifications -->
        <div class="app-navbar-item ms-1 ms-md-3">
            <div
                class="btn btn-icon btn-custom btn-icon-muted btn-active-light btn-active-color-primary w-30px h-30px w-md-40px h-md-40px position-relative"
                data-kt-menu-trigger="{default: 'click', lg: 'hover'}"
                data-kt-menu-attach="parent"
                data-kt-menu-placement="bottom-end"
            >
                <i class="ki-duotone ki-notification-status fs-2 fs-lg-1">
                    <span v-for="n in 4" :key="n" :class="`path${n}`"></span>
                </i>
                <span v-if="alertas.length" class="mosi-pisca badge badge-circle badge-danger position-absolute top-0 start-100 translate-middle fs-9 w-15px h-15px">{{ alertas.length }}</span>
            </div>
            <NotificationsMenu />
        </div>

        <!-- Theme Mode -->
        <div class="app-navbar-item ms-1 ms-md-3">
            <ThemeModeMenu />
        </div>

        <!-- User Menu -->
        <div class="app-navbar-item ms-1 ms-md-3" id="kt_header_user_menu_toggle">
            <div
                class="cursor-pointer"
                data-kt-menu-trigger="{default: 'click', lg: 'hover'}"
                data-kt-menu-attach="parent"
                data-kt-menu-placement="bottom-end"
            >
                <AvatarUtilizador :foto="foto" :iniciais="iniciais" tamanho="symbol-30px symbol-md-40px" circulo />
            </div>
            <UserMenu />
        </div>

        <!-- Header menu toggle (mobile) -->
        <div class="app-navbar-item d-lg-none ms-2 me-n2" title="Show header menu">
            <div class="btn btn-flex btn-icon btn-active-color-primary w-30px h-30px" id="kt_app_header_menu_toggle">
                <i class="ki-duotone ki-element-4 fs-1">
                    <span class="path1"></span>
                    <span class="path2"></span>
                </i>
            </div>
        </div>
    </div>
</template>

<script setup>
import { computed } from 'vue'
import { usePage } from '@inertiajs/vue3'
import HeaderSearch from './navbar/HeaderSearch.vue'
import NotificationsMenu from './navbar/NotificationsMenu.vue'
import ThemeModeMenu from './navbar/ThemeModeMenu.vue'
import UserMenu from './navbar/UserMenu.vue'
import AvatarUtilizador from '@/Components/Shared/AvatarUtilizador.vue'

// Alertas reais (propinas em atraso, acordos, matrículas...) — prop partilhada diferida.
const alertas = computed(() => usePage().props.alertas ?? [])

// Foto do utilizador autenticado; sem foto, as iniciais (como no menu e nas Definições).
const foto = computed(() => usePage().props.auth.foto)
const iniciais = computed(() => (usePage().props.auth.user?.name ?? '').split(/\s+/).filter(Boolean).slice(0, 2).map((p) => p[0].toUpperCase()).join(''))
</script>
