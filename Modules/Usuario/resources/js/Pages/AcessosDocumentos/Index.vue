<script setup>
import { reactive } from 'vue';
import { Link, router } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';

const props = defineProps({
    acessos: { type: Object, required: true }, // paginador: { data: [{ id, quando, utilizador, pessoa, documento, acao, acao_codigo, ip }], links, total }
    filtros: { type: Object, required: true }, // { pessoa, utilizador, acao, de, ate }
    acoes: { type: Array, required: true }, // [{ valor, rotulo }]
});
defineOptions({ layout: AppLayout });

const form = reactive({
    pessoa: props.filtros.pessoa ?? '',
    utilizador: props.filtros.utilizador ?? '',
    acao: props.filtros.acao ?? '',
    de: props.filtros.de ?? '',
    ate: props.filtros.ate ?? '',
});

let temporizador = null;
function filtrar(imediato = false) {
    clearTimeout(temporizador);
    const aplicar = () => router.get('/acessos-documentos', Object.fromEntries(Object.entries(form).filter(([, v]) => v !== '')), { preserveState: true, replace: true });
    imediato ? aplicar() : (temporizador = setTimeout(aplicar, 350));
}

function formatar(quando) {
    return quando ? quando.slice(8, 10) + '/' + quando.slice(5, 7) + '/' + quando.slice(0, 4) + ' ' + quando.slice(11, 16) : '—';
}
</script>

<template>
    <div class="app-container container-fluid py-6">
        <div class="mb-6">
            <h1 class="fs-2 fw-bold mb-1">Acessos a documentos pessoais</h1>
            <div class="text-muted">Quem abriu, descarregou ou tentou abrir documentos de pessoas · {{ acessos.total }} {{ acessos.total === 1 ? 'registo' : 'registos' }}</div>
        </div>

        <div class="card card-flush">
            <div class="card-header pt-6">
                <div class="d-flex flex-wrap gap-3 w-100">
                    <input v-model="form.pessoa" type="search" class="form-control form-control-solid w-225px" placeholder="Pessoa" @input="filtrar()" />
                    <input v-model="form.utilizador" type="search" class="form-control form-control-solid w-225px" placeholder="Utilizador" @input="filtrar()" />
                    <select v-model="form.acao" class="form-select form-select-solid w-200px" @change="filtrar(true)">
                        <option value="">Todas as acções</option>
                        <option v-for="a in acoes" :key="a.valor" :value="a.valor">{{ a.rotulo }}</option>
                    </select>
                    <input v-model="form.de" type="date" class="form-control form-control-solid w-175px" @change="filtrar(true)" />
                    <input v-model="form.ate" type="date" class="form-control form-control-solid w-175px" @change="filtrar(true)" />
                </div>
            </div>
            <div class="card-body py-4">
                <div v-if="!acessos.data.length" class="text-center text-muted py-10">Nenhum acesso registado.</div>
                <div v-else class="table-responsive">
                    <table class="table align-middle table-row-dashed fs-6 gy-4 mb-0">
                        <thead>
                            <tr class="text-start text-muted fw-bold fs-7 text-uppercase">
                                <th>Quando</th>
                                <th>Utilizador</th>
                                <th>Pessoa</th>
                                <th>Documento</th>
                                <th>Acção</th>
                                <th>IP</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-for="a in acessos.data" :key="a.id">
                                <td class="text-nowrap">{{ formatar(a.quando) }}</td>
                                <td>{{ a.utilizador ?? '—' }}</td>
                                <td>{{ a.pessoa ?? '—' }}</td>
                                <td>{{ a.documento ?? '—' }}</td>
                                <td>
                                    <span class="badge" :class="a.acao_codigo === 'negado' ? 'badge-light-danger' : 'badge-light-primary'">{{ a.acao }}</span>
                                </td>
                                <td class="text-muted">{{ a.ip ?? '—' }}</td>
                            </tr>
                        </tbody>
                    </table>
                </div>
                <div v-if="acessos.last_page > 1" class="d-flex flex-wrap gap-2 justify-content-end mt-5">
                    <template v-for="(l, i) in acessos.links" :key="i">
                        <Link v-if="l.url" :href="l.url" class="btn btn-sm" :class="l.active ? 'btn-primary' : 'btn-light'" preserve-scroll v-html="l.label" />
                        <span v-else class="btn btn-sm btn-light disabled" v-html="l.label" />
                    </template>
                </div>
            </div>
        </div>
    </div>
</template>
