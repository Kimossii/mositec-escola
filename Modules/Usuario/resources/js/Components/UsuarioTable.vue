<script setup>
import UsuarioAvatar from './UsuarioAvatar.vue';
import UsuarioStatusBadge from './UsuarioStatusBadge.vue';

defineProps({
    usuarios: {
        type: Array,
        required: true,
    },
});
const emit = defineEmits(['editar']);

async function editar(usuario) {
    const resposta = await fetch(`/usuarios/${usuario.id}/editar`, {
        headers: { Accept: 'application/json' },
    });
    const dados = await resposta.json();
    emit('editar', dados);
}
</script>

<template>
    <!--begin::Table-->
    <table class="table align-middle table-row-dashed fs-6 gy-5" id="kt_table_users">
        <thead>
            <tr class="text-start text-muted fw-bold fs-7 text-uppercase gs-0">
                <th class="w-10px pe-2">
                    <div class="form-check form-check-sm form-check-custom form-check-solid me-3">
                        <input class="form-check-input" type="checkbox" data-kt-check="true" data-kt-check-target="#kt_table_users .form-check-input" value="1" />
                    </div>
                </th>
                <th class="min-w-125px">User</th>
                <th class="min-w-125px">Matrícula</th>
                <th class="min-w-125px">Last login</th>
                <th class="min-w-125px">Estado</th>
                <th class="min-w-125px">Joined Date</th>
                <th class="text-end min-w-100px">Actions</th>
            </tr>
        </thead>
        <tbody class="text-gray-600 fw-semibold">
            <tr v-for="usuario in usuarios" :key="usuario.id">
                <td>
                    <div class="form-check form-check-sm form-check-custom form-check-solid">
                        <input class="form-check-input" type="checkbox" :value="usuario.id" />
                    </div>
                </td>
                <td class="d-flex align-items-center">
                    <UsuarioAvatar :usuario="usuario" />
                    <!--begin::User details-->
                    <div class="d-flex flex-column">
                        <a href="#" class="text-gray-800 text-hover-primary mb-1">{{ usuario.name }}</a>
                        <span>{{ usuario.email }}</span>
                    </div>
                    <!--begin::User details-->
                </td>
                <td>
                    {{ usuario.matricula }}
                </td>
                <td>
                    <div class="badge badge-light fw-bold">{{ usuario.ultimo_acesso }}</div>
                </td>
                <td>
                    <UsuarioStatusBadge :estado="usuario.estado" />
                </td>
                <td>
                    {{ usuario.created_at }}
                </td>
                <td class="text-end">
                    <a href="#" class="btn btn-light btn-active-light-primary btn-flex btn-center btn-sm" data-kt-menu-trigger="click" data-kt-menu-placement="bottom-end">
                        Actions
                        <i class="ki-duotone ki-down fs-5 ms-1"></i>
                    </a>
                    <!--begin::Menu-->
                    <div class="menu menu-sub menu-sub-dropdown menu-column menu-rounded menu-gray-600 menu-state-bg-light-primary fw-semibold fs-7 w-150px py-4" data-kt-menu="true">
                        <!--begin::Menu item-->
                        <div class="menu-item px-3">
                            <a href="#" class="menu-link px-3" @click.prevent="editar(usuario)">
                                Edit
                            </a>
                        </div>
                        <!--end::Menu item-->

                        <!--begin::Menu item-->
                        <div class="menu-item px-3">
                            <a :href="`/permissoes/utilizadores/${usuario.id}/permissoes`" class="menu-link px-3">
                                Permissões
                            </a>
                        </div>
                        <!--end::Menu item-->

                        <!--begin::Menu item-->
                        <div class="menu-item px-3">
                            <a href="#" class="menu-link px-3" data-kt-users-table-filter="delete_row">
                                Delete
                            </a>
                        </div>
                        <!--end::Menu item-->
                    </div>
                    <!--end::Menu-->
                </td>
            </tr>
        </tbody>
    </table>
    <!--end::Table-->
</template>
