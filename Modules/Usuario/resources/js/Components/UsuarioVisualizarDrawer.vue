<script setup>
// Painel de detalhes (somente leitura) que desliza pela lateral direita —
// widget KTDrawer do próprio Metronic (já iniciado globalmente em
// AppLayout.vue), em vez de um modal como o de criar/editar.
import UsuarioAvatar from './UsuarioAvatar.vue';
import UsuarioStatusBadge from './UsuarioStatusBadge.vue';

defineProps({
    utilizador: { type: Object, default: null },
});
</script>

<template>
    <div id="kt_usuario_visualizar_drawer" class="bg-body" data-kt-drawer="true" data-kt-drawer-name="usuario-visualizar"
        data-kt-drawer-activate="true" data-kt-drawer-overlay="true"
        data-kt-drawer-width="{default:'350px', 'md': '450px'}" data-kt-drawer-direction="end"
        data-kt-drawer-close="#kt_usuario_visualizar_close">
        <div class="card shadow-none rounded-0 w-100 border-0">
            <div class="card-header" id="kt_usuario_visualizar_header">
                <h3 class="card-title fw-bold text-dark">Detalhes do utilizador</h3>
                <div class="card-toolbar">
                    <button type="button" class="btn btn-sm btn-icon btn-active-light-primary" id="kt_usuario_visualizar_close">
                        <i class="ki-duotone ki-cross fs-1">
                            <span class="path1"></span>
                            <span class="path2"></span>
                        </i>
                    </button>
                </div>
            </div>

            <div class="card-body" v-if="utilizador">
                <div class="d-flex flex-column align-items-center text-center mb-8">
                    <div class="mb-4">
                        <UsuarioAvatar :usuario="utilizador" />
                    </div>
                    <span class="fs-3 fw-bold text-dark">{{ utilizador.name }}</span>
                    <UsuarioStatusBadge :estado="utilizador.estado" class="mt-2" />
                </div>

                <div class="separator separator-dashed mb-6"></div>

                <div class="mb-6">
                    <label class="fw-semibold text-muted fs-7 text-uppercase">{{ utilizador.email ? 'Email' : 'Matrícula' }}</label>
                    <div class="fs-6 text-dark">{{ utilizador.email || utilizador.matricula || '—' }}</div>
                </div>

                <div class="mb-6">
                    <label class="fw-semibold text-muted fs-7 text-uppercase">Perfil</label>
                    <div class="mt-1">
                        <span v-if="!utilizador.perfis?.length" class="text-muted">—</span>
                        <span v-for="perfilNome in utilizador.perfis" :key="perfilNome" class="badge badge-light-primary me-1">
                            {{ perfilNome }}
                        </span>
                    </div>
                </div>

                <div class="mb-6">
                    <label class="fw-semibold text-muted fs-7 text-uppercase">Último acesso</label>
                    <div class="fs-6 text-dark">{{ utilizador.ultimo_acesso }}</div>
                </div>

                <div class="mb-6">
                    <label class="fw-semibold text-muted fs-7 text-uppercase">Criado em</label>
                    <div class="fs-6 text-dark">{{ utilizador.created_at }}</div>
                </div>
            </div>
        </div>
    </div>
</template>
