<template>
    <div>
        <!-- Projects -->
        <!-- <div
            data-kt-menu-trigger="{default:'click', lg: 'hover'}"
            data-kt-menu-placement="right-start"
            class="menu-item menu-lg-down-accordion"
        >
            <span class="menu-link">
                <span class="menu-icon">
                    <i class="ki-duotone ki-rocket fs-2"><span class="path1"></span><span class="path2"></span></i>
                </span>
                <span class="menu-title">Projects</span>
                <span class="menu-arrow"></span>
            </span>
            <div class="menu-sub menu-sub-lg-down-accordion menu-sub-lg-dropdown menu-active-bg px-lg-2 py-lg-4 w-lg-225px">
                <div v-for="link in projectLinks" :key="link.href" class="menu-item">
                    <a class="menu-link" :href="link.href">
                        <span class="menu-bullet"><span class="bullet bullet-dot"></span></span>
                        <span class="menu-title">{{ link.label }}</span>
                    </a>
                </div>
            </div>
        </div> -->

        <!-- eCommerce -->
        <!-- <div
            data-kt-menu-trigger="{default:'click', lg: 'hover'}"
            data-kt-menu-placement="right-start"
            class="menu-item menu-lg-down-accordion"
        >
            <span class="menu-link">
                <span class="menu-icon"><i class="ki-duotone ki-handcart fs-2"></i></span>
                <span class="menu-title">eCommerce</span>
                <span class="menu-arrow"></span>
            </span>
            <div class="menu-sub menu-sub-lg-down-accordion menu-sub-lg-dropdown menu-active-bg px-lg-2 py-lg-4 w-lg-225px">
                <div data-kt-menu-trigger="{default:'click', lg: 'hover'}" data-kt-menu-placement="right-start" class="menu-item menu-lg-down-accordion">
                    <span class="menu-link">
                        <span class="menu-bullet"><span class="bullet bullet-dot"></span></span>
                        <span class="menu-title">Catalog</span>
                        <span class="menu-arrow"></span>
                    </span>
                    <div class="menu-sub menu-sub-lg-down-accordion menu-sub-lg-dropdown menu-active-bg px-lg-2 py-lg-4 w-lg-225px">
                        <div v-for="link in catalogLinks" :key="link.href" class="menu-item">
                            <a class="menu-link" :href="link.href">
                                <span class="menu-bullet"><span class="bullet bullet-dot"></span></span>
                                <span class="menu-title">{{ link.label }}</span>
                            </a>
                        </div>
                    </div>
                </div>
                <div data-kt-menu-trigger="click" class="menu-item menu-accordion menu-sub-indention">
                    <span class="menu-link">
                        <span class="menu-bullet"><span class="bullet bullet-dot"></span></span>
                        <span class="menu-title">Sales</span>
                        <span class="menu-arrow"></span>
                    </span>
                    <div class="menu-sub menu-sub-accordion">
                        <div v-for="link in salesLinks" :key="link.href" class="menu-item">
                            <a class="menu-link" :href="link.href">
                                <span class="menu-bullet"><span class="bullet bullet-dot"></span></span>
                                <span class="menu-title">{{ link.label }}</span>
                            </a>
                        </div>
                    </div>
                </div>
                <div data-kt-menu-trigger="click" class="menu-item menu-accordion menu-sub-indention">
                    <span class="menu-link">
                        <span class="menu-bullet"><span class="bullet bullet-dot"></span></span>
                        <span class="menu-title">Customers</span>
                        <span class="menu-arrow"></span>
                    </span>
                    <div class="menu-sub menu-sub-accordion">
                        <div v-for="link in ecomCustomerLinks" :key="link.href" class="menu-item">
                            <a class="menu-link" :href="link.href">
                                <span class="menu-bullet"><span class="bullet bullet-dot"></span></span>
                                <span class="menu-title">{{ link.label }}</span>
                            </a>
                        </div>
                    </div>
                </div>
                <div data-kt-menu-trigger="click" class="menu-item menu-accordion menu-sub-indention">
                    <span class="menu-link">
                        <span class="menu-bullet"><span class="bullet bullet-dot"></span></span>
                        <span class="menu-title">Reports</span>
                        <span class="menu-arrow"></span>
                    </span>
                    <div class="menu-sub menu-sub-accordion">
                        <div v-for="link in ecomReportLinks" :key="link.href" class="menu-item">
                            <a class="menu-link" :href="link.href">
                                <span class="menu-bullet"><span class="bullet bullet-dot"></span></span>
                                <span class="menu-title">{{ link.label }}</span>
                            </a>
                        </div>
                    </div>
                </div>
                <div class="menu-item">
                    <a class="menu-link" href="apps/ecommerce/settings.html">
                        <span class="menu-bullet"><span class="bullet bullet-dot"></span></span>
                        <span class="menu-title">Settings</span>
                    </a>
                </div>
            </div>
        </div> -->

        <!-- Support Center -->
        <!-- <AppSubMenu
            icon="ki-chart"
            :paths="2"
            title="Support Center"
            :links="supportLinks"
        /> -->

        <!-- Configurações -->
        <div
            data-kt-menu-trigger="{default:'click', lg: 'hover'}"
            data-kt-menu-placement="right-start"
            class="menu-item menu-lg-down-accordion"
        >
            <span class="menu-link">
                <span class="menu-icon">
                    <i class="ki-duotone ki-shield-tick fs-2">
                        <span class="path1"></span><span class="path2"></span>
                    </i>
                </span>
                <span class="menu-title">Configurações</span>
                <span class="menu-arrow"></span>
            </span>
            <div class="menu-sub menu-sub-lg-down-accordion menu-sub-lg-dropdown menu-active-bg px-lg-2 py-lg-4 w-lg-225px">
                <div
                    v-for="group in gruposVisiveis"
                    :key="group.title"
                    data-kt-menu-trigger="click"
                    class="menu-item menu-accordion menu-sub-indention"
                >
                    <span class="menu-link">
                        <span class="menu-bullet"><span class="bullet bullet-dot"></span></span>
                        <span class="menu-title">{{ group.title }}</span>
                        <span class="menu-arrow"></span>
                    </span>
                    <div class="menu-sub menu-sub-accordion">
                        <div v-for="link in group.links" :key="link.href" class="menu-item">
                            <a class="menu-link" :href="link.href">
                                <span class="menu-bullet"><span class="bullet bullet-dot"></span></span>
                                <span class="menu-title">{{ link.label }}</span>
                            </a>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Contacts -->
        <!-- <AppSubMenu
            icon="ki-phone"
            :paths="2"
            title="Contacts"
            :links="contactLinks"
        /> -->

        <!-- Subscriptions -->
        <!-- <AppSubMenu
            icon="ki-basket"
            :paths="4"
            title="Subscriptions"
            :links="subscriptionLinks"
        /> -->

        <!-- Customers -->
        <!-- <AppSubMenu
            icon="ki-briefcase"
            :paths="2"
            title="Customers"
            :links="customerLinks"
        /> -->

        <!-- Invoice Management -->
        <!-- <AppSubMenu
            icon="ki-credit-cart"
            :paths="2"
            title="Invoice Management"
            :links="invoiceLinks"
        /> -->

        <!-- File Manager -->
        <!-- <AppSubMenu
            icon="ki-file-added"
            :paths="2"
            title="File Manager"
            :links="fileManagerLinks"
        /> -->

        <!-- Inbox -->
        <!-- <AppSubMenu
            icon="ki-sms"
            :paths="2"
            title="Inbox"
            :links="inboxLinks"
        /> -->

        <!-- Chat -->
        <!-- <AppSubMenu
            icon="ki-message-text-2"
            :paths="3"
            title="Chat"
            :links="chatLinks"
        /> -->

        <!-- Calendar -->
        <!-- <div class="menu-item">
            <a class="menu-link" href="apps/calendar.html">
                <span class="menu-icon">
                    <i class="ki-duotone ki-calendar-8 fs-2">
                        <span v-for="n in 6" :key="n" :class="`path${n}`"></span>
                    </i>
                </span>
                <span class="menu-title">Calendar</span>
            </a>
        </div> -->
    </div>
</template>

<script setup>
import { computed } from 'vue'
import { podeVer } from '@/Composables/usePermissoes'
// import AppSubMenu from './AppSubMenu.vue'

// const projectLinks = [
//     { href: 'apps/projects/list.html', label: 'My Projects' },
//     { href: 'apps/projects/project.html', label: 'View Project' },
//     { href: 'apps/projects/targets.html', label: 'Targets' },
//     { href: 'apps/projects/budget.html', label: 'Budget' },
//     { href: 'apps/projects/users.html', label: 'Users' },
//     { href: 'apps/projects/files.html', label: 'Files' },
//     { href: 'apps/projects/activity.html', label: 'Activity' },
//     { href: 'apps/projects/settings.html', label: 'Settings' },
// ]

// const catalogLinks = [
//     { href: 'apps/ecommerce/catalog/products.html', label: 'Products' },
//     { href: 'apps/ecommerce/catalog/categories.html', label: 'Categories' },
//     { href: 'apps/ecommerce/catalog/add-product.html', label: 'Add Product' },
//     { href: 'apps/ecommerce/catalog/edit-product.html', label: 'Edit Product' },
//     { href: 'apps/ecommerce/catalog/add-category.html', label: 'Add Category' },
//     { href: 'apps/ecommerce/catalog/edit-category.html', label: 'Edit Category' },
// ]

// const salesLinks = [
//     { href: 'apps/ecommerce/sales/listing.html', label: 'Orders Listing' },
//     { href: 'apps/ecommerce/sales/details.html', label: 'Order Details' },
//     { href: 'apps/ecommerce/sales/add-order.html', label: 'Add Order' },
//     { href: 'apps/ecommerce/sales/edit-order.html', label: 'Edit Order' },
// ]

// const ecomCustomerLinks = [
//     { href: 'apps/ecommerce/customers/listing.html', label: 'Customers Listing' },
//     { href: 'apps/ecommerce/customers/details.html', label: 'Customers Details' },
// ]

// const ecomReportLinks = [
//     { href: 'apps/ecommerce/reports/view.html', label: 'Products Viewed' },
//     { href: 'apps/ecommerce/reports/sales.html', label: 'Sales' },
//     { href: 'apps/ecommerce/reports/returns.html', label: 'Returns' },
//     { href: 'apps/ecommerce/reports/customer-orders.html', label: 'Customer Orders' },
//     { href: 'apps/ecommerce/reports/shipping.html', label: 'Shipping' },
// ]

// const supportLinks = [
//     { href: 'apps/support-center/overview.html', label: 'Overview' },
//     { href: 'apps/support-center/tickets/list.html', label: 'Ticket List' },
//     { href: 'apps/support-center/tickets/view.html', label: 'Ticket View' },
//     { href: 'apps/support-center/tutorials/list.html', label: 'Tutorials List' },
//     { href: 'apps/support-center/tutorials/post.html', label: 'Tutorials Post' },
//     { href: 'apps/support-center/faq.html', label: 'FAQ' },
//     { href: 'apps/support-center/licenses.html', label: 'Licenses' },
//     { href: 'apps/support-center/contact.html', label: 'Contact Us' },
// ]

const userManagementGroups = [
    {
        title: 'Gestão de utilizadores',
        links: [
            { href: '/usuarios', label: 'Todos os Utilizadores', permissao: 'usuario.ver' },
            { href: '/usuarios/alunos', label: 'Alunos', permissao: 'usuario.ver' },
            { href: '/usuarios/professores', label: 'Professores', permissao: 'usuario.ver' },
            { href: '/usuarios/funcionarios', label: 'Funcionários', permissao: 'usuario.ver' },
            { href: '/usuarios/administradores', label: 'Administradores', permissao: 'autorizacao.ver' },
            { href: '/usuarios/encarregados', label: 'Encarregados', permissao: 'usuario.ver' },
        ],
    },
    {
        title: 'Perfis',
        links: [
            { href: '/permissoes/perfis', label: 'Perfis e Permissões', permissao: 'autorizacao.ver' },
        ],
    },
    {
        title: 'Estabelecimento',
        links: [
            { href: '/estabelecimento', label: 'Dados da Escola', permissao: 'estabelecimento.ver' },
            { href: '/estabelecimento/aparencia', label: 'Logótipo & Aparência', permissao: 'estabelecimento.ver' },
        ],
    },
    {
        title: 'Ano Lectivo',
        links: [
            { href: '/ano-lectivos', label: 'Anos Lectivos', permissao: 'ano-lectivo.ver' },
        ],
    },
]

// Esconde o grupo todo quando nenhum dos seus links sobra depois do filtro
// (ex: Funcionário sem autorizacao.ver não vê o grupo "Perfis" de todo).
const gruposVisiveis = computed(() =>
    userManagementGroups
        .map((group) => ({ ...group, links: group.links.filter(podeVer) }))
        .filter((group) => group.links.length > 0)
)

// const contactLinks = [
//     { href: 'apps/contacts/getting-started.html', label: 'Getting Started' },
//     { href: 'apps/contacts/add-contact.html', label: 'Add Contact' },
//     { href: 'apps/contacts/edit-contact.html', label: 'Edit Contact' },
//     { href: 'apps/contacts/view-contact.html', label: 'View Contact' },
// ]

// const subscriptionLinks = [
//     { href: 'apps/subscriptions/getting-started.html', label: 'Getting Started' },
//     { href: 'apps/subscriptions/list.html', label: 'Subscription List' },
//     { href: 'apps/subscriptions/add.html', label: 'Add Subscription' },
//     { href: 'apps/subscriptions/view.html', label: 'View Subscription' },
// ]

// const customerLinks = [
//     { href: 'apps/customers/getting-started.html', label: 'Getting Started' },
//     { href: 'apps/customers/list.html', label: 'Customer Listing' },
//     { href: 'apps/customers/view.html', label: 'Customer Details' },
// ]

// const invoiceLinks = [
//     { href: 'apps/invoices/view/invoice-1.html', label: 'Invoice 1' },
//     { href: 'apps/invoices/view/invoice-2.html', label: 'Invoice 2' },
//     { href: 'apps/invoices/view/invoice-3.html', label: 'Invoice 3' },
//     { href: 'apps/invoices/create.html', label: 'Create Invoice' },
// ]

// const fileManagerLinks = [
//     { href: 'apps/file-manager/folders.html', label: 'Folders' },
//     { href: 'apps/file-manager/files.html', label: 'Files' },
//     { href: 'apps/file-manager/blank.html', label: 'Blank Directory' },
//     { href: 'apps/file-manager/settings.html', label: 'Settings' },
// ]

// const inboxLinks = [
//     { href: 'apps/inbox/listing.html', label: 'Messages' },
//     { href: 'apps/inbox/compose.html', label: 'Compose' },
//     { href: 'apps/inbox/reply.html', label: 'View & Reply' },
// ]

// const chatLinks = [
//     { href: 'apps/chat/private.html', label: 'Private Chat' },
//     { href: 'apps/chat/group.html', label: 'Group Chat' },
//     { href: 'apps/chat/drawer.html', label: 'Drawer Chat' },
// ]
</script>
