<template>
    <div>
        <a
            href="#"
            class="btn btn-icon btn-custom btn-icon-muted btn-active-light btn-active-color-primary w-30px h-30px w-md-40px h-md-40px"
            :data-kt-menu-trigger="menuTrigger"
            data-kt-menu-attach="parent"
            data-kt-menu-placement="bottom-end"
        >
            <i class="ki-duotone ki-night-day theme-light-show fs-2 fs-lg-1">
                <span v-for="n in 10" :key="n" :class="`path${n}`"></span>
            </i>
            <i class="ki-duotone ki-moon theme-dark-show fs-2 fs-lg-1">
                <span class="path1"></span>
                <span class="path2"></span>
            </i>
        </a>

        <div
            class="menu menu-sub menu-sub-dropdown menu-column menu-rounded menu-title-gray-700 menu-icon-gray-500 menu-active-bg menu-state-color fw-semibold py-4 fs-base w-150px"
            data-kt-menu="true"
            data-kt-element="theme-mode-menu"
        >
            <div v-for="mode in modes" :key="mode.value" class="menu-item px-3 my-0">
                <a
                    href="#"
                    class="menu-link px-3 py-2"
                    data-kt-element="mode"
                    :data-kt-value="mode.value"
                    @click.prevent="setMode(mode.value)"
                >
                    <span class="menu-icon" data-kt-element="icon">
                        <i :class="`ki-duotone ${mode.icon} fs-2`">
                            <span v-for="n in mode.paths" :key="n" :class="`path${n}`"></span>
                        </i>
                    </span>
                    <span class="menu-title">{{ mode.label }}</span>
                </a>
            </div>
        </div>
    </div>
</template>

<script setup>
import { onMounted } from 'vue'

const menuTrigger = "{default:'click', lg: 'hover'}"

const modes = [
    { value: 'light',  label: 'Light',  icon: 'ki-night-day', paths: 10 },
    { value: 'dark',   label: 'Dark',   icon: 'ki-moon',      paths: 2  },
    { value: 'system', label: 'System', icon: 'ki-screen',    paths: 4  },
]

const setMode = (mode) => {
    localStorage.setItem('kt_theme_mode_value', mode)
    localStorage.setItem('kt_theme_mode_switch', mode)

    const resolved = mode === 'system'
        ? (window.matchMedia('(prefers-color-scheme: dark)').matches ? 'dark' : 'light')
        : mode

    document.documentElement.setAttribute('data-bs-theme', resolved)
    document.documentElement.setAttribute('data-kt-theme', resolved)

    if (window.KTThemeMode) {
        window.KTThemeMode.setMode(mode)
    }
}

onMounted(() => {
    if (window.KTThemeMode) {
        window.KTThemeMode.init()
    }

    const saved = localStorage.getItem('kt_theme_mode_value') || 'system'
    setMode(saved)
})
</script>
