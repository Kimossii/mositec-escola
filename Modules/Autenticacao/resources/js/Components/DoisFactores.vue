<script setup>
import { onMounted, ref } from 'vue';
import { router } from '@inertiajs/vue3';
import { toast } from 'vue-sonner';
import Loader from '@/Components/Shared/Loader.vue';
import CampoSenha from './CampoSenha.vue';

const props = defineProps({
    activo: { type: Boolean, default: false },
    sugerir: { type: Boolean, default: false }, // só um empurrão: nunca obriga
});

// Banner dispensável: a escolha fica só neste navegador (localStorage pode falhar em janela privada).
const CHAVE_BANNER = 'mosi.2fa.banner.dispensado';
const bannerDispensado = ref(false);
onMounted(() => {
    try {
        bannerDispensado.value = localStorage.getItem(CHAVE_BANNER) === '1';
    } catch {
        bannerDispensado.value = false;
    }
});
function dispensarBanner() {
    bannerDispensado.value = true;
    try {
        localStorage.setItem(CHAVE_BANNER, '1');
    } catch {
        // sem armazenamento: o banner volta no próximo carregamento, sem problema
    }
}

// Estados: 'repouso' | 'senha' (a confirmar a senha, para activar) | 'codigo' (QR visível, a confirmar) | 'desactivar' (a pedir senha)
const passo = ref('repouso');
const senha = ref('');
const codigo = ref('');
const qr = ref('');
const chave = ref('');
const codigosDeRecuperacao = ref([]);
const accaoPendente = ref('');
const aTrabalhar = ref(false);
const erro = ref('');

function mensagem(e, padrao) {
    return e?.response?.data?.errors?.password?.[0]
        ?? e?.response?.data?.errors?.code?.[0]
        ?? e?.response?.data?.message
        ?? padrao;
}

function cancelar() {
    passo.value = 'repouso';
    senha.value = '';
    codigo.value = '';
    qr.value = '';
    chave.value = '';
    erro.value = '';
    accaoPendente.value = '';
}

async function confirmarSenha() {
    await window.axios.post('/user/confirm-password', { password: senha.value });
    senha.value = '';
}

async function iniciarActivacao() {
    aTrabalhar.value = true;
    erro.value = '';
    try {
        await confirmarSenha();
        await window.axios.post('/user/two-factor-authentication');
        qr.value = (await window.axios.get('/user/two-factor-qr-code')).data.svg;
        chave.value = (await window.axios.get('/user/two-factor-secret-key')).data.secretKey;
        passo.value = 'codigo';
    } catch (e) {
        erro.value = mensagem(e, 'Não foi possível iniciar a activação.');
    } finally {
        aTrabalhar.value = false;
    }
}

async function confirmarActivacao() {
    aTrabalhar.value = true;
    erro.value = '';
    try {
        await window.axios.post('/user/confirmed-two-factor-authentication', { code: codigo.value });
        codigosDeRecuperacao.value = (await window.axios.get('/user/two-factor-recovery-codes')).data;
        toast.success('Autenticação em dois factores activada.');
        cancelar();
        router.reload({ only: ['doisFactores'] });
    } catch (e) {
        erro.value = mensagem(e, 'Código inválido. Tente de novo.');
    } finally {
        aTrabalhar.value = false;
    }
}

async function desactivar() {
    aTrabalhar.value = true;
    erro.value = '';
    try {
        await confirmarSenha();
        await window.axios.delete('/user/two-factor-authentication');
        codigosDeRecuperacao.value = [];
        toast.success('Autenticação em dois factores desactivada.');
        cancelar();
        router.reload({ only: ['doisFactores'] });
    } catch (e) {
        erro.value = mensagem(e, 'Não foi possível desactivar.');
    } finally {
        aTrabalhar.value = false;
    }
}

// Ver/gerar códigos exige a senha confirmada há pouco (423 do Fortify): pede-se só quando faz falta.
async function comSenhaSeFizerFalta(accao, executar) {
    aTrabalhar.value = true;
    erro.value = '';
    try {
        await executar();
        accaoPendente.value = '';
        passo.value = 'repouso';
    } catch (e) {
        if (e?.response?.status === 423) {
            accaoPendente.value = accao;
            passo.value = 'confirmar';
        } else {
            erro.value = mensagem(e, 'Não foi possível concluir a operação.');
        }
    } finally {
        aTrabalhar.value = false;
    }
}

const verCodigos = () => comSenhaSeFizerFalta('ver', async () => {
    codigosDeRecuperacao.value = (await window.axios.get('/user/two-factor-recovery-codes')).data;
});

const regenerarCodigos = () => comSenhaSeFizerFalta('gerar', async () => {
    await window.axios.post('/user/two-factor-recovery-codes');
    codigosDeRecuperacao.value = (await window.axios.get('/user/two-factor-recovery-codes')).data;
    toast.success('Novos códigos de recuperação gerados. Os anteriores deixaram de funcionar.');
});

async function confirmarEExecutar() {
    aTrabalhar.value = true;
    erro.value = '';
    try {
        await confirmarSenha();
    } catch (e) {
        erro.value = mensagem(e, 'Senha incorrecta.');
        aTrabalhar.value = false;
        return;
    }
    aTrabalhar.value = false;
    const accao = accaoPendente.value;
    senha.value = '';
    if (accao === 'ver') await verCodigos();
    else if (accao === 'gerar') await regenerarCodigos();
}
</script>

<template>
    <div class="card mb-6">
        <div class="card-header">
            <h3 class="card-title fw-bold fs-5">
                Autenticação em dois factores
                <span v-if="activo" class="badge badge-light-success ms-3">Activa</span>
                <span v-else class="badge badge-light ms-3">Desactivada</span>
            </h3>
        </div>
        <div class="card-body pt-4">
            <div v-if="props.sugerir && !bannerDispensado && passo === 'repouso'" class="alert alert-warning d-flex align-items-center justify-content-between py-3 mb-5">
                <span>Como administrador, a sua conta dá acesso a muitos dados. Recomendamos activar a verificação em dois factores (continua opcional).</span>
                <button type="button" class="btn btn-sm btn-light ms-4 flex-shrink-0" @click="dispensarBanner">Agora não</button>
            </div>
            <div class="text-muted mb-5">
                Opcional. Além da senha, o início de sessão pede um código de 6 dígitos gerado por uma aplicação no telemóvel (Google Authenticator, Microsoft Authenticator ou semelhante).
                Funciona sem internet.
            </div>

            <div v-if="erro" class="alert alert-danger py-3">{{ erro }}</div>

            <!-- Desactivado -->
            <template v-if="!activo && passo === 'repouso'">
                <button type="button" class="btn btn-primary" @click="passo = 'senha'">Activar</button>
            </template>

            <form v-if="!activo && passo === 'senha'" class="mw-500px" novalidate @submit.prevent="iniciarActivacao">
                <CampoSenha v-model="senha" rotulo="Confirme a sua senha para continuar" autocomplete="current-password" />
                <button type="submit" class="btn btn-primary me-3" :disabled="aTrabalhar || !senha">
                    <span v-if="!aTrabalhar">Continuar</span>
                    <span v-else>A preparar... <Loader size="0.3px" class="align-middle ms-2" /></span>
                </button>
                <button type="button" class="btn btn-light-danger" @click="cancelar">Cancelar</button>
            </form>

            <form v-if="!activo && passo === 'codigo'" class="mw-500px" novalidate @submit.prevent="confirmarActivacao">
                <div class="fw-semibold mb-3">1. Leia o QR code com a aplicação</div>
                <div class="mb-3 bg-white d-inline-block p-3 rounded" v-html="qr"></div>
                <div class="text-muted fs-7 mb-5">Não consegue ler? Introduza esta chave na aplicação: <code>{{ chave }}</code></div>
                <div class="fw-semibold mb-3">2. Introduza o código de 6 dígitos que a aplicação mostra</div>
                <input v-model="codigo" type="text" inputmode="numeric" autocomplete="one-time-code" maxlength="6" class="form-control form-control-solid mb-5 mw-200px" placeholder="000000" />
                <button type="submit" class="btn btn-primary me-3" :disabled="aTrabalhar || codigo.length !== 6">
                    <span v-if="!aTrabalhar">Confirmar e activar</span>
                    <span v-else>A confirmar... <Loader size="0.3px" class="align-middle ms-2" /></span>
                </button>
                <button type="button" class="btn btn-light-danger" @click="cancelar">Cancelar</button>
            </form>

            <!-- Activo -->
            <template v-if="activo">
                <div v-if="codigosDeRecuperacao.length" class="mb-5">
                    <div class="fw-semibold mb-2">Códigos de recuperação</div>
                    <div class="text-muted fs-7 mb-3">Guarde-os num sítio seguro. Cada código só funciona uma vez. Sem eles, só o administrador da escola pode repor o seu acesso.</div>
                    <div class="bg-body-secondary rounded p-4 mb-3"><div v-for="c in codigosDeRecuperacao" :key="c"><code>{{ c }}</code></div></div>
                </div>

                <div v-if="passo === 'repouso'" class="d-flex flex-wrap gap-3">
                    <button v-if="!codigosDeRecuperacao.length" type="button" class="btn btn-light" :disabled="aTrabalhar" @click="verCodigos">Ver códigos de recuperação</button>
                    <button type="button" class="btn btn-light" :disabled="aTrabalhar" @click="regenerarCodigos">Gerar novos códigos</button>
                    <button type="button" class="btn btn-light-danger" @click="passo = 'desactivar'">Desactivar</button>
                </div>

                <form v-if="passo === 'confirmar'" class="mw-500px" novalidate @submit.prevent="confirmarEExecutar">
                    <CampoSenha v-model="senha" rotulo="Confirme a sua senha para continuar" autocomplete="current-password" />
                    <button type="submit" class="btn btn-primary me-3" :disabled="aTrabalhar || !senha">Continuar</button>
                    <button type="button" class="btn btn-light-danger" @click="cancelar">Cancelar</button>
                </form>

                <form v-if="passo === 'desactivar'" class="mw-500px" novalidate @submit.prevent="desactivar">
                    <CampoSenha v-model="senha" rotulo="Confirme a sua senha para desactivar" autocomplete="current-password" />
                    <button type="submit" class="btn btn-danger me-3" :disabled="aTrabalhar || !senha">Desactivar</button>
                    <button type="button" class="btn btn-light-danger" @click="cancelar">Cancelar</button>
                </form>
            </template>
        </div>
    </div>
</template>
