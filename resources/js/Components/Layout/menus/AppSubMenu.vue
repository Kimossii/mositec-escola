<template>
    <div
        data-kt-menu-trigger="{default:'click', lg: 'hover'}"
        data-kt-menu-placement="right-start"
        class="menu-item menu-lg-down-accordion"
    >
        <span class="menu-link">
            <span class="menu-icon">
                <i :class="`ki-duotone ${icon} fs-2`">
                    <span v-for="n in paths" :key="n" :class="`path${n}`"></span>
                </i>
            </span>
            <span class="menu-title">{{ title }}</span>
            <span class="menu-arrow"></span>
        </span>
        <div class="menu-sub menu-sub-lg-down-accordion menu-sub-lg-dropdown menu-active-bg px-lg-2 py-lg-4 w-lg-225px">
            <div v-for="link in links" :key="link.href" class="menu-item">
                <a class="menu-link" :href="link.href">
                    <span class="menu-bullet"><span class="bullet bullet-dot"></span></span>
                    <span class="menu-title">{{ link.label }}</span>
                    <span v-if="link.badge" class="menu-badge">
                        <span :class="`badge badge-light-success`">{{ link.badge }}</span>
                    </span>
                </a>
            </div>
        </div>
    </div>
</template>

<script setup>
defineProps({
    icon: { type: String, required: true },
    paths: { type: Number, default: 2 },
    title: { type: String, required: true },
    links: { type: Array, required: true },
})
</script>
