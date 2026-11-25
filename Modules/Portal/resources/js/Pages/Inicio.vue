<script setup>
import { computed } from 'vue';
import { Link, router } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import EstadoVazio from '@/Components/Shared/EstadoVazio.vue';
import { moduloContratado } from '@/Composables/usePermissoes';

/** Início do portal do encarregado: escolher o educando e ver a sua página. O que cada educando mostra vem do perfil Aluno (`acessos`). */
const props = defineProps({
    educandos: { type: Array, required: true },
    educando: { type: Object, default: null },
    acessos: { type: Array, default: () => [] },
});
defineOptions({ layout: AppLayout });

const trocar = (event) => router.visit(`/portal/${event.target.value}`);

// As páginas do educando são as que o perfil Aluno tem abertas (e que a escola contratou).
const paginas = computed(() => [
    { titulo: 'Notas', descricao: 'Resultados finais e boletim', href: 'notas', mostrar: props.acessos.includes('nota.ver-proprias') && moduloContratado('avaliacao') },
    { titulo: 'Faltas', descricao: 'Total, justificadas e por disciplina', href: 'faltas', mostrar: props.acessos.includes('frequencia.ver-proprias') },
].filter((p) => p.mostrar));
</script>

<template>
    <div class="app-container container-xxl py-6">
        <div v-if="!educandos.length" class="card">
            <div class="card-body"><EstadoVazio titulo="Sem educandos ligados" descricao="A escola ainda não ligou nenhum aluno à sua conta." /></div>
        </div>

        <template v-else-if="!educando">
            <h1 class="fs-2 fw-bold mb-6">Escolha o educando</h1>
            <div class="row g-4">
                <div v-for="e in educandos" :key="e.id" class="col-12 col-md-6 col-xl-4">
                    <Link :href="`/portal/${e.id}`" class="card h-100 text-decoration-none">
                        <div class="card-body">
                            <div class="fs-4 fw-bold text-gray-900">{{ e.nome }}</div>
                            <div v-if="e.numero_matricula" class="text-muted fs-7 mt-1">Matrícula {{ e.numero_matricula }}</div>
                            <div v-if="e.parentesco" class="text-muted fs-7">{{ e.parentesco }}</div>
                        </div>
                    </Link>
                </div>
            </div>
        </template>

        <template v-else>
            <div class="d-flex flex-wrap align-items-center justify-content-between gap-3 mb-6">
                <div>
                    <h1 class="fs-2 fw-bold mb-1">{{ educando.nome }}</h1>
                    <div class="text-muted fs-7">
                        <span v-if="educando.numero_matricula">Matrícula {{ educando.numero_matricula }}</span>
                        <span v-if="educando.numero_matricula && educando.parentesco"> · </span>
                        <span v-if="educando.parentesco">{{ educando.parentesco }}</span>
                    </div>
                </div>
                <select v-if="educandos.length > 1" class="form-select form-select-sm w-100 w-md-250px" :value="educando.id" @change="trocar">
                    <option v-for="e in educandos" :key="e.id" :value="e.id">{{ e.nome }}</option>
                </select>
            </div>

            <div v-if="paginas.length" class="row g-4">
                <div v-for="p in paginas" :key="p.href" class="col-12 col-md-6 col-xl-4">
                    <Link :href="`/portal/${educando.id}/${p.href}`" class="card h-100 text-decoration-none">
                        <div class="card-body">
                            <div class="fs-4 fw-bold text-gray-900">{{ p.titulo }}</div>
                            <div class="text-muted fs-7 mt-1">{{ p.descricao }}</div>
                        </div>
                    </Link>
                </div>
            </div>
            <div v-else class="card">
                <div class="card-body">
                    <div class="rounded bg-body-secondary p-4 text-muted fs-7">
                        As informações do seu educando vão aparecendo aqui à medida que a escola as disponibiliza.
                    </div>
                </div>
            </div>
        </template>
    </div>
</template>
