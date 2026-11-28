<script setup>
import { Link } from '@inertiajs/vue3';
import LayoutPlataforma from '../Components/LayoutPlataforma.vue';
import { formatarDataHora } from '../Composables/formatarData';
import EstadoVazio from '@/Components/Shared/EstadoVazio.vue';

defineProps({
    contagem: { type: Object, required: true }, // { 1: activas, 2: suspensas, 3: encerradas }
    actividade: { type: Array, default: () => [] },
    emailInfra: { type: Array, default: () => [] }, // escolas com servidor próprio: [{ escola, escola_nome, estado, estado_descricao, activo, bloqueada, ultimo_erro }]; nunca servidor nem credenciais
    emailVolume: { type: Array, default: () => [] }, // escolas que mais enviaram nas últimas 24 h: [{ escola, escola_nome, enviados_24h, opcionais_mes, canal_proprio }]
    emailFalhas: { type: Object, default: () => ({ total: 0, dias: 7, recentes: [] }) }, // { total, dias, recentes: [{ id, escola, escola_nome, notificacao, canal, erro, created_at }] }
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

        <div class="card mb-6">
            <div class="card-header">
                <h3 class="card-title">Falhas de email</h3>
                <div class="card-toolbar">
                    <span class="badge" :class="emailFalhas.total > 0 ? 'badge-light-danger' : 'badge-light-success'">
                        {{ emailFalhas.total }} {{ emailFalhas.total === 1 ? 'falha' : 'falhas' }} nos últimos {{ emailFalhas.dias }} dias
                    </span>
                </div>
            </div>
            <div class="card-body p-0">
                <table class="table align-middle table-row-dashed fs-6 gy-4 mb-0">
                    <thead>
                        <tr class="text-start text-muted fw-bold fs-7 text-uppercase gs-0">
                            <th class="min-w-150px">Quando</th>
                            <th class="min-w-125px">Escola</th>
                            <th class="min-w-150px">Notificação</th>
                            <th class="min-w-125px">Canal</th>
                            <th class="min-w-200px">Erro</th>
                        </tr>
                    </thead>
                    <tbody class="text-gray-600 fw-semibold">
                        <tr v-if="emailFalhas.recentes.length === 0">
                            <td colspan="5"><EstadoVazio titulo="Sem falhas de email registadas." /></td>
                        </tr>
                        <tr v-for="falha in emailFalhas.recentes" :key="falha.id">
                            <td>{{ formatarDataHora(falha.created_at) }}</td>
                            <td>
                                <Link v-if="falha.escola" :href="`/plataforma/escolas/${falha.escola}`" class="text-gray-800 text-hover-primary fw-bold">{{ falha.escola_nome ?? falha.escola }}</Link>
                                <span v-else>—</span>
                            </td>
                            <td>{{ falha.notificacao }}</td>
                            <td>{{ falha.canal }}</td>
                            <td class="text-danger text-break">{{ falha.erro ?? '—' }}</td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>

        <div class="card mb-6">
            <div class="card-header"><h3 class="card-title">Emails enviados nas últimas 24 horas (as 10 escolas com mais envios)</h3></div>
            <div class="card-body p-0">
                <table class="table align-middle table-row-dashed fs-6 gy-4 mb-0">
                    <thead>
                        <tr class="text-start text-muted fw-bold fs-7 text-uppercase gs-0">
                            <th class="min-w-150px">Escola</th>
                            <th>Últimas 24 h</th>
                            <th>Opcionais no mês</th>
                            <th>Sai por</th>
                        </tr>
                    </thead>
                    <tbody class="text-gray-600 fw-semibold">
                        <tr v-if="emailVolume.length === 0">
                            <td colspan="4"><EstadoVazio titulo="Sem envios nas últimas 24 horas." /></td>
                        </tr>
                        <tr v-for="v in emailVolume" :key="v.escola">
                            <td><Link v-if="v.escola" :href="`/plataforma/escolas/${v.escola}`" class="text-gray-800 text-hover-primary fw-bold">{{ v.escola_nome ?? v.escola }}</Link><span v-else>—</span></td>
                            <td>{{ v.enviados_24h }}</td>
                            <td>{{ v.opcionais_mes }}</td>
                            <td>{{ v.canal_proprio ? 'Infra da escola' : 'Central' }}</td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>

        <div class="card mb-6">
            <div class="card-header"><h3 class="card-title">Escolas com servidor ou domínio próprio de email</h3></div>
            <div class="card-body p-0">
                <table class="table align-middle table-row-dashed fs-6 gy-4 mb-0">
                    <thead>
                        <tr class="text-start text-muted fw-bold fs-7 text-uppercase gs-0">
                            <th class="min-w-150px">Escola</th>
                            <th class="min-w-100px">Tipo</th>
                            <th class="min-w-125px">Estado</th>
                            <th class="min-w-125px">A enviar por</th>
                            <th class="min-w-200px">Último erro</th>
                        </tr>
                    </thead>
                    <tbody class="text-gray-600 fw-semibold">
                        <tr v-if="emailInfra.length === 0">
                            <td colspan="5"><EstadoVazio titulo="Nenhuma escola configurou servidor ou domínio próprio." /></td>
                        </tr>
                        <tr v-for="e in emailInfra" :key="`${e.escola}-${e.tipo}`">
                            <td>
                                <Link v-if="e.escola" :href="`/plataforma/escolas/${e.escola}`" class="text-gray-800 text-hover-primary fw-bold">{{ e.escola_nome ?? e.escola }}</Link>
                                <span v-else>—</span>
                            </td>
                            <td>{{ e.tipo }}</td>
                            <td><span class="badge" :class="e.estado === 1 ? 'badge-light-success' : e.estado === 2 ? 'badge-light-danger' : 'badge-light-warning'">{{ e.estado_descricao }}</span></td>
                            <td>{{ e.activo ? (e.tipo === 'Domínio' ? 'Domínio da escola' : 'Servidor da escola') : 'Central' }}<span v-if="e.bloqueada" class="badge badge-light-danger ms-2">Bloqueada</span></td>
                            <td class="text-danger text-break">{{ e.ultimo_erro ?? '—' }}</td>
                        </tr>
                    </tbody>
                </table>
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
                            <td colspan="4"><EstadoVazio titulo="Sem actividade registada." /></td>
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
