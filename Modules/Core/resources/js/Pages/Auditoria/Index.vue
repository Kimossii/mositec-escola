<script setup>
import { reactive, ref } from 'vue';
import { Link, router } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import ListaComVistas from '@/Components/Shared/ListaComVistas.vue';

const props = defineProps({
    registos: { type: Object, required: true }, // paginador: { data: [{ id, quando, utilizador, accao, entidade_tipo, entidade_id, detalhe: { antes, depois }, ip }], links, total }
    filtros: { type: Object, required: true }, // { accao, utilizador, entidade_tipo, de, ate }
});
defineOptions({ layout: AppLayout });

const form = reactive({
    accao: props.filtros.accao ?? '',
    utilizador: props.filtros.utilizador ?? '',
    entidade_tipo: props.filtros.entidade_tipo ?? '',
    de: props.filtros.de ?? '',
    ate: props.filtros.ate ?? '',
});

let temporizador = null;
function filtrar(imediato = false) {
    clearTimeout(temporizador);
    const aplicar = () => router.get('/auditoria', Object.fromEntries(Object.entries(form).filter(([, v]) => v !== '')), { preserveState: true, replace: true });
    imediato ? aplicar() : (temporizador = setTimeout(aplicar, 350));
}

const aberto = ref(null);
const alternar = (id) => (aberto.value = aberto.value === id ? null : id);

function formatar(quando) {
    return quando ? quando.slice(8, 10) + '/' + quando.slice(5, 7) + '/' + quando.slice(0, 4) + ' ' + quando.slice(11, 16) : '—';
}

// Valores do diff: texto simples, ou JSON compacto para listas/objectos.
function valor(v) {
    if (v === null || v === undefined) return '—';
    return typeof v === 'object' ? JSON.stringify(v) : String(v);
}

// Campos do diff, na ordem em que aparecem em `depois` e `antes`.
function campos(detalhe) {
    return [...new Set([...Object.keys(detalhe?.antes ?? {}), ...Object.keys(detalhe?.depois ?? {})])];
}
</script>

<template>
    <div class="app-container container-fluid py-6">
        <div class="mb-6">
            <h1 class="fs-2 fw-bold mb-1">Auditoria</h1>
            <div class="text-muted">Quem alterou o quê, e quando · {{ registos.total }} {{ registos.total === 1 ? 'registo' : 'registos' }}</div>
        </div>

        <ListaComVistas chave="auditoria" prefixo="/auditoria">
            <div class="card card-flush">
                <div class="card-header pt-6">
                    <div class="d-flex flex-wrap gap-3 w-100">
                        <input v-model="form.accao" type="search" class="form-control form-control-solid w-225px" placeholder="Acção (ex.: perfil.)" @input="filtrar()" />
                        <input v-model="form.entidade_tipo" type="search" class="form-control form-control-solid w-200px" placeholder="Entidade" @input="filtrar()" />
                        <input v-model="form.utilizador" type="search" class="form-control form-control-solid w-225px" placeholder="Utilizador" @input="filtrar()" />
                        <input v-model="form.de" type="date" class="form-control form-control-solid w-175px" @change="filtrar(true)" />
                        <input v-model="form.ate" type="date" class="form-control form-control-solid w-175px" @change="filtrar(true)" />
                    </div>
                </div>
                <div class="card-body py-4">
                    <div class="table-responsive">
                        <table class="table align-middle table-row-dashed fs-6 gy-4 mb-0">
                            <thead>
                                <tr class="text-start text-muted fw-bold fs-7 text-uppercase">
                                    <th>Quando</th>
                                    <th>Utilizador</th>
                                    <th>Acção</th>
                                    <th>Entidade</th>
                                    <th>IP</th>
                                    <th class="text-end">Detalhe</th>
                                </tr>
                            </thead>
                            <tbody>
                                <template v-for="r in registos.data" :key="r.id">
                                    <tr>
                                        <td class="text-nowrap">{{ formatar(r.quando) }}</td>
                                        <td>{{ r.utilizador ?? '—' }}</td>
                                        <td><span class="badge badge-light-primary">{{ r.accao }}</span></td>
                                        <td>{{ r.entidade_tipo }}<span v-if="r.entidade_id" class="text-muted"> #{{ r.entidade_id }}</span></td>
                                        <td class="text-muted">{{ r.ip ?? '—' }}</td>
                                        <td class="text-end">
                                            <button type="button" class="btn btn-sm btn-light" :aria-expanded="aberto === r.id" @click="alternar(r.id)">
                                                {{ aberto === r.id ? 'Fechar' : 'Ver' }}
                                            </button>
                                        </td>
                                    </tr>
                                    <tr v-if="aberto === r.id">
                                        <td colspan="6" class="bg-body-secondary">
                                            <div class="table-responsive">
                                                <table class="table table-sm mb-0">
                                                    <thead>
                                                        <tr class="text-muted fs-7 text-uppercase"><th>Campo</th><th>Antes</th><th>Depois</th></tr>
                                                    </thead>
                                                    <tbody>
                                                        <tr v-for="c in campos(r.detalhe)" :key="c">
                                                            <td class="fw-semibold">{{ c }}</td>
                                                            <td class="text-break">{{ valor(r.detalhe?.antes?.[c]) }}</td>
                                                            <td class="text-break">{{ valor(r.detalhe?.depois?.[c]) }}</td>
                                                        </tr>
                                                    </tbody>
                                                </table>
                                            </div>
                                        </td>
                                    </tr>
                                </template>
                                <tr v-if="!registos.data.length">
                                    <td colspan="6" class="text-center text-muted py-10">Nenhum registo de auditoria.</td>
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
