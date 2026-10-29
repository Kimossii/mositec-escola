<script setup>
import { computed } from 'vue'
import { Link, usePage } from '@inertiajs/vue3'
import AvatarUtilizador from '@/Components/Shared/AvatarUtilizador.vue'
import PerfisDoUtilizador from '@/Components/Shared/PerfisDoUtilizador.vue'

const user = usePage().props.auth.user
const conta = computed(() => usePage().props.conta)
const foto = computed(() => usePage().props.auth.foto)

const iniciais = computed(() => (user.name ?? '')
    .split(/\s+/).filter(Boolean).slice(0, 2).map((p) => p[0].toUpperCase()).join(''))
</script>

<template>
    <div
        class="menu menu-sub menu-sub-dropdown menu-column menu-rounded menu-gray-800 menu-state-bg menu-state-color fw-semibold py-4 fs-6 w-275px"
        data-kt-menu="true"
    >
        <div class="menu-item px-3">
            <div class="menu-content d-flex align-items-center px-3">
                <AvatarUtilizador :foto="foto" :iniciais="iniciais" class="me-5" />
                <div class="d-flex flex-column min-w-0">
                    <div class="fw-bold fs-5 text-truncate">{{ user.name }}</div>
                    <div class="fw-semibold text-muted fs-7 text-truncate">{{ user.email }}</div>
                    <div v-if="conta?.perfis?.length" class="mt-1">
                        <PerfisDoUtilizador :nome="user.name" :perfis="conta.perfis" classe="fw-bold fs-8 px-2 py-1" />
                    </div>
                </div>
            </div>
        </div>

        <div v-if="conta?.escola" class="menu-item px-3">
            <div class="menu-content px-3 pt-2 pb-0 d-flex align-items-center text-muted fs-7">
                <i class="ki-duotone ki-bank fs-4 me-2"><span class="path1"></span><span class="path2"></span></i>
                <span class="text-truncate">{{ conta.escola }}</span>
            </div>
        </div>

        <div class="separator my-2"></div>

        <div class="menu-item px-5">
            <Link href="/ficheiros" class="menu-link px-5">
                <i class="ki-duotone ki-folder fs-4 me-3 text-primary"><span class="path1"></span><span class="path2"></span></i>
                <span class="menu-title">Os meus ficheiros</span>
            </Link>
        </div>

        <div class="menu-item px-5">
            <Link href="/importacoes" class="menu-link px-5">
                <i class="ki-duotone ki-file-up fs-4 me-3 text-primary"><span class="path1"></span><span class="path2"></span></i>
                <span class="menu-title">Importações</span>
            </Link>
        </div>

        <div class="menu-item px-5">
            <Link href="/definicoes" class="menu-link px-5">
                <i class="ki-duotone ki-setting-2 fs-4 me-3 text-primary"><span class="path1"></span><span class="path2"></span></i>
                <span class="menu-title">Definições</span>
            </Link>
        </div>

        <div class="menu-item px-5">
            <Link href="/definicoes?aba=seguranca" class="menu-link px-5">
                <i class="ki-duotone ki-shield-tick fs-4 me-3 text-success"><span class="path1"></span><span class="path2"></span></i>
                <span class="menu-title">Segurança e senha</span>
            </Link>
        </div>

        <div class="separator my-2"></div>

        <div class="menu-item px-5">
            <Link href="/logout" method="post" as="button" class="menu-link px-5 w-100 text-start border-0 bg-transparent text-danger">
                <i class="ki-duotone ki-exit-right fs-4 me-3 text-danger"><span class="path1"></span><span class="path2"></span></i>
                <span class="menu-title">Terminar sessão</span>
            </Link>
        </div>
    </div>
</template>
