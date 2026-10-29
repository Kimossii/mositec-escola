<script setup>
import { usePage } from '@inertiajs/vue3';
import { can } from '@/Composables/usePermissoes';
import AcaoIcone from '@/Components/Shared/AcaoIcone.vue';
import { ESTADO } from '../Models/Usuario';

/** Menu «Ações» de um utilizador, partilhado pela vista de lista e pela de cards. A lógica de cada acção fica em UsuarioTable. */
const props = defineProps({
    usuario: { type: Object, required: true },
    compacto: { type: Boolean, default: false }, // só o ícone de três pontos (cards)
});
const emit = defineEmits(['visualizar', 'editar', 'alternar-estado', 'redefinir-senha', 'eliminar']);
const pagina = usePage();

// Mexer num utilizador com perfil Admin Escola é sempre autorizacao.*, nunca usuario.* sozinho — mesma fronteira do backend (UserPolicy).
const eAdmin = () => props.usuario.perfis.includes('Admin escola');
const podeEditar = () => can(eAdmin() ? 'autorizacao.editar' : 'usuario.editar');
const podeEliminar = () => can(eAdmin() ? 'autorizacao.eliminar' : 'usuario.eliminar');
// Redefinir a senha de outra conta: nunca a própria, e contas Admin escola só a quem também gere administradores. O backend é a fonte de verdade.
function podeRedefinirSenha() {
    if (!can('senha-utilizador.editar')) return false;
    if (props.usuario.id === pagina.props.auth.user?.id) return false;

    return !eAdmin() || can('autorizacao.editar');
}
</script>

<template>
    <span class="d-inline-block">
                    <a v-if="compacto" href="#" class="btn btn-icon btn-sm border-0" style="background: rgba(255, 255, 255, 0.28); backdrop-filter: blur(2px)" data-kt-menu-trigger="click" data-kt-menu-placement="bottom-end" title="Ações" @click.prevent>
                        <i class="ki-duotone ki-dots-vertical fs-2 text-white"><span class="path1"></span><span class="path2"></span><span class="path3"></span></i>
                    </a>
                    <a v-else href="#" class="btn btn-light btn-active-light-primary btn-flex btn-center btn-sm" data-kt-menu-trigger="click" data-kt-menu-placement="bottom-end" @click.prevent>
                        Ações
                        <i class="ki-duotone ki-down fs-5 ms-1"></i>
                    </a>
                    <!--begin::Menu-->
                    <div class="menu menu-sub menu-sub-dropdown menu-column menu-rounded menu-gray-600 menu-state-bg-light-primary fw-semibold fs-7 w-175px py-4" data-kt-menu="true">
                        <!--begin::Menu item-->
                        <div class="menu-item px-3">
                            <a href="#" class="menu-link px-3" @click.prevent="emit('visualizar', usuario)">
                                <AcaoIcone acao="visualizar" class="me-2" />
                                Visualizar
                            </a>
                        </div>
                        <!--end::Menu item-->

                        <!--begin::Menu item-->
                        <div v-if="podeEditar()" class="menu-item px-3">
                            <a href="#" class="menu-link px-3" @click.prevent="emit('editar', usuario)">
                                <AcaoIcone acao="editar" class="me-2" />
                                Editar
                            </a>
                        </div>
                        <!--end::Menu item-->

                        <!--begin::Menu item-->
                        <div v-if="can('autorizacao.ver') && !usuario.e_aluno" class="menu-item px-3">
                            <a :href="`/permissoes/utilizadores/${usuario.id}/permissoes`" class="menu-link px-3">
                                <AcaoIcone acao="permissoes" class="me-2" />
                                Permissões
                            </a>
                        </div>
                        <!--end::Menu item-->

                        <!--begin::Menu item-->
                        <div v-if="podeEditar()" class="menu-item px-3">
                            <a href="#" class="menu-link px-3" @click.prevent="emit('alternar-estado', usuario)">
                                <AcaoIcone :acao="usuario.estado === ESTADO.ATIVO ? 'desativar' : 'ativar'" class="me-2" />
                                {{ usuario.estado === ESTADO.ATIVO ? 'Desativar' : 'Ativar' }}
                            </a>
                        </div>
                        <!--end::Menu item-->

                        <!--begin::Menu item-->
                        <div v-if="podeRedefinirSenha()" class="menu-item px-3">
                            <a href="#" class="menu-link px-3" @click.prevent="emit('redefinir-senha', usuario)">
                                <AcaoIcone acao="redefinirSenha" class="me-2" />
                                Redefinir senha
                            </a>
                        </div>
                        <!--end::Menu item-->

                        <!--begin::Menu item-->
                        <div v-if="podeEliminar()" class="menu-item px-3">
                            <a href="#" class="menu-link px-3 text-danger" @click.prevent="emit('eliminar', usuario)">
                                <AcaoIcone acao="eliminar" class="me-2" />
                                Eliminar
                            </a>
                        </div>
                        <!--end::Menu item-->
                    </div>
                    <!--end::Menu-->
    </span>
</template>
