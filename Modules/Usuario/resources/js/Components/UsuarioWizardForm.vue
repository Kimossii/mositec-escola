<script setup>
import { computed, reactive, ref } from 'vue';
import { router } from '@inertiajs/vue3';
import { toast } from 'vue-sonner';
import Loader from '@/Components/Shared/Loader.vue';
import UsuarioFormFields from './UsuarioFormFields.vue';

const props = defineProps({
    perfilFixo: { type: String, default: null },
    perfis: { type: Array, required: true },
    modulos: { type: Array, required: true },
    acoes: { type: Array, required: true },
    permissoesPorPerfil: { type: Object, required: true },
    utilizador: { type: Object, default: null },
    rotaCriar: { type: String, required: true },
});
const emit = defineEmits(['fechado']);

// Lista de passos do wizard — acrescentar aqui é o único sítio a mudar
// quando surgir um novo passo (o resto da navegação já é genérico).
const passos = [
    { numero: 1, titulo: 'Dados' },
    { numero: 2, titulo: 'Permissões' },
];

const passo = ref(1);
const processing = ref(false);
const errors = ref({});
const errorMessage = ref('');

const form = reactive({
    name: props.utilizador?.name ?? '',
    email: props.utilizador?.email ?? '',
    password: '',
});

const perfilSelecionado = ref(props.perfilFixo ?? props.utilizador?.perfil ?? props.perfis[0]?.slug ?? '');

const matriculaEducando = ref('');
const matriculasEducandos = ref([]);

function adicionarEducando() {
    const matricula = matriculaEducando.value.trim();
    if (matricula && !matriculasEducandos.value.includes(matricula)) {
        matriculasEducandos.value.push(matricula);
    }
    matriculaEducando.value = '';
}

function removerEducando(matricula) {
    matriculasEducandos.value = matriculasEducandos.value.filter((m) => m !== matricula);
}

const tipoLogin = computed(() => {
    if (props.utilizador) return props.utilizador.tipo_login;
    return perfilSelecionado.value === 'aluno' ? 'matricula' : 'email';
});

const perfilAtual = computed(() => props.perfis.find((p) => p.slug === perfilSelecionado.value));

const chave = (moduloId, acaoId) => `${moduloId}-${acaoId}`;

// -1 = herda, 1 = concede, 0 = nega
const overridesEstado = reactive(
    Object.fromEntries(
        (props.utilizador?.celulas ?? []).map((o) => [chave(o.modulo_id, o.acao_id), o.permitido ? 1 : 0]),
    ),
);

function permiteDefault(moduloId, acaoId) {
    const permissoes = props.permissoesPorPerfil[perfilAtual.value?.id] ?? [];
    return permissoes.some((p) => p.modulo_id === moduloId && p.acao_id === acaoId);
}

function estadoCelula(moduloId, acaoId) {
    return overridesEstado[chave(moduloId, acaoId)] ?? -1;
}

function proximoEstado(moduloId, acaoId) {
    const k = chave(moduloId, acaoId);
    const atual = overridesEstado[k] ?? -1;
    const seguinte = atual === -1 ? 1 : atual === 1 ? 0 : -1;

    if (seguinte === -1) {
        delete overridesEstado[k];
    } else {
        overridesEstado[k] = seguinte;
    }
}

function validarAntesDeAvancar() {
    if (perfilSelecionado.value === 'encarregado' && matriculasEducandos.value.length === 0) {
        errorMessage.value = 'É preciso ligar pelo menos um educando.';
        return false;
    }
    errorMessage.value = '';
    return true;
}

function irParaPasso(destino) {
    if (destino <= passo.value) {
        passo.value = destino;
        return;
    }
    if (validarAntesDeAvancar()) {
        passo.value = destino;
    }
}

function avancar() {
    irParaPasso(passo.value + 1);
}

function voltar() {
    irParaPasso(passo.value - 1);
}

function fecharModal() {
    window.bootstrap?.Modal.getInstance(document.getElementById('kt_modal_add_user'))?.hide();
}

function guardar() {
    processing.value = true;
    errors.value = {};

    const celulas = Object.entries(overridesEstado).map(([k, valor]) => {
        const [modulo_id, acao_id] = k.split('-').map(Number);
        return { modulo_id, acao_id, permitido: valor === 1 };
    });

    const payload = {
        name: form.name,
        email: tipoLogin.value === 'email' ? form.email : undefined,
        perfil: perfilSelecionado.value,
        celulas,
    };

    if (!props.utilizador) {
        payload.password = form.password;
        payload.tipo_login = tipoLogin.value;
        if (perfilSelecionado.value === 'encarregado') {
            payload.matriculas_educandos = matriculasEducandos.value;
        }
    }

    const url = props.utilizador ? `/usuarios/${props.utilizador.id}` : props.rotaCriar;
    const metodo = props.utilizador ? 'put' : 'post';

    router[metodo](url, payload, {
        preserveScroll: true,
        onSuccess: () => {
            toast.success(props.utilizador ? 'Utilizador atualizado com sucesso.' : 'Utilizador criado com sucesso.');
            fecharModal();
            emit('fechado');
        },
        onError: (erros) => {
            errors.value = erros;
            passo.value = 1;
            toast.error(Object.values(erros)[0] ?? 'Não foi possível guardar o utilizador.');
        },
        onFinish: () => {
            processing.value = false;
        },
    });
}
</script>

<template>
    <div>
        <div class="alert alert-danger" v-if="errorMessage">{{ errorMessage }}</div>

        <div class="mb-7 d-flex align-items-center gap-3">
            <span
                v-for="item in passos"
                :key="item.numero"
                class="badge rounded-pill fs-6 fw-semibold px-4 py-3 cursor-pointer"
                :class="passo === item.numero ? 'badge-primary' : 'badge-light-primary'"
                @click="irParaPasso(item.numero)"
            >
                {{ item.numero }}. {{ item.titulo }}
            </span>
        </div>

        <div v-if="passo === 1">
            <UsuarioFormFields v-model:name="form.name" v-model:email="form.email" v-model:password="form.password"
                :tipo-login="tipoLogin" :errors="errors" />

            <div class="fv-row mb-7">
                <label class="required fw-semibold fs-6 mb-2">Perfil</label>
                <div v-if="perfilFixo">
                    <span class="badge badge-light-primary fs-6">
                        {{ perfis.find((p) => p.slug === perfilFixo)?.descricao }}
                    </span>
                </div>
                <select v-else v-model="perfilSelecionado" class="form-select form-select-solid">
                    <option v-for="perfil in perfis" :key="perfil.slug" :value="perfil.slug">{{ perfil.descricao }}</option>
                </select>
            </div>

            <div class="fv-row mb-7" v-if="perfilSelecionado === 'encarregado'">
                <label class="fw-semibold fs-6 mb-2">Educandos</label>
                <div class="d-flex gap-2 mb-2">
                    <input v-model="matriculaEducando" type="text" class="form-control form-control-solid"
                        placeholder="Matrícula do educando" @keydown.enter.prevent="adicionarEducando" />
                    <button type="button" class="btn btn-light-primary" @click="adicionarEducando">Adicionar</button>
                </div>
                <div class="d-flex flex-wrap gap-2">
                    <span v-for="matricula in matriculasEducandos" :key="matricula" class="badge badge-light-primary fs-7">
                        {{ matricula }}
                        <a href="#" class="ms-2 text-danger" @click.prevent="removerEducando(matricula)">&times;</a>
                    </span>
                </div>
                <div class="text-danger fs-7 mt-1" v-if="errors.matriculas_educandos">{{ errors.matriculas_educandos[0] }}</div>
            </div>

            <div class="text-end pt-5">
                <button type="button" class="btn btn-primary" @click="avancar">Seguinte</button>
            </div>
        </div>

        <div v-else>
            <p class="text-muted fs-7">
                Clique numa célula para alternar entre Herda (o que o perfil "{{ perfilAtual?.descricao }}" já dá por
                padrão), Concede e Nega.
            </p>
            <table class="table align-middle">
                <thead>
                    <tr>
                        <th>Módulo</th>
                        <th v-for="acao in acoes" :key="acao.id" class="text-center text-capitalize">{{ acao.nome }}</th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-for="modulo in modulos" :key="modulo.id">
                        <td>{{ modulo.descricao }}</td>
                        <td v-for="acao in acoes" :key="acao.id" class="text-center">
                            <button
                                type="button"
                                class="btn btn-sm"
                                :class="{
                                    'btn-light': estadoCelula(modulo.id, acao.id) === -1 && !permiteDefault(modulo.id, acao.id),
                                    'btn-light-success': estadoCelula(modulo.id, acao.id) === 1 || (estadoCelula(modulo.id, acao.id) === -1 && permiteDefault(modulo.id, acao.id)),
                                    'btn-light-danger': estadoCelula(modulo.id, acao.id) === 0,
                                }"
                                @click="proximoEstado(modulo.id, acao.id)"
                            >
                                {{ estadoCelula(modulo.id, acao.id) === -1 ? (permiteDefault(modulo.id, acao.id) ? 'Herda (concede)' : 'Herda (nega)') : estadoCelula(modulo.id, acao.id) === 1 ? 'Concede' : 'Nega' }}
                            </button>
                        </td>
                    </tr>
                </tbody>
            </table>

            <div class="d-flex justify-content-between pt-5">
                <button type="button" class="btn btn-light-primary" :disabled="processing" @click="voltar">
                    <i class="ki-duotone ki-arrow-left fs-4 me-1">
                        <span class="path1"></span>
                        <span class="path2"></span>
                    </i>
                    Voltar
                </button>
                <button type="button" class="btn btn-primary" :disabled="processing" @click="guardar">
                    <span v-if="!processing">Guardar</span>
                    <span v-else>Aguarde... <Loader size="0.3px" class="align-middle ms-2" /></span>
                </button>
            </div>
        </div>
    </div>
</template>
