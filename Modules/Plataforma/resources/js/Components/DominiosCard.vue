<script setup>
import { ref } from 'vue';
import { router } from '@inertiajs/vue3';
import Loader from '@/Components/Shared/Loader.vue';
import ConfirmModal from '@/Components/Shared/ConfirmModal.vue';

// Lista, adição, remoção e troca de domínio principal. O que se oferece vem do backend: o botão Remover só aparece
// quando o domínio é `removivel` (nunca o principal, nunca numa escola encerrada), o botão Tornar principal
// quando é `definivel_como_principal` e o formulário só
// quando `accoes_permitidas.gerir_dominios`. Erros e confirmações vêm do backend, sem textos de reserva.
const props = defineProps({
    escola: { type: Object, required: true },
});

const novo = ref('');
const aAdicionar = ref(false);
const erros = ref({});
const paraRemover = ref(null);
const aRemover = ref(false);
const aDefinir = ref(null); // domínio cujo pedido de "Tornar principal" está em curso

function adicionar() {
    aAdicionar.value = true;
    erros.value = {};

    router.post(`/plataforma/escolas/${props.escola.codigo}/dominios`, { dominio: novo.value }, {
        preserveScroll: true,
        onSuccess: () => {
            novo.value = '';
        },
        onError: (e) => {
            erros.value = e;
        },
        onFinish: () => {
            aAdicionar.value = false;
        },
    });
}

function tornarPrincipal(dominio) {
    aDefinir.value = dominio;

    router.post(`/plataforma/escolas/${props.escola.codigo}/dominios/${encodeURIComponent(dominio)}/principal`, {}, {
        preserveScroll: true,
        onFinish: () => {
            aDefinir.value = null;
        },
    });
}

function remover() {
    aRemover.value = true;

    router.delete(`/plataforma/escolas/${props.escola.codigo}/dominios/${encodeURIComponent(paraRemover.value)}`, {
        preserveScroll: true,
        onFinish: () => {
            aRemover.value = false;
            paraRemover.value = null;
        },
    });
}
</script>

<template>
    <div class="card mb-6">
        <div class="card-header"><h3 class="card-title">Domínios</h3></div>
        <div class="card-body p-0">
            <table class="table align-middle table-row-dashed fs-6 gy-4 mb-0">
                <thead>
                    <tr class="text-start text-muted fw-bold fs-7 text-uppercase gs-0">
                        <th class="min-w-200px">Domínio</th>
                        <th class="min-w-150px">Tipo</th>
                        <th class="text-end min-w-100px"></th>
                    </tr>
                </thead>
                <tbody class="text-gray-600 fw-semibold">
                    <tr v-for="dominio in escola.dominios" :key="dominio.dominio">
                        <td>
                            {{ dominio.dominio }}
                            <span v-if="dominio.is_principal" class="badge badge-light-primary fw-bold ms-2">Principal</span>
                        </td>
                        <td>{{ dominio.tipo_descricao }}</td>
                        <td class="text-end">
                            <button
                                v-if="dominio.definivel_como_principal"
                                type="button"
                                class="btn btn-sm btn-light-primary me-2"
                                :disabled="aDefinir !== null || aRemover"
                                @click="tornarPrincipal(dominio.dominio)"
                            >
                                <span v-if="aDefinir !== dominio.dominio">Tornar principal</span>
                                <span v-else>Aguarde... <Loader size="0.3px" class="align-middle ms-2" /></span>
                            </button>
                            <button
                                v-if="dominio.removivel"
                                type="button"
                                class="btn btn-sm btn-light-danger"
                                :disabled="aRemover"
                                @click="paraRemover = dominio.dominio"
                            >
                                Remover
                            </button>
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>
        <div v-if="escola.accoes_permitidas.gerir_dominios" class="card-footer">
            <form class="row g-3 align-items-start" novalidate @submit.prevent="adicionar">
                <div class="col-12 col-md">
                    <input
                        v-model="novo"
                        type="text"
                        name="dominio"
                        placeholder="outro-dominio.exemplo.ao"
                        aria-label="Novo domínio"
                        class="form-control form-control-solid"
                        :class="{ 'is-invalid': erros.dominio }"
                        :disabled="aAdicionar"
                    />
                    <div v-if="erros.dominio" class="text-danger fs-7 mt-1">{{ erros.dominio }}</div>
                </div>
                <div class="col-12 col-md-auto">
                    <button type="submit" class="btn btn-primary" :disabled="aAdicionar">
                        <span v-if="!aAdicionar">Adicionar domínio</span>
                        <span v-else>Aguarde... <Loader size="0.3px" class="align-middle ms-2" /></span>
                    </button>
                </div>
            </form>
        </div>

        <ConfirmModal
            :show="paraRemover !== null"
            titulo="Remover domínio"
            :mensagem="`Remover o domínio ${paraRemover}? A escola deixa de ser acessível por este endereço.`"
            texto-confirmar="Remover"
            :processando="aRemover"
            @confirmar="remover"
            @cancelar="paraRemover = null"
        />
    </div>
</template>
