<script setup>
import AcaoIcone from '@/Components/Shared/AcaoIcone.vue';
import PermissoesPorMenu from '../../../../Permissao/resources/js/Components/PermissoesPorMenu.vue';
import { computed, reactive, ref, watch } from 'vue';
import { router } from '@inertiajs/vue3';
import { can } from '@/Composables/usePermissoes';
import { toast } from 'vue-sonner';
import Loader from '@/Components/Shared/Loader.vue';
import SelectSolid from '@/Components/Shared/SelectSolid.vue';
import AlunoMatriculaLookup from './AlunoMatriculaLookup.vue';
import DocenteSemContaLookup from './DocenteSemContaLookup.vue';
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

// Professor: nunca se escreve à mão. A conta nasce de um docente já registado (Pessoal → Docentes) e sem acesso; o nome
// e a pessoa vêm do docente (servidor) e do formulário só vão docente_id, email de login e senha. Exige usuario.criar e docente.editar.
const docenteEscolhido = ref(null);
const cadastroDeDocente = computed(() => !props.utilizador && perfilSelecionado.value === 'professor');
const semPermissaoDeDocente = computed(() => cadastroDeDocente.value && !(can('usuario.criar') && can('docente.editar')));
const identidadePronta = computed(() => alunoPronto.value && (!cadastroDeDocente.value || (docenteEscolhido.value !== null && !semPermissaoDeDocente.value)));

// O email de contacto do docente serve de sugestão para o login (só se ainda estiver vazio).
watch(docenteEscolhido, (d) => {
    if (d?.email && !form.email) form.email = d.email;
});

// Os campos partilhados lêem `errors.x[0]`; o Inertia manda cada erro como texto. Neste modo normalizam-se para listas.
const errosDosCampos = computed(() => (cadastroDeDocente.value
    ? Object.fromEntries(Object.entries(errors.value).map(([k, v]) => [k, Array.isArray(v) ? v : [v]]))
    : errors.value));
const erroDocente = computed(() => {
    const e = errors.value.docente_id;
    return (Array.isArray(e) ? e[0] : e) ?? '';
});

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

    // Formato do painel partilhado: só o que o perfil concede.
    return concedidas.flatMap((p) => {
        const modulo = props.modulos.find((m) => m.id === p.modulo_id);
        const acao = props.acoes.find((a) => a.id === p.acao_id);

        return modulo && acao ? [{ modulo: modulo.descricao, slug: modulo.slug, acao: acao.nome }] : [];
    });
});
const nomesDasAcoes = computed(() => props.acoes.map((a) => a.nome));

const descricaoDoPerfil = computed(() => props.perfis.find((p) => p.slug === perfilSelecionado.value)?.descricao ?? '');

function validarAntesDeAvancar() {
    if (!identidadePronta.value) {
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

    if (!identidadePronta.value) {
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
    // Professor a partir de docente: sem nome nem dados da pessoa (o servidor usa os do docente).
    const payload = perfilSelecionado.value === 'aluno'
        ? { perfil: perfilSelecionado.value }
        : cadastroDeDocente.value
            ? { perfil: 'professor', docente_id: docenteEscolhido.value.id, email: form.email }
            : {
                name: form.name,
                email: tipoLogin.value === 'email' ? form.email : undefined,
                perfil: perfilSelecionado.value,
            };

    if (cadastroDeDocente.value) {
        payload.password = form.password;
        payload.password_confirmation = form.passwordConfirmation;
    } else if (!props.utilizador) {
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
            docenteEscolhido.value = null;
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
            <div v-if="semPermissaoDeDocente" class="alert alert-warning mb-7">
                Para criar o acesso de um professor é preciso poder editar docentes. Peça a um administrador.
            </div>

            <DocenteSemContaLookup v-if="cadastroDeDocente && !semPermissaoDeDocente" v-model:docente="docenteEscolhido" :erro="erroDocente" />

            <UsuarioFormFields v-model:name="form.name" v-model:email="form.email" v-model:password="form.password"
                v-model:password-confirmation="form.passwordConfirmation" :tipo-login="tipoLogin" :errors="errosDosCampos"
                :edicao="!!props.utilizador" :matricula="props.utilizador?.matricula ?? ''" :ocultar-nome="cadastroDeDocente">
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
                    <AcaoIcone acao="fechar" herda-cor class="me-1" />
                    Cancelar
                </button>
                <button type="button" class="btn btn-primary" :disabled="!identidadePronta" @click="avancar">Seguinte</button>
            </div>
        </div>

        <div v-else>
            <div class="d-flex flex-wrap align-items-center justify-content-between gap-2 mb-2">
                <div class="d-flex align-items-center gap-2">
                    <span class="text-muted fs-7 fw-semibold text-uppercase">Perfil</span>
                    <span class="badge badge-light-primary fs-7 fw-bold">{{ descricaoDoPerfil }}</span>
                </div>
            </div>
            <p class="text-muted fs-7">
                Permissões do perfil escolhido (só leitura). Podem ser personalizadas depois do cadastro, na tela de Permissões do utilizador.
            </p>
            <div class="overflow-auto pe-1" style="max-height: 420px">
                <PermissoesPorMenu :permissoes="permissoesDoPerfil" :acoes="nomesDasAcoes" :total-modulos="modulos.length" />
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
                        <AcaoIcone acao="fechar" herda-cor class="me-1" />
                        Cancelar
                    </button>
                    <button type="button" class="btn btn-primary" :disabled="processing || !identidadePronta" @click="guardar">
                        <span v-if="!processing">Guardar</span>
                        <span v-else>Aguarde... <Loader size="0.3px" class="align-middle ms-2" /></span>
                    </button>
                </div>
            </div>
        </div>
    </div>
</template>
