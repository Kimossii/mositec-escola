<script setup>
import { computed, reactive, ref } from 'vue';
import { router } from '@inertiajs/vue3';
import { toast } from 'vue-sonner';
import Loader from '@/Components/Shared/Loader.vue';
import SelectSolid from '@/Components/Shared/SelectSolid.vue';
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
    passwordConfirmation: '',
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

// Só o perfil "aluno" usa login por matrícula — deriva sempre do perfil em
// vez de confiar no tipo_login gravado (pode estar errado em registos antigos).
const tipoLogin = computed(() => (perfilSelecionado.value === 'aluno' ? 'matricula' : 'email'));

const chave = (moduloId, acaoId) => `${moduloId}-${acaoId}`;

// overridesEstado só guarda as células que o admin decidiu explicitamente
// (tocou nesta sessão, ou já vinham gravadas como override do utilizador).
// 1 = concedido, 0 = negado — só estes dois valores, nunca "herda" nem null.
// Uma célula sem entrada aqui usa o que o perfil seleccionado já dá por
// padrão (ver permiteDefault) — é assim que "o perfil vence por defeito,
// overrides são só a excepção" continua verdadeiro depois de guardar.
const overridesEstado = reactive(
    Object.fromEntries(
        (props.utilizador?.celulas ?? []).map((o) => [chave(o.modulo_id, o.acao_id), o.permitido ? 1 : 0]),
    ),
);

const roleIdDoPerfilSelecionado = computed(() => props.perfis.find((p) => p.slug === perfilSelecionado.value)?.id);

function permiteDefault(moduloId, acaoId) {
    const permissoes = props.permissoesPorPerfil[roleIdDoPerfilSelecionado.value] ?? [];
    return permissoes.some((p) => p.modulo_id === moduloId && p.acao_id === acaoId);
}

function estadoCelula(moduloId, acaoId) {
    const k = chave(moduloId, acaoId);
    if (k in overridesEstado) return overridesEstado[k];
    return permiteDefault(moduloId, acaoId) ? 1 : 0;
}

function proximoEstado(moduloId, acaoId) {
    const k = chave(moduloId, acaoId);
    overridesEstado[k] = estadoCelula(moduloId, acaoId) === 1 ? 0 : 1;
}

function todosConcedidosNaColuna(acaoId) {
    return props.modulos.every((modulo) => estadoCelula(modulo.id, acaoId) === 1);
}

function alternarColuna(acaoId) {
    const marcar = todosConcedidosNaColuna(acaoId) ? 0 : 1;
    props.modulos.forEach((modulo) => {
        overridesEstado[chave(modulo.id, acaoId)] = marcar;
    });
}

function todosConcedidosNaLinha(moduloId) {
    return props.acoes.every((acao) => estadoCelula(moduloId, acao.id) === 1);
}

function alternarLinha(moduloId) {
    const marcar = todosConcedidosNaLinha(moduloId) ? 0 : 1;
    props.acoes.forEach((acao) => {
        overridesEstado[chave(moduloId, acao.id)] = marcar;
    });
}

function validarAntesDeAvancar() {
    if (form.password && form.password !== form.passwordConfirmation) {
        errorMessage.value = 'As senhas não coincidem.';
        return false;
    }
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
    errors.value = {};

    if (form.password && form.password !== form.passwordConfirmation) {
        errors.value = { password_confirmation: ['As senhas não coincidem.'] };
        passo.value = 1;
        toast.error('As senhas não coincidem.');
        return;
    }

    processing.value = true;

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
        payload.password_confirmation = form.passwordConfirmation;
        payload.tipo_login = tipoLogin.value;
        if (perfilSelecionado.value === 'encarregado') {
            payload.matriculas_educandos = matriculasEducandos.value;
        }
    } else if (form.password) {
        payload.password = form.password;
        payload.password_confirmation = form.passwordConfirmation;
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
            toast.error(Object.values(erros)[0]);
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
                v-model:password-confirmation="form.passwordConfirmation" :tipo-login="tipoLogin" :errors="errors"
                :edicao="!!props.utilizador" />

            <div class="fv-row mb-7">
                <label class="required fw-semibold fs-6 mb-2">Perfil</label>
                <div v-if="perfilFixo">
                    <span class="badge badge-light-primary fs-6">
                        {{ perfis.find((p) => p.slug === perfilFixo)?.descricao }}
                    </span>
                </div>
                <SelectSolid v-else v-model="perfilSelecionado"
                    :options="perfis.map((p) => ({ value: p.slug, label: p.descricao }))" />
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
                <button type="button" class="btn btn-light-danger me-2" @click="fecharModal">
                    <i class="ki-duotone ki-cross fs-4 me-1">
                        <span class="path1"></span>
                        <span class="path2"></span>
                    </i>
                    Cancelar
                </button>
                <button type="button" class="btn btn-primary" @click="avancar">Seguinte</button>
            </div>
        </div>

        <div v-else>
            <p class="text-muted fs-7">
                Clique numa célula para alternar entre Concedido (verde) e Negado (vermelho).
            </p>
            <table class="table align-middle">
                <thead>
                    <tr>
                        <th>Módulo</th>
                        <th v-for="acao in acoes" :key="acao.id" class="text-center text-capitalize">
                            <div class="d-flex flex-column align-items-center gap-1">
                                <span>{{ acao.nome }}</span>
                                <input
                                    type="checkbox"
                                    class="form-check-input"
                                    title="Marcar/desmarcar toda a coluna"
                                    :checked="todosConcedidosNaColuna(acao.id)"
                                    @change="alternarColuna(acao.id)"
                                />
                            </div>
                        </th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-for="modulo in modulos" :key="modulo.id">
                        <td>
                            <div class="d-flex align-items-center gap-2">
                                <input
                                    type="checkbox"
                                    class="form-check-input"
                                    title="Marcar/desmarcar toda a linha"
                                    :checked="todosConcedidosNaLinha(modulo.id)"
                                    @change="alternarLinha(modulo.id)"
                                />
                                <span>{{ modulo.descricao }}</span>
                            </div>
                        </td>
                        <td v-for="acao in acoes" :key="acao.id" class="text-center">
                            <button
                                type="button"
                                class="btn btn-sm"
                                :class="{
                                    'btn-light-success btn-permissao-concedido': estadoCelula(modulo.id, acao.id) === 1,
                                    'btn-light-danger': estadoCelula(modulo.id, acao.id) === 0,
                                }"
                                @click="proximoEstado(modulo.id, acao.id)"
                            >
                                {{ estadoCelula(modulo.id, acao.id) === 1 ? 'Concedido' : 'Negado' }}
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
                <div>
                    <button type="button" class="btn btn-light-danger me-2" :disabled="processing" @click="fecharModal">
                        <i class="ki-duotone ki-cross fs-4 me-1">
                            <span class="path1"></span>
                            <span class="path2"></span>
                        </i>
                        Cancelar
                    </button>
                    <button type="button" class="btn btn-primary" :disabled="processing" @click="guardar">
                        <span v-if="!processing">Guardar</span>
                        <span v-else>Aguarde... <Loader size="0.3px" class="align-middle ms-2" /></span>
                    </button>
                </div>
            </div>
        </div>
    </div>
</template>
