<template>
    <div class="app-header-menu app-header-mobile-drawer align-items-stretch" data-kt-drawer="true"
        data-kt-drawer-name="app-header-menu" data-kt-drawer-activate="{default: true, lg: false}"
        data-kt-drawer-overlay="true" data-kt-drawer-width="250px" data-kt-drawer-direction="end"
        data-kt-drawer-toggle="#kt_app_header_menu_toggle" data-kt-swapper="true"
        data-kt-swapper-mode="{default: 'append', lg: 'prepend'}"
        data-kt-swapper-parent="{default: '#kt_app_body', lg: '#kt_app_header_wrapper'}">
        <div class="menu menu-rounded menu-column menu-lg-row my-5 my-lg-0 align-items-stretch fw-semibold px-2 px-lg-0"
            id="kt_app_header_menu" data-kt-menu="true">

            <!-- Início -->
            <div :class="['menu-item', 'me-0', 'me-lg-2', { here: isActive('/', { exact: true }), show: isActive('/', { exact: true }), 'menu-here-bg': isActive('/', { exact: true }) }]">
                <a href="/" :class="['menu-link', { active: isActive('/', { exact: true }) }]">

                    <span class="menu-icon">
                        <i class="ki-duotone ki-home-2 fs-2">
                            <span class="path1"></span>
                            <span class="path2"></span>
                        </i>
                    </span>

                    <span class="menu-title">
                        Início
                    </span>

                </a>
            </div>


            <!-- Académico -->
            <div v-if="academicoSeccoesVisiveis.length" data-kt-menu-trigger="{default: 'click', lg: 'hover'}" data-kt-menu-placement="bottom-start"
                :class="['menu-item', 'menu-lg-down-accordion', 'me-0', 'me-lg-2', { here: academicoActivo, show: academicoActivo }]">
                <span class="menu-link">
                    <span class="menu-icon">
                        <i class="ki-duotone ki-profile-user fs-2">
                            <span class="path1"></span>
                            <span class="path2"></span>
                            <span class="path3"></span>
                        </i>
                    </span>

                    <span class="menu-title">Académico</span>

                    <span class="menu-arrow d-lg-none"></span>
                </span>

                <div class="menu-sub menu-sub-lg-down-accordion menu-sub-lg-dropdown p-0">
                    <AcademicoMenu />
                </div>
            </div>

            <!-- Pedagógico -->
            <div v-if="pedagogicoSeccoesVisiveis.length" data-kt-menu-trigger="{default: 'click', lg: 'hover'}" data-kt-menu-placement="bottom-start"
                :class="['menu-item', 'menu-lg-down-accordion', 'me-0', 'me-lg-2', { here: pedagogicoActivo, show: pedagogicoActivo }]">
                <span class="menu-link">
                    <span class="menu-icon">
                        <i class="ki-duotone ki-book-open fs-2">
                            <span class="path1"></span>
                            <span class="path2"></span>
                            <span class="path3"></span>
                            <span class="path4"></span>
                        </i>
                    </span>

                    <span class="menu-title">Pedagógico</span>

                    <span class="menu-arrow d-lg-none"></span>
                </span>

                <div class="menu-sub menu-sub-lg-down-accordion menu-sub-lg-dropdown p-0">
                    <PagesMenu />
                </div>
            </div>

            <!-- Configurações -->
            <div v-if="configuracoesGruposVisiveis.length" data-kt-menu-trigger="{default: 'click', lg: 'hover'}" data-kt-menu-placement="bottom-start"
                :class="['menu-item', 'menu-lg-down-accordion', 'menu-sub-lg-down-indention', 'me-0', 'me-lg-2', { here: configuracoesActivo, show: configuracoesActivo }]">
                <span class="menu-link">
                    <span class="menu-icon">
                        <i class="ki-duotone ki-setting-2 fs-2">
                            <span class="path1"></span>
                            <span class="path2"></span>
                        </i>
                    </span>

                    <span class="menu-title">Configurações</span>

                    <span class="menu-arrow d-lg-none"></span>
                </span>

                <div class="menu-sub menu-sub-lg-down-accordion menu-sub-lg-dropdown px-lg-2 py-lg-4 w-lg-250px">
                    <ConfiguracoesMenu />
                </div>
            </div>

            <!-- Layouts -->
            <!-- <div
                data-kt-menu-trigger="{default: 'click', lg: 'hover'}"
                data-kt-menu-placement="bottom-start"
                class="menu-item menu-lg-down-accordion me-0 me-lg-2"
            >
                <span class="menu-link">
                    <span class="menu-title">Layouts</span>
                    <span class="menu-arrow d-lg-none"></span>
                </span>
                <div class="menu-sub menu-sub-lg-down-accordion menu-sub-lg-dropdown p-0 w-100 w-lg-850px">
                    <LayoutsMenu />
                </div>
            </div> -->

            <!-- Help -->
            <!-- <div
                data-kt-menu-trigger="{default: 'click', lg: 'hover'}"
                data-kt-menu-placement="bottom-start"
                class="menu-item menu-lg-down-accordion menu-sub-lg-down-indention me-0 me-lg-2"
            >
                <span class="menu-link">
                    <span class="menu-title">Help</span>
                    <span class="menu-arrow d-lg-none"></span>
                </span>
                <div class="menu-sub menu-sub-lg-down-accordion menu-sub-lg-dropdown px-lg-2 py-lg-4 w-lg-200px">
                    <div class="menu-item">
                        <a
                            class="menu-link"
                            href="https://preview.keenthemes.com/html/metronic/docs/base/utilities"
                            target="_blank"
                        >
                            <span class="menu-icon">
                                <i class="ki-duotone ki-rocket fs-2">
                                    <span class="path1"></span>
                                    <span class="path2"></span>
                                </i>
                            </span>
                            <span class="menu-title">Components</span>
                        </a>
                    </div>
                    <div class="menu-item">
                        <a
                            class="menu-link"
                            href="https://preview.keenthemes.com/html/metronic/docs"
                            target="_blank"
                        >
                            <span class="menu-icon">
                                <i class="ki-duotone ki-abstract-26 fs-2">
                                    <span class="path1"></span>
                                    <span class="path2"></span>
                                </i>
                            </span>
                            <span class="menu-title">Documentation</span>
                        </a>
                    </div>
                    <div class="menu-item">
                        <a
                            class="menu-link"
                            href="https://preview.keenthemes.com/html/metronic/docs/getting-started/changelog"
                            target="_blank"
                        >
                            <span class="menu-icon">
                                <i class="ki-duotone ki-code fs-2">
                                    <span class="path1"></span>
                                    <span class="path2"></span>
                                    <span class="path3"></span>
                                    <span class="path4"></span>
                                </i>
                            </span>
                            <span class="menu-title">Changelog v8.1.8</span>
                        </a>
                    </div>
                </div>
            </div> -->
        </div>
    </div>
</template>

<script setup>
import { computed } from 'vue'
import AcademicoMenu from './menus/AcademicoMenu.vue'
import PagesMenu from './menus/PedagogicoMenu.vue'
import ConfiguracoesMenu from './menus/ConfiguracoesMenu.vue'
// import LayoutsMenu from './menus/LayoutsMenu.vue'
import { useAcademicoMenu } from '@/Composables/useAcademicoMenu'
import { usePedagogicoMenu } from '@/Composables/usePedagogicoMenu'
import { useConfiguracoesMenu } from '@/Composables/useConfiguracoesMenu'
import { useActiveMenu } from '@/composables/useActiveMenu'

const { seccoesVisiveis: academicoSeccoesVisiveis } = useAcademicoMenu()
const { seccoesVisiveis: pedagogicoSeccoesVisiveis } = usePedagogicoMenu()
const { gruposVisiveis: configuracoesGruposVisiveis } = useConfiguracoesMenu()
const { isActive, isGroupActive } = useActiveMenu()

// Cada composable devolve secções/grupos ({ links: [...] }) — achatamos para
// a lista plana de links que isGroupActive espera, para o item de topo do
// Header ficar "aqui" sempre que a rota actual bater com algum link lá dentro.
const academicoActivo = computed(() => isGroupActive(academicoSeccoesVisiveis.value.flatMap((s) => s.links)))
const pedagogicoActivo = computed(() => isGroupActive(pedagogicoSeccoesVisiveis.value.flatMap((s) => s.links)))
const configuracoesActivo = computed(() => isGroupActive(configuracoesGruposVisiveis.value.flatMap((g) => g.links)))
</script>
