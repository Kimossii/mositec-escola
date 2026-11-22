<script setup>
import { computed, reactive, ref, watch } from 'vue';
import { Link, router } from '@inertiajs/vue3';
import Loader from '@/Components/Shared/Loader.vue';
import SelectSolid from '@/Components/Shared/SelectSolid.vue';
import BotaoVoltar from '@/Components/Shared/BotaoVoltar.vue';
import LayoutPlataforma from '../../Components/LayoutPlataforma.vue';
import AvisoEmissorIndisponivel from '../../Components/AvisoEmissorIndisponivel.vue';
import { rotuloModulo } from '../../Composables/licencas';

const props = defineProps({
    emissor: { type: Object, required: true },
    planos: { type: Array, default: () => [] },
    escolas: { type: Array, default: () => [] },
    modalidades: { type: Array, default: () => [] },
    modulosDisponiveis: { type: Array, default: () => [] },
    padroes: { type: Object, default: () => ({}) },
});
defineOptions({ layout: LayoutPlataforma });

const ONLINE = 1;
const vazio = (valor) => (valor === null || valor === undefined ? '' : valor);

// `?escola=CODIGO` (atalho da página da escola) pré-selecciona a escola.
const escolaInicial = new URLSearchParams(window.location.search).get('escola') ?? '';

const form = reactive({
    plano_id: props.planos[0]?.id ?? '',
    modalidade: props.planos[0]?.modalidade ?? ONLINE,
    escola: props.escolas.some((e) => e.codigo === escolaInicial) ? escolaInicial : '',
    instalacao: '',
    validade_dias: props.planos[0]?.validade_dias ?? 365,
    limite_alunos: '',
    limite_utilizadores: '',
    tolerancia_dias: '',
    aviso_dias: '',
    modulos: [],
    ligacao_obrigatoria: false,
});
const processing = ref(false);
const errors = ref({});

const planoActual = computed(() => props.planos.find((p) => p.id === form.plano_id) ?? null);
const online = computed(() => form.modalidade === ONLINE);

// Escolher o plano pré-preenche os direitos (podem ser alterados só para esta licença).
function aplicarPlano(plano) {
    if (!plano) return;
    form.modalidade = plano.modalidade;
    form.validade_dias = plano.validade_dias;
    form.limite_alunos = vazio(plano.limite_alunos);
    form.limite_utilizadores = vazio(plano.limite_utilizadores);
    form.tolerancia_dias = vazio(plano.tolerancia_dias);
    form.aviso_dias = vazio(plano.aviso_dias);
    form.modulos = [...plano.modulos];
}
aplicarPlano(planoActual.value);
form.ligacao_obrigatoria = !online.value;

watch(() => form.plano_id, () => {
    aplicarPlano(planoActual.value);
    form.ligacao_obrigatoria = !online.value;
});
watch(() => form.modalidade, () => {
    form.ligacao_obrigatoria = !online.value;
});

const opcoesPlano = computed(() => props.planos.map((p) => ({ value: p.id, label: p.nome })));
const opcoesModalidade = computed(() => props.modalidades.map((m) => ({ value: m.value, label: m.label })));
const opcoesEscola = computed(() => [
    { value: '', label: online.value ? 'Escolha a escola' : 'Sem escola específica' },
    ...props.escolas.map((e) => ({ value: e.codigo, label: `${e.codigo} — ${e.nome}` })),
]);

const CAMPOS = ['plano', 'plano_id', 'escola', 'instalacao', 'modalidade', 'validade_dias', 'limite_alunos', 'limite_utilizadores', 'limites', 'tolerancia_dias', 'aviso_dias', 'modulos', 'licenca'];
const errosGerais = () => Object.entries(errors.value).filter(([campo]) => !CAMPOS.includes(campo)).map(([, mensagem]) => mensagem);

function alternarModulo(alias, marcado) {
    form.modulos = marcado ? [...form.modulos, alias] : form.modulos.filter((m) => m !== alias);
}

function submit() {
    processing.value = true;
    errors.value = {};

    router.post('/plataforma/licencas', {
        ...form,
        escola: form.escola || null,
        instalacao: online.value ? null : form.instalacao,
        ligacao_obrigatoria: online.value ? false : form.ligacao_obrigatoria,
    }, {
        onError: (erros) => {
            errors.value = erros;
        },
        onFinish: () => {
            processing.value = false;
        },
    });
}
</script>

<template>
    <div>
        <BotaoVoltar href="/plataforma/licencas" />
        <div class="mb-6">
            <h1 class="fs-2 fw-bold">Emitir licença</h1>
        </div>

        <AvisoEmissorIndisponivel v-if="!emissor.disponivel" :mensagem="emissor.mensagem" :motivo="emissor.motivo" />

        <div v-if="planos.length === 0" class="alert alert-info">
            Ainda não há planos. <Link href="/plataforma/planos/novo">Crie um plano</Link> para poder emitir licenças.
        </div>

        <div v-else class="card">
            <form class="card-body" novalidate @submit.prevent="submit">
                <div v-if="errosGerais().length" class="alert alert-danger" role="alert">
                    <div v-for="mensagem in errosGerais()" :key="mensagem">{{ mensagem }}</div>
                </div>

                <h4 class="mb-4">Plano e destino</h4>
                <div class="row g-6 mb-8">
                    <div class="col-12 col-md-6">
                        <label class="required fw-semibold fs-6 mb-2">Plano</label>
                        <SelectSolid v-model="form.plano_id" :options="opcoesPlano" />
                        <div v-if="errors.plano || errors.plano_id" class="text-danger fs-7 mt-1">{{ errors.plano ?? errors.plano_id }}</div>
                    </div>
                    <div class="col-12 col-md-6">
                        <label class="required fw-semibold fs-6 mb-2">Modalidade</label>
                        <SelectSolid v-model="form.modalidade" :options="opcoesModalidade" />
                        <div v-if="errors.modalidade" class="text-danger fs-7 mt-1">{{ errors.modalidade }}</div>
                    </div>
                    <div class="col-12 col-md-6">
                        <label class="fw-semibold fs-6 mb-2" :class="{ required: online }">Escola desta instalação</label>
                        <SelectSolid v-model="form.escola" :options="opcoesEscola" searchable />
                        <div v-if="errors.escola" class="text-danger fs-7 mt-1">{{ errors.escola }}</div>
                    </div>
                    <div v-if="!online" class="col-12 col-md-6">
                        <label class="fw-semibold fs-6 mb-2" for="licenca-instalacao" :class="{ required: form.ligacao_obrigatoria }">Código da instalação</label>
                        <input id="licenca-instalacao" v-model="form.instalacao" type="text" name="instalacao" maxlength="120" autocomplete="off" placeholder="Código mostrado na instalação do cliente" class="form-control form-control-solid" :class="{ 'is-invalid': errors.instalacao }" />
                        <div v-if="errors.instalacao" class="text-danger fs-7 mt-1">{{ errors.instalacao }}</div>
                    </div>
                    <div v-if="!online" class="col-12">
                        <div class="form-check form-check-custom form-check-solid">
                            <input id="licenca-ligacao" v-model="form.ligacao_obrigatoria" class="form-check-input" type="checkbox" name="ligacao_obrigatoria" />
                            <label class="form-check-label text-gray-700" for="licenca-ligacao">
                                Ligação à instalação obrigatória (a licença só funciona na instalação indicada)
                            </label>
                        </div>
                    </div>
                </div>

                <h4 class="mb-4">Validade e prazos</h4>
                <div class="row g-6 mb-8">
                    <div class="col-12 col-md-4">
                        <label class="fw-semibold fs-6 mb-2" for="licenca-validade">Validade (dias)</label>
                        <input id="licenca-validade" v-model="form.validade_dias" type="number" min="1" inputmode="numeric" name="validade_dias" :placeholder="planoActual ? `Plano: ${planoActual.validade_dias}` : ''" class="form-control form-control-solid" :class="{ 'is-invalid': errors.validade_dias }" />
                        <div v-if="errors.validade_dias" class="text-danger fs-7 mt-1">{{ errors.validade_dias }}</div>
                    </div>
                    <div class="col-12 col-md-4">
                        <label class="fw-semibold fs-6 mb-2" for="licenca-tolerancia">Tolerância (dias)</label>
                        <input id="licenca-tolerancia" v-model="form.tolerancia_dias" type="number" min="0" inputmode="numeric" name="tolerancia_dias" :placeholder="`Vazio: plano ou ${padroes.tolerancia_dias ?? ''}`" class="form-control form-control-solid" :class="{ 'is-invalid': errors.tolerancia_dias }" />
                        <div v-if="errors.tolerancia_dias" class="text-danger fs-7 mt-1">{{ errors.tolerancia_dias }}</div>
                    </div>
                    <div class="col-12 col-md-4">
                        <label class="fw-semibold fs-6 mb-2" for="licenca-aviso">Aviso de expiração (dias)</label>
                        <input id="licenca-aviso" v-model="form.aviso_dias" type="number" min="0" inputmode="numeric" name="aviso_dias" :placeholder="`Vazio: plano ou ${padroes.aviso_dias ?? ''}`" class="form-control form-control-solid" :class="{ 'is-invalid': errors.aviso_dias }" />
                        <div v-if="errors.aviso_dias" class="text-danger fs-7 mt-1">{{ errors.aviso_dias }}</div>
                    </div>
                </div>

                <h4 class="mb-1">Limites</h4>
                <div class="text-muted fs-7 mb-4">Deixe um campo vazio para «sem limite».</div>
                <div class="row g-6 mb-2">
                    <div class="col-12 col-md-6">
                        <label class="fw-semibold fs-6 mb-2" for="licenca-alunos">Alunos</label>
                        <input id="licenca-alunos" v-model="form.limite_alunos" type="number" min="0" inputmode="numeric" name="limite_alunos" placeholder="Sem limite" class="form-control form-control-solid" :class="{ 'is-invalid': errors.limite_alunos }" />
                        <div v-if="errors.limite_alunos" class="text-danger fs-7 mt-1">{{ errors.limite_alunos }}</div>
                    </div>
                    <div class="col-12 col-md-6">
                        <label class="fw-semibold fs-6 mb-2" for="licenca-utilizadores">Utilizadores (sem alunos)</label>
                        <input id="licenca-utilizadores" v-model="form.limite_utilizadores" type="number" min="0" inputmode="numeric" name="limite_utilizadores" placeholder="Sem limite" class="form-control form-control-solid" :class="{ 'is-invalid': errors.limite_utilizadores }" />
                        <div v-if="errors.limite_utilizadores" class="text-danger fs-7 mt-1">{{ errors.limite_utilizadores }}</div>
                    </div>
                </div>
                <div v-if="errors.limites" class="text-danger fs-7 mb-4">{{ errors.limites }}</div>

                <h4 class="mb-4 mt-8">Módulos incluídos</h4>
                <div class="d-flex flex-wrap gap-6 mb-2">
                    <div v-for="alias in modulosDisponiveis" :key="alias" class="form-check form-check-custom form-check-solid">
                        <input :id="`licenca-modulo-${alias}`" class="form-check-input" type="checkbox" :checked="form.modulos.includes(alias)" @change="alternarModulo(alias, $event.target.checked)" />
                        <label class="form-check-label text-gray-700" :for="`licenca-modulo-${alias}`">{{ rotuloModulo(alias) }}</label>
                    </div>
                </div>
                <div v-if="errors.modulos" class="text-danger fs-7 mb-4">{{ errors.modulos }}</div>
                <div v-if="errors.licenca" class="text-danger fs-7 mb-4">{{ errors.licenca }}</div>

                <div class="text-end mt-8">
                    <Link href="/plataforma/licencas" class="btn btn-light-danger me-2" :class="{ disabled: processing }">Cancelar</Link>
                    <button type="submit" class="btn btn-primary" :disabled="processing || !emissor.disponivel">
                        <span v-if="!processing">Emitir licença</span>
                        <span v-else>Aguarde... <Loader size="0.3px" class="align-middle ms-2" /></span>
                    </button>
                </div>
            </form>
        </div>
    </div>
</template>
