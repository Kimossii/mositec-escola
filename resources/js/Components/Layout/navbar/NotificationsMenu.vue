<template>
    <div class="menu menu-sub menu-sub-dropdown menu-column w-350px w-lg-375px" data-kt-menu="true" id="kt_menu_notifications">
        <!-- Header -->
        <div
            class="d-flex flex-column bgi-no-repeat rounded-top"
            style="background-image: url('/themes/metronic/assets/media/misc/menu-header-bg.jpg')"
        >
            <h3 class="text-white fw-semibold px-9 mt-10 mb-6">
                Notifications <span class="fs-8 opacity-75 ps-3">24 reports</span>
            </h3>
            <ul class="nav nav-line-tabs nav-line-tabs-2x nav-stretch fw-semibold px-9">
                <li class="nav-item">
                    <a class="nav-link text-white opacity-75 opacity-state-100 pb-4" data-bs-toggle="tab" href="#kt_topbar_notifications_1">Alerts</a>
                </li>
                <li class="nav-item">
                    <a class="nav-link text-white opacity-75 opacity-state-100 pb-4 active" data-bs-toggle="tab" href="#kt_topbar_notifications_2">Updates</a>
                </li>
                <li class="nav-item">
                    <a class="nav-link text-white opacity-75 opacity-state-100 pb-4" data-bs-toggle="tab" href="#kt_topbar_notifications_3">Logs</a>
                </li>
            </ul>
        </div>

        <!-- Tab content -->
        <div class="tab-content">
            <!-- Alerts -->
            <div class="tab-pane fade" id="kt_topbar_notifications_1" role="tabpanel">
                <div class="scroll-y mh-325px my-5 px-8">
                    <div v-for="alert in alerts" :key="alert.title" class="d-flex flex-stack py-4">
                        <div class="d-flex align-items-center">
                            <div class="symbol symbol-35px me-4">
                                <span :class="`symbol-label bg-light-${alert.color}`">
                                    <i :class="`ki-duotone ${alert.icon} fs-2 text-${alert.color}`">
                                        <span v-for="n in alert.paths" :key="n" :class="`path${n}`"></span>
                                    </i>
                                </span>
                            </div>
                            <div class="mb-0 me-2">
                                <a href="#" class="fs-6 text-gray-800 text-hover-primary fw-bold">{{ alert.title }}</a>
                                <div class="text-gray-400 fs-7">{{ alert.subtitle }}</div>
                            </div>
                        </div>
                        <span class="badge badge-light fs-8">{{ alert.time }}</span>
                    </div>
                </div>
                <div class="py-3 text-center border-top">
                    <a href="pages/user-profile/activity.html" class="btn btn-color-gray-600 btn-active-color-primary">
                        View All <i class="ki-duotone ki-arrow-right fs-5"><span class="path1"></span><span class="path2"></span></i>
                    </a>
                </div>
            </div>

            <!-- Updates -->
            <div class="tab-pane fade show active" id="kt_topbar_notifications_2" role="tabpanel">
                <div class="d-flex flex-column px-9">
                    <div class="pt-10 pb-0">
                        <h3 class="text-dark text-center fw-bold">Get Pro Access</h3>
                        <div class="text-center text-gray-600 fw-semibold pt-1">
                            Outlines keep you honest. They stoping you from amazing poorly about drive
                        </div>
                        <div class="text-center mt-5 mb-9">
                            <a href="#" class="btn btn-sm btn-primary px-6" data-bs-toggle="modal" data-bs-target="#kt_modal_upgrade_plan">Upgrade</a>
                        </div>
                    </div>
                    <div class="text-center px-4">
                        <img class="mw-100 mh-200px" alt="image" src="/themes/metronic/assets/media/illustrations/sketchy-1/1.png" />
                    </div>
                </div>
            </div>

            <!-- Logs -->
            <div class="tab-pane fade" id="kt_topbar_notifications_3" role="tabpanel">
                <div class="scroll-y mh-325px my-5 px-8">
                    <div v-for="log in logs" :key="log.label" class="d-flex flex-stack py-4">
                        <div class="d-flex align-items-center me-2">
                            <span :class="`w-70px badge badge-light-${log.color} me-4`">{{ log.code }}</span>
                            <a href="#" class="text-gray-800 text-hover-primary fw-semibold">{{ log.label }}</a>
                        </div>
                        <span class="badge badge-light fs-8">{{ log.time }}</span>
                    </div>
                </div>
                <div class="py-3 text-center border-top">
                    <a href="pages/user-profile/activity.html" class="btn btn-color-gray-600 btn-active-color-primary">
                        View All <i class="ki-duotone ki-arrow-right fs-5"><span class="path1"></span><span class="path2"></span></i>
                    </a>
                </div>
            </div>
        </div>
    </div>
</template>

<script setup>
const alerts = [
    { title: 'Project Alice', subtitle: 'Phase 1 development', icon: 'ki-abstract-28', color: 'primary', paths: 2, time: '1 hr' },
    { title: 'HR Confidential', subtitle: 'Confidential staff documents', icon: 'ki-information', color: 'danger', paths: 3, time: '2 hrs' },
    { title: 'Company HR', subtitle: 'Corporeate staff profiles', icon: 'ki-briefcase', color: 'warning', paths: 2, time: '5 hrs' },
    { title: 'Project Redux', subtitle: 'New frontend admin theme', icon: 'ki-abstract-12', color: 'success', paths: 2, time: '2 days' },
    { title: 'Project Breafing', subtitle: 'Product launch status update', icon: 'ki-colors-square', color: 'primary', paths: 4, time: '21 Jan' },
    { title: 'Banner Assets', subtitle: 'Collection of banner images', icon: 'ki-picture', color: 'info', paths: 0, time: '21 Jan' },
    { title: 'Icon Assets', subtitle: 'Collection of SVG icons', icon: 'ki-color-swatch', color: 'warning', paths: 21, time: '20 March' },
]

const logs = [
    { code: '200 OK', color: 'success', label: 'New order', time: 'Just now' },
    { code: '500 ERR', color: 'danger', label: 'New customer', time: '2 hrs' },
    { code: '200 OK', color: 'success', label: 'Payment process', time: '5 hrs' },
    { code: '300 WRN', color: 'warning', label: 'Search query', time: '2 days' },
    { code: '200 OK', color: 'success', label: 'API connection', time: '1 week' },
    { code: '200 OK', color: 'success', label: 'Database restore', time: 'Mar 5' },
    { code: '300 WRN', color: 'warning', label: 'System update', time: 'May 15' },
    { code: '300 WRN', color: 'warning', label: 'Server OS update', time: 'Apr 3' },
    { code: '300 WRN', color: 'warning', label: 'API rollback', time: 'Jun 30' },
    { code: '500 ERR', color: 'danger', label: 'Refund process', time: 'Jul 10' },
    { code: '500 ERR', color: 'danger', label: 'Withdrawal process', time: 'Sep 10' },
    { code: '500 ERR', color: 'danger', label: 'Mail tasks', time: 'Dec 10' },
]
</script>
