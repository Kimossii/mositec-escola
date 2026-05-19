<template>
    <div class="menu-active-bg pt-1 pb-3 px-3 py-lg-6 px-lg-6" data-kt-menu-dismiss="true">
        <div class="row">
            <div class="col-lg-6">
                <div class="row">
                    <div class="col-lg-6 mb-3">
                        <h4 class="fs-6 fs-lg-4 text-gray-800 fw-bold mt-3 mb-3 ms-4">Layouts</h4>
                        <div v-for="link in layoutLinks" :key="link.href" class="menu-item p-0 m-0">
                            <a :href="link.href" class="menu-link">
                                <span class="menu-bullet">
                                    <span class="bullet bullet-dot bg-gray-300i h-6px w-6px"></span>
                                </span>
                                <span class="menu-title">{{ link.label }}</span>
                            </a>
                        </div>
                    </div>
                    <div class="col-lg-6 mb-3">
                        <h4 class="fs-6 fs-lg-4 text-gray-800 fw-bold mt-3 mb-3 ms-4">Toolbars</h4>
                        <div v-for="link in toolbarLinks" :key="link.href" class="menu-item p-0 m-0">
                            <a :href="link.href" class="menu-link">
                                <span class="menu-bullet">
                                    <span class="bullet bullet-dot bg-gray-300i h-6px w-6px"></span>
                                </span>
                                <span class="menu-title">{{ link.label }}</span>
                            </a>
                        </div>
                    </div>
                </div>

                <div class="separator separator-dashed mx-lg-5 mt-2 mb-6"></div>

                <div class="d-flex flex-stack flex-wrap flex-lg-nowrap gap-2 mb-5 mb-lg-0 mx-lg-5">
                    <div class="d-flex flex-column me-5">
                        <div class="fs-6 fw-bold text-gray-800">Layout Builder</div>
                        <div class="fs-7 fw-semibold text-muted">Customize, preview and export</div>
                    </div>
                    <a href="layout-builder.html" class="btn btn-sm btn-primary fw-bold">Try Builder</a>
                </div>
            </div>

            <div class="col-lg-6 mb-3 py-lg-3 pe-lg-8 d-flex align-items-center">
                <img src="/themes/metronic/assets/media/stock/900x600/45.jpg" class="rounded mw-100" alt="" />
            </div>
        </div>
    </div>
</template>

<script setup>
const layoutLinks = [
    { href: 'layouts/light-sidebar.html', label: 'Light Sidebar' },
    { href: 'layouts/dark-sidebar.html', label: 'Dark Sidebar' },
    { href: 'layouts/light-header.html', label: 'Light Header' },
    { href: 'layouts/dark-header.html', label: 'Dark Header' },
]

const toolbarLinks = [
    { href: 'toolbars/classic.html', label: 'Classic' },
    { href: 'toolbars/saas.html', label: 'SaaS' },
    { href: 'toolbars/accounting.html', label: 'Accounting' },
    { href: 'toolbars/extended.html', label: 'Extended' },
    { href: 'toolbars/reports.html', label: 'Reports' },
]
</script>
