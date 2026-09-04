<template>
    <div>
        <div
            v-for="group in gruposVisiveis"
            :key="group.title"
            data-kt-menu-trigger="click"
            :class="['menu-item', 'menu-accordion', 'menu-sub-indention', { here: isGroupActive(group.links), show: isGroupActive(group.links) }]"
        >
            <span class="menu-link">
                <span class="menu-bullet"><span class="bullet bullet-dot"></span></span>
                <span class="menu-title">{{ group.title }}</span>
                <span class="menu-arrow"></span>
            </span>
            <div class="menu-sub menu-sub-accordion">
                <div v-for="link in group.links" :key="link.href" class="menu-item">
                    <a :class="['menu-link', { active: isActive(link.href, { exact: link.exact, prefix: link.activePrefix }) }]" :href="link.href">
                        <span class="menu-bullet"><span class="bullet bullet-dot"></span></span>
                        <span class="menu-title">{{ link.label }}</span>
                    </a>
                </div>
            </div>
        </div>
    </div>
</template>

<script setup>
import { useConfiguracoesMenu } from '@/Composables/useConfiguracoesMenu'
import { useActiveMenu } from '@/composables/useActiveMenu'

const { gruposVisiveis } = useConfiguracoesMenu()
const { isActive, isGroupActive } = useActiveMenu()
</script>
