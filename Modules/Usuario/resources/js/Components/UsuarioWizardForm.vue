<script setup>
import { computed, reactive, ref } from 'vue';
import { router } from '@inertiajs/vue3';
import { toast } from 'vue-sonner';
import Loader from '@/Components/Shared/Loader.vue';
import SelectSolid from '@/Components/Shared/SelectSolid.vue';
import AlunoMatriculaLookup from './AlunoMatriculaLookup.vue';
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

// Cadastro de aluno: login = número de matrícula oficial; o nome vem do servidor (só leitura).
const matriculaAluno = ref('');
const alunoEncontrado = ref(null);
const cadastroDeAluno = computed(() => !props.utilizador && perfilSelecionado.value === 'aluno');
// Só se avança/guarda com o aluno encontrado, activo e sem conta (o servidor volta a validar tudo).
const alunoPronto = computed(() => !cadastroDeAluno.value
    || (alunoEncontrado.value !== null && alunoEncontrado.value.estado === 1 && !alunoEncontrado.value.ja_tem_conta));

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

const roleIdDoPerfilSelecionado = computed(() => props.perfis.find((p) => p.slug === perfilSelecionado.value)?.id);

// Passo 2 é só leitura: mostra o que o perfil escolhido já concede. Permissões
// personalizadas fazem-se depois do cadastro, na tela de Permissões do utilizador.
const permissoesDoPerfil = computed(() => {
    const concedidas = props.permissoesPorPerfil[roleIdDoPerfilSelecionado.value] ?? [];
    return props.modulos.map((modulo) => ({
        modulo,
        acoes: props.acoes.filter((acao) => concedidas.some((p) => p.modulo_id === modulo.id && p.acao_id === acao.id)),
    }));
});

function validarAntesDeAvancar() {
    if (!alunoPronto.value) {
        return false;
    }
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

    if (!alunoPronto.value) {
        passo.value = 1;
        return;
    }

    if (form.password && form.password !== form.passwordConfirmation) {
        errors.value = { password_confirmation: ['As senhas não coincidem.'] };
        passo.value = 1;
        toast.error('As senhas não coincidem.');
        return;
    }

    processing.value = true;

    // Aluno: nunca se envia nome, email, tipo de login nem dados pessoais (vêm do registo do aluno).
    const payload = perfilSelecionado.value === 'aluno'
        ? { perfil: perfilSelecionado.value }
        : {
            name: form.name,
            email: tipoLogin.value === 'email' ? form.email : undefined,
            perfil: perfilSelecionado.value,
        };

    if (!props.utilizador) {
        payload.password = form.password;
        payload.password_confirmation = form.passwordConfirmation;
        if (cadastroDeAluno.value) {
            payload.numero_matricula = matriculaAluno.value.trim();
        } else {
            payload.tipo_login = tipoLogin.value;
        }
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
            matriculaAluno.value = '';
            alunoEncontrado.value = null;
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
                :edicao="!!props.utilizador" :matricula="props.utilizador?.matricula ?? ''">
                <template #identidade>
                    <AlunoMatriculaLookup v-model:matricula="matriculaAluno" v-model:aluno="alunoEncontrado"
                        :erro="errors.numero_matricula?.[0] ?? ''" />
                </template>
            </UsuarioFormFields>

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
                <button type="button" class="btn btn-primary" :disabled="!alunoPronto" @click="avancar">Seguinte</button>
            </div>
        </div>

        <div v-else>
            <p class="text-muted fs-7">
                Permissões do perfil escolhido (só leitura). Podem ser personalizadas depois do cadastro, na tela de Permissões do utilizador.
            </p>
            <div class="border rounded overflow-auto" style="max-height: 340px">
                <table class="table align-middle table-row-dashed fs-6 gy-3 mb-0">
                    <thead class="position-sticky top-0 bg-body-secondary" style="z-index: 1">
                        <tr class="text-start text-muted fw-bold fs-7 text-uppercase gs-0">
                            <th class="min-w-200px">Módulo</th>
                            <th>Ações permitidas</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="item in permissoesDoPerfil" :key="item.modulo.id">
                            <td>{{ item.modulo.descricao }}</td>
                            <td>
                                <span v-for="acao in item.acoes" :key="acao.id" class="badge badge-light-success text-capitalize me-1">{{ acao.nome }}</span>
                                <span v-if="!item.acoes.length" class="text-muted fs-7">Sem acesso</span>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>

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
                    <button type="button" class="btn btn-primary" :disabled="processing || !alunoPronto" @click="guardar">
                        <span v-if="!processing">Guardar</span>
                        <span v-else>Aguarde... <Loader size="0.3px" class="align-middle ms-2" /></span>
                    </button>
                </div>
            </div>
        </div>
    </div>
</template>
