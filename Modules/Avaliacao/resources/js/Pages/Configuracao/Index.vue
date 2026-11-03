<script setup>
import { reactive } from 'vue';
import { router } from '@inertiajs/vue3';
import { toast } from 'vue-sonner';
import AppLayout from '@/Layouts/AppLayout.vue';
import { can } from '@/Composables/usePermissoes';

const props = defineProps({
    anos: { type: Array, required: true }, // [{ id, nome }]
    ano_id: { type: Number, default: null },
    classes: { type: Array, required: true },
    periodos: { type: Array, required: true }, // [{ id, nome, numero, data_fim }]
    modos: { type: Array, required: true }, // [{ valor, nome }]
    tipos: { type: Array, required: true }, // [{ valor, nome }]
});
defineOptions({ layout: AppLayout });

const formularios = reactive({}); // nivel_id -> campos da regra
const datas = reactive({}); // `${regra}-${periodo}-${tipo}` -> data
const prolongamentos = reactive({}); // prazo_id -> { data_limite, motivo, turma_id }

const aoFalhar = (e) => toast.error(Object.values(e)[0]);
const aoGuardar = (texto) => ({ preserveScroll: true, preserveState: true, onError: aoFalhar, onSuccess: () => toast.success(texto) });

function formulario(c) {
    formularios[c.nivel_id] ??= { ...(c.regra ?? { ...c.sugestao, peso_mac: '50', peso_pt: '50', peso_mt1: null, peso_mt2: null, peso_mt3: null, casas_decimais: 2, modo_arredondamento: 0 }) };
    return formularios[c.nivel_id];
}

function guardarRegra(c) {
    router.post('/avaliacao/configuracao/regras', { ...formulario(c), ano_lectivo_id: props.ano_id, nivel_academico_id: c.nivel_id }, aoGuardar('Regra guardada.'));
}

const prazoDe = (c, periodoId, tipo) => c.prazos.find((p) => p.periodo_id === periodoId && p.tipo === tipo);

function definirPrazo(c, periodoId, tipo) {
    const data = datas[`${c.regra.id}-${periodoId}-${tipo}`];
    if (!data) return;
    router.post('/avaliacao/configuracao/prazos', { regra_id: c.regra.id, periodo_id: periodoId, tipo, data_limite: data }, aoGuardar('Prazo definido.'));
}

function prolongar(prazo) {
    const p = prolongamentos[prazo.id];
    router.post(`/avaliacao/configuracao/prazos/${prazo.id}/excepcoes`, { ...p, turma_id: p.turma_id || null }, {
        ...aoGuardar('Prazo prolongado.'), onSuccess: () => { delete prolongamentos[prazo.id]; toast.success('Prazo prolongado.'); },
    });
}

function copiar() {
    router.post('/avaliacao/configuracao/regras/copiar', { ano_lectivo_id: props.ano_id }, { preserveScroll: true, onError: aoFalhar, onSuccess: () => toast.success('Cópia concluída.') });
}
</script>

<template>
    <div class="app-container container-fluid">
        <div class="mb-6 d-flex flex-wrap justify-content-between align-items-end gap-4">
            <div>
                <h1 class="fs-2 fw-bold mb-1">Configuração da avaliação</h1>
                <div class="text-muted">Escala, pesos, arredondamento e prazos de lançamento, por classe e ano lectivo.</div>
            </div>
            <div class="d-flex gap-3">
                <select class="form-select form-select-sm w-200px" :value="ano_id" @change="router.get('/avaliacao/configuracao', { ano: $event.target.value })">
                    <option v-for="a in anos" :key="a.id" :value="a.id">{{ a.nome }}</option>
                </select>
                <button type="button" class="btn btn-sm btn-light" @click="copiar">Copiar regras do ano anterior</button>
            </div>
        </div>

        <div v-for="c in classes" :key="c.nivel_id" class="card card-flush mb-6">
            <div class="card-header">
                <h3 class="card-title fw-bold">{{ c.nome }} <span class="text-muted fs-7 ms-2">{{ c.codigo }}</span></h3>
                <div class="card-toolbar">
                    <span v-if="c.regra" class="badge badge-light-success">Configurada</span>
                    <span v-else-if="c.quantitativa" class="badge badge-light-warning">Por configurar</span>
                    <span v-else class="badge badge-light">Avaliação qualitativa</span>
                </div>
            </div>

            <div v-if="!c.quantitativa" class="card-body text-muted">Esta classe usa avaliação qualitativa, tratada numa fase seguinte.</div>

            <div v-else class="card-body">
                <div class="row g-4">
                    <div class="col-6 col-md-2"><label class="form-label fs-8 fw-bold text-muted text-uppercase">Escala máx.</label><input v-model="formulario(c).escala_maxima" type="number" class="form-control form-control-sm"></div>
                    <div class="col-6 col-md-2"><label class="form-label fs-8 fw-bold text-muted text-uppercase">Nota mínima</label><input v-model="formulario(c).nota_minima" type="text" class="form-control form-control-sm"></div>
                    <div class="col-6 col-md-2"><label class="form-label fs-8 fw-bold text-muted text-uppercase">Peso MAC %</label><input v-model="formulario(c).peso_mac" type="text" class="form-control form-control-sm"></div>
                    <div class="col-6 col-md-2"><label class="form-label fs-8 fw-bold text-muted text-uppercase">Peso PT %</label><input v-model="formulario(c).peso_pt" type="text" class="form-control form-control-sm"></div>
                    <div class="col-6 col-md-2"><label class="form-label fs-8 fw-bold text-muted text-uppercase">Casas decimais</label><input v-model="formulario(c).casas_decimais" type="number" min="0" max="4" class="form-control form-control-sm"></div>
                    <div class="col-6 col-md-2">
                        <label class="form-label fs-8 fw-bold text-muted text-uppercase">Arredondamento</label>
                        <select v-model="formulario(c).modo_arredondamento" class="form-select form-select-sm"><option v-for="m in modos" :key="m.valor" :value="m.valor">{{ m.nome }}</option></select>
                    </div>
                    <div class="col-12 col-md-6">
                        <label class="form-label fs-8 fw-bold text-muted text-uppercase">Pesos dos trimestres % (vazio = iguais)</label>
                        <div class="d-flex gap-2">
                            <input v-model="formulario(c).peso_mt1" type="text" class="form-control form-control-sm" placeholder="1.º">
                            <input v-model="formulario(c).peso_mt2" type="text" class="form-control form-control-sm" placeholder="2.º">
                            <input v-model="formulario(c).peso_mt3" type="text" class="form-control form-control-sm" placeholder="3.º">
                        </div>
                    </div>
                    <div class="col-12 col-md-4 d-flex align-items-end">
                        <label class="form-check form-check-sm form-check-custom form-check-solid">
                            <input v-model="formulario(c).reprova_por_aproveitamento" type="checkbox" class="form-check-input">
                            <span class="form-check-label">Reprova por aproveitamento</span>
                        </label>
                    </div>
                    <div class="col-12 col-md-2 d-flex align-items-end justify-content-md-end"><button type="button" class="btn btn-sm btn-primary" @click="guardarRegra(c)">Guardar regra</button></div>
                </div>

                <div v-if="c.regra" class="border-top mt-6 pt-5">
                    <div class="fw-bold mb-1">Prazos de lançamento</div>
                    <div class="text-muted fs-7 mb-4">Sem prazo definido, vale o fim do trimestre.</div>
                    <div class="table-responsive">
                        <table class="table align-middle table-row-dashed fs-7 gy-3 mb-0">
                            <thead><tr class="text-muted fw-bold text-uppercase"><th>Trimestre</th><th v-for="t in tipos" :key="t.valor">{{ t.nome }}</th></tr></thead>
                            <tbody>
                                <tr v-for="p in periodos" :key="p.id">
                                    <td class="fw-semibold">{{ p.nome }}<div class="text-muted fs-8">termina em {{ p.data_fim }}</div></td>
                                    <td v-for="t in tipos" :key="t.valor">
                                        <div class="d-flex align-items-center gap-2">
                                            <input v-model="datas[`${c.regra.id}-${p.id}-${t.valor}`]" type="date" class="form-control form-control-sm w-175px"
                                                :placeholder="prazoDe(c, p.id, t.valor)?.data_limite"
                                                @focus="datas[`${c.regra.id}-${p.id}-${t.valor}`] ??= prazoDe(c, p.id, t.valor)?.data_limite">
                                            <button type="button" class="btn btn-sm btn-light" @click="definirPrazo(c, p.id, t.valor)">Definir</button>
                                        </div>
                                        <div v-if="prazoDe(c, p.id, t.valor)" class="mt-2">
                                            <div v-for="e in prazoDe(c, p.id, t.valor).excepcoes" :key="e.id" class="fs-8 text-muted">
                                                Excepção até {{ e.data_limite }}{{ e.turma_id ? ' (turma)' : '' }} — {{ e.motivo }}
                                            </div>
                                            <a v-if="can('nota.prolongar-prazo')" href="#" class="fs-8"
                                                @click.prevent="prolongamentos[prazoDe(c, p.id, t.valor).id] ??= { data_limite: '', motivo: '', turma_id: '' }">Prolongar prazo</a>
                                            <div v-if="prolongamentos[prazoDe(c, p.id, t.valor).id]" class="d-flex flex-wrap gap-2 mt-2">
                                                <input v-model="prolongamentos[prazoDe(c, p.id, t.valor).id].data_limite" type="date" class="form-control form-control-sm w-150px">
                                                <input v-model="prolongamentos[prazoDe(c, p.id, t.valor).id].motivo" type="text" class="form-control form-control-sm w-200px" placeholder="Motivo">
                                                <select v-model="prolongamentos[prazoDe(c, p.id, t.valor).id].turma_id" class="form-select form-select-sm w-200px">
                                                    <option value="">Todas as turmas</option>
                                                    <option v-for="tu in c.turmas" :key="tu.id" :value="tu.id">{{ tu.rotulo }}</option>
                                                </select>
                                                <button type="button" class="btn btn-sm btn-primary" @click="prolongar(prazoDe(c, p.id, t.valor))">Prolongar</button>
                                            </div>
                                        </div>
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </div>
</template>
