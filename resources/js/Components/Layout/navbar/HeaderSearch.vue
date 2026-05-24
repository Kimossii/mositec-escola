<template>
    <div
        id="kt_header_search"
        class="header-search d-flex align-items-stretch"
        data-kt-search-keypress="true"
        data-kt-search-min-length="2"
        data-kt-search-enter="enter"
        data-kt-search-layout="menu"
        data-kt-menu-trigger="auto"
        data-kt-menu-overflow="false"
        data-kt-menu-permanent="true"
        data-kt-menu-placement="bottom-end"
    >
        <!-- Toggle -->
        <div class="d-flex align-items-center" data-kt-search-element="toggle" id="kt_header_search_toggle">
            <div class="btn btn-icon btn-custom btn-icon-muted btn-active-light btn-active-color-primary w-30px h-30px w-md-40px h-md-40px">
                <i class="ki-duotone ki-magnifier fs-2 fs-lg-1">
                    <span class="path1"></span>
                    <span class="path2"></span>
                </i>
            </div>
        </div>

        <!-- Dropdown -->
        <div data-kt-search-element="content" class="menu menu-sub menu-sub-dropdown p-7 w-325px w-md-375px">
            <div data-kt-search-element="wrapper">

                <!-- Form -->
                <form data-kt-search-element="form" class="w-100 position-relative mb-3" autocomplete="off">
                    <i class="ki-duotone ki-magnifier fs-2 text-gray-500 position-absolute top-50 translate-middle-y ms-0">
                        <span class="path1"></span><span class="path2"></span>
                    </i>
                    <input
                        type="text"
                        class="search-input form-control form-control-flush ps-10"
                        name="search"
                        placeholder="Search..."
                        data-kt-search-element="input"
                        v-model="searchQuery"
                    />
                    <span class="search-spinner position-absolute top-50 end-0 translate-middle-y lh-0 d-none me-1" data-kt-search-element="spinner">
                        <span class="spinner-border h-15px w-15px align-middle text-gray-400"></span>
                    </span>
                    <span class="search-reset btn btn-flush btn-active-color-primary position-absolute top-50 end-0 translate-middle-y lh-0 d-none" data-kt-search-element="clear">
                        <i class="ki-duotone ki-cross fs-2 fs-lg-1 me-0">
                            <span class="path1"></span><span class="path2"></span>
                        </i>
                    </span>
                    <div class="position-absolute top-50 end-0 translate-middle-y" data-kt-search-element="toolbar">
                        <div data-kt-search-element="preferences-show" class="btn btn-icon w-20px btn-sm btn-active-color-primary me-1">
                            <i class="ki-duotone ki-setting-2 fs-2"><span class="path1"></span><span class="path2"></span></i>
                        </div>
                        <div data-kt-search-element="advanced-options-form-show" class="btn btn-icon w-20px btn-sm btn-active-color-primary">
                            <i class="ki-duotone ki-down fs-2"></i>
                        </div>
                    </div>
                </form>

                <div class="separator border-gray-200 mb-6"></div>

                <!-- Results -->
                <div data-kt-search-element="results" class="d-none">
                    <div class="scroll-y mh-200px mh-lg-350px">
                        <h3 class="fs-5 text-muted m-0 pb-5" data-kt-search-element="category-title">Users</h3>
                        <a v-for="user in recentUsers" :key="user.name" href="#" class="d-flex text-dark text-hover-primary align-items-center mb-5">
                            <div class="symbol symbol-40px me-4">
                                <img :src="user.avatar" alt="" />
                            </div>
                            <div class="d-flex flex-column justify-content-start fw-semibold">
                                <span class="fs-6 fw-semibold">{{ user.name }}</span>
                                <span class="fs-7 fw-semibold text-muted">{{ user.role }}</span>
                            </div>
                        </a>
                    </div>
                </div>

                <!-- Recently searched -->
                <div class="mb-5" data-kt-search-element="main">
                    <div class="d-flex flex-stack fw-semibold mb-4">
                        <span class="text-muted fs-6 me-2">Recently Searched:</span>
                    </div>
                    <div class="scroll-y mh-200px mh-lg-325px">
                        <div v-for="item in recentSearches" :key="item.label" class="d-flex align-items-center mb-5">
                            <div class="symbol symbol-40px me-4">
                                <span class="symbol-label bg-light">
                                    <i :class="`ki-duotone ${item.icon} fs-2 text-primary`">
                                        <span v-for="n in item.paths" :key="n" :class="`path${n}`"></span>
                                    </i>
                                </span>
                            </div>
                            <div class="d-flex flex-column">
                                <a href="#" class="fs-6 text-gray-800 text-hover-primary fw-semibold">{{ item.label }}</a>
                                <span class="fs-7 text-muted fw-semibold">{{ item.id }}</span>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Empty state -->
                <div data-kt-search-element="empty" class="text-center d-none">
                    <div class="pt-10 pb-10">
                        <i class="ki-duotone ki-search-list fs-4x opacity-50">
                            <span class="path1"></span><span class="path2"></span><span class="path3"></span>
                        </i>
                    </div>
                    <div class="pb-15 fw-semibold">
                        <h3 class="text-gray-600 fs-5 mb-2">No result found</h3>
                        <div class="text-muted fs-7">Please try again with a different query</div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</template>

<script setup>
import { ref } from 'vue'

const searchQuery = ref('')

const recentUsers = [
    { name: 'Karina Clark', role: 'Marketing Manager', avatar: '/themes/metronic/assets/media/avatars/300-6.jpg' },
    { name: 'Olivia Bold', role: 'Software Engineer', avatar: '/themes/metronic/assets/media/avatars/300-2.jpg' },
    { name: 'Ana Clark', role: 'UI/UX Designer', avatar: '/themes/metronic/assets/media/avatars/300-9.jpg' },
    { name: 'Nick Pitola', role: 'Art Director', avatar: '/themes/metronic/assets/media/avatars/300-14.jpg' },
    { name: 'Edward Kulnic', role: 'System Administrator', avatar: '/themes/metronic/assets/media/avatars/300-11.jpg' },
]

const recentSearches = [
    { label: 'BoomApp by Keenthemes', id: '#45789', icon: 'ki-laptop', paths: 2 },
    { label: 'Kept API Project Meeting', id: '#84050', icon: 'ki-chart-simple', paths: 4 },
    { label: 'KPI Monitoring App Launch', id: '#84250', icon: 'ki-chart', paths: 2 },
    { label: 'Project Reference FAQ', id: '#67945', icon: 'ki-chart-line-down', paths: 2 },
    { label: 'FitPro App Development', id: '#84250', icon: 'ki-sms', paths: 2 },
    { label: 'Shopix Mobile App', id: '#45690', icon: 'ki-bank', paths: 2 },
    { label: '"Landing UI Design" Launch', id: '#24005', icon: 'ki-chart-line-down', paths: 2 },
]
</script>
