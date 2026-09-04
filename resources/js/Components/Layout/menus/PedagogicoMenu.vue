<template>
    <div class="menu-active-bg px-4 px-lg-0">
        <div class="tab-content py-4 py-lg-8 px-lg-7">
            <div class="tab-pane active" id="kt_app_header_menu_pages_pages">
                <div class="d-flex">
                    <div v-for="section in seccoesVisiveis" :key="section.title" class="me-10">
                        <h4 class="fs-6 fs-lg-4 fw-bold mb-3">
                            {{ section.title }}
                        </h4>

                        <div v-for="link in section.links" :key="link.href" class="menu-item p-0 m-0">
                            <a :href="link.href" :class="['menu-link', { active: isActive(link.href) }]">
                                <span class="menu-title">
                                    {{ link.label }}
                                </span>
                            </a>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</template>

<script setup>
import { usePedagogicoMenu } from '@/Composables/usePedagogicoMenu'
import { useActiveMenu } from '@/composables/useActiveMenu'

const { seccoesVisiveis } = usePedagogicoMenu()
const { isActive } = useActiveMenu()
</script>

