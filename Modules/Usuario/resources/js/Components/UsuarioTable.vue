<script setup>
import { onBeforeUnmount, ref } from 'vue';
import { router } from '@inertiajs/vue3';
import { toast } from 'vue-sonner';
import ConfirmModal from '@/Components/Shared/ConfirmModal.vue';
import { ESTADO, tempoRelativo } from '../Models/Usuario';
import RedefinirSenhaModal from './Usuarios/RedefinirSenhaModal.vue';
import UsuarioAcoesMenu from './UsuarioAcoesMenu.vue';
import UsuarioAvatar from './UsuarioAvatar.vue';
import UsuarioCartao from './UsuarioCartao.vue';
import UsuarioPerfis from '@/Components/Shared/PerfisDoUtilizador.vue';
import UsuarioStatusBadge from './UsuarioStatusBadge.vue';

defineProps({
    usuarios: {
        type: Array,
        required: true,
    },
    vista: { type: String, default: 'lista' }, // 'lista' | 'cards'
    carregando: { type: Boolean, default: false }, // pedido em curso: mostra esqueletos
});
const emit = defineEmits(['editar', 'visualizar']);

async function editar(usuario) {
    const resposta = await fetch(`/usuarios/${usuario.id}/editar`, {
        headers: { Accept: 'application/json' },
    });
    const dados = await resposta.json();
    emit('editar', dados);
}

function alternarEstado(usuario) {
    router.patch(`/usuarios/${usuario.id}/estado`, {}, {
        preserveScroll: true,
        onSuccess: () => toast.success(
            usuario.estado === ESTADO.ATIVO ? 'Utilizador desativado.' : 'Utilizador ativado.',
        ),
        onError: (erros) => toast.error(Object.values(erros)[0]),
    });
}

const usuarioParaRedefinir = ref(null);
const senhaTemporaria = ref(null); // { nome, senha } — só em memória, descartada ao fechar
const redefinindo = ref(false);

function pedirRedefinicao(usuario) {
    usuarioParaRedefinir.value = usuario;
}

function fecharRedefinicao() {
    const tinhaSenha = senhaTemporaria.value !== null;
    usuarioParaRedefinir.value = null;
    senhaTemporaria.value = null;
    // roda a chave do histórico: entradas antigas (com a senha cifrada) ficam ilegíveis
    if (tinhaSenha) router.clearHistory();
}

onBeforeUnmount(() => {
    if (senhaTemporaria.value !== null) router.clearHistory();
});

function confirmarRedefinicao() {
    redefinindo.value = true;
    router.patch(`/usuarios/${usuarioParaRedefinir.value.id}/redefinir-senha`, {}, {
        preserveScroll: true,
        onSuccess: (page) => {
            const flash = page.props.flash?.senha_temporaria;
            usuarioParaRedefinir.value = null;
            if (flash) {
                senhaTemporaria.value = { nome: flash.nome, senha: flash.senha };
            } else {
                toast.error('Não foi possível obter a senha temporária. Tente novamente.');
            }
        },
        onError: (erros) => {
            usuarioParaRedefinir.value = null;
            toast.error(Object.values(erros)[0]);
        },
        onFinish: () => { redefinindo.value = false; },
    });
}

const usuarioParaEliminar = ref(null);
const eliminando = ref(false);

function pedirEliminacao(usuario) {
    usuarioParaEliminar.value = usuario;
}

function cancelarEliminacao() {
    usuarioParaEliminar.value = null;
}

function confirmarEliminacao() {
    eliminando.value = true;
    router.delete(`/usuarios/${usuarioParaEliminar.value.id}`, {
        preserveScroll: true,
        onSuccess: () => toast.success('Utilizador eliminado com sucesso.'),
        onError: (erros) => toast.error(Object.values(erros)[0]),
        onFinish: () => {
            eliminando.value = false;
            usuarioParaEliminar.value = null;
        },
    });
}
</script>

<template>
    <!-- Loader: esqueletos enquanto a lista (pesquisa, filtro ou página) está a carregar -->
    <div v-if="carregando && vista === 'lista'" class="placeholder-glow" aria-busy="true">
        <div v-for="n in 6" :key="n" class="d-flex align-items-center gap-4 py-4 border-bottom border-gray-200 border-dashed">
            <span class="placeholder rounded-circle w-50px h-50px flex-shrink-0"></span>
            <div class="flex-grow-1"><span class="placeholder col-3 d-block mb-2"></span><span class="placeholder col-5 d-block"></span></div>
            <span class="placeholder col-1 d-none d-md-block"></span><span class="placeholder col-1 d-none d-md-block"></span>
        </div>
    </div>
    <div v-else-if="carregando" class="row g-5 placeholder-glow" aria-busy="true">
        <div v-for="n in 6" :key="n" class="col-12 col-md-6 col-xl-4">
            <div class="card h-100"><div class="card-body">
                <span class="placeholder rounded-circle w-70px h-70px d-block mb-4"></span>
                <span class="placeholder col-6 d-block mb-2"></span><span class="placeholder col-8 d-block mb-4"></span><span class="placeholder col-4 d-block"></span>
            </div></div>
        </div>
    </div>

    <!-- Vista de cards -->
    <div v-else-if="vista === 'cards'" class="row g-5">
        <div v-if="usuarios.length === 0" class="col-12 text-center text-muted py-10">Nenhum utilizador encontrado.</div>
        <div v-for="usuario in usuarios" :key="usuario.id" class="col-12 col-md-6 col-xl-4">
            <UsuarioCartao
                :usuario="usuario"
                @visualizar="emit('visualizar', $event)"
                @editar="editar"
                @alternar-estado="alternarEstado"
                @redefinir-senha="pedirRedefinicao"
                @eliminar="pedirEliminacao"
            />
        </div>
    </div>

    <!--begin::Table-->
    <table v-else class="table align-middle table-row-dashed table-hover fs-6 gy-5" id="kt_table_users">
        <thead>
            <tr class="text-start text-muted fw-bold fs-7 text-uppercase gs-0">
                <th class="min-w-125px">Utilizador</th>
                <th class="min-w-125px">Perfil</th>
                <th class="min-w-125px">Último acesso</th>
                <th class="min-w-125px">Estado</th>
                <th class="min-w-125px">Data de Criação</th>
                <th class="text-end min-w-100px">Ações</th>
            </tr>
        </thead>
        <tbody class="text-gray-600 fw-semibold">
            <tr v-if="usuarios.length === 0">
                <td colspan="6" class="text-center text-muted py-6">Nenhum utilizador encontrado.</td>
            </tr>
            <tr v-for="usuario in usuarios" :key="usuario.id">
                <td class="d-flex align-items-center">
                    <UsuarioAvatar :usuario="usuario" />
                    <!--begin::User details-->
                    <div class="d-flex flex-column">
                        <a href="#" class="text-gray-800 text-hover-primary fw-bold mb-1" @click.prevent="emit('visualizar', usuario)">{{ usuario.name }}</a>
                        <span class="text-muted fs-7">{{ usuario.email }}</span>
                        <span v-if="usuario.matricula" class="text-muted fs-8">Nº {{ usuario.matricula }}</span>
                    </div>
                    <!--begin::User details-->
                </td>
                <td>
                    <UsuarioPerfis :nome="usuario.name" :perfis="usuario.perfis" />
                </td>
                <td>
                    <span v-if="usuario.online" class="d-inline-flex align-items-center text-success fw-bold"><span class="mosi-pisca rounded-circle bg-success me-2 w-10px h-10px"></span>Online agora</span>
                    <span v-else-if="usuario.ultimo_acesso" class="text-gray-700">{{ tempoRelativo(usuario.ultimo_acesso) }}</span>
                    <span v-else class="text-muted fs-7">Sem sessão ativa</span>
                </td>
                <td>
                    <UsuarioStatusBadge :estado="usuario.estado" />
                </td>
                <td>
                    {{ usuario.created_at }}
                </td>
                <td class="text-end">
                    <UsuarioAcoesMenu
                        :usuario="usuario"
                        @visualizar="emit('visualizar', $event)"
                        @editar="editar"
                        @alternar-estado="alternarEstado"
                        @redefinir-senha="pedirRedefinicao"
                        @eliminar="pedirEliminacao"
                    />
                </td>
            </tr>
        </tbody>
    </table>
    <!--end::Table-->

    <RedefinirSenhaModal
        :usuario="usuarioParaRedefinir"
        :resultado="senhaTemporaria"
        :processando="redefinindo"
        @confirmar="confirmarRedefinicao"
        @fechar="fecharRedefinicao"
    />

    <ConfirmModal
        :show="!!usuarioParaEliminar"
        titulo="Eliminar utilizador"
        :mensagem="`Tem certeza que deseja eliminar ${usuarioParaEliminar?.name}?`"
        :processando="eliminando"
        @confirmar="confirmarEliminacao"
        @cancelar="cancelarEliminacao"
    />
</template>
