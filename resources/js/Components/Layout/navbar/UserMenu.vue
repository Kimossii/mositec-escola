<template>
    <div
        class="menu menu-sub menu-sub-dropdown menu-column menu-rounded menu-gray-800 menu-state-bg menu-state-color fw-semibold py-4 fs-6 w-275px"
        data-kt-menu="true"
    >
        <!-- User info -->
        <div class="menu-item px-3">
            <div class="menu-content d-flex align-items-center px-3">
                <div class="symbol symbol-50px me-5">
                    <img alt="Logo" src="/themes/metronic/assets/media/avatars/300-1.jpg" />
                </div>
                <div class="d-flex flex-column">
                    <div class="fw-bold d-flex align-items-center fs-5">
                        {{ user.name }}
                        <span class="badge badge-light-success fw-bold fs-8 px-2 py-1 ms-2">Pro</span>
                    </div>
                    <a href="#" class="fw-semibold text-muted text-hover-primary fs-7">{{ user.email }}</a>
                </div>
            </div>
        </div>

        <div class="separator my-2"></div>

        <div class="menu-item px-5">
            <a href="account/overview.html" class="menu-link px-5">My Profile</a>
        </div>

        <div class="menu-item px-5">
            <a href="apps/projects/list.html" class="menu-link px-5">
                <span class="menu-text">My Projects</span>
                <span class="menu-badge">
                    <span class="badge badge-light-danger badge-circle fw-bold fs-7">3</span>
                </span>
            </a>
        </div>

        <!-- My Subscription submenu -->
        <div
            class="menu-item px-5"
            data-kt-menu-trigger="{default: 'click', lg: 'hover'}"
            data-kt-menu-placement="left-start"
            data-kt-menu-offset="-15px, 0"
        >
            <a href="#" class="menu-link px-5">
                <span class="menu-title">My Subscription</span>
                <span class="menu-arrow"></span>
            </a>
            <div class="menu-sub menu-sub-dropdown w-175px py-4">
                <div v-for="link in subscriptionLinks" :key="link.href" class="menu-item px-3">
                    <a :href="link.href" class="menu-link px-5">{{ link.label }}</a>
                </div>
                <div class="separator my-2"></div>
                <div class="menu-item px-3">
                    <div class="menu-content px-3">
                        <label class="form-check form-switch form-check-custom form-check-solid">
                            <input class="form-check-input w-30px h-20px" type="checkbox" value="1" checked name="notifications" />
                            <span class="form-check-label text-muted fs-7">Notifications</span>
                        </label>
                    </div>
                </div>
            </div>
        </div>

        <div class="menu-item px-5">
            <a href="account/statements.html" class="menu-link px-5">My Statements</a>
        </div>

        <div class="separator my-2"></div>

        <!-- Language submenu -->
        <div
            class="menu-item px-5"
            data-kt-menu-trigger="{default: 'click', lg: 'hover'}"
            data-kt-menu-placement="left-start"
            data-kt-menu-offset="-15px, 0"
        >
            <a href="#" class="menu-link px-5">
                <span class="menu-title position-relative">
                    Language
                    <span class="fs-8 rounded bg-light px-3 py-2 position-absolute translate-middle-y top-50 end-0">
                        English
                        <img class="w-15px h-15px rounded-1 ms-2" src="/themes/metronic/assets/media/flags/united-states.svg" alt="" />
                    </span>
                </span>
            </a>
            <div class="menu-sub menu-sub-dropdown w-175px py-4">
                <div v-for="lang in languages" :key="lang.code" class="menu-item px-3">
                    <a href="account/settings.html" :class="['menu-link d-flex px-5', { active: lang.active }]">
                        <span class="symbol symbol-20px me-4">
                            <img class="rounded-1" :src="lang.flag" alt="" />
                        </span>
                        {{ lang.label }}
                    </a>
                </div>
            </div>
        </div>

        <div class="menu-item px-5 my-1">
            <a href="account/settings.html" class="menu-link px-5">Account Settings</a>
        </div>

        <div class="menu-item px-5">
            <Link href="/logout" method="post" as="button" class="menu-link px-5 w-100 text-start border-0 bg-transparent">
                Sign Out
            </Link>
        </div>
    </div>
</template>

<script setup>
import { Link, usePage } from '@inertiajs/vue3'

const user = usePage().props.auth.user

const subscriptionLinks = [
    { href: 'account/referrals.html', label: 'Referrals' },
    { href: 'account/billing.html', label: 'Billing' },
    { href: 'account/statements.html', label: 'Payments' },
    { href: 'account/statements.html', label: 'Statements' },
]

const languages = [
    { code: 'en', label: 'English', flag: '/themes/metronic/assets/media/flags/united-states.svg', active: true },
    { code: 'es', label: 'Spanish', flag: '/themes/metronic/assets/media/flags/spain.svg', active: false },
    { code: 'de', label: 'German', flag: '/themes/metronic/assets/media/flags/germany.svg', active: false },
    { code: 'ja', label: 'Japanese', flag: '/themes/metronic/assets/media/flags/japan.svg', active: false },
    { code: 'fr', label: 'French', flag: '/themes/metronic/assets/media/flags/france.svg', active: false },
]
</script>
