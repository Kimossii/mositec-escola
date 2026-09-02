<template>
    <div class="menu-active-bg px-4 px-lg-0">
        <div class="tab-content py-4 py-lg-8 px-lg-7">
            <div class="tab-pane active" id="kt_app_header_menu_pages_pages">
                <div class="d-flex">
                    <div v-for="section in generalSections" :key="section.title" class="me-10">
                        <h4 class="fs-6 fs-lg-4 fw-bold mb-3">
                            {{ section.title }}
                        </h4>

                        <div v-for="link in section.links" :key="link.href" class="menu-item p-0 m-0">
                            <a :href="link.href" class="menu-link">
                                <span class="menu-title">
                                    {{ link.label }}
                                </span>
                            </a>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</template>

<script setup>
const generalSections = [
    {
        title: 'Pedagógico',
        links: [
            { href: '#', label: 'Disciplinas' },
            { href: '/horarios', label: 'Horários' },
            { href: '#', label: 'Planos de Aula' },
            { href: '#', label: 'Avaliações / Notas' },
            { href: '#', label: 'Pautas' },
            { href: '#', label: 'Conselho de Turma' },
            { href: '#', label: 'Exames / Recuperações' },
        ],
    },
]
</script>

