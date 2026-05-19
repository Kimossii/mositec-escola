<template>
    <!-- Teleport coloca o AppHeader no lugar exacto do blade -->
    <Teleport to="#app-header">
        <AppHeader />
    </Teleport>

    <!-- Slot recebe o conteúdo da página -->
    <slot />
</template>

<script setup>
import { onMounted } from 'vue'
import AppHeader from '@/Components/Layout/AppHeader.vue'

onMounted(() => {
    if (window.KTMenu)    window.KTMenu.init()
    if (window.KTDrawer)  window.KTDrawer.init()
    if (window.KTToggle)  window.KTToggle.init()
    if (window.KTScroll)  window.KTScroll.init()
})
</script>
