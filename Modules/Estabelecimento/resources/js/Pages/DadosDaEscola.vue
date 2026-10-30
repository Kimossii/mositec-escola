<script setup>
import AcaoIcone from '@/Components/Shared/AcaoIcone.vue';
import { computed, onBeforeUnmount, onMounted, reactive, ref, watch } from 'vue';
import { router } from '@inertiajs/vue3';
import { toast } from 'vue-sonner';
import AppLayout from '@/Layouts/AppLayout.vue';
import { can } from '@/Composables/usePermissoes';
import EstabelecimentoCabecalho from '../Components/EstabelecimentoCabecalho.vue';
import EstabelecimentoTabs from '../Components/EstabelecimentoTabs.vue';
import SelectSolid from '@/Components/Shared/SelectSolid.vue';
import SelectProvinciaMunicipio from '@/Components/Shared/SelectProvinciaMunicipio.vue';
import CampoFicha from '../Components/CampoFicha.vue';
import SeccaoFicha from '../Components/SeccaoFicha.vue';

const props = defineProps({
    estabelecimento: {
        type: Object,
        default: null,
    },
    etapasEnsino: {
        type: Array,
        default: () => [],
    },
    fusosHorarios: {
        type: Array,
        default: () => [],
    },
    provincias: {
        type: Array,
        default: () => [],
    },
    municipiosPorProvincia: {
        type: Object,
        default: () => ({}),
    },
});
defineOptions({ layout: AppLayout });

const tipos = [
    { value: 1, label: 'Público' },
    { value: 2, label: 'Privado' },
    { value: 3, label: 'Comparticipada' },
];

const tiposEnsino = [
    { value: 1, label: 'Ensino Geral' },
    { value: 2, label: 'Ensino Técnico' },
    { value: 3, label: 'Ensino Universitário' },
];

const opcoesFusos = computed(() => props.fusosHorarios.map((fuso) => ({ value: fuso, label: fuso })));

const TIPO_ENSINO_UNIVERSITARIO = 3;
const ETAPA_SUPERIOR = 5;

const etapasNaoSuperior = [
    { value: 1, label: 'Creche' },
    { value: 2, label: 'Pré-Escolar' },
    { value: 3, label: 'Ensino Primário' },
    { value: 4, label: 'Ensino Secundário' },
];

function snapshot() {
    return {
        nome: props.estabelecimento?.nome ?? '',
        nome_abreviado: props.estabelecimento?.nome_abreviado ?? '',
        tipo: props.estabelecimento?.tipo ?? 2,
        tipo_ensino: props.estabelecimento?.tipo_ensino ?? 1,
        etapas_ensino: props.etapasEnsino ?? [],
        nif: props.estabelecimento?.nif ?? '',
        codigo_mined: props.estabelecimento?.codigo_mined ?? '',
        numero_alvara: props.estabelecimento?.numero_alvara ?? '',
        email: props.estabelecimento?.email ?? '',
        telefone: props.estabelecimento?.telefone ?? '',
        telefone_alternativo: props.estabelecimento?.telefone_alternativo ?? '',
        website: props.estabelecimento?.website ?? '',
        endereco: props.estabelecimento?.endereco ?? '',
        caixa_postal: props.estabelecimento?.caixa_postal ?? '',
        municipio: props.estabelecimento?.municipio ?? '',
        provincia: props.estabelecimento?.provincia ?? '',
        responsavel_nome: props.estabelecimento?.responsavel_nome ?? '',
        responsavel_cargo: props.estabelecimento?.responsavel_cargo ?? '',
        ano_fundacao: props.estabelecimento?.ano_fundacao ?? '',
        observacoes: props.estabelecimento?.observacoes ?? '',
        fuso_horario: props.estabelecimento?.fuso_horario ?? 'Africa/Luanda',
    };
}

const form = reactive(snapshot());

watch(() => form.tipo_ensino, (novo) => {
    if (novo === TIPO_ENSINO_UNIVERSITARIO) form.etapas_ensino = [ETAPA_SUPERIOR];
});

// Enquanto a configuração inicial não foi feita (configurado_em nulo) entra logo em edição
// quem pode editar; o estabelecimento mínimo já existe, só falta completá-lo.
const naoConfigurado = computed(() => !props.estabelecimento?.configurado_em);
const editando = ref(naoConfigurado.value && can('estabelecimento.editar'));
const processing = ref(false);
const errors = ref({});

function editar() {
    Object.assign(form, snapshot());
    errors.value = {};
    editando.value = true;
}

function cancelar() {
    Object.assign(form, snapshot());
    errors.value = {};
    editando.value = false;
}

// Completude da ficha (sobre os dados gravados, não sobre o que se está a editar).
const CAMPOS_FICHA = [
    ['nome', 'nome'], ['nome_abreviado', 'nome abreviado'], ['tipo', 'tipo'], ['tipo_ensino', 'tipo de ensino'],
    ['nif', 'NIF'], ['codigo_mined', 'código MINED'], ['numero_alvara', 'alvará'], ['email', 'email'], ['telefone', 'telefone'],
    ['website', 'website'], ['endereco', 'endereço'], ['municipio', 'município'], ['provincia', 'província'],
    ['responsavel_nome', 'responsável'], ['responsavel_cargo', 'cargo do responsável'], ['ano_fundacao', 'ano de fundação'],
];
const completude = computed(() => {
    const e = props.estabelecimento ?? {};
    const faltam = CAMPOS_FICHA.filter(([chave]) => e[chave] === null || e[chave] === undefined || e[chave] === '').map(([, rotulo]) => rotulo);
    if (!(props.etapasEnsino ?? []).length) faltam.push('etapas de ensino');
    const total = CAMPOS_FICHA.length + 1;

    return { percentual: Math.round(((total - faltam.length) / total) * 100), faltam };
});

// Resumo (vista): anos de existência, etapas, hora local no fuso da escola e responsável.
const NOMES_ETAPAS = { 1: 'Creche', 2: 'Pré-Escolar', 3: 'Ensino Primário', 4: 'Ensino Secundário', 5: 'Ensino Superior' };
const anosDeExistencia = computed(() => {
    const ano = Number(props.estabelecimento?.ano_fundacao);

    return ano ? Math.max(0, new Date().getFullYear() - ano) : null;
});
const agora = ref(new Date());
let relogio = null;
onMounted(() => { relogio = setInterval(() => { agora.value = new Date(); }, 30000); });
onBeforeUnmount(() => clearInterval(relogio));
const horaLocal = computed(() => {
    try {
        return new Intl.DateTimeFormat('pt-PT', { hour: '2-digit', minute: '2-digit', timeZone: form.fuso_horario }).format(agora.value);
    } catch {
        return '—';
    }
});
const resumo = computed(() => [
    { rotulo: 'Fundada', valor: props.estabelecimento?.ano_fundacao || '—', nota: anosDeExistencia.value !== null ? `há ${anosDeExistencia.value} ano(s)` : 'ano por definir', cor: 'primary' },
    { rotulo: 'Etapas de ensino', valor: String((props.etapasEnsino ?? []).length), nota: (props.etapasEnsino ?? []).map((id) => NOMES_ETAPAS[id]).join(' · ') || 'por definir', cor: 'success' },
    { rotulo: 'Hora local', valor: horaLocal.value, nota: form.fuso_horario, cor: 'info' },
    { rotulo: 'Responsável', valor: props.estabelecimento?.responsavel_nome || '—', nota: props.estabelecimento?.responsavel_cargo || 'cargo por definir', cor: 'warning', texto: true },
]);
const mapaUrl = computed(() => {
    const local = [props.estabelecimento?.endereco, props.estabelecimento?.municipio, props.estabelecimento?.provincia, 'Angola'].filter(Boolean).join(', ');

    return props.estabelecimento?.endereco || props.estabelecimento?.municipio ? `https://www.google.com/maps/search/?api=1&query=${encodeURIComponent(local)}` : null;
});

function submeter() {
    processing.value = true;
    errors.value = {};

    router.put('/estabelecimento', form, {
        preserveScroll: true,
        onSuccess: () => {
            toast.success('Dados do estabelecimento atualizados com sucesso.');
            editando.value = false;
        },
        onError: (erros) => {
            errors.value = erros;
            toast.error(Object.values(erros)[0]);
        },
        onFinish: () => {
            processing.value = false;
        },
    });
}
</script>

<template>
    <div class="app-container container-xxl py-6">
        <EstabelecimentoCabecalho :estabelecimento="estabelecimento" :etapas="etapasEnsino" :completude="completude" />

        <div v-if="naoConfigurado" class="alert alert-warning d-flex align-items-center">
            <i class="ki-duotone ki-information-5 fs-2 me-3"><span class="path1"></span><span class="path2"></span><span class="path3"></span></i>
            <div>Complete os dados da escola para começar a usar o sistema.</div>
        </div>

        <div class="d-flex flex-wrap align-items-center justify-content-between gap-3">
            <EstabelecimentoTabs atual="dados" class="mb-0" />
            <button v-if="!editando && can('estabelecimento.editar')" type="button" class="btn btn-sm btn-success mb-8" @click="editar">
                <AcaoIcone acao="editar" herda-cor class="me-1" />
                Editar dados
            </button>
        </div>

        <!-- Resumo rápido (só na vista) -->
        <div v-if="!editando" class="row g-4 mb-6">
            <div v-for="r in resumo" :key="r.rotulo" class="col-12 col-sm-6 col-xl-3">
                <div class="card h-100 border-start border-4" :class="`border-${r.cor}`"><div class="card-body py-5">
                    <div class="text-muted fs-8 fw-bold text-uppercase mb-1">{{ r.rotulo }}</div>
                    <div class="fw-bolder lh-1 text-truncate" :class="[`text-${r.cor}`, r.texto ? 'fs-4' : 'fs-2']">{{ r.valor }}</div>
                    <div class="text-muted fs-7 mt-3 text-truncate">{{ r.nota }}</div>
                </div></div>
            </div>
        </div>

        <p v-else class="text-muted fs-6 mb-6">A editar: as alterações só ficam gravadas depois de carregar em «Guardar alterações».</p>

        <form @submit.prevent="submeter">
            <div class="row g-6">
                <div class="col-12 col-xl-8">
                    <SeccaoFicha titulo="Identificação" subtitulo="Como a escola se apresenta oficialmente" icone="ki-bank" :paths="2">
                        <div class="row">
                            <div class="col-md-8">
                                <CampoFicha v-model="form.nome" label="Nome do estabelecimento" required :editing="editando" placeholder="ex: Escola Secundária Mositec" :error="errors.nome?.[0]" icon="ki-bank" />
                            </div>
                            <div class="col-md-4">
                                <CampoFicha v-model="form.nome_abreviado" label="Nome abreviado" :editing="editando" placeholder="ex: Mositec" :error="errors.nome_abreviado?.[0]" icon="ki-badge" :icon-paths="5" />
                            </div>
                            <div class="col-md-4">
                                <CampoFicha v-model="form.tipo" label="Tipo" type="select" :options="tipos" required :editing="editando" :error="errors.tipo?.[0]" icon="ki-category" :icon-paths="4" />
                            </div>
                            <div class="col-md-4">
                                <CampoFicha v-model="form.tipo_ensino" label="Tipo de Ensino" type="select" :options="tiposEnsino" required :editing="editando" :error="errors.tipo_ensino?.[0]" icon="ki-book-open" :icon-paths="2" />
                            </div>
                            <div class="col-md-4">
                                <CampoFicha v-model="form.ano_fundacao" label="Ano de fundação" type="number" :editing="editando" placeholder="ex: 2010" :error="errors.ano_fundacao?.[0]" icon="ki-calendar-8" :icon-paths="6" />
                            </div>
                            <div class="col-12">
                                <template v-if="form.tipo_ensino === TIPO_ENSINO_UNIVERSITARIO">
                                    <span class="ficha-rotulo d-block mt-3 text-muted fs-8 fw-semibold text-uppercase">Etapas de Ensino</span>
                                    <div class="fw-semibold mt-1">Ensino Superior <span class="text-muted fs-8 fw-normal">(fixo para Ensino Universitário)</span></div>
                                </template>
                                <CampoFicha v-else v-model="form.etapas_ensino" label="Etapas de Ensino" type="checkboxes" :options="etapasNaoSuperior" required :editing="editando" :error="errors.etapas_ensino?.[0]" icon="ki-teacher" :icon-paths="2" />
                                <div v-if="editando && form.tipo_ensino !== TIPO_ENSINO_UNIVERSITARIO" class="text-muted fs-8 mt-2">Os Cursos só existem em escolas com Ensino Secundário. Sem esta etapa, o menu e os campos de curso ficam escondidos (os dados já gravados não se perdem).</div>
                            </div>
                        </div>
                    </SeccaoFicha>

                    <SeccaoFicha titulo="Documentação" subtitulo="Identificadores fiscais e de licenciamento" icone="ki-document" :paths="2">
                        <div class="row">
                            <div class="col-md-4"><CampoFicha v-model="form.nif" label="NIF" :editing="editando" :error="errors.nif?.[0]" icon="ki-document" /></div>
                            <div class="col-md-4"><CampoFicha v-model="form.codigo_mined" label="Código MINED" :editing="editando" :error="errors.codigo_mined?.[0]" icon="ki-code" :icon-paths="4" /></div>
                            <div class="col-md-4"><CampoFicha v-model="form.numero_alvara" label="Número de alvará" :editing="editando" :error="errors.numero_alvara?.[0]" icon="ki-verify" /></div>
                        </div>
                    </SeccaoFicha>

                    <SeccaoFicha titulo="Contacto" subtitulo="Os meios para falar com a escola" icone="ki-sms" :paths="2">
                        <div class="row">
                            <div class="col-md-6"><CampoFicha v-model="form.email" label="Email" type="email" :editing="editando" :error="errors.email?.[0]" icon="ki-sms" :link="form.email ? `mailto:${form.email}` : null" /></div>
                            <div class="col-md-6"><CampoFicha v-model="form.website" label="Website" :editing="editando" placeholder="https://" :error="errors.website?.[0]" icon="ki-compass" :link="form.website ? (form.website.startsWith('http') ? form.website : `https://${form.website}`) : null" /></div>
                            <div class="col-md-6"><CampoFicha v-model="form.telefone" label="Telefone" :editing="editando" :error="errors.telefone?.[0]" icon="ki-phone" :link="form.telefone ? `tel:${form.telefone.replace(/\s+/g, '')}` : null" /></div>
                            <div class="col-md-6"><CampoFicha v-model="form.telefone_alternativo" label="Telefone alternativo" :editing="editando" :error="errors.telefone_alternativo?.[0]" icon="ki-phone" :link="form.telefone_alternativo ? `tel:${form.telefone_alternativo.replace(/\s+/g, '')}` : null" /></div>
                        </div>
                    </SeccaoFicha>

                    <SeccaoFicha titulo="Observações" subtitulo="Notas institucionais usadas em faturas, recibos e relatórios" icone="ki-notepad" :paths="5">
                        <CampoFicha v-model="form.observacoes" label="Notas institucionais" type="textarea" :editing="editando" placeholder="Usadas em faturas, recibos e relatórios" :error="errors.observacoes?.[0]" icon="ki-notepad" :icon-paths="5" />
                    </SeccaoFicha>
                </div>

                <div class="col-12 col-xl-4">
                    <SeccaoFicha titulo="Localização" subtitulo="Onde fica a escola" icone="ki-geolocation" :paths="2">
                        <CampoFicha v-model="form.endereco" label="Endereço" :editing="editando" :error="errors.endereco?.[0]" icon="ki-map" :icon-paths="3" />
                        <CampoFicha v-model="form.caixa_postal" label="Caixa postal" :editing="editando" :error="errors.caixa_postal?.[0]" icon="ki-directbox-default" :icon-paths="4" />
                        <template v-if="editando">
                            <SelectProvinciaMunicipio
                                v-model:provincia="form.provincia"
                                v-model:municipio="form.municipio"
                                :provincias="provincias"
                                :municipios-por-provincia="municipiosPorProvincia"
                                :erro-provincia="errors.provincia?.[0]"
                                :erro-municipio="errors.municipio?.[0]"
                            />
                        </template>
                        <template v-else>
                            <CampoFicha :model-value="form.municipio" label="Município" :editing="false" icon="ki-geolocation" />
                            <CampoFicha :model-value="form.provincia" label="Província" :editing="false" icon="ki-flag" />
                            <a v-if="mapaUrl" :href="mapaUrl" target="_blank" rel="noopener" class="btn btn-sm btn-light-success mt-3">
                                <i class="ki-duotone ki-map fs-4 me-1"><span class="path1"></span><span class="path2"></span><span class="path3"></span></i>Ver no mapa
                            </a>
                        </template>
                    </SeccaoFicha>

                    <SeccaoFicha titulo="Responsável" subtitulo="Quem responde pela escola" icone="ki-profile-circle" :paths="3">
                        <CampoFicha v-model="form.responsavel_nome" label="Nome" :editing="editando" :error="errors.responsavel_nome?.[0]" icon="ki-profile-circle" :icon-paths="3" />
                        <CampoFicha v-model="form.responsavel_cargo" label="Cargo" :editing="editando" :error="errors.responsavel_cargo?.[0]" icon="ki-briefcase" />
                    </SeccaoFicha>

                    <SeccaoFicha titulo="Fuso horário" subtitulo="Define o «hoje» da escola" icone="ki-time" :paths="2">
                        <SelectSolid v-if="editando" v-model="form.fuso_horario" :options="opcoesFusos" searchable />
                        <div v-else class="d-flex align-items-baseline gap-3">
                            <span class="fs-2 fw-bolder text-info lh-1">{{ horaLocal }}</span>
                            <span class="fw-semibold">{{ form.fuso_horario }}</span>
                        </div>
                        <div v-if="errors.fuso_horario" class="text-danger fs-8 mt-1">{{ errors.fuso_horario }}</div>
                        <div class="form-text bg-body-secondary rounded px-3 py-2 mt-3">
                            Usado em vencimentos, atrasos e pagamentos. Não altera as datas já guardadas.
                        </div>
                    </SeccaoFicha>
                </div>
            </div>

            <!-- Barra de acções fixa durante a edição -->
            <div v-if="editando" class="barra-edicao">
                <span class="text-gray-700 fw-semibold fs-7 d-none d-md-inline">A editar os dados da escola</span>
                <div class="d-flex gap-2 ms-auto">
                    <button type="button" class="btn btn-light" :disabled="processing" @click="cancelar">Cancelar</button>
                    <button type="submit" class="btn btn-success" :disabled="processing">Guardar alterações</button>
                </div>
            </div>
        </form>
    </div>
</template>

<style scoped>
.barra-edicao {
    position: sticky;
    bottom: 1rem;
    z-index: 10;
    display: flex;
    align-items: center;
    gap: 1rem;
    padding: 0.85rem 1.25rem;
    border-radius: 0.85rem;
    background: var(--bs-body-bg);
    border: 1px solid var(--bs-gray-300);
    box-shadow: 0 10px 30px rgba(15, 23, 42, 0.15);
}
</style>
