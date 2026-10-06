<script setup>
import { computed, ref, watch } from 'vue';
import { router } from '@inertiajs/vue3';
import Loader from '@/Components/Shared/Loader.vue';

// Recuperar o administrador de uma escola. Ao abrir, carrega a lista (nome e e-mail, nada mais) do
// endpoint próprio: o detalhe da escola não abre contexto em cada visita. A senha temporária não passa
// por aqui: vem no flash do detalhe e o SenhaTemporariaModal mostra-a uma única vez.
const props = defineProps({
    show: { type: Boolean, default: false },
    escola: { type: Object, required: true },
});
const emit = defineEmits(['fechar']);

const base = () => `/plataforma/escolas/${props.escola.codigo}`;

const carregando = ref(false);
const erroDeCarga = ref('');
const administradores = ref([]);
const escolhido = ref('');
const processando = ref(false);
const erros = ref({});

const variosAdministradores = computed(() => administradores.value.length > 1);
const semAdministradores = computed(() => !carregando.value && !erroDeCarga.value && administradores.value.length === 0);
const podeConfirmar = computed(() => !carregando.value && !processando.value && escolhido.value !== '');

let abortar = null;

async function carregar() {
    abortar?.abort();
    abortar = new AbortController();
    carregando.value = true;
    erroDeCarga.value = '';
    administradores.value = [];
    escolhido.value = '';
    erros.value = {};

    try {
        const resposta = await fetch(`${base()}/administradores`, {
            headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
            credentials: 'same-origin',
            signal: abortar.signal,
        });

        // 401/419 (sessão terminada) ou um redireccionamento seguido pelo fetch (veio o HTML do login):
        // volta-se ao login em vez de mostrar uma lista vazia.
        if (resposta.status === 401 || resposta.status === 419 || resposta.redirected) {
            window.location.assign('/plataforma/login');
            return;
        }

        const corpo = await resposta.json().catch(() => ({}));

        if (!resposta.ok) {
            erroDeCarga.value = corpo.message ?? 'Não foi possível carregar os administradores desta escola.';
            return;
        }

        administradores.value = corpo.administradores ?? [];
        // Um só: já vem escolhido. Vários: tem de escolher (nada pré-seleccionado).
        if (administradores.value.length === 1) escolhido.value = administradores.value[0].email;
    } catch (e) {
        if (e.name !== 'AbortError') erroDeCarga.value = 'Não foi possível carregar os administradores desta escola.';
    } finally {
        carregando.value = false;
    }
}

watch(
    () => props.show,
    (aberto) => {
        if (aberto) carregar();
        else abortar?.abort();
    },
    { immediate: true },
);

function fechar() {
    if (!processando.value) emit('fechar');
}

function confirmar() {
    if (!podeConfirmar.value) return;
    processando.value = true;
    erros.value = {};

    router.post(`${base()}/administrador/recuperar`, { email: escolhido.value }, {
        preserveScroll: true,
        onSuccess: () => emit('fechar'),
        onError: (e) => {
            erros.value = e;
            // Erro que não é do e-mail (ex.: escola já não activa): mostra-se no topo do detalhe.
            if (e.geral) emit('fechar');
        },
        onFinish: () => {
            processando.value = false;
        },
    });
}
</script>

<template>
    <div v-if="show" class="modal d-block" style="background: rgba(0,0,0,0.5);" @click.self="fechar">
        <div class="modal-dialog modal-dialog-centered">
            <form class="modal-content p-6" novalidate @submit.prevent="confirmar">
                <h3 class="mb-4">Recuperar administrador de {{ escola.nome }}</h3>

                <div v-if="carregando" class="text-center py-6" data-testid="a-carregar">
                    <Loader size="0.3px" class="align-middle" />
                </div>

                <div v-else-if="erroDeCarga" class="alert alert-danger mb-0" role="alert">{{ erroDeCarga }}</div>

                <p v-else-if="semAdministradores" class="text-gray-700 mb-0">
                    Esta escola não tem administradores activos.
                </p>

                <template v-else>
                    <p v-if="variosAdministradores" class="text-gray-700 mb-3">Escolha o administrador a recuperar.</p>
                    <div class="d-flex flex-column gap-3 mb-4">
                        <label
                            v-for="administrador in administradores"
                            :key="administrador.email"
                            class="form-check form-check-custom form-check-solid align-items-start"
                        >
                            <input
                                v-model="escolhido"
                                class="form-check-input mt-1"
                                type="radio"
                                name="email"
                                :value="administrador.email"
                                :disabled="processando"
                            />
                            <span class="form-check-label ms-3">
                                <span class="d-block fw-bold text-gray-800">{{ administrador.nome }}</span>
                                <span class="d-block text-muted fs-7">{{ administrador.email }}</span>
                            </span>
                        </label>
                    </div>
                    <div v-if="erros.email" class="text-danger fs-7 mb-3">{{ erros.email }}</div>
                    <div class="bg-body-secondary text-body border border-primary rounded p-4 mb-0" role="alert">
                        Isto invalida a palavra-passe e as sessões actuais deste administrador. Será gerada uma
                        senha temporária, mostrada uma única vez.
                    </div>
                </template>

                <div class="text-end mt-6">
                    <button type="button" class="btn btn-light-primary me-2" :disabled="processando" @click="fechar">Cancelar</button>
                    <button v-if="administradores.length > 0" type="submit" class="btn btn-primary" :disabled="!podeConfirmar">
                        <span v-if="!processando">Recuperar acesso</span>
                        <span v-else>Aguarde... <Loader size="0.3px" class="align-middle ms-2" /></span>
                    </button>
                </div>
            </form>
        </div>
    </div>
</template>
