<template>
    <div class="app-navbar flex-shrink-0">

        <!-- Search -->
        <div class="app-navbar-item align-items-stretch ms-1 ms-md-3">
            <HeaderSearch />
        </div>

        <!-- Activities -->
        <div class="app-navbar-item ms-1 ms-md-3">
            <div
                class="btn btn-icon btn-custom btn-icon-muted btn-active-light btn-active-color-primary w-30px h-30px w-md-40px h-md-40px"
                id="kt_activities_toggle"
            >
                <i class="ki-duotone ki-notification-on fs-2 fs-lg-1">
                    <span v-for="n in 5" :key="n" :class="`path${n}`"></span>
                </i>
            </div>
        </div>

        <!-- Notifications -->
        <div class="app-navbar-item ms-1 ms-md-3">
            <div
                class="btn btn-icon btn-custom btn-icon-muted btn-active-light btn-active-color-primary w-30px h-30px w-md-40px h-md-40px"
                data-kt-menu-trigger="{default: 'click', lg: 'hover'}"
                data-kt-menu-attach="parent"
                data-kt-menu-placement="bottom-end"
            >
                <i class="ki-duotone ki-notification-status fs-2 fs-lg-1">
                    <span v-for="n in 4" :key="n" :class="`path${n}`"></span>
                </i>
            </div>
            <NotificationsMenu />
        </div>

        <!-- Chat -->
        <div class="app-navbar-item ms-1 ms-md-3">
            <div
                class="btn btn-icon btn-custom btn-icon-muted btn-active-light btn-active-color-primary w-30px h-30px w-md-40px h-md-40px position-relative"
                id="kt_drawer_chat_toggle"
            >
                <i class="ki-duotone ki-message-text-2 fs-2 fs-lg-1">
                    <span v-for="n in 3" :key="n" :class="`path${n}`"></span>
                </i>
                <span class="bullet bullet-dot bg-success h-6px w-6px position-absolute translate-middle top-0 start-50 animation-blink"></span>
            </div>
        </div>

        <!-- My Apps -->
        <div class="app-navbar-item ms-1 ms-md-3">
            <div
                class="btn btn-icon btn-custom btn-icon-muted btn-active-light btn-active-color-primary w-30px h-30px w-md-40px h-md-40px"
                data-kt-menu-trigger="{default: 'click', lg: 'hover'}"
                data-kt-menu-attach="parent"
                data-kt-menu-placement="bottom-end"
            >
                <i class="ki-duotone ki-element-11 fs-2 fs-lg-1">
                    <span v-for="n in 4" :key="n" :class="`path${n}`"></span>
                </i>
            </div>
            <MyAppsMenu />
        </div>

        <!-- Theme Mode -->
        <div class="app-navbar-item ms-1 ms-md-3">
            <ThemeModeMenu />
        </div>

        <!-- User Menu -->
        <div class="app-navbar-item ms-1 ms-md-3" id="kt_header_user_menu_toggle">
            <div
                class="cursor-pointer symbol symbol-30px symbol-md-40px"
                data-kt-menu-trigger="{default: 'click', lg: 'hover'}"
                data-kt-menu-attach="parent"
                data-kt-menu-placement="bottom-end"
            >
                <img src="/themes/metronic/assets/media/avatars/300-1.jpg" alt="user" />
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
import HeaderSearch from './navbar/HeaderSearch.vue'
import NotificationsMenu from './navbar/NotificationsMenu.vue'
import MyAppsMenu from './navbar/MyAppsMenu.vue'
import ThemeModeMenu from './navbar/ThemeModeMenu.vue'
import UserMenu from './navbar/UserMenu.vue'
</script>
