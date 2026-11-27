<script setup>
import { reactive } from 'vue';
import { Link, router } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import ListaComVistas from '@/Components/Shared/ListaComVistas.vue';

const props = defineProps({
    registos: { type: Object, required: true }, // paginador: { data: [{ id, quando, notificacao, categoria, estado, estado_descricao, canal_descricao, destinatario, erro }], links, total }
    filtros: { type: Object, required: true }, // { estado, categoria, de, ate }
    resumo: { type: Object, required: true }, // { dias, enviados, falhas, descartados }
});
defineOptions({ layout: AppLayout });

// Estados de EstadoEnvioEmail: 0 enviado, 1 falhou, 2 descartado (limite), 3 descartado (contrato).
const ESTADOS = [
    { valor: '0', rotulo: 'Enviado' },
    { valor: '1', rotulo: 'Falhou' },
    { valor: '2', rotulo: 'Descartado (limite por hora)' },
    { valor: '3', rotulo: 'Descartado (módulo não contratado)' },
];
const corDoEstado = (estado) => (estado === 0 ? 'success' : estado === 1 ? 'danger' : 'warning');

const form = reactive({
    estado: props.filtros.estado ?? '',
    categoria: props.filtros.categoria ?? '',
    de: props.filtros.de ?? '',
    ate: props.filtros.ate ?? '',
});

function filtrar() {
    router.get('/envios-email', Object.fromEntries(Object.entries(form).filter(([, v]) => v !== '')), { preserveState: true, replace: true });
}

function formatar(quando) {
    return quando ? quando.slice(8, 10) + '/' + quando.slice(5, 7) + '/' + quando.slice(0, 4) + ' ' + quando.slice(11, 16) : '—';
}
</script>

<template>
    <div class="app-container container-fluid py-6">
        <div class="mb-6">
            <h1 class="fs-2 fw-bold mb-1">Envios de email</h1>
            <div class="text-muted">Histórico dos emails enviados pelo sistema · {{ registos.total }} {{ registos.total === 1 ? 'registo' : 'registos' }}</div>
        </div>

        <div v-if="resumo.falhas > 0" class="alert alert-danger d-flex align-items-center mb-6" role="alert">
            <span>
                <strong>{{ resumo.falhas }} {{ resumo.falhas === 1 ? 'email falhou' : 'emails falharam' }}</strong>
                nos últimos {{ resumo.dias }} dias. Quem não recebeu pode pedir ao administrador a redefinição manual.
            </span>
        </div>
        <div v-else class="text-muted fs-7 mb-6">
            Últimos {{ resumo.dias }} dias: {{ resumo.enviados }} enviados, sem falhas<span v-if="resumo.descartados"> · {{ resumo.descartados }} descartados</span>.
        </div>

        <ListaComVistas chave="envios-email" prefixo="/envios-email">
            <div class="card card-flush">
                <div class="card-header pt-6">
                    <div class="d-flex flex-wrap gap-3 w-100">
                        <select v-model="form.estado" class="form-select form-select-solid w-225px" @change="filtrar()">
                            <option value="">Todos os estados</option>
                            <option v-for="e in ESTADOS" :key="e.valor" :value="e.valor">{{ e.rotulo }}</option>
                        </select>
                        <select v-model="form.categoria" class="form-select form-select-solid w-200px" @change="filtrar()">
                            <option value="">Todas as categorias</option>
                            <option value="essencial">Essencial</option>
                            <option value="opcional">Opcional</option>
                        </select>
                        <input v-model="form.de" type="date" class="form-control form-control-solid w-175px" @change="filtrar()" />
                        <input v-model="form.ate" type="date" class="form-control form-control-solid w-175px" @change="filtrar()" />
                    </div>
                </div>
                <div class="card-body py-4">
                    <div class="table-responsive">
                        <table class="table align-middle table-row-dashed fs-6 gy-4 mb-0">
                            <thead>
                                <tr class="text-start text-muted fw-bold fs-7 text-uppercase">
                                    <th>Quando</th>
                                    <th>Notificação</th>
                                    <th>Categoria</th>
                                    <th>Destinatário</th>
                                    <th>Canal</th>
                                    <th>Estado</th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr v-for="r in registos.data" :key="r.id">
                                    <td class="text-nowrap">{{ formatar(r.quando) }}</td>
                                    <td>{{ r.notificacao }}</td>
                                    <td class="text-capitalize">{{ r.categoria }}</td>
                                    <td>{{ r.destinatario ?? '—' }}</td>
                                    <td>{{ r.canal_descricao }}</td>
                                    <td>
                                        <span class="badge" :class="`badge-light-${corDoEstado(r.estado)}`">{{ r.estado_descricao }}</span>
                                        <div v-if="r.erro" class="text-danger fs-7 mt-1 text-break">{{ r.erro }}</div>
                                    </td>
                                </tr>
                                <tr v-if="!registos.data.length">
                                    <td colspan="6" class="text-center text-muted py-10">Nenhum envio registado.</td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                    <div v-if="registos.last_page > 1" class="d-flex flex-wrap gap-2 justify-content-end mt-5">
                        <template v-for="(l, i) in registos.links" :key="i">
                            <Link v-if="l.url" :href="l.url" class="btn btn-sm" :class="l.active ? 'btn-primary' : 'btn-light'" preserve-scroll v-html="l.label" />
                            <span v-else class="btn btn-sm btn-light disabled" v-html="l.label" />
                        </template>
                    </div>
                </div>
            </div>
        </ListaComVistas>
    </div>
</template>
