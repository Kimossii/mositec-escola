<template>
    <div v-if="itemsVisiveis.length" data-kt-menu-trigger="click" :class="['menu-item', 'menu-accordion', { here: groupActive, show: groupActive }]">
        <span class="menu-link">
            <span class="menu-icon">
                <i :class="`ki-duotone ${item.icon} fs-2`">
                    <span v-for="n in item.paths" :key="n" :class="`path${n}`"></span>
                </i>
            </span>
            <span class="menu-title">{{ item.title }}</span>
            <span class="menu-arrow"></span>
        </span>
        <div class="menu-sub menu-sub-accordion">
            <div v-for="link in itemsVisiveis" :key="link.href" class="menu-item">
                <a :class="['menu-link', { active: isActive(link.href) }]" :href="link.href">
                    <span class="menu-bullet"><span class="bullet bullet-dot"></span></span>
                    <span class="menu-title">{{ link.title }}</span>
                </a>
            </div>
        </div>
    </div>
</template>

<script setup>
import { computed } from 'vue'
import { useActiveMenu } from '@/composables/useActiveMenu'
import { podeVer } from '@/Composables/usePermissoes'

const props = defineProps({
    item: {
        type: Object,
        required: true,
        // { title, icon, paths, items: [{ href, title, permissao? }] }
    },
})

const { isActive, isGroupActive } = useActiveMenu()
// Links sem `permissao` ficam sempre visíveis (placeholders "#"); um link
// com `permissao` só aparece se can() a conceder — mesmo filtro usado em
// todos os outros menus, nunca um segundo sistema de autorização.
const itemsVisiveis = computed(() => props.item.items.filter(podeVer))
const groupActive = computed(() => isGroupActive(itemsVisiveis.value))
</script>
