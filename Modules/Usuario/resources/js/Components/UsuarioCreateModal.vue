<script setup>
import UsuarioForm from '../Forms/UsuarioForm.vue';

defineProps({
    formComponent: {
        type: [Object, Function],
        default: () => UsuarioForm,
    },
    utilizador: { type: Object, default: null },
    perfis: { type: Array, default: () => [] },
    modulos: { type: Array, default: () => [] },
    acoes: { type: Array, default: () => [] },
    permissoesPorPerfil: { type: Object, default: () => ({}) },
});
</script>

<template>
    <!--begin::Modal - Add user-->
    <div class="modal fade" id="kt_modal_add_user" tabindex="-1" aria-hidden="true">
        <!--begin::Modal dialog-->
        <div class="modal-dialog modal-dialog-centered mw-650px">
            <!--begin::Modal content-->
            <div class="modal-content">
                <!--begin::Modal header-->
                <div class="modal-header" id="kt_modal_add_user_header">
                    <!--begin::Modal title-->
                    <h2 class="fw-bold">{{ utilizador ? 'Editar Utilizador' : 'Registar Novo Utilizador' }}</h2>
                    <!--end::Modal title-->

                    <!--begin::Close-->
                    <div class="btn btn-icon btn-sm btn-active-icon-primary" data-bs-dismiss="modal">
                        <i class="ki-duotone ki-cross fs-1"><span class="path1"></span><span class="path2"></span></i>
                    </div>
                    <!--end::Close-->
                </div>
                <!--end::Modal header-->

                <!--begin::Modal body-->
                <div class="modal-body scroll-y mx-5 mx-xl-15 my-7">
                    <component
                        :is="formComponent"
                        :key="utilizador?.id ?? 'novo'"
                        :utilizador="utilizador"
                        :perfis="perfis"
                        :modulos="modulos"
                        :acoes="acoes"
                        :permissoes-por-perfil="permissoesPorPerfil"
                    />
                </div>
                <!--end::Modal body-->
            </div>
            <!--end::Modal content-->
        </div>
        <!--end::Modal dialog-->
    </div>
    <!--end::Modal - Add user-->
</template>
