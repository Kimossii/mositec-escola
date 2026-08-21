<template>
    <div class="menu-state-bg menu-extended overflow-hidden overflow-lg-visible" data-kt-menu-dismiss="true">
        <div class="row">
            <!-- Main dashboards -->
            <!-- <div class="col-lg-8 mb-3 mb-lg-0 py-3 px-3 py-lg-6 px-lg-6">
                <div class="row">
                    <div v-for="item in mainDashboards" :key="item.href" class="col-lg-6 mb-3">
                        <div class="menu-item p-0 m-0">
                            <a :href="item.href" :class="['menu-link', { active: item.active }]">
                                <span class="menu-custom-icon d-flex flex-center flex-shrink-0 rounded w-40px h-40px me-3">
                                    <i :class="`ki-duotone ${item.icon} ${item.color} fs-1`">
                                        <span v-for="n in item.paths" :key="n" :class="`path${n}`"></span>
                                    </i>
                                </span>
                                <span class="d-flex flex-column">
                                    <span class="fs-6 fw-bold text-gray-800">{{ item.title }}</span>
                                    <span class="fs-7 fw-semibold text-muted">{{ item.subtitle }}</span>
                                </span>
                            </a>
                        </div>
                    </div>
                </div>

            </div> -->

            <!-- More dashboards -->
            <!-- <div class="menu-more bg-light col-lg-4 py-3 px-3 py-lg-6 px-lg-6 rounded-end">
                <h4 class="fs-6 fs-lg-4 text-gray-800 fw-bold mt-3 mb-3 ms-4">More Dashboards</h4>
                <div v-for="item in moreDashboards" :key="item.href" class="menu-item p-0 m-0">
                    <a :href="item.href" class="menu-link py-2">
                        <span class="menu-title">{{ item.title }}</span>
                    </a>
                </div>
            </div> -->
        </div>
    </div>
</template>

<script setup>
// const mainDashboards = [
//     { href: '/', title: 'Default', subtitle: 'Reports & statistics', icon: 'ki-element-11', color: 'text-primary', paths: 4, active: true },
//     // { href: 'dashboards/ecommerce.html', title: 'eCommerce', subtitle: 'Sales reports', icon: 'ki-basket', color: 'text-danger', paths: 4, active: false },
//     // { href: 'dashboards/projects.html', title: 'Projects', subtitle: 'Tasts, graphs & charts', icon: 'ki-abstract-44', color: 'text-info', paths: 2, active: false },
//     // { href: 'dashboards/online-courses.html', title: 'Online Courses', subtitle: 'Student progress', icon: 'ki-color-swatch', color: 'text-success', paths: 21, active: false },
//     // { href: 'dashboards/marketing.html', title: 'Marketing', subtitle: 'Campaings & conversions', icon: 'ki-chart-simple', color: 'text-dark', paths: 4, active: false },
//     // { href: 'dashboards/bidding.html', title: 'Bidding', subtitle: 'Campaings & conversions', icon: 'ki-switch', color: 'text-warning', paths: 2, active: false },
//     // { href: 'dashboards/pos.html', title: 'POS System', subtitle: 'Campaings & conversions', icon: 'ki-abstract-42', color: 'text-danger', paths: 2, active: false },
//     // { href: 'dashboards/call-center.html', title: 'Call Center', subtitle: 'Campaings & conversions', icon: 'ki-call', color: 'text-primary', paths: 8, active: false },
// ]

// const moreDashboards = [
//     { href: 'dashboards/logistics.html', title: 'Logistics' },
//     { href: 'dashboards/website-analytics.html', title: 'Website Analytics' },
//     { href: 'dashboards/finance-performance.html', title: 'Finance Performance' },
//     { href: 'dashboards/store-analytics.html', title: 'Store Analytics' },
//     { href: 'dashboards/social.html', title: 'Social' },
//     { href: 'dashboards/delivery.html', title: 'Delivery' },
//     { href: 'dashboards/crypto.html', title: 'Crypto' },
//     { href: 'dashboards/school.html', title: 'School' },
//     { href: 'dashboards/podcast.html', title: 'Podcast' },
// ]
</script>
