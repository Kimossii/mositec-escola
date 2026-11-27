<script setup>
import { ref, computed } from 'vue';
import { useForm, router, usePage } from '@inertiajs/vue3';
import { toast } from 'vue-sonner';
import AppLayout from '@/Layouts/AppLayout.vue';
import ConfirmModal from '@/Components/Shared/ConfirmModal.vue';
import Loader from '@/Components/Shared/Loader.vue';

const props = defineProps({
    // null = a escola ainda não configurou; nunca traz a senha, só `tem_senha`.
    configuracao: { type: Object, default: null },
});
defineOptions({ layout: AppLayout });

const PORTAS = [25, 465, 587, 2525];
const ENCRIPTACOES = [
    { valor: 0, rotulo: 'Nenhuma' },
    { valor: 1, rotulo: 'STARTTLS' },
    { valor: 2, rotulo: 'SSL/TLS' },
];
// Só preenchem servidor, porta e encriptação; o resto é da escola.
const PREDEFINICOES = [
    { nome: 'Gmail', servidor: 'smtp.gmail.com', porta: 587, encriptacao: 1 },
    { nome: 'Outlook', servidor: 'smtp-mail.outlook.com', porta: 587, encriptacao: 1 },
    { nome: 'Zoho', servidor: 'smtp.zoho.com', porta: 465, encriptacao: 2 },
    { nome: 'Outro', servidor: '', porta: 587, encriptacao: 1 },
];
const ESTADOS = { 0: ['warning', 'Por verificar'], 1: ['success', 'Verificado'], 2: ['danger', 'Com falha'] };

const c = props.configuracao;
const form = useForm({
    servidor: c?.servidor ?? '',
    porta: c?.porta ?? 587,
    encriptacao: c?.encriptacao ?? 1,
    utilizador: c?.utilizador ?? '',
    senha: '',
    validar_certificado: c?.validar_certificado ?? true,
    dominio_ehlo: c?.dominio_ehlo ?? '',
    tempo_limite: c?.tempo_limite ?? 15,
    remetente_nome: c?.remetente_nome ?? '',
    remetente_endereco: c?.remetente_endereco ?? '',
    responder_para: c?.responder_para ?? '',
});

const testeForm = useForm({ destino: '' });
const aConfirmarRegresso = ref(false);
const aDesactivar = ref(false);

const estado = computed(() => (props.configuracao ? ESTADOS[props.configuracao.estado] : null));
const sujo = computed(() => form.isDirty);
const bloqueada = computed(() => props.configuracao?.bloqueada_pela_plataforma === true);
const podeTestar = computed(() => props.configuracao && !sujo.value && !bloqueada.value);

function aplicar(p) {
    form.servidor = p.servidor;
    form.porta = p.porta;
    form.encriptacao = p.encriptacao;
}

function avisar() {
    const flash = usePage().props.flash ?? {};
    if (flash.success) toast.success(flash.success);
    if (flash.warning) toast.error(flash.warning);
}

function guardar() {
    form.put('/configuracao-email', {
        preserveScroll: true,
        onSuccess: () => {
            form.senha = '';
            avisar();
        },
    });
}

function testar() {
    testeForm.post('/configuracao-email/testar', { preserveScroll: true, onSuccess: avisar });
}

function voltarACentral() {
    aDesactivar.value = true;
    router.post('/configuracao-email/desactivar', {}, {
        preserveScroll: true,
        onSuccess: avisar,
        onFinish: () => {
            aDesactivar.value = false;
            aConfirmarRegresso.value = false;
        },
    });
}

function formatar(quando) {
    return quando ? quando.slice(8, 10) + '/' + quando.slice(5, 7) + '/' + quando.slice(0, 4) + ' ' + quando.slice(11, 16) : '—';
}
</script>

<template>
    <div class="app-container container-fluid py-6">
        <div class="mb-6">
            <h1 class="fs-2 fw-bold mb-1">Email da escola</h1>
            <div class="text-muted">Envie os emails da escola pelo seu próprio servidor SMTP. Enquanto não estiver verificado, a escola envia pela central.</div>
        </div>

        <div v-if="bloqueada" class="alert alert-danger mb-6" role="alert">
            A MosiTec bloqueou o servidor próprio desta escola: os emails saem pela central e não é possível testar nem reactivar. Contacte a MosiTec.
        </div>
        <div v-if="$page.props.errors?.geral" class="alert alert-danger mb-6" role="alert">{{ $page.props.errors.geral }}</div>

        <div v-if="configuracao" class="card card-flush mb-6">
            <div class="card-body py-5 d-flex flex-wrap align-items-center gap-4">
                <span class="badge fs-7" :class="`badge-light-${estado[0]}`">{{ estado[1] }}</span>
                <span v-if="configuracao.activo" class="badge badge-light-success fs-7">A enviar pelo servidor da escola</span>
                <span v-else class="badge badge-light fs-7">A enviar pela central</span>
                <span v-if="configuracao.verificada_em" class="text-muted fs-7">Verificado em {{ formatar(configuracao.verificada_em) }}</span>
                <div v-if="configuracao.ultimo_erro" class="text-danger fs-7 text-break w-100">{{ configuracao.ultimo_erro }}</div>
                <div class="ms-auto">
                    <button v-if="configuracao.activo" type="button" class="btn btn-sm btn-light-danger" @click="aConfirmarRegresso = true">Voltar à central</button>
                </div>
            </div>
        </div>

        <form class="card card-flush mb-6" @submit.prevent="guardar">
            <div class="card-header pt-6">
                <h3 class="card-title">Servidor SMTP</h3>
                <div class="card-toolbar gap-2">
                    <button v-for="p in PREDEFINICOES" :key="p.nome" type="button" class="btn btn-sm btn-light" @click="aplicar(p)">{{ p.nome }}</button>
                </div>
            </div>
            <div class="card-body">
                <div class="row g-5 mb-5">
                    <div class="col-md-6">
                        <label class="form-label required">Servidor</label>
                        <input v-model="form.servidor" type="text" class="form-control form-control-solid" placeholder="smtp.exemplo.com" />
                        <div v-if="form.errors.servidor" class="text-danger fs-7 mt-1">{{ form.errors.servidor }}</div>
                    </div>
                    <div class="col-md-3">
                        <label class="form-label required">Porta</label>
                        <select v-model.number="form.porta" class="form-select form-select-solid">
                            <option v-for="p in PORTAS" :key="p" :value="p">{{ p }}</option>
                        </select>
                        <div v-if="form.errors.porta" class="text-danger fs-7 mt-1">{{ form.errors.porta }}</div>
                    </div>
                    <div class="col-md-3">
                        <label class="form-label required">Encriptação</label>
                        <select v-model.number="form.encriptacao" class="form-select form-select-solid">
                            <option v-for="e in ENCRIPTACOES" :key="e.valor" :value="e.valor">{{ e.rotulo }}</option>
                        </select>
                        <div v-if="form.errors.encriptacao" class="text-danger fs-7 mt-1">{{ form.errors.encriptacao }}</div>
                    </div>
                </div>

                <div class="row g-5 mb-5">
                    <div class="col-md-6">
                        <label class="form-label">Utilizador</label>
                        <input v-model="form.utilizador" type="text" class="form-control form-control-solid" autocomplete="off" />
                        <div v-if="form.errors.utilizador" class="text-danger fs-7 mt-1">{{ form.errors.utilizador }}</div>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label">Senha</label>
                        <input v-model="form.senha" type="password" class="form-control form-control-solid" autocomplete="new-password"
                               :placeholder="configuracao?.tem_senha ? '•••••••• (deixe em branco para manter)' : ''" />
                        <div v-if="form.errors.senha" class="text-danger fs-7 mt-1">{{ form.errors.senha }}</div>
                    </div>
                    <div class="col-12 text-muted fs-7">Utilizador e senha são opcionais: deixe em branco para servidores sem autenticação.</div>
                </div>

                <div class="row g-5 mb-5">
                    <div class="col-md-4">
                        <label class="form-label">Domínio EHLO</label>
                        <input v-model="form.dominio_ehlo" type="text" class="form-control form-control-solid" placeholder="escola.exemplo.com" />
                        <div v-if="form.errors.dominio_ehlo" class="text-danger fs-7 mt-1">{{ form.errors.dominio_ehlo }}</div>
                    </div>
                    <div class="col-md-4">
                        <label class="form-label required">Tempo limite (segundos)</label>
                        <input v-model.number="form.tempo_limite" type="number" min="5" max="30" class="form-control form-control-solid" />
                        <div v-if="form.errors.tempo_limite" class="text-danger fs-7 mt-1">{{ form.errors.tempo_limite }}</div>
                    </div>
                    <div class="col-md-4 d-flex flex-column justify-content-end">
                        <label class="form-check form-check-custom form-check-solid">
                            <input v-model="form.validar_certificado" type="checkbox" class="form-check-input" />
                            <span class="form-check-label">Validar o certificado do servidor</span>
                        </label>
                        <div v-if="!form.validar_certificado" class="text-danger fs-7 mt-2">
                            Atenção: sem validar o certificado, a ligação pode ser interceptada. Use apenas com certificados autoassinados de confiança.
                        </div>
                    </div>
                </div>

                <div class="separator my-6"></div>

                <div class="row g-5">
                    <div class="col-md-4">
                        <label class="form-label">Nome do remetente</label>
                        <input v-model="form.remetente_nome" type="text" class="form-control form-control-solid" placeholder="Nome da escola" />
                        <div v-if="form.errors.remetente_nome" class="text-danger fs-7 mt-1">{{ form.errors.remetente_nome }}</div>
                    </div>
                    <div class="col-md-4">
                        <label class="form-label required">Endereço do remetente</label>
                        <input v-model="form.remetente_endereco" type="email" class="form-control form-control-solid" placeholder="secretaria@escola.ao" />
                        <div v-if="form.errors.remetente_endereco" class="text-danger fs-7 mt-1">{{ form.errors.remetente_endereco }}</div>
                    </div>
                    <div class="col-md-4">
                        <label class="form-label">Responder para</label>
                        <input v-model="form.responder_para" type="email" class="form-control form-control-solid" />
                        <div v-if="form.errors.responder_para" class="text-danger fs-7 mt-1">{{ form.errors.responder_para }}</div>
                    </div>
                </div>
            </div>
            <div class="card-footer d-flex align-items-center gap-4">
                <button type="submit" class="btn btn-primary" :disabled="form.processing">
                    <Loader v-if="form.processing" class="me-2" />
                    Guardar
                </button>
                <span class="text-muted fs-7">Alterar o servidor ou o remetente obriga a testar o envio outra vez.</span>
            </div>
        </form>

        <form v-if="configuracao" class="card card-flush" @submit.prevent="testar">
            <div class="card-header pt-6"><h3 class="card-title">Testar envio</h3></div>
            <div class="card-body">
                <p class="text-muted">Envia um email de teste para o endereço indicado. Só um envio bem-sucedido activa o servidor da escola (limite de 5 testes por hora).</p>
                <div class="d-flex flex-wrap gap-3 align-items-start">
                    <div>
                        <input v-model="testeForm.destino" type="email" class="form-control form-control-solid w-300px" placeholder="o-seu@email.com" />
                        <div v-if="testeForm.errors.destino" class="text-danger fs-7 mt-1">{{ testeForm.errors.destino }}</div>
                    </div>
                    <button type="submit" class="btn btn-primary" :disabled="!podeTestar || testeForm.processing">
                        <Loader v-if="testeForm.processing" class="me-2" />
                        Testar envio
                    </button>
                </div>
                <div v-if="sujo" class="text-warning fs-7 mt-3">Há alterações por guardar: guarde primeiro, o teste usa a configuração guardada.</div>
            </div>
        </form>

        <ConfirmModal
            :show="aConfirmarRegresso"
            titulo="Voltar à central"
            mensagem="A escola deixa de enviar pelo seu servidor e volta a enviar pela central. Para voltar a usar o servidor próprio terá de testar o envio outra vez."
            texto-confirmar="Voltar à central"
            :processando="aDesactivar"
            @confirmar="voltarACentral"
            @cancelar="aConfirmarRegresso = false"
        />
    </div>
</template>
