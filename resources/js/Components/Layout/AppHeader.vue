<template>
    <div id="kt_app_header" class="app-header">
        <div
            class="app-container container-fluid d-flex align-items-stretch justify-content-between"
            id="kt_app_header_container"
        >
            <!-- Sidebar mobile toggle -->
            <div
                class="d-flex align-items-center d-lg-none ms-n3 me-1 me-md-2"
                title="Show sidebar menu"
            >
                <div
                    class="btn btn-icon btn-active-color-primary w-35px h-35px"
                    id="kt_app_sidebar_mobile_toggle"
                >
                    <i class="ki-duotone ki-abstract-14 fs-2 fs-md-1">
                        <span class="path1"></span>
                        <span class="path2"></span>
                    </i>
                </div>
            </div>

            <!-- Mobile logo -->
            <div class="d-flex align-items-center flex-grow-1 flex-lg-grow-0">
                <Link href="/" class="d-lg-none">
                    <img
                        alt="Logo"
                        :src="asset('themes/metronic/assets/media/logos/default-small.svg')"
                        class="h-30px"
                    />
                </Link>
            </div>

            <!-- Header wrapper -->
            <div
                class="d-flex align-items-stretch justify-content-between flex-lg-grow-1"
                id="kt_app_header_wrapper"
            >
                <!-- Menu wrapper -->
                <HeaderMenu />

                <!-- Navbar -->
                <HeaderNavbar />
            </div>
        </div>
    </div>
</template>

<script setup>
import { Link } from '@inertiajs/vue3'
import HeaderMenu from './HeaderMenu.vue'
import HeaderNavbar from './HeaderNavbar.vue'

const asset = (path) => `/${path}`
</script>
