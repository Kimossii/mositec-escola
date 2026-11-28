<script setup>
import { computed, reactive, ref } from 'vue';
import { Link, router } from '@inertiajs/vue3';
import Loader from '@/Components/Shared/Loader.vue';
import ConfirmModal from '@/Components/Shared/ConfirmModal.vue';
import SelectSolid from '@/Components/Shared/SelectSolid.vue';
import BotaoVoltar from '@/Components/Shared/BotaoVoltar.vue';
import LayoutPlataforma from '../../Components/LayoutPlataforma.vue';
import { rotuloModulo } from '../../Composables/licencas';

// Criar (plano = null) e editar. Os campos vazios têm significado: limite vazio = sem limite;
// tolerância/aviso vazios = valores por omissão da instalação.
const props = defineProps({
    plano: { type: Object, default: null },
    modulosDisponiveis: { type: Array, default: () => [] },
    modalidades: { type: Array, default: () => [] },
    padroes: { type: Object, default: () => ({}) },
});
defineOptions({ layout: LayoutPlataforma });

const vazio = (valor) => (valor === null || valor === undefined ? '' : valor);

const form = reactive({
    nome: props.plano?.nome ?? '',
    descricao: props.plano?.descricao ?? '',
    validade_dias: props.plano?.validade_dias ?? 365,
    modalidade: props.plano?.modalidade ?? 1,
    limite_alunos: vazio(props.plano?.limite_alunos),
    limite_utilizadores: vazio(props.plano?.limite_utilizadores),
    limite_emails_mes: vazio(props.plano?.limite_emails_mes),
    emails_aviso_pct: props.plano?.emails_aviso_pct ?? 80,
    emails_bloqueia_ao_exceder: props.plano?.emails_bloqueia_ao_exceder ?? true,
    tolerancia_dias: vazio(props.plano?.tolerancia_dias),
    aviso_dias: vazio(props.plano?.aviso_dias),
    modulos: [...(props.plano?.modulos ?? [])],
});
const processing = ref(false);
const aConfirmar = ref(false);
const errors = ref({});

const opcoesModalidade = computed(() => props.modalidades.map((m) => ({ value: m.value, label: m.label })));
const CAMPOS = ['nome', 'descricao', 'validade_dias', 'modalidade', 'limite_alunos', 'limite_utilizadores', 'limite_emails_mes', 'emails_aviso_pct', 'emails_bloqueia_ao_exceder', 'tolerancia_dias', 'aviso_dias', 'modulos', 'limites'];
const errosGerais = () => Object.entries(errors.value).filter(([campo]) => !CAMPOS.includes(campo)).map(([, mensagem]) => mensagem);

function alternarModulo(alias, marcado) {
    form.modulos = marcado ? [...form.modulos, alias] : form.modulos.filter((m) => m !== alias);
}

function pedirConfirmacao() {
    aConfirmar.value = true;
}

function submit() {
    processing.value = true;
    errors.value = {};

    const opcoes = {
        onError: (erros) => {
            errors.value = erros;
        },
        onFinish: () => {
            processing.value = false;
            aConfirmar.value = false;
        },
    };

    if (props.plano) router.put(`/plataforma/planos/${props.plano.id}`, form, opcoes);
    else router.post('/plataforma/planos', form, opcoes);
}
</script>

<template>
    <div>
        <BotaoVoltar href="/plataforma/planos" />
        <div class="mb-6">
            <h1 class="fs-2 fw-bold">{{ plano ? `Editar plano ${plano.nome}` : 'Novo plano' }}</h1>
            <div v-if="plano" class="text-muted fs-7">Alterar um plano não altera as licenças já emitidas: cada uma guarda os seus direitos.</div>
        </div>

        <div class="card">
            <form class="card-body" novalidate @submit.prevent="pedirConfirmacao">
                <div v-if="errosGerais().length" class="alert alert-danger">
                    <div v-for="mensagem in errosGerais()" :key="mensagem">{{ mensagem }}</div>
                </div>

                <div class="row g-6 mb-8">
                    <div class="col-12 col-md-6">
                        <label class="required fw-semibold fs-6 mb-2" for="plano-nome">Nome</label>
                        <input id="plano-nome" v-model="form.nome" type="text" name="nome" class="form-control form-control-solid" :class="{ 'is-invalid': errors.nome }" />
                        <div v-if="errors.nome" class="text-danger fs-7 mt-1">{{ errors.nome }}</div>
                    </div>
                    <div class="col-12 col-md-6">
                        <label class="required fw-semibold fs-6 mb-2">Modalidade</label>
                        <SelectSolid v-model="form.modalidade" :options="opcoesModalidade" />
                        <div v-if="errors.modalidade" class="text-danger fs-7 mt-1">{{ errors.modalidade }}</div>
                    </div>
                    <div class="col-12">
                        <label class="fw-semibold fs-6 mb-2" for="plano-descricao">Descrição (opcional)</label>
                        <textarea id="plano-descricao" v-model="form.descricao" name="descricao" rows="2" maxlength="500" class="form-control form-control-solid" :class="{ 'is-invalid': errors.descricao }" />
                        <div v-if="errors.descricao" class="text-danger fs-7 mt-1">{{ errors.descricao }}</div>
                    </div>
                </div>

                <h4 class="mb-4">Validade e prazos</h4>
                <div class="row g-6 mb-8">
                    <div class="col-12 col-md-4">
                        <label class="required fw-semibold fs-6 mb-2" for="plano-validade">Validade (dias)</label>
                        <input id="plano-validade" v-model="form.validade_dias" type="number" min="1" inputmode="numeric" name="validade_dias" class="form-control form-control-solid" :class="{ 'is-invalid': errors.validade_dias }" />
                        <div v-if="errors.validade_dias" class="text-danger fs-7 mt-1">{{ errors.validade_dias }}</div>
                    </div>
                    <div class="col-12 col-md-4">
                        <label class="fw-semibold fs-6 mb-2" for="plano-tolerancia">Tolerância (dias)</label>
                        <input id="plano-tolerancia" v-model="form.tolerancia_dias" type="number" min="0" inputmode="numeric" name="tolerancia_dias" :placeholder="`Por omissão: ${padroes.tolerancia_dias ?? ''}`" class="form-control form-control-solid" :class="{ 'is-invalid': errors.tolerancia_dias }" />
                        <div v-if="errors.tolerancia_dias" class="text-danger fs-7 mt-1">{{ errors.tolerancia_dias }}</div>
                    </div>
                    <div class="col-12 col-md-4">
                        <label class="fw-semibold fs-6 mb-2" for="plano-aviso">Aviso de expiração (dias)</label>
                        <input id="plano-aviso" v-model="form.aviso_dias" type="number" min="0" inputmode="numeric" name="aviso_dias" :placeholder="`Por omissão: ${padroes.aviso_dias ?? ''}`" class="form-control form-control-solid" :class="{ 'is-invalid': errors.aviso_dias }" />
                        <div v-if="errors.aviso_dias" class="text-danger fs-7 mt-1">{{ errors.aviso_dias }}</div>
                    </div>
                </div>

                <h4 class="mb-1">Limites</h4>
                <div class="text-muted fs-7 mb-4">Deixe um campo vazio para «sem limite».</div>
                <div class="row g-6 mb-2">
                    <div class="col-12 col-md-6">
                        <label class="fw-semibold fs-6 mb-2" for="plano-alunos">Alunos</label>
                        <input id="plano-alunos" v-model="form.limite_alunos" type="number" min="0" inputmode="numeric" name="limite_alunos" placeholder="Sem limite" class="form-control form-control-solid" :class="{ 'is-invalid': errors.limite_alunos }" />
                        <div v-if="errors.limite_alunos" class="text-danger fs-7 mt-1">{{ errors.limite_alunos }}</div>
                    </div>
                    <div class="col-12 col-md-6">
                        <label class="fw-semibold fs-6 mb-2" for="plano-utilizadores">Utilizadores (sem alunos)</label>
                        <input id="plano-utilizadores" v-model="form.limite_utilizadores" type="number" min="0" inputmode="numeric" name="limite_utilizadores" placeholder="Sem limite" class="form-control form-control-solid" :class="{ 'is-invalid': errors.limite_utilizadores }" />
                        <div v-if="errors.limite_utilizadores" class="text-danger fs-7 mt-1">{{ errors.limite_utilizadores }}</div>
                    </div>
                </div>
                <div v-if="errors.limites" class="text-danger fs-7 mb-6">{{ errors.limites }}</div>

                <h4 class="mb-1 mt-8">Emails opcionais</h4>
                <div class="text-muted fs-7 mb-4">Quota mensal de emails opcionais por escola. Os essenciais (recuperação de senha, confirmação de email) nunca são bloqueados. Só se aplica às licenças emitidas depois de guardar.</div>
                <div class="row g-6 mb-2">
                    <div class="col-12 col-md-4">
                        <label class="fw-semibold fs-6 mb-2" for="plano-emails-mes">Emails por mês</label>
                        <input id="plano-emails-mes" v-model="form.limite_emails_mes" type="number" min="0" inputmode="numeric" name="limite_emails_mes" placeholder="Sem limite" class="form-control form-control-solid" :class="{ 'is-invalid': errors.limite_emails_mes }" />
                        <div v-if="errors.limite_emails_mes" class="text-danger fs-7 mt-1">{{ errors.limite_emails_mes }}</div>
                    </div>
                    <div class="col-12 col-md-4">
                        <label class="fw-semibold fs-6 mb-2" for="plano-emails-aviso">Avisar aos (%)</label>
                        <input id="plano-emails-aviso" v-model="form.emails_aviso_pct" type="number" min="1" max="100" inputmode="numeric" name="emails_aviso_pct" class="form-control form-control-solid" :class="{ 'is-invalid': errors.emails_aviso_pct }" />
                        <div v-if="errors.emails_aviso_pct" class="text-danger fs-7 mt-1">{{ errors.emails_aviso_pct }}</div>
                    </div>
                    <div class="col-12 col-md-4 d-flex align-items-end">
                        <div class="form-check form-check-custom form-check-solid mb-3">
                            <input id="plano-emails-bloqueia" v-model="form.emails_bloqueia_ao_exceder" class="form-check-input" type="checkbox" name="emails_bloqueia_ao_exceder" />
                            <label class="form-check-label text-gray-700" for="plano-emails-bloqueia">Bloquear opcionais ao exceder</label>
                        </div>
                    </div>
                </div>

                <h4 class="mb-4 mt-8">Módulos incluídos</h4>
                <div class="d-flex flex-wrap gap-6 mb-2">
                    <div v-for="alias in modulosDisponiveis" :key="alias" class="form-check form-check-custom form-check-solid">
                        <input :id="`plano-modulo-${alias}`" class="form-check-input" type="checkbox" :checked="form.modulos.includes(alias)" @change="alternarModulo(alias, $event.target.checked)" />
                        <label class="form-check-label text-gray-700" :for="`plano-modulo-${alias}`">{{ rotuloModulo(alias) }}</label>
                    </div>
                </div>
                <div v-if="errors.modulos" class="text-danger fs-7 mb-4">{{ errors.modulos }}</div>

                <div class="text-end mt-8">
                    <Link href="/plataforma/planos" class="btn btn-light-danger me-2" :class="{ disabled: processing }">Cancelar</Link>
                    <button type="submit" class="btn btn-primary" :disabled="processing">
                        <span v-if="!processing">{{ plano ? 'Guardar plano' : 'Criar plano' }}</span>
                        <span v-else>Aguarde... <Loader size="0.3px" class="align-middle ms-2" /></span>
                    </button>
                </div>
            </form>
        </div>

        <ConfirmModal
            :show="aConfirmar"
            :titulo="plano ? 'Guardar plano' : 'Criar plano'"
            :mensagem="plano ? 'As alterações aplicam-se às próximas licenças emitidas com este plano; as já emitidas não mudam. Deseja guardar?' : 'O plano fica disponível para emitir licenças. Deseja criá-lo?'"
            :texto-confirmar="plano ? 'Guardar' : 'Criar'"
            variante="primary"
            :processando="processing"
            @confirmar="submit"
            @cancelar="aConfirmar = false"
        />
    </div>
</template>
