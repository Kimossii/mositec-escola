<script setup>
import { ref } from 'vue';
import { router } from '@inertiajs/vue3';
import LayoutPlataforma from '../Components/LayoutPlataforma.vue';
import { formatarDataHora } from '../Composables/formatarData';
import EstadoVazio from '@/Components/Shared/EstadoVazio.vue';
import Loader from '@/Components/Shared/Loader.vue';

defineProps({
    saude: { type: Object, required: true }, // { executado_em, verificacoes: [{ nome, rotulo, estado, resumo, mensagem }] }
    backups: { type: Array, default: () => [] },
    filas: { type: Object, required: true }, // { modo, filas: [{ nome, em_espera, minutos, parada }], falhados }
});
defineOptions({ layout: LayoutPlataforma });

const aVerificar = ref(false);

// Estados do spatie/laravel-health (ok, warning, failed, crashed, skipped).
const ESTADOS = {
    ok: { texto: 'OK', classe: 'badge-light-success' },
    warning: { texto: 'Aviso', classe: 'badge-light-warning' },
    failed: { texto: 'Falhou', classe: 'badge-light-danger' },
    crashed: { texto: 'Erro', classe: 'badge-light-danger' },
    skipped: { texto: 'Ignorada', classe: 'badge-light-info' },
};
const estado = (e) => ESTADOS[e] ?? { texto: e || '—', classe: 'badge-light' };

function verificar() {
    aVerificar.value = true;
    router.post('/plataforma/sistema/verificar', {}, { preserveScroll: true, onFinish: () => { aVerificar.value = false; } });
}
</script>

<template>
    <div>
        <div class="d-flex justify-content-between align-items-center flex-wrap gap-3 mb-6">
            <div>
                <h1 class="fs-2 fw-bold mb-1">Sistema</h1>
                <div class="text-muted fs-7">Saúde, backups e filas. Dados de infraestrutura, comuns a todas as escolas.</div>
            </div>
            <button type="button" class="btn btn-primary" :disabled="aVerificar" @click="verificar">
                <span v-if="!aVerificar">Verificar agora</span>
                <span v-else>A verificar... <Loader size="0.3px" class="align-middle ms-2" /></span>
            </button>
        </div>

        <div class="card mb-6">
            <div class="card-header">
                <h3 class="card-title">Saúde</h3>
                <div class="card-toolbar text-muted fs-7">Última verificação: {{ formatarDataHora(saude.executado_em) }}</div>
            </div>
            <div class="card-body p-0">
                <table class="table align-middle table-row-dashed fs-6 gy-4 mb-0">
                    <thead>
                        <tr class="text-start text-muted fw-bold fs-7 text-uppercase gs-0">
                            <th>Verificação</th>
                            <th>Estado</th>
                            <th>Detalhe</th>
                        </tr>
                    </thead>
                    <tbody class="text-gray-600 fw-semibold">
                        <tr v-if="saude.verificacoes.length === 0">
                            <td colspan="3"><EstadoVazio titulo="Ainda não há resultados. Use «Verificar agora»." /></td>
                        </tr>
                        <tr v-for="v in saude.verificacoes" :key="v.nome">
                            <td class="text-gray-800 fw-bold">{{ v.rotulo }}</td>
                            <td><span class="badge" :class="estado(v.estado).classe">{{ estado(v.estado).texto }}</span></td>
                            <td>{{ v.mensagem ?? v.resumo }}</td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>

        <div class="card mb-6">
            <div class="card-header"><h3 class="card-title">Backups</h3></div>
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table align-middle table-row-dashed fs-6 gy-4 mb-0">
                        <thead>
                            <tr class="text-start text-muted fw-bold fs-7 text-uppercase gs-0">
                                <th>Disco</th>
                                <th>Estado</th>
                                <th>Cópias</th>
                                <th>Mais recente</th>
                                <th>Espaço</th>
                            </tr>
                        </thead>
                        <tbody class="text-gray-600 fw-semibold">
                            <tr v-if="backups.length === 0">
                                <td colspan="5"><EstadoVazio titulo="Sem destinos de backup configurados." /></td>
                            </tr>
                            <tr v-for="b in backups" :key="b.disco">
                                <td class="text-gray-800 fw-bold">{{ b.disco }}</td>
                                <td>
                                    <span v-if="!b.acessivel" class="badge badge-light-danger">Inacessível</span>
                                    <span v-else-if="!b.saudavel" class="badge badge-light-danger" :title="b.problemas.join(' ')">Desactualizado</span>
                                    <span v-else class="badge badge-light-success">Saudável</span>
                                </td>
                                <td>{{ b.quantidade }}</td>
                                <td>{{ formatarDataHora(b.ultimo) }}</td>
                                <td>{{ b.espaco }}</td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <div class="card">
            <div class="card-header"><h3 class="card-title">Filas</h3></div>
            <div class="card-body">
                <div v-if="filas.modo !== 'database'" class="text-muted">
                    Fila em modo «{{ filas.modo }}»: {{ filas.modo === 'sync' ? 'os trabalhos correm no próprio pedido, não é preciso worker.' : 'verifique o estado no próprio serviço.' }}
                </div>
                <template v-else>
                    <div class="row g-5">
                        <div v-for="f in filas.filas" :key="f.nome" class="col-12 col-md-4">
                            <div class="border border-dashed rounded p-4 h-100">
                                <div class="text-muted fs-7 fw-semibold text-uppercase">{{ f.nome }}</div>
                                <div class="fs-2 fw-bold text-gray-900">{{ f.em_espera }} <span class="fs-7 text-muted fw-semibold">em espera</span></div>
                                <span v-if="f.parada" class="badge badge-light-danger">Parada há {{ f.minutos }} min: verifique o worker</span>
                                <span v-else class="badge badge-light-success">A processar</span>
                            </div>
                        </div>
                        <div class="col-12 col-md-4">
                            <div class="border border-dashed rounded p-4 h-100">
                                <div class="text-muted fs-7 fw-semibold text-uppercase">Falhados</div>
                                <div class="fs-2 fw-bold" :class="filas.falhados > 0 ? 'text-danger' : 'text-gray-900'">{{ filas.falhados }}</div>
                            </div>
                        </div>
                    </div>
                </template>
            </div>
        </div>
    </div>
</template>
