<script setup>
import { computed, ref } from 'vue';
import { Link, router } from '@inertiajs/vue3';
import { toast } from 'vue-sonner';
import AppLayout from '@/Layouts/AppLayout.vue';
import Loader from '@/Components/Shared/Loader.vue';
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

const token = ref('');
const ficheiro = ref(null);
const inputFicheiro = ref(null);
const erros = ref({});
const aEnviar = ref(false);

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
        <div class="d-flex flex-wrap align-items-center justify-content-between gap-3 mb-6">
            <h1 class="fs-2 fw-bold text-gray-900 mb-0">Licença</h1>
            <span class="badge fs-6" :class="modo.classe">{{ modo.texto }}</span>
        </div>

        <div v-if="estado.modo === 'bloqueado'" class="alert alert-danger mb-6">
            <template v-if="estado.revogada">A licença foi revogada.</template>
            <template v-else-if="estado.tem_licenca">A licença expirou e o período de tolerância terminou.</template>
            <template v-else>Esta escola ainda não tem uma licença instalada.</template>
            Instale abaixo a licença (ou a renovação) recebida para voltar a utilizar o sistema.
        </div>

        <div class="row g-6">
            <div class="col-lg-6">
                <div class="card h-100">
                    <div class="card-header min-h-auto py-4">
                        <h3 class="card-title fs-6 fw-bold text-uppercase text-gray-600">Estado</h3>
                    </div>
                    <div class="card-body">
                        <template v-if="estado.tem_licenca">
                            <dl class="row mb-0 gy-2">
                                <dt class="col-6 text-muted fw-semibold">Modalidade</dt>
                                <dd class="col-6 mb-0">{{ estado.modalidade }}</dd>
                                <dt class="col-6 text-muted fw-semibold">Início</dt>
                                <dd class="col-6 mb-0">{{ data(estado.inicio) }}</dd>
                                <dt class="col-6 text-muted fw-semibold">Expira em</dt>
                                <dd class="col-6 mb-0">{{ data(estado.expira_em) }}</dd>
                                <dt class="col-6 text-muted fw-semibold">Tolerância</dt>
                                <dd class="col-6 mb-0">{{ estado.tolerancia_dias }} dias</dd>
                                <dt class="col-6 text-muted fw-semibold">Bloqueio em</dt>
                                <dd class="col-6 mb-0">{{ data(estado.bloqueia_em) }}</dd>
                                <dt class="col-6 text-muted fw-semibold">Dias até expirar</dt>
                                <dd class="col-6 mb-0">{{ estado.dias_restantes }}</dd>
                            </dl>
                        </template>
                        <p v-else class="text-muted mb-0">Sem licença instalada.</p>
                    </div>
                </div>
            </div>

            <div class="col-lg-6">
                <div class="card h-100">
                    <div class="card-header min-h-auto py-4">
                        <h3 class="card-title fs-6 fw-bold text-uppercase text-gray-600">Código da instalação</h3>
                    </div>
                    <div class="card-body">
                        <p class="text-muted fs-7">Indique este código ao pedir ou renovar a licença.</p>
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

            <div class="col-12">
                <div class="card">
                    <div class="card-header min-h-auto py-4">
                        <h3 class="card-title fs-6 fw-bold text-uppercase text-gray-600">Limites e utilização</h3>
                    </div>
                    <div class="card-body p-0">
                        <table class="table align-middle gs-6 gy-4 mb-0 d-none d-md-table">
                            <thead>
                                <tr class="text-muted fw-bold fs-7 text-uppercase">
                                    <th>Recurso</th>
                                    <th>Contratado</th>
                                    <th>Em uso</th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr v-for="l in estado.limites" :key="l.recurso">
                                    <td class="fw-bold text-gray-800">{{ l.nome }}</td>
                                    <td>{{ limiteTexto(l) }}</td>
                                    <td :class="{ 'text-danger fw-bold': l.limite !== null && l.utilizacao >= l.limite }">{{ l.utilizacao }}</td>
                                </tr>
                            </tbody>
                        </table>
                        <div class="d-md-none">
                            <div v-for="l in estado.limites" :key="l.recurso" class="p-5 border-bottom border-gray-200">
                                <div class="fw-bold text-gray-800 fs-5">{{ l.nome }}</div>
                                <div class="text-muted fs-7">Contratado: {{ limiteTexto(l) }}</div>
                                <div class="fs-7" :class="l.limite !== null && l.utilizacao >= l.limite ? 'text-danger fw-bold' : 'text-gray-700'">Em uso: {{ l.utilizacao }}</div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <div class="col-12">
                <div class="card">
                    <div class="card-header min-h-auto py-4">
                        <h3 class="card-title fs-6 fw-bold text-uppercase text-gray-600">Módulos contratados</h3>
                    </div>
                    <div class="card-body">
                        <div v-if="estado.modulos.length" class="d-flex flex-wrap gap-2">
                            <span v-for="m in estado.modulos" :key="m.alias" class="badge badge-light-primary fs-7">{{ m.nome }}</span>
                        </div>
                        <p v-else class="text-muted mb-0">Nenhum módulo adicional contratado.</p>
                    </div>
                </div>
            </div>

            <div class="col-12" data-testid="exportar-dados">
                <div class="card">
                    <div class="card-header min-h-auto py-4">
                        <h3 class="card-title fs-6 fw-bold text-uppercase text-gray-600">Exportar dados</h3>
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
                        <h3 class="card-title fs-6 fw-bold text-uppercase text-gray-600">Instalar ou renovar licença</h3>
                    </div>
                    <div class="card-body">
                        <form @submit.prevent="instalar">
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
    </div>
</template>
