<script setup>
import { computed, ref } from 'vue';
import { Link, router } from '@inertiajs/vue3';
import { toast } from 'vue-sonner';
import AppLayout from '@/Layouts/AppLayout.vue';
import Loader from '@/Components/Shared/Loader.vue';
import ConfirmModal from '@/Components/Shared/ConfirmModal.vue';
import { copiarParaAreaDeTransferencia } from '@/Composables/useCopiar';

const props = defineProps({
    licenca_estado: { type: Object, required: true },
});
defineOptions({ layout: AppLayout });

const estado = computed(() => props.licenca_estado);

const MODOS = {
    normal: { texto: 'Activa', classe: 'badge-light-success' },
    aviso: { texto: 'A terminar', classe: 'badge-light-warning' },
    bloqueado: { texto: 'Bloqueada', classe: 'badge-light-danger' },
};
const modo = computed(() => MODOS[estado.value.modo] ?? MODOS.bloqueado);

function data(iso) {
    return iso ? new Date(iso).toLocaleDateString('pt-PT') : '—';
}
function limiteTexto(l) {
    return l.limite === null ? 'Sem limite' : l.limite;
}

const ACENTO = {
    normal: { cor: '#86EFAC', icone: 'ki-shield-tick', titulo: 'Licença activa', bar: 'bg-success' },
    aviso: { cor: '#FCD34D', icone: 'ki-time', titulo: 'Licença a terminar', bar: 'bg-warning' },
    bloqueado: { cor: '#FCA5A5', icone: 'ki-shield-cross', titulo: 'Licença bloqueada', bar: 'bg-danger' },
};
const acento = computed(() => ACENTO[estado.value.modo] ?? ACENTO.bloqueado);

// Linha do tempo: início → expiração → fim da tolerância, com o marcador de hoje.
const linhaDoTempo = computed(() => {
    const e = estado.value;
    if (!e.tem_licenca || !e.inicio || !e.expira_em || !e.bloqueia_em) return null;
    const ini = new Date(e.inicio).getTime();
    const exp = new Date(e.expira_em).getTime();
    const blo = new Date(e.bloqueia_em).getTime();
    const total = Math.max(blo - ini, 1);
    const pct = (t) => Math.min(100, Math.max(0, ((t - ini) / total) * 100));
    const expira = pct(exp);
    // Com tolerância curta (ou nula) os rótulos de expiração e bloqueio chocariam: fundem-se no fim da barra.
    return { expira, hoje: pct(Date.now()), separados: expira > 15 && expira < 85 };
});
const diasTexto = computed(() => {
    const d = estado.value.dias_restantes;
    if (d === null || d === undefined) return '';
    if (d < 0) return 'Já expirou';
    if (d === 0) return 'Expira hoje';
    return d === 1 ? 'Falta 1 dia' : `Faltam ${d} dias`;
});

function percentagem(l) {
    return l.limite ? Math.min(100, Math.round((l.utilizacao / l.limite) * 100)) : 0;
}
function corBarra(l) {
    const p = percentagem(l);
    return p >= 100 ? 'bg-danger' : p >= 80 ? 'bg-warning' : 'bg-success';
}
const ICONE_RECURSO = { alunos: 'ki-people', utilizadores: 'ki-user' };

const token = ref('');
const ficheiro = ref(null);
const inputFicheiro = ref(null);
const erros = ref({});
const aEnviar = ref(false);
const aConfirmar = ref(false);

function escolherFicheiro(e) {
    ficheiro.value = e.target.files?.[0] ?? null;
}
function limpar() {
    token.value = '';
    ficheiro.value = null;
    erros.value = {};
    if (inputFicheiro.value) inputFicheiro.value.value = '';
}
const podeEnviar = computed(() => !aEnviar.value && (ficheiro.value !== null || token.value.trim() !== ''));

// O envio só acontece depois de o utilizador confirmar no modal.
function pedirConfirmacao() {
    if (podeEnviar.value) aConfirmar.value = true;
}

function instalar() {
    if (!podeEnviar.value) return;
    aEnviar.value = true;
    erros.value = {};

    router.post('/licenca/instalar', { token: token.value, ficheiro: ficheiro.value }, {
        forceFormData: ficheiro.value !== null,
        preserveScroll: true,
        onSuccess: (page) => {
            toast.success(page.props.flash?.success ?? 'Licença instalada com sucesso.');
            limpar();
        },
        onError: (e) => {
            erros.value = e;
            toast.error(Object.values(e)[0]);
        },
        onFinish: () => {
            aEnviar.value = false;
            aConfirmar.value = false;
        },
    });
}

async function copiarCodigo() {
    const ok = await copiarParaAreaDeTransferencia(estado.value.codigo_instalacao);
    if (ok) toast.success('Código copiado.');
    else toast.error('Não foi possível copiar. Seleccione o código e copie manualmente.');
}
</script>

<template>
    <div class="app-container container-xxl py-6">
        <div v-if="estado.modo === 'bloqueado'" class="alert alert-danger mb-6">
            <template v-if="estado.revogada">A licença foi revogada.</template>
            <template v-else-if="estado.tem_licenca">A licença expirou e o período de tolerância terminou.</template>
            <template v-else>Esta escola ainda não tem uma licença instalada.</template>
            Instale abaixo a licença (ou a renovação) recebida para voltar a utilizar o sistema.
        </div>

        <div class="row g-6">
            <!-- Cabeçalho: estado e linha do tempo da licença -->
            <div class="col-12">
                <div class="card overflow-hidden border-0" style="background: #0F172A">
                    <div class="card-body p-6 p-lg-9">
                        <div class="d-flex flex-wrap align-items-center gap-5" :class="{ 'mb-7': linhaDoTempo }">
                            <div class="symbol symbol-65px">
                                <span class="symbol-label" :style="{ background: '#1E293B' }">
                                    <i class="ki-duotone fs-1" :class="acento.icone" :style="{ color: acento.cor }"><span class="path1"></span><span class="path2"></span><span class="path3"></span></i>
                                </span>
                            </div>
                            <div class="flex-grow-1">
                                <h1 class="fs-1 fw-bold mb-1" style="color: #F8FAFC">{{ acento.titulo }}</h1>
                                <div class="fs-6" style="color: #94A3B8">
                                    <template v-if="estado.tem_licenca">{{ estado.modalidade }} · {{ diasTexto }}</template>
                                    <template v-else>Instale uma licença para activar o sistema.</template>
                                </div>
                            </div>
                        </div>

                        <div v-if="linhaDoTempo" class="px-2 pb-2">
                            <div class="position-relative" style="height: 12px; border-radius: 6px; background: #1E293B">
                                <div class="position-absolute top-0 start-0 h-100" :style="{ width: linhaDoTempo.expira + '%', background: '#166534', borderRadius: '6px 0 0 6px' }"></div>
                                <div class="position-absolute top-0 h-100" :style="{ left: linhaDoTempo.expira + '%', right: 0, background: '#B45309', borderRadius: '0 6px 6px 0', opacity: 0.8 }"></div>
                                <div class="position-absolute" :style="{ left: linhaDoTempo.hoje + '%', top: '-6px', transform: 'translateX(-50%)' }" title="Hoje">
                                    <div style="width: 4px; height: 24px; border-radius: 2px" :style="{ background: acento.cor }"></div>
                                </div>
                            </div>
                            <div class="position-relative mt-3" style="height: 54px">
                                <div class="position-absolute start-0">
                                    <div class="fs-8" style="color: #94A3B8">Início</div>
                                    <div class="fw-bold" style="color: #F8FAFC">{{ data(estado.inicio) }}</div>
                                </div>
                                <div v-if="linhaDoTempo.separados" class="position-absolute text-center" :style="{ left: linhaDoTempo.expira + '%', transform: 'translateX(-50%)' }">
                                    <div class="fs-8" style="color: #94A3B8">Expira</div>
                                    <div class="fw-bold" style="color: #F8FAFC">{{ data(estado.expira_em) }}</div>
                                </div>
                                <div class="position-absolute end-0 text-end">
                                    <template v-if="linhaDoTempo.separados">
                                        <div class="fs-8" style="color: #94A3B8">Bloqueio (tolerância de {{ estado.tolerancia_dias }} dias)</div>
                                        <div class="fw-bold" style="color: #F8FAFC">{{ data(estado.bloqueia_em) }}</div>
                                    </template>
                                    <template v-else-if="estado.tolerancia_dias > 0">
                                        <div class="fs-8" style="color: #94A3B8">Expira em {{ data(estado.expira_em) }} · tolerância de {{ estado.tolerancia_dias }} dias</div>
                                        <div class="fw-bold" style="color: #F8FAFC">Bloqueio em {{ data(estado.bloqueia_em) }}</div>
                                    </template>
                                    <template v-else>
                                        <div class="fs-8" style="color: #94A3B8">Expira e bloqueia</div>
                                        <div class="fw-bold" style="color: #F8FAFC">{{ data(estado.expira_em) }}</div>
                                    </template>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Limites: um medidor por recurso -->
            <div v-for="l in estado.limites" :key="l.recurso" class="col-md-6">
                <div class="card h-100">
                    <div class="card-body">
                        <div class="d-flex align-items-center gap-4 mb-5">
                            <div class="symbol symbol-50px">
                                <span class="symbol-label bg-light-primary">
                                    <i class="ki-duotone fs-2x text-primary" :class="ICONE_RECURSO[l.recurso] ?? 'ki-abstract-26'"><span class="path1"></span><span class="path2"></span><span class="path3"></span><span class="path4"></span><span class="path5"></span></i>
                                </span>
                            </div>
                            <div class="flex-grow-1">
                                <div class="fw-bold fs-5 text-gray-900">{{ l.nome }}</div>
                                <div class="text-gray-600 fs-7 fw-semibold">{{ l.limite === null ? "Plano ilimitado" : "Contratado: " + l.limite }}</div>
                            </div>
                            <div class="text-end">
                                <span class="fs-1 fw-bold lh-1" :class="l.limite !== null && l.utilizacao >= l.limite ? 'text-danger' : 'text-gray-900'">{{ l.utilizacao }}</span>
                                <div class="text-gray-600 fs-7 fw-semibold">em uso</div>
                            </div>
                        </div>
                        <template v-if="l.limite !== null">
                            <div class="progress h-8px"><div class="progress-bar" :class="corBarra(l)" :style="{ width: percentagem(l) + '%' }"></div></div>
                            <div class="d-flex justify-content-between text-gray-600 fs-7 fw-semibold mt-3">
                                <span>{{ percentagem(l) }}% do limite</span>
                                <span>{{ Math.max(0, l.limite - l.utilizacao) }} disponíveis</span>
                            </div>
                        </template>
                        <div v-else class="text-success fs-7 fw-semibold d-flex align-items-center gap-2">
                            <i class="ki-duotone ki-verify fs-3"><span class="path1"></span><span class="path2"></span></i>
                            Sem limite de utilização
                        </div>
                    </div>
                </div>
            </div>

            <!-- Módulos contratados -->
            <div class="col-lg-7 align-self-start">
                <div class="card">
                    <div class="card-header min-h-auto py-4">
                        <h3 class="card-title fs-5 fw-bold text-gray-900">Módulos contratados</h3>
                    </div>
                    <div class="card-body py-5">
                        <div v-if="estado.modulos.length" class="d-flex flex-wrap gap-2">
                            <span v-for="m in estado.modulos" :key="m.alias" class="badge badge-light-success fs-7 fw-semibold py-2 px-3 d-inline-flex align-items-center gap-1">
                                <i class="ki-duotone ki-check-circle fs-5 text-success"><span class="path1"></span><span class="path2"></span></i>{{ m.nome }}
                            </span>
                        </div>
                        <div v-else class="text-gray-600 fs-7 fw-semibold">Nenhum módulo adicional contratado.</div>
                    </div>
                </div>
            </div>

            <!-- Código da instalação -->
            <div class="col-lg-5">
                <div class="card">
                    <div class="card-header min-h-auto py-4">
                        <h3 class="card-title fs-5 fw-bold text-gray-900">Código da instalação</h3>
                    </div>
                    <div class="card-body py-5">
                        <div class="text-gray-600 fs-7 fw-semibold mb-3">Indique este código ao pedir ou renovar a licença.</div>
                        <div class="input-group">
                            <input type="text" class="form-control form-control-solid font-monospace" :value="estado.codigo_instalacao" readonly aria-label="Código da instalação" />
                            <button type="button" class="btn btn-light-primary" @click="copiarCodigo">
                                <i class="ki-duotone ki-copy fs-3"><span class="path1"></span><span class="path2"></span></i>
                                Copiar
                            </button>
                        </div>
                    </div>
                </div>
            </div>

            <div class="col-12" data-testid="exportar-dados">
                <div class="card">
                    <div class="card-header min-h-auto py-4">
                        <h3 class="card-title fs-5 fw-bold text-gray-900">Exportar dados</h3>
                    </div>
                    <div class="card-body">
                        <p class="text-muted fs-7">Mesmo com a licença bloqueada pode descarregar os dados da escola (Excel). Ficheiros grandes são preparados em segundo plano e ficam em «Os meus ficheiros».</p>
                        <div class="d-flex flex-wrap gap-2">
                            <a v-for="e in estado.exportacoes" :key="e.url" :href="e.url" class="btn btn-light-primary btn-sm">
                                <i class="ki-duotone ki-cloud-download fs-4"><span class="path1"></span><span class="path2"></span></i>
                                {{ e.nome }}
                            </a>
                            <Link v-if="estado.ficheiros_url" :href="estado.ficheiros_url" class="btn btn-light btn-sm">Os meus ficheiros</Link>
                        </div>
                    </div>
                </div>
            </div>

            <div class="col-12">
                <div class="card">
                    <div class="card-header min-h-auto py-4">
                        <h3 class="card-title fs-5 fw-bold text-gray-900">Instalar ou renovar licença</h3>
                    </div>
                    <div class="card-body">
                        <form @submit.prevent="pedirConfirmacao">
                            <label for="licenca-token" class="fw-semibold fs-6 mb-2">Cole o texto da licença</label>
                            <textarea id="licenca-token" v-model="token" rows="5" class="form-control form-control-solid font-monospace" :class="{ 'is-invalid': erros.token }" autocomplete="off" spellcheck="false" :disabled="aEnviar"></textarea>

                            <div class="fw-semibold fs-6 my-4 text-muted text-center">ou</div>

                            <label for="licenca-ficheiro" class="fw-semibold fs-6 mb-2">Carregue o ficheiro da licença</label>
                            <input id="licenca-ficheiro" ref="inputFicheiro" type="file" class="form-control form-control-solid" accept=".txt,.lic,.paseto,text/plain" :disabled="aEnviar" @change="escolherFicheiro" />

                            <div v-if="erros.token || erros.ficheiro" class="text-danger fs-7 mt-2">{{ erros.token ?? erros.ficheiro }}</div>

                            <div class="d-flex justify-content-end gap-2 mt-6">
                                <button v-if="token || ficheiro" type="button" class="btn btn-light-danger" :disabled="aEnviar" @click="limpar">Cancelar</button>
                                <button type="submit" class="btn btn-primary" :disabled="!podeEnviar">
                                    <span v-if="!aEnviar">Instalar licença</span>
                                    <span v-else>A instalar... <Loader size="0.3px" class="align-middle ms-2" /></span>
                                </button>
                            </div>
                        </form>
                    </div>
                </div>
            </div>
        </div>

        <ConfirmModal
            :show="aConfirmar"
            titulo="Instalar licença"
            mensagem="A licença indicada vai ser instalada e passa a valer nesta instalação, substituindo a actual. Deseja continuar?"
            texto-confirmar="Instalar"
            variante="primary"
            :processando="aEnviar"
            @confirmar="instalar"
            @cancelar="aConfirmar = false"
        />
    </div>
</template>
