<script setup>
import { ref } from 'vue';
import { router, usePage } from '@inertiajs/vue3';
import { toast } from 'vue-sonner';
import Loader from '@/Components/Shared/Loader.vue';
import PasswordInput from '@/Components/Shared/PasswordInput.vue';
import LayoutPlataforma from '../Components/LayoutPlataforma.vue';

defineOptions({ layout: LayoutPlataforma });

defineProps({
    doisFactores: { type: Object, required: true }, // { activo: bool }
});

const page = usePage();

// Estados: 'repouso' | 'senha' (pedir senha para activar) | 'codigo' (QR visível, a confirmar) | 'desactivar' (pedir senha)
const passo = ref('repouso');
const senha = ref('');
const codigo = ref('');
const qr = ref('');
const chave = ref('');
const codigosDeRecuperacao = ref([]);
const aTrabalhar = ref(false);
const erro = ref('');

// O cliente do Inertia já leva o token do painel; estes pedidos JSON (axios) enviam-no à mão.
const cabecalhos = () => ({ 'X-CSRF-TOKEN': page.props.csrf_token });

function mensagem(e, padrao) {
    const erros = e?.response?.data?.errors;
    return erros?.password?.[0] ?? erros?.code?.[0] ?? e?.response?.data?.message ?? padrao;
}

function cancelar() {
    passo.value = 'repouso';
    senha.value = '';
    codigo.value = '';
    qr.value = '';
    chave.value = '';
    erro.value = '';
}

async function iniciar() {
    aTrabalhar.value = true;
    erro.value = '';
    try {
        const { data } = await window.axios.post('/plataforma/seguranca/dois-factores', { password: senha.value }, { headers: cabecalhos() });
        qr.value = data.qr;
        chave.value = data.chave;
        senha.value = '';
        passo.value = 'codigo';
    } catch (e) {
        erro.value = mensagem(e, 'Não foi possível iniciar a activação.');
    } finally {
        aTrabalhar.value = false;
    }
}

async function confirmar() {
    aTrabalhar.value = true;
    erro.value = '';
    try {
        const { data } = await window.axios.post('/plataforma/seguranca/dois-factores/confirmar', { code: codigo.value }, { headers: cabecalhos() });
        // Os códigos só aparecem agora; cancelar() não os apaga para o utilizador os poder guardar.
        codigosDeRecuperacao.value = data.codigos;
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
        await window.axios.delete('/plataforma/seguranca/dois-factores', { data: { password: senha.value }, headers: cabecalhos() });
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
</script>

<template>
    <div class="card mw-600px mx-auto">
        <div class="card-body p-10">
            <div class="text-center mb-8">
                <h1 class="text-dark fw-bolder mb-3">Segurança</h1>
                <div class="text-gray-500 fw-semibold fs-6">
                    Autenticação em dois factores (opcional): pede um código da aplicação além da senha.
                </div>
            </div>

            <div class="d-flex flex-stack mb-6">
                <span class="fw-bold fs-5">Estado</span>
                <span class="badge" :class="doisFactores.activo ? 'badge-light-success' : 'badge-light-secondary'">
                    {{ doisFactores.activo ? 'Activa' : 'Inactiva' }}
                </span>
            </div>

            <div v-if="codigosDeRecuperacao.length" class="alert alert-warning mb-6">
                <div class="fw-bold mb-2">Guarde estes códigos de recuperação num sítio seguro.</div>
                <div class="fs-7 mb-3">Cada um serve uma só vez e não voltam a ser mostrados. Se perder a aplicação e estes códigos, só o comando de reposição no servidor recupera o acesso.</div>
                <ul class="list-unstyled mb-0 font-monospace">
                    <li v-for="c in codigosDeRecuperacao" :key="c">{{ c }}</li>
                </ul>
            </div>

            <div v-if="passo === 'repouso'">
                <button v-if="!doisFactores.activo" type="button" class="btn btn-primary" @click="passo = 'senha'">Activar</button>
                <button v-else type="button" class="btn btn-light-danger" @click="passo = 'desactivar'">Desactivar</button>
            </div>

            <form v-else-if="passo === 'senha' || passo === 'desactivar'" novalidate @submit.prevent="passo === 'senha' ? iniciar() : desactivar()">
                <label class="required fw-semibold fs-6 mb-2">Senha actual</label>
                <PasswordInput v-model="senha" name="password" autocomplete="current-password" placeholder="Senha actual" :invalid="!!erro" />
                <div v-if="erro" class="text-danger fs-7 mt-1">{{ erro }}</div>
                <div class="d-flex gap-3 mt-6">
                    <button type="submit" class="btn btn-primary" :disabled="aTrabalhar || !senha">
                        <span v-if="!aTrabalhar">{{ passo === 'senha' ? 'Continuar' : 'Desactivar' }}</span>
                        <span v-else>A processar... <Loader size="0.3px" class="align-middle ms-2" /></span>
                    </button>
                    <button type="button" class="btn btn-light-danger" :disabled="aTrabalhar" @click="cancelar">Cancelar</button>
                </div>
            </form>

            <form v-else-if="passo === 'codigo'" novalidate @submit.prevent="confirmar">
                <p class="fs-6">Leia o QR code com a sua aplicação de autenticação e introduza o código de 6 dígitos.</p>
                <div class="d-flex justify-content-center mb-4" v-html="qr" />
                <div class="text-center fs-7 text-gray-600 mb-6">Ou use a chave: <span class="font-monospace fw-bold">{{ chave }}</span></div>
                <input v-model="codigo" type="text" inputmode="numeric" autocomplete="one-time-code" maxlength="6" class="form-control form-control-solid" placeholder="000000" />
                <div v-if="erro" class="text-danger fs-7 mt-1">{{ erro }}</div>
                <div class="d-flex gap-3 mt-6">
                    <button type="submit" class="btn btn-primary" :disabled="aTrabalhar || !codigo">
                        <span v-if="!aTrabalhar">Confirmar</span>
                        <span v-else>A confirmar... <Loader size="0.3px" class="align-middle ms-2" /></span>
                    </button>
                    <button type="button" class="btn btn-light-danger" :disabled="aTrabalhar" @click="cancelar">Cancelar</button>
                </div>
            </form>
        </div>
    </div>
</template>
