<template>
    <div id="kt_app_sidebar_menu_wrapper" class="app-sidebar-wrapper hover-scroll-overlay-y my-5" data-kt-scroll="true"
        data-kt-scroll-activate="true" data-kt-scroll-height="auto"
        data-kt-scroll-dependencies="#kt_app_sidebar_logo, #kt_app_sidebar_footer"
        data-kt-scroll-wrappers="#kt_app_sidebar_menu" data-kt-scroll-offset="5px" data-kt-scroll-save-state="true">
        <!--begin::Menu-->
        <div class="menu menu-column menu-rounded menu-sub-indention px-3" id="kt_app_sidebar_menu" data-kt-menu="true"
            data-kt-menu-expand="false">
            <!-- ── Dashboards ──────────────────────────────────────── -->
            <div data-kt-menu-trigger="click" :class="['menu-item', 'menu-accordion', { here: dashboardsActive, show: dashboardsActive }]">
                <span class="menu-link">
                    <span class="menu-icon">
                        <i class="ki-duotone ki-element-11 fs-2">
                            <span v-for="n in 4" :key="n" :class="`path${n}`"></span>
                        </i>
                    </span>
                    <span class="menu-title">Dashboards</span>
                    <span class="menu-arrow"></span>
                </span>
                <div class="menu-sub menu-sub-accordion">
                    <div v-for="item in dashboards.items" :key="item.href" class="menu-item">
                        <a :class="['menu-link', { active: isActive(item.href) }]" :href="item.href">
                            <span class="menu-bullet"><span class="bullet bullet-dot"></span></span>
                            <span class="menu-title">{{ item.title }}</span>
                        </a>
                    </div>

                    <!-- <div class="menu-inner flex-column collapse" id="kt_app_sidebar_menu_dashboards_collapse">
                        <div v-for="item in dashboards.moreItems" :key="item.href" class="menu-item">
                            <a class="menu-link" :href="item.href">
                                <span class="menu-bullet"><span class="bullet bullet-dot"></span></span>
                                <span class="menu-title">{{ item.title }}</span>
                            </a>
                        </div>
                    </div> -->

                    <!-- <div class="menu-item">
                        <div class="menu-content">
                            <a
                                class="btn btn-flex btn-color-primary d-flex flex-stack fs-base p-0 ms-2 mb-2 toggle collapsible collapsed"
                                data-bs-toggle="collapse"
                                href="#kt_app_sidebar_menu_dashboards_collapse"
                                data-kt-toggle-text="Show Less"
                            >
                                <span data-kt-toggle-text-target="true">Show 12 More</span>
                                <i class="ki-duotone ki-minus-square toggle-on fs-2 me-0"><span class="path1"></span><span class="path2"></span></i>
                                <i class="ki-duotone ki-plus-square toggle-off fs-2 me-0"><span class="path1"></span><span class="path2"></span><span class="path3"></span></i>
                            </a>
                        </div>
                    </div> -->

                </div>
            </div>

            <!-- ── Section: Pages ─────────────────────────────────── -->
            <div class="menu-item pt-5">
                <div class="menu-content">
                    <span class="menu-heading fw-bold text-uppercase fs-7">Pages</span>
                </div>
            </div>

            <!-- User Profile -->
            <SidebarAccordion :item="userProfile" />

            <!-- Account -->
            <!-- <SidebarAccordion :item="account" /> -->

            <!-- Authentication (com sub-layouts aninhados) -->
            <!-- <div data-kt-menu-trigger="click" class="menu-item menu-accordion">
                <span class="menu-link">
                    <span class="menu-icon">
                        <i class="ki-duotone ki-user fs-2">
                            <span class="path1"></span><span class="path2"></span>
                        </i>
                    </span>
                    <span class="menu-title">Authentication</span>
                    <span class="menu-arrow"></span>
                </span>
                <div class="menu-sub menu-sub-accordion">
                    <div
                        v-for="layout in authLayouts"
                        :key="layout.title"
                        data-kt-menu-trigger="click"
                        class="menu-item menu-accordion"
                    >
                        <span class="menu-link">
                            <span class="menu-bullet"><span class="bullet bullet-dot"></span></span>
                            <span class="menu-title">{{ layout.title }}</span>
                            <span class="menu-arrow"></span>
                        </span>
                        <div class="menu-sub menu-sub-accordion">
                            <div v-for="page in layout.items" :key="page.href" class="menu-item">
                                <a class="menu-link" :href="page.href">
                                    <span class="menu-bullet"><span class="bullet bullet-dot"></span></span>
                                    <span class="menu-title">{{ page.title }}</span>
                                </a>
                            </div>
                        </div>
                    </div>
                </div>
            </div> -->

            <!-- Email Templates -->
            <!-- <div data-kt-menu-trigger="click" class="menu-item menu-accordion">
                <span class="menu-link">
                    <span class="menu-icon">
                        <i class="ki-duotone ki-file fs-2">
                            <span class="path1"></span><span class="path2"></span>
                        </i>
                    </span>
                    <span class="menu-title">Email Templates</span>
                    <span class="menu-arrow"></span>
                </span>
                <div class="menu-sub menu-sub-accordion">
                    <div v-for="item in emailTemplates" :key="item.href" class="menu-item">
                        <a class="menu-link" :href="item.href">
                            <span class="menu-bullet"><span class="bullet bullet-dot"></span></span>
                            <span class="menu-title">{{ item.title }}</span>
                        </a>
                    </div>
                </div>
            </div> -->

            <!-- Social, Blog, FAQ, Pricing, Careers -->
            <!-- <SidebarAccordion v-for="item in pagesSimple" :key="item.title" :item="item" /> -->

            <!-- Utilities (com sub-secções aninhadas) -->
            <!-- <div data-kt-menu-trigger="click" class="menu-item menu-accordion">
                <span class="menu-link">
                    <span class="menu-icon">
                        <i class="ki-duotone ki-color-swatch fs-2">
                            <span v-for="n in 21" :key="n" :class="`path${n}`"></span>
                        </i>
                    </span>
                    <span class="menu-title">Utilities</span>
                    <span class="menu-arrow"></span>
                </span>
                <div class="menu-sub menu-sub-accordion"> -->
            <!-- Modals sub-secção -->
            <!-- <div data-kt-menu-trigger="click" class="menu-item menu-accordion">
                        <span class="menu-link">
                            <span class="menu-bullet"><span class="bullet bullet-dot"></span></span>
                            <span class="menu-title">Modals</span>
                            <span class="menu-arrow"></span>
                        </span>
                        <div class="menu-sub menu-sub-accordion">
                            <div
                                v-for="group in utilitiesModals"
                                :key="group.title"
                                data-kt-menu-trigger="click"
                                class="menu-item menu-accordion"
                            >
                                <span class="menu-link">
                                    <span class="menu-bullet"><span class="bullet bullet-dot"></span></span>
                                    <span class="menu-title">{{ group.title }}</span>
                                    <span class="menu-arrow"></span>
                                </span>
                                <div class="menu-sub menu-sub-accordion">
                                    <div v-for="page in group.items" :key="page.href" class="menu-item">
                                        <a class="menu-link" :href="page.href">
                                            <span class="menu-bullet"><span class="bullet bullet-dot"></span></span>
                                            <span class="menu-title">{{ page.title }}</span>
                                        </a>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div> -->

            <!-- Search sub-secção -->
            <!-- <div data-kt-menu-trigger="click" class="menu-item menu-accordion">
                        <span class="menu-link">
                            <span class="menu-bullet"><span class="bullet bullet-dot"></span></span>
                            <span class="menu-title">Search</span>
                            <span class="menu-arrow"></span>
                        </span>
                        <div class="menu-sub menu-sub-accordion">
                            <div v-for="item in utilitiesSearch" :key="item.href" class="menu-item">
                                <a class="menu-link" :href="item.href">
                                    <span class="menu-bullet"><span class="bullet bullet-dot"></span></span>
                                    <span class="menu-title">{{ item.title }}</span>
                                </a>
                            </div>
                        </div>
                    </div> -->

            <!-- Wizards sub-secção -->
            <!-- <div data-kt-menu-trigger="click" class="menu-item menu-accordion">
                        <span class="menu-link">
                            <span class="menu-bullet"><span class="bullet bullet-dot"></span></span>
                            <span class="menu-title">Wizards</span>
                            <span class="menu-arrow"></span>
                        </span>
                        <div class="menu-sub menu-sub-accordion">
                            <div v-for="item in utilitiesWizards" :key="item.href" class="menu-item">
                                <a class="menu-link" :href="item.href">
                                    <span class="menu-bullet"><span class="bullet bullet-dot"></span></span>
                                    <span class="menu-title">{{ item.title }}</span>
                                </a>
                            </div>
                        </div>
                    </div> -->
            <!-- </div>
            </div> -->

            <!-- Widgets -->
            <!-- <SidebarAccordion :item="widgets" /> -->

            <!-- ── Section: Apps ───────────────────────────────────── -->
            <div class="menu-item pt-5">
                <div class="menu-content">
                    <span class="menu-heading fw-bold text-uppercase fs-7">Configurações</span>
                </div>
            </div>

            <!-- Projects -->
            <!-- <SidebarAccordion :item="projects" /> -->

            <!-- eCommerce (com sub-grupos) -->
            <!-- <div data-kt-menu-trigger="click" class="menu-item menu-accordion">
                <span class="menu-link">
                    <span class="menu-icon">
                        <i class="ki-duotone ki-basket fs-2">
                            <span v-for="n in 4" :key="n" :class="`path${n}`"></span>
                        </i>
                    </span>
                    <span class="menu-title">eCommerce</span>
                    <span class="menu-arrow"></span>
                </span>
                <div class="menu-sub menu-sub-accordion">
                    <div
                        v-for="group in ecommerceGroups"
                        :key="group.title"
                        data-kt-menu-trigger="click"
                        class="menu-item menu-accordion"
                    >
                        <span class="menu-link">
                            <span class="menu-bullet"><span class="bullet bullet-dot"></span></span>
                            <span class="menu-title">{{ group.title }}</span>
                            <span class="menu-arrow"></span>
                        </span>
                        <div class="menu-sub menu-sub-accordion">
                            <div v-for="item in group.items" :key="item.href" class="menu-item">
                                <a class="menu-link" :href="item.href">
                                    <span class="menu-bullet"><span class="bullet bullet-dot"></span></span>
                                    <span class="menu-title">{{ item.title }}</span>
                                </a>
                            </div>
                        </div>
                    </div>
                </div>
            </div> -->

            <!-- Contacts -->
            <!-- <SidebarAccordion :item="contacts" /> -->

            <!-- Support Center (com sub-grupos) -->
            <!-- <div data-kt-menu-trigger="click" class="menu-item menu-accordion">
                <span class="menu-link">
                    <span class="menu-icon">
                        <i class="ki-duotone ki-chart fs-2">
                            <span class="path1"></span><span class="path2"></span>
                        </i>
                    </span>
                    <span class="menu-title">Support Center</span>
                    <span class="menu-arrow"></span>
                </span>
                <div class="menu-sub menu-sub-accordion">
                    <div class="menu-item">
                        <a class="menu-link" href="apps/support-center/overview.html">
                            <span class="menu-bullet"><span class="bullet bullet-dot"></span></span>
                            <span class="menu-title">Overview</span>
                        </a>
                    </div>
                    <div
                        v-for="group in supportCenterGroups"
                        :key="group.title"
                        data-kt-menu-trigger="click"
                        class="menu-item menu-accordion"
                    >
                        <span class="menu-link">
                            <span class="menu-bullet"><span class="bullet bullet-dot"></span></span>
                            <span class="menu-title">{{ group.title }}</span>
                            <span class="menu-arrow"></span>
                        </span>
                        <div class="menu-sub menu-sub-accordion">
                            <div v-for="item in group.items" :key="item.href" class="menu-item">
                                <a class="menu-link" :href="item.href">
                                    <span class="menu-bullet"><span class="bullet bullet-dot"></span></span>
                                    <span class="menu-title">{{ item.title }}</span>
                                </a>
                            </div>
                        </div>
                    </div>
                </div>
            </div> -->

            <!-- User Management (com sub-grupos) -->
            <div data-kt-menu-trigger="click" :class="['menu-item', 'menu-accordion', { here: userManagementActive, show: userManagementActive }]">
                <span class="menu-link">
                    <span class="menu-icon">
                        <i class="ki-duotone ki-abstract-28 fs-2">
                            <span class="path1"></span><span class="path2"></span>
                        </i>
                    </span>
                    <span class="menu-title">Utilizadores & Perfis</span>
                    <span class="menu-arrow"></span>
                </span>
                <div class="menu-sub menu-sub-accordion">
                    <div v-for="group in userManagementGroups" :key="group.title" data-kt-menu-trigger="click"
                        :class="['menu-item', 'menu-accordion', { here: isGroupActive(group.items), show: isGroupActive(group.items) }]">
                        <span class="menu-link">
                            <span class="menu-bullet"><span class="bullet bullet-dot"></span></span>
                            <span class="menu-title">{{ group.title }}</span>
                            <span class="menu-arrow"></span>
                        </span>
                        <div class="menu-sub menu-sub-accordion">
                            <div v-for="item in group.items" :key="item.href" class="menu-item">
                                <a :class="['menu-link', { active: isActive(item.href) }]" :href="item.href">
                                    <span class="menu-bullet"><span class="bullet bullet-dot"></span></span>
                                    <span class="menu-title">{{ item.title }}</span>
                                </a>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Customers -->
            <!-- <SidebarAccordion :item="customers" /> -->

            <!-- Subscription -->
            <!-- <SidebarAccordion :item="subscription" /> -->

            <!-- Invoice Manager (com sub-grupo) -->
            <!-- <div data-kt-menu-trigger="click" class="menu-item menu-accordion">
                <span class="menu-link">
                    <span class="menu-icon">
                        <i class="ki-duotone ki-credit-cart fs-2">
                            <span class="path1"></span><span class="path2"></span>
                        </i>
                    </span>
                    <span class="menu-title">Invoice Manager</span>
                    <span class="menu-arrow"></span>
                </span>
                <div class="menu-sub menu-sub-accordion">
                    <div data-kt-menu-trigger="click" class="menu-item menu-accordion">
                        <span class="menu-link">
                            <span class="menu-bullet"><span class="bullet bullet-dot"></span></span>
                            <span class="menu-title">View Invoices</span>
                            <span class="menu-arrow"></span>
                        </span>
                        <div class="menu-sub menu-sub-accordion">
                            <div v-for="item in invoices" :key="item.href" class="menu-item">
                                <a class="menu-link" :href="item.href">
                                    <span class="menu-bullet"><span class="bullet bullet-dot"></span></span>
                                    <span class="menu-title">{{ item.title }}</span>
                                </a>
                            </div>
                        </div>
                    </div>
                </div>
            </div> -->

            <!-- File Manager, Inbox, Chat -->
            <!-- <SidebarAccordion v-for="item in appsSimple" :key="item.title" :item="item" /> -->

            <!-- ── Section: Layouts ────────────────────────────────── -->
            <!-- <div class="menu-item pt-5">
                <div class="menu-content">
                    <span class="menu-heading fw-bold text-uppercase fs-7">Layouts</span>
                </div>
            </div> -->

            <!-- <SidebarAccordion v-for="item in layoutsMenu" :key="item.title" :item="item" /> -->

            <!-- <div class="menu-item">
                <a class="menu-link" href="layout-builder.html">
                    <span class="menu-icon">
                        <i class="ki-duotone ki-element-9 fs-2">
                            <span class="path1"></span><span class="path2"></span>
                        </i>
                    </span>
                    <span class="menu-title">Layout Builder</span>
                </a>
            </div> -->

            <!-- ── Section: Help ───────────────────────────────────── -->
            <!-- <div class="menu-item pt-5">
                <div class="menu-content">
                    <span class="menu-heading fw-bold text-uppercase fs-7">Help</span>
                </div>
            </div> -->

            <!-- <div v-for="item in helpLinks" :key="item.href" class="menu-item">
                <a class="menu-link" :href="item.href">
                    <span class="menu-icon">
                        <i :class="`ki-duotone ${item.icon} fs-2`">
                            <span v-for="n in item.paths" :key="n" :class="`path${n}`"></span>
                        </i>
                    </span>
                    <span class="menu-title">{{ item.title }}</span>
                </a>
            </div> -->
        </div>
        <!--end::Menu-->
    </div>
</template>

<script setup>
import { computed } from 'vue'
import SidebarAccordion from './sidebar/SidebarAccordion.vue'
import { useActiveMenu } from '@/composables/useActiveMenu'

const { isActive, isGroupActive } = useActiveMenu()

// ── Dashboards ───────────────────────────────────────────────────────────────
const dashboards = {
    items: [
        { href: '/', title: 'Página inicial' },
        // { href: 'dashboards/ecommerce.html', title: 'eCommerce' },
        // { href: 'dashboards/projects.html', title: 'Projects' },
        // { href: 'dashboards/online-courses.html', title: 'Online Courses' },
        // { href: 'dashboards/marketing.html', title: 'Marketing' },
    ],
    // moreItems: [
    //     { href: 'dashboards/bidding.html', title: 'Bidding' },
    //     { href: 'dashboards/pos.html', title: 'POS System' },
    //     { href: 'dashboards/call-center.html', title: 'Call Center' },
    //     { href: 'dashboards/logistics.html', title: 'Logistics' },
    //     { href: 'dashboards/website-analytics.html', title: 'Website Analytics' },
    //     { href: 'dashboards/finance-performance.html', title: 'Finance Performance' },
    //     { href: 'dashboards/store-analytics.html', title: 'Store Analytics' },
    //     { href: 'dashboards/social.html', title: 'Social' },
    //     { href: 'dashboards/delivery.html', title: 'Delivery' },
    //     { href: 'dashboards/crypto.html', title: 'Crypto' },
    //     { href: 'dashboards/school.html', title: 'School' },
    //     { href: 'dashboards/podcast.html', title: 'Podcast' },
    //     { href: 'landing.html', title: 'Landing' },
    // ],
}
const dashboardsActive = computed(() => isGroupActive(dashboards.items))

// ── Pages ────────────────────────────────────────────────────────────────────
const userProfile = {
    title: 'User Profile', icon: 'ki-address-book', paths: 3,
    items: [
        { href: 'pages/user-profile/overview.html', title: 'Overview' },
        { href: 'pages/user-profile/projects.html', title: 'Projects' },
        { href: 'pages/user-profile/campaigns.html', title: 'Campaigns' },
        { href: 'pages/user-profile/documents.html', title: 'Documents' },
        { href: 'pages/user-profile/followers.html', title: 'Followers' },
        { href: 'pages/user-profile/activity.html', title: 'Activity' },
    ],
}

// const account = {
//     title: 'Account', icon: 'ki-element-plus', paths: 5,
//     items: [
//         { href: 'account/overview.html', title: 'Overview' },
//         { href: 'account/settings.html', title: 'Settings' },
//         { href: 'account/security.html', title: 'Security' },
//         { href: 'account/activity.html', title: 'Activity' },
//         { href: 'account/billing.html', title: 'Billing' },
//         { href: 'account/statements.html', title: 'Statements' },
//         { href: 'account/referrals.html', title: 'Referrals' },
//         { href: 'account/api-keys.html', title: 'API Keys' },
//         { href: 'account/logs.html', title: 'Logs' },
//     ],
// }

// const authLayouts = [
//     {
//         title: 'Corporate Layout',
//         items: [
//             { href: 'authentication/layouts/corporate/sign-in.html', title: 'Sign-in' },
//             { href: 'authentication/layouts/corporate/sign-up.html', title: 'Sign-up' },
//             { href: 'authentication/layouts/corporate/two-factor.html', title: 'Two-Factor' },
//             { href: 'authentication/layouts/corporate/reset-password.html', title: 'Reset Password' },
//             { href: 'authentication/layouts/corporate/new-password.html', title: 'New Password' },
//         ],
//     },
//     {
//         title: 'Overlay Layout',
//         items: [
//             { href: 'authentication/layouts/overlay/sign-in.html', title: 'Sign-in' },
//             { href: 'authentication/layouts/overlay/sign-up.html', title: 'Sign-up' },
//             { href: 'authentication/layouts/overlay/two-factor.html', title: 'Two-Factor' },
//             { href: 'authentication/layouts/overlay/reset-password.html', title: 'Reset Password' },
//             { href: 'authentication/layouts/overlay/new-password.html', title: 'New Password' },
//         ],
//     },
//     {
//         title: 'Creative Layout',
//         items: [
//             { href: 'authentication/layouts/creative/sign-in.html', title: 'Sign-in' },
//             { href: 'authentication/layouts/creative/sign-up.html', title: 'Sign-up' },
//             { href: 'authentication/layouts/creative/two-factor.html', title: 'Two-Factor' },
//             { href: 'authentication/layouts/creative/reset-password.html', title: 'Reset Password' },
//             { href: 'authentication/layouts/creative/new-password.html', title: 'New Password' },
//         ],
//     },
//     {
//         title: 'Fancy Layout',
//         items: [
//             { href: 'authentication/layouts/fancy/sign-in.html', title: 'Sign-in' },
//             { href: 'authentication/layouts/fancy/sign-up.html', title: 'Sign-up' },
//             { href: 'authentication/layouts/fancy/two-factor.html', title: 'Two-Factor' },
//             { href: 'authentication/layouts/fancy/reset-password.html', title: 'Reset Password' },
//             { href: 'authentication/layouts/fancy/new-password.html', title: 'New Password' },
//         ],
//     },
// ]

// const emailTemplates = [
//     { href: 'authentication/email/welcome-message.html', title: 'Welcome Message' },
//     { href: 'authentication/email/reset-password.html', title: 'Reset Password' },
//     { href: 'authentication/email/subscription-confirmed.html', title: 'Subscription Confirmed' },
//     { href: 'authentication/email/card-declined.html', title: 'Credit Card Declined' },
//     { href: 'authentication/email/promo-1.html', title: 'Promo 1' },
//     { href: 'authentication/email/promo-2.html', title: 'Promo 2' },
//     { href: 'authentication/email/promo-3.html', title: 'Promo 3' },
//     { href: 'authentication/extended/multi-steps-sign-up.html', title: 'Multi-steps Sign-up' },
//     { href: 'authentication/general/welcome.html', title: 'Welcome Message' },
//     { href: 'authentication/general/verify-email.html', title: 'Verify Email' },
//     { href: 'authentication/general/coming-soon.html', title: 'Coming Soon' },
//     { href: 'authentication/general/password-confirmation.html', title: 'Password Confirmation' },
//     { href: 'authentication/general/account-deactivated.html', title: 'Account Deactivation' },
//     { href: 'authentication/general/error-404.html', title: 'Error 404' },
//     { href: 'authentication/general/error-500.html', title: 'Error 500' },
// ]

// const pagesSimple = [
//     {
//         title: 'Social', icon: 'ki-abstract-39', paths: 2,
//         items: [
//             { href: 'pages/social/feeds.html', title: 'Feeds' },
//             { href: 'pages/social/activity.html', title: 'Activity' },
//             { href: 'pages/social/followers.html', title: 'Followers' },
//             { href: 'pages/social/settings.html', title: 'Settings' },
//         ],
//     },
//     {
//         title: 'Blog', icon: 'ki-bank', paths: 2,
//         items: [
//             { href: 'pages/blog/home.html', title: 'Blog Home' },
//             { href: 'pages/blog/post.html', title: 'Blog Post' },
//         ],
//     },
//     {
//         title: 'FAQ', icon: 'ki-chart-pie-3', paths: 3,
//         items: [
//             { href: 'pages/faq/classic.html', title: 'FAQ Classic' },
//             { href: 'pages/faq/extended.html', title: 'FAQ Extended' },
//         ],
//     },
//     {
//         title: 'Pricing', icon: 'ki-bucket', paths: 4,
//         items: [
//             { href: 'pages/pricing/column.html', title: 'Column Pricing' },
//             { href: 'pages/pricing/table.html', title: 'Table Pricing' },
//         ],
//     },
//     {
//         title: 'Careers', icon: 'ki-call', paths: 8,
//         items: [
//             { href: 'pages/careers/list.html', title: 'Careers List' },
//             { href: 'pages/careers/apply.html', title: 'Careers Apply' },
//         ],
//     },
// ]

// const utilitiesModals = [
//     {
//         title: 'General',
//         items: [
//             { href: 'utilities/modals/general/invite-friends.html', title: 'Invite Friends' },
//             { href: 'utilities/modals/general/view-users.html', title: 'View Users' },
//             { href: 'utilities/modals/general/select-users.html', title: 'Select Users' },
//             { href: 'utilities/modals/general/upgrade-plan.html', title: 'Upgrade Plan' },
//             { href: 'utilities/modals/general/share-earn.html', title: 'Share & Earn' },
//         ],
//     },
//     {
//         title: 'Forms',
//         items: [
//             { href: 'utilities/modals/forms/new-target.html', title: 'New Target' },
//             { href: 'utilities/modals/forms/new-card.html', title: 'New Card' },
//             { href: 'utilities/modals/forms/new-address.html', title: 'New Address' },
//             { href: 'utilities/modals/forms/create-api-key.html', title: 'Create API Key' },
//             { href: 'utilities/modals/forms/bidding.html', title: 'Bidding' },
//         ],
//     },
//     {
//         title: 'Wizards',
//         items: [
//             { href: 'utilities/modals/wizards/create-app.html', title: 'Create App' },
//             { href: 'utilities/modals/wizards/create-campaign.html', title: 'Create Campaign' },
//             { href: 'utilities/modals/wizards/create-account.html', title: 'Create Business Acc' },
//             { href: 'utilities/modals/wizards/create-project.html', title: 'Create Project' },
//             { href: 'utilities/modals/wizards/top-up-wallet.html', title: 'Top Up Wallet' },
//             { href: 'utilities/modals/wizards/offer-a-deal.html', title: 'Offer a Deal' },
//             { href: 'utilities/modals/wizards/two-factor-authentication.html', title: 'Two Factor Auth' },
//         ],
//     },
//     {
//         title: 'Search',
//         items: [
//             { href: 'utilities/modals/search/users.html', title: 'Users' },
//             { href: 'utilities/modals/search/select-location.html', title: 'Select Location' },
//         ],
//     },
// ]

// const utilitiesSearch = [
//     { href: 'utilities/search/horizontal.html', title: 'Horizontal' },
//     { href: 'utilities/search/vertical.html', title: 'Vertical' },
//     { href: 'utilities/search/users.html', title: 'Users' },
//     { href: 'utilities/search/select-location.html', title: 'Location' },
// ]

// const utilitiesWizards = [
//     { href: 'utilities/wizards/horizontal.html', title: 'Horizontal' },
//     { href: 'utilities/wizards/vertical.html', title: 'Vertical' },
//     { href: 'utilities/wizards/two-factor-authentication.html', title: 'Two Factor Auth' },
//     { href: 'utilities/wizards/create-app.html', title: 'Create App' },
//     { href: 'utilities/wizards/create-campaign.html', title: 'Create Campaign' },
//     { href: 'utilities/wizards/create-account.html', title: 'Create Account' },
//     { href: 'utilities/wizards/create-project.html', title: 'Create Project' },
//     { href: 'utilities/wizards/top-up-wallet.html', title: 'Top Up Wallet' },
//     { href: 'utilities/wizards/offer-a-deal.html', title: 'Offer a Deal' },
// ]

// const widgets = {
//     title: 'Widgets', icon: 'ki-element-7', paths: 2,
//     items: [
//         { href: 'widgets/lists.html', title: 'Lists' },
//         { href: 'widgets/statistics.html', title: 'Statistics' },
//         { href: 'widgets/charts.html', title: 'Charts' },
//         { href: 'widgets/mixed.html', title: 'Mixed' },
//         { href: 'widgets/tables.html', title: 'Tables' },
//         { href: 'widgets/feeds.html', title: 'Feeds' },
//     ],
// }

// ── Apps ─────────────────────────────────────────────────────────────────────
// const projects = {
//     title: 'Projects', icon: 'ki-abstract-41', paths: 2,
//     items: [
//         { href: 'apps/projects/list.html', title: 'My Projects' },
//         { href: 'apps/projects/project.html', title: 'View Project' },
//         { href: 'apps/projects/targets.html', title: 'Targets' },
//         { href: 'apps/projects/budget.html', title: 'Budget' },
//         { href: 'apps/projects/users.html', title: 'Users' },
//         { href: 'apps/projects/files.html', title: 'Files' },
//         { href: 'apps/projects/activity.html', title: 'Activity' },
//         { href: 'apps/projects/settings.html', title: 'Settings' },
//     ],
// }

// const ecommerceGroups = [
//     {
//         title: 'Catalog',
//         items: [
//             { href: 'apps/ecommerce/catalog/products.html', title: 'Products' },
//             { href: 'apps/ecommerce/catalog/categories.html', title: 'Categories' },
//             { href: 'apps/ecommerce/catalog/add-product.html', title: 'Add Product' },
//             { href: 'apps/ecommerce/catalog/edit-product.html', title: 'Edit Product' },
//             { href: 'apps/ecommerce/catalog/add-category.html', title: 'Add Category' },
//             { href: 'apps/ecommerce/catalog/edit-category.html', title: 'Edit Category' },
//         ],
//     },
//     {
//         title: 'Sales',
//         items: [
//             { href: 'apps/ecommerce/sales/listing.html', title: 'Orders Listing' },
//             { href: 'apps/ecommerce/sales/details.html', title: 'Order Details' },
//             { href: 'apps/ecommerce/sales/add-order.html', title: 'Add Order' },
//             { href: 'apps/ecommerce/sales/edit-order.html', title: 'Edit Order' },
//         ],
//     },
//     {
//         title: 'Customers',
//         items: [
//             { href: 'apps/ecommerce/customers/listing.html', title: 'Customer Listing' },
//             { href: 'apps/ecommerce/customers/details.html', title: 'Customer Details' },
//         ],
//     },
//     {
//         title: 'Reports',
//         items: [
//             { href: 'apps/ecommerce/reports/products-viewed.html', title: 'Products Viewed' },
//             { href: 'apps/ecommerce/reports/sales.html', title: 'Sales' },
//             { href: 'apps/ecommerce/reports/returns.html', title: 'Returns' },
//             { href: 'apps/ecommerce/reports/customer-orders.html', title: 'Customer Orders' },
//             { href: 'apps/ecommerce/reports/shipping.html', title: 'Shipping' },
//             { href: 'apps/ecommerce/reports/settings.html', title: 'Settings' },
//         ],
//     },
// ]

// const contacts = {
//     title: 'Contacts', icon: 'ki-abstract-25', paths: 2,
//     items: [
//         { href: 'apps/contacts/getting-started.html', title: 'Getting Started' },
//         { href: 'apps/contacts/add-contact.html', title: 'Add Contact' },
//         { href: 'apps/contacts/edit-contact.html', title: 'Edit Contact' },
//         { href: 'apps/contacts/view-contact.html', title: 'View Contact' },
//     ],
// }

// const supportCenterGroups = [
//     {
//         title: 'Tickets',
//         items: [
//             { href: 'apps/support-center/tickets/list.html', title: 'Tickets List' },
//             { href: 'apps/support-center/tickets/view.html', title: 'View Ticket' },
//         ],
//     },
//     {
//         title: 'Tutorials',
//         items: [
//             { href: 'apps/support-center/tutorials/list.html', title: 'Tutorials List' },
//             { href: 'apps/support-center/tutorials/post.html', title: 'Tutorial Post' },
//             { href: 'apps/support-center/faq.html', title: 'FAQ' },
//             { href: 'apps/support-center/licenses.html', title: 'Licenses' },
//             { href: 'apps/support-center/contact.html', title: 'Contact Us' },
//         ],
//     },
// ]

const userManagementGroups = [
    {
        title: 'Gestão de utilizadores',
        items: [
            { href: '/usuarios', title: 'Todos os Utilizadores' },
            { href: 'apps/user-management/users/view.html', title: 'Alunos' },
            { href: 'apps/user-management/users/view.html', title: 'Professores' },
            { href: 'apps/user-management/users/view.html', title: 'Funcionários' },
            { href: 'apps/user-management/users/view.html', title: 'Administradores' },
        ],
    },
    {
        title: 'Perfis',
        items: [
            { href: 'apps/user-management/roles/list.html', title: 'Roles List' },
            { href: 'apps/user-management/roles/view.html', title: 'View Role' },
            { href: 'apps/user-management/roles/permissions.html', title: 'Permissions' },
        ],
    },
]
const userManagementActive = computed(() =>
    userManagementGroups.some((group) => isGroupActive(group.items))
)

// const customers = {
//     title: 'Customers', icon: 'ki-abstract-38', paths: 2,
//     items: [
//         { href: 'apps/customers/getting-started.html', title: 'Getting Started' },
//         { href: 'apps/customers/listing.html', title: 'Customer Listing' },
//         { href: 'apps/customers/details.html', title: 'Customer Details' },
//     ],
// }

// const subscription = {
//     title: 'Subscription', icon: 'ki-map', paths: 3,
//     items: [
//         { href: 'apps/subscriptions/getting-started.html', title: 'Getting Started' },
//         { href: 'apps/subscriptions/list.html', title: 'Subscription List' },
//         { href: 'apps/subscriptions/add.html', title: 'Add Subscription' },
//         { href: 'apps/subscriptions/view.html', title: 'View Subscription' },
//     ],
// }

// const invoices = [
//     { href: 'apps/invoices/view/invoice-1.html', title: 'Invoice 1' },
//     { href: 'apps/invoices/view/invoice-2.html', title: 'Invoice 2' },
//     { href: 'apps/invoices/view/invoice-3.html', title: 'Invoice 3' },
//     { href: 'apps/invoices/create.html', title: 'Create Invoice' },
// ]

// const appsSimple = [
//     {
//         title: 'File Manager', icon: 'ki-switch', paths: 2,
//         items: [
//             { href: 'apps/file-manager/folders.html', title: 'Folders' },
//             { href: 'apps/file-manager/files.html', title: 'Files' },
//             { href: 'apps/file-manager/blank.html', title: 'Blank Directory' },
//             { href: 'apps/file-manager/settings.html', title: 'Settings' },
//         ],
//     },
//     {
//         title: 'Inbox', icon: 'ki-sms', paths: 2,
//         items: [
//             { href: 'apps/inbox/listing.html', title: 'Messages' },
//             { href: 'apps/inbox/compose.html', title: 'Compose' },
//             { href: 'apps/inbox/reply.html', title: 'View & Reply' },
//         ],
//     },
//     {
//         title: 'Chat', icon: 'ki-message-text-2', paths: 3,
//         items: [
//             { href: 'apps/chat/private-chat.html', title: 'Private Chat' },
//             { href: 'apps/chat/group-chat.html', title: 'Group Chat' },
//             { href: 'apps/chat/drawer-chat.html', title: 'Drawer Chat' },
//         ],
//     },
// ]

// ── Layouts ───────────────────────────────────────────────────────────────────
// const layoutsMenu = [
//     {
//         title: 'Layout Options', icon: 'ki-element-7', paths: 2,
//         items: [
//             { href: 'layouts/light-sidebar.html', title: 'Light Sidebar' },
//             { href: 'layouts/dark-sidebar.html', title: 'Dark Sidebar' },
//             { href: 'layouts/light-header.html', title: 'Light Header' },
//             { href: 'layouts/dark-header.html', title: 'Dark Header' },
//         ],
//     },
//     {
//         title: 'Toolbars', icon: 'ki-text-align-center', paths: 4,
//         items: [
//             { href: 'toolbars/classic.html', title: 'Classic' },
//             { href: 'toolbars/saas.html', title: 'SaaS' },
//             { href: 'toolbars/accounting.html', title: 'Accounting' },
//             { href: 'toolbars/extended.html', title: 'Extended' },
//             { href: 'toolbars/reports.html', title: 'Reports' },
//         ],
//     },
// ]

// // ── Help ─────────────────────────────────────────────────────────────────────
// const helpLinks = [
//     {
//         href: 'https://preview.keenthemes.com/html/metronic/docs/base/utilities',
//         title: 'Components',
//         icon: 'ki-abstract-25',
//         paths: 2,
//     },
//     {
//         href: 'https://preview.keenthemes.com/html/metronic/docs',
//         title: 'Documentation',
//         icon: 'ki-document',
//         paths: 2,
//     },
//     {
//         href: 'https://preview.keenthemes.com/html/metronic/docs/getting-started/changelog',
//         title: 'Changelog v8.1.8',
//         icon: 'ki-code',
//         paths: 2,
//     },
// ]
</script>
