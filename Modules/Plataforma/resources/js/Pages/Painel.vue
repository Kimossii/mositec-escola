<script setup>
import { Link } from '@inertiajs/vue3';
import LayoutPlataforma from '../Components/LayoutPlataforma.vue';
import { formatarDataHora } from '../Composables/formatarData';

defineProps({
    contagem: { type: Object, required: true }, // { 1: activas, 2: suspensas, 3: encerradas }
    actividade: { type: Array, default: () => [] },
});
defineOptions({ layout: LayoutPlataforma });

// Estados de EstadoTenant (1 Activa, 2 Suspensa, 3 Encerrada); o filtro da lista usa o mesmo valor.
const CARTOES = [
    { estado: 1, rotulo: 'Activas', cor: 'success' },
    { estado: 2, rotulo: 'Suspensas', cor: 'warning' },
    { estado: 3, rotulo: 'Encerradas', cor: 'secondary' },
];
</script>

<template>
    <div>
        <div class="d-flex justify-content-between align-items-center mb-6">
            <h1 class="fs-2 fw-bold mb-0">Painel</h1>
            <Link href="/plataforma/escolas/nova" class="btn btn-primary">Nova escola</Link>
        </div>

        <div class="row g-5 mb-6">
            <div v-for="c in CARTOES" :key="c.estado" class="col-12 col-md-4">
                <Link :href="`/plataforma/escolas?estado=${c.estado}`" class="card h-100 text-decoration-none">
                    <div class="card-body d-flex align-items-center justify-content-between">
                        <div>
                            <div class="text-muted fs-7 fw-semibold text-uppercase">{{ c.rotulo }}</div>
                            <div class="fs-2hx fw-bold text-gray-900">{{ contagem[c.estado] ?? 0 }}</div>
                        </div>
                        <span class="badge" :class="`badge-light-${c.cor}`">{{ (contagem[c.estado] ?? 0) === 1 ? 'escola' : 'escolas' }}</span>
                    </div>
                </Link>
            </div>
        </div>

        <div class="card">
            <div class="card-header"><h3 class="card-title">Actividade recente</h3></div>
            <div class="card-body p-0">
                <table class="table align-middle table-row-dashed fs-6 gy-4 mb-0">
                    <thead>
                        <tr class="text-start text-muted fw-bold fs-7 text-uppercase gs-0">
                            <th class="min-w-150px">Quando</th>
                            <th class="min-w-150px">Acção</th>
                            <th class="min-w-125px">Escola</th>
                            <th class="min-w-150px">Operador</th>
                        </tr>
                    </thead>
                    <tbody class="text-gray-600 fw-semibold">
                        <tr v-if="actividade.length === 0">
                            <td colspan="4" class="text-center text-muted py-6">Sem actividade registada.</td>
                        </tr>
                        <tr v-for="registo in actividade" :key="registo.id">
                            <td>{{ formatarDataHora(registo.created_at) }}</td>
                            <td>{{ registo.accao }}</td>
                            <td>
                                <Link v-if="registo.escola" :href="`/plataforma/escolas/${registo.escola}`" class="text-gray-800 text-hover-primary fw-bold">{{ registo.escola }}</Link>
                                <span v-else>—</span>
                            </td>
                            <td>{{ registo.autor ?? '—' }}</td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</template>
