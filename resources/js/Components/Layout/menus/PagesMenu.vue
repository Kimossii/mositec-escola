<template>
    <div class="menu-active-bg px-4 px-lg-0">
        <!-- Tabs nav -->
        <div class="d-flex w-100 overflow-auto">
            <ul class="nav nav-stretch nav-line-tabs fw-bold fs-6 p-0 p-lg-10 flex-nowrap flex-grow-1">
                <li v-for="tab in tabs" :key="tab.id" class="nav-item mx-lg-1">
                    <a
                        :class="['nav-link py-3 py-lg-6 text-active-primary', { active: tab.active }]"
                        href="#"
                        data-bs-toggle="tab"
                        :data-bs-target="`#${tab.id}`"
                    >
                        {{ tab.label }}
                    </a>
                </li>
            </ul>
        </div>

        <!-- Tab content -->
        <div class="tab-content py-4 py-lg-8 px-lg-7">

            <!-- General tab -->
            <div class="tab-pane active w-lg-1000px" id="kt_app_header_menu_pages_pages">
                <div class="row">
                    <div class="col-lg-8">
                        <div class="row">
                            <div v-for="section in generalSections" :key="section.title" class="col-lg-3 mb-6 mb-lg-0">
                                <h4 class="fs-6 fs-lg-4 fw-bold mb-3 ms-4">{{ section.title }}</h4>
                                <div v-for="link in section.links" :key="link.href" class="menu-item p-0 m-0">
                                    <a :href="link.href" class="menu-link">
                                        <span class="menu-title">{{ link.label }}</span>
                                    </a>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="col-lg-4">
                        <img src="/themes/metronic/assets/media/stock/600x600/img-82.jpg" class="rounded mw-100" alt="" />
                    </div>
                </div>
            </div>

            <!-- Account tab -->
            <div class="tab-pane w-lg-600px" id="kt_app_header_menu_pages_account">
                <div class="row">
                    <div class="col-lg-5 mb-6 mb-lg-0">
                        <div class="row">
                            <div class="col-lg-6">
                                <div v-for="link in accountLinksCol1" :key="link.href" class="menu-item p-0 m-0">
                                    <a :href="link.href" class="menu-link">
                                        <span class="menu-title">{{ link.label }}</span>
                                    </a>
                                </div>
                            </div>
                            <div class="col-lg-6">
                                <div v-for="link in accountLinksCol2" :key="link.href" class="menu-item p-0 m-0">
                                    <a :href="link.href" class="menu-link">
                                        <span class="menu-title">{{ link.label }}</span>
                                    </a>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="col-lg-7">
                        <img src="/themes/metronic/assets/media/stock/900x600/46.jpg" class="rounded mw-100" alt="" />
                    </div>
                </div>
            </div>

            <!-- Authentication tab -->
            <div class="tab-pane w-lg-1000px" id="kt_app_header_menu_pages_authentication">
                <div class="row">
                    <div v-for="col in authColumns" :key="col.title" class="col-lg-3 mb-6 mb-lg-0">
                        <div v-for="section in col.sections" :key="section.title" class="mb-6">
                            <h4 class="fs-6 fs-lg-4 fw-bold mb-3 ms-4">{{ section.title }}</h4>
                            <div v-for="link in section.links" :key="link.href" class="menu-item p-0 m-0">
                                <a :href="link.href" class="menu-link">
                                    <span class="menu-title">{{ link.label }}</span>
                                </a>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Utilities tab -->
            <div class="tab-pane w-lg-1000px" id="kt_app_header_menu_pages_utilities">
                <div class="row">
                    <div class="col-lg-7">
                        <div class="row">
                            <div v-for="col in utilitiesColumns" :key="col.title" class="col-lg-4 mb-6 mb-lg-0">
                                <div v-for="section in col.sections" :key="section.title" class="mb-6">
                                    <h4 class="fs-6 fs-lg-4 fw-bold mb-3 ms-4">{{ section.title }}</h4>
                                    <div v-for="link in section.links" :key="link.href" class="menu-item p-0 m-0">
                                        <a :href="link.href" class="menu-link">
                                            <span class="menu-title">{{ link.label }}</span>
                                        </a>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="col-lg-5 pe-lg-5">
                        <img src="/themes/metronic/assets/media/stock/600x600/img-84.jpg" class="rounded mw-100" alt="" />
                    </div>
                </div>
            </div>

            <!-- Widgets tab -->
            <div class="tab-pane w-lg-500px" id="kt_app_header_menu_pages_widgets">
                <div class="row">
                    <div class="col-lg-4 mb-6 mb-lg-0">
                        <div v-for="link in widgetLinks" :key="link.href" class="menu-item p-0 m-0">
                            <a :href="link.href" class="menu-link">
                                <span class="menu-title">{{ link.label }}</span>
                            </a>
                        </div>
                    </div>
                    <div class="col-lg-8">
                        <img src="/themes/metronic/assets/media/stock/900x600/44.jpg" class="rounded mw-100" alt="" />
                    </div>
                </div>
            </div>

        </div>
    </div>
</template>

<script setup>
const tabs = [
    { id: 'kt_app_header_menu_pages_pages', label: 'General', active: true },
    { id: 'kt_app_header_menu_pages_account', label: 'Account', active: false },
    { id: 'kt_app_header_menu_pages_authentication', label: 'Authentication', active: false },
    { id: 'kt_app_header_menu_pages_utilities', label: 'Utilities', active: false },
    { id: 'kt_app_header_menu_pages_widgets', label: 'Widgets', active: false },
]

const generalSections = [
    {
        title: 'User Profile',
        links: [
            { href: 'pages/user-profile/overview.html', label: 'Overview' },
            { href: 'pages/user-profile/projects.html', label: 'Projects' },
            { href: 'pages/user-profile/campaigns.html', label: 'Campaigns' },
            { href: 'pages/user-profile/documents.html', label: 'Documents' },
            { href: 'pages/user-profile/followers.html', label: 'Followers' },
            { href: 'pages/user-profile/activity.html', label: 'Activity' },
        ],
    },
    {
        title: 'Corporate',
        links: [
            { href: 'pages/about.html', label: 'About' },
            { href: 'pages/team.html', label: 'Our Team' },
            { href: 'pages/contact.html', label: 'Contact Us' },
            { href: 'pages/licenses.html', label: 'Licenses' },
            { href: 'pages/sitemap.html', label: 'Sitemap' },
            { href: 'pages/careers/list.html', label: 'Careers List' },
            { href: 'pages/careers/apply.html', label: 'Careers Apply' },
        ],
    },
    {
        title: 'FAQ',
        links: [
            { href: 'pages/faq/classic.html', label: 'FAQ Classic' },
            { href: 'pages/faq/extended.html', label: 'FAQ Extended' },
            { href: 'pages/blog/home.html', label: 'Blog Home' },
            { href: 'pages/blog/post.html', label: 'Blog Post' },
            { href: 'pages/pricing/column.html', label: 'Column Pricing' },
            { href: 'pages/pricing/table.html', label: 'Table Pricing' },
        ],
    },
    {
        title: 'Social',
        links: [
            { href: 'pages/social/feeds.html', label: 'Feeds' },
            { href: 'pages/social/activity.html', label: 'Activity' },
            { href: 'pages/social/followers.html', label: 'Followers' },
            { href: 'pages/social/settings.html', label: 'Settings' },
        ],
    },
]

const accountLinksCol1 = [
    { href: 'account/overview.html', label: 'Overview' },
    { href: 'account/settings.html', label: 'Settings' },
    { href: 'account/security.html', label: 'Security' },
    { href: 'account/activity.html', label: 'Activity' },
    { href: 'account/billing.html', label: 'Billing' },
]

const accountLinksCol2 = [
    { href: 'account/statements.html', label: 'Statements' },
    { href: 'account/referrals.html', label: 'Referrals' },
    { href: 'account/api-keys.html', label: 'API Keys' },
    { href: 'account/logs.html', label: 'Logs' },
]

const makeAuthLinks = (base, labels) =>
    labels.map(label => ({ href: `${base}/${label.toLowerCase().replace(/\s/g, '-')}.html`, label }))

const authColumns = [
    {
        sections: [
            { title: 'Corporate Layout', links: makeAuthLinks('authentication/layouts/corporate', ['Sign-In', 'Sign-Up', 'Two-Factor', 'Reset Password', 'New Password']) },
            { title: 'Overlay Layout', links: makeAuthLinks('authentication/layouts/overlay', ['Sign-In', 'Sign-Up', 'Two-Factor', 'Reset Password', 'New Password']) },
        ],
    },
    {
        sections: [
            { title: 'Creative Layout', links: makeAuthLinks('authentication/layouts/creative', ['Sign-in', 'Sign-up', 'Two-Factor', 'Reset Password', 'New Password']) },
            { title: 'Fancy Layout', links: makeAuthLinks('authentication/layouts/fancy', ['Sign-In', 'Sign-Up', 'Two-Factor', 'Reset Password', 'New Password']) },
        ],
    },
    {
        sections: [
            {
                title: 'General',
                links: [
                    { href: 'authentication/extended/multi-steps-sign-up.html', label: 'Multi-Steps Sign-Up' },
                    { href: 'authentication/general/welcome.html', label: 'Welcome Message' },
                    { href: 'authentication/general/verify-email.html', label: 'Verify Email' },
                    { href: 'authentication/general/coming-soon.html', label: 'Coming Soon' },
                    { href: 'authentication/general/password-confirmation.html', label: 'Password Confirmation' },
                    { href: 'authentication/general/account-deactivated.html', label: 'Account Deactivation' },
                    { href: 'authentication/general/error-404.html', label: 'Error 404' },
                    { href: 'authentication/general/error-500.html', label: 'Error 500' },
                ],
            },
        ],
    },
    {
        sections: [
            {
                title: 'Email Templates',
                links: [
                    { href: 'authentication/email/welcome-message.html', label: 'Welcome Message' },
                    { href: 'authentication/email/reset-password.html', label: 'Reset Password' },
                    { href: 'authentication/email/subscription-confirmed.html', label: 'Subscription Confirmed' },
                    { href: 'authentication/email/card-declined.html', label: 'Credit Card Declined' },
                    { href: 'authentication/email/promo-1.html', label: 'Promo 1' },
                    { href: 'authentication/email/promo-2.html', label: 'Promo 2' },
                    { href: 'authentication/email/promo-3.html', label: 'Promo 3' },
                ],
            },
        ],
    },
]

const utilitiesColumns = [
    {
        sections: [
            {
                title: 'General Modals',
                links: [
                    { href: 'utilities/modals/general/invite-friends.html', label: 'Invite Friends' },
                    { href: 'utilities/modals/general/view-users.html', label: 'View Users' },
                    { href: 'utilities/modals/general/select-users.html', label: 'Select Users' },
                    { href: 'utilities/modals/general/upgrade-plan.html', label: 'Upgrade Plan' },
                    { href: 'utilities/modals/general/share-earn.html', label: 'Share & Earn' },
                    { href: 'utilities/modals/forms/new-target.html', label: 'New Target' },
                    { href: 'utilities/modals/forms/new-card.html', label: 'New Card' },
                    { href: 'utilities/modals/forms/new-address.html', label: 'New Address' },
                    { href: 'utilities/modals/forms/create-api-key.html', label: 'Create API Key' },
                    { href: 'utilities/modals/forms/bidding.html', label: 'Bidding' },
                ],
            },
        ],
    },
    {
        sections: [
            {
                title: 'Advanced Modals',
                links: [
                    { href: 'utilities/modals/wizards/create-app.html', label: 'Create App' },
                    { href: 'utilities/modals/wizards/create-campaign.html', label: 'Create Campaign' },
                    { href: 'utilities/modals/wizards/create-account.html', label: 'Create Business Acc' },
                    { href: 'utilities/modals/wizards/create-project.html', label: 'Create Project' },
                    { href: 'utilities/modals/wizards/top-up-wallet.html', label: 'Top Up Wallet' },
                    { href: 'utilities/modals/wizards/offer-a-deal.html', label: 'Offer a Deal' },
                    { href: 'utilities/modals/wizards/two-factor-authentication.html', label: 'Two Factor Auth' },
                ],
            },
            {
                title: 'Search',
                links: [
                    { href: 'utilities/search/horizontal.html', label: 'Horizontal' },
                    { href: 'utilities/search/vertical.html', label: 'Vertical' },
                    { href: 'utilities/modals/search/users.html', label: 'Users' },
                    { href: 'utilities/modals/search/select-location.html', label: 'Select Location' },
                ],
            },
        ],
    },
    {
        sections: [
            {
                title: 'Wizards',
                links: [
                    { href: 'utilities/wizards/horizontal.html', label: 'Horizontal' },
                    { href: 'utilities/wizards/vertical.html', label: 'Vertical' },
                    { href: 'utilities/wizards/two-factor-authentication.html', label: 'Two Factor Auth' },
                    { href: 'utilities/wizards/create-app.html', label: 'Create App' },
                    { href: 'utilities/wizards/create-campaign.html', label: 'Create Campaign' },
                    { href: 'utilities/wizards/create-account.html', label: 'Create Account' },
                    { href: 'utilities/wizards/create-project.html', label: 'Create Project' },
                    { href: 'utilities/modals/wizards/top-up-wallet.html', label: 'Top Up Wallet' },
                    { href: 'utilities/wizards/offer-a-deal.html', label: 'Offer a Deal' },
                ],
            },
        ],
    },
]

const widgetLinks = [
    { href: 'widgets/lists.html', label: 'Lists' },
    { href: 'widgets/statistics.html', label: 'Statistics' },
    { href: 'widgets/charts.html', label: 'Charts' },
    { href: 'widgets/mixed.html', label: 'Mixed' },
    { href: 'widgets/tables.html', label: 'Tables' },
    { href: 'widgets/feeds.html', label: 'Feeds' },
]
</script>
