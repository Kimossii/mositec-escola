<script setup>
import { corDoPerfil, ESTADO, tempoRelativo } from '../Models/Usuario';
import UsuarioAcoesMenu from './UsuarioAcoesMenu.vue';
import UsuarioAvatar from './UsuarioAvatar.vue';
import UsuarioPerfis from '@/Components/Shared/PerfisDoUtilizador.vue';
import UsuarioStatusBadge from './UsuarioStatusBadge.vue';

/** Utilizador em formato de cartão: faixa de cor do perfil, avatar, dados, estado, actividade e acções. */
const props = defineProps({
    usuario: { type: Object, required: true },
});
defineEmits(['visualizar', 'editar', 'alternar-estado', 'redefinir-senha', 'eliminar']);

const cor = () => corDoPerfil(props.usuario.perfis[0]);
</script>

<template>
    <div class="card h-100 cartao-utilizador overflow-hidden" :class="{ 'cartao-utilizador--inativo': usuario.estado !== ESTADO.ATIVO }">
        <div class="cartao-utilizador__faixa" :class="`bg-${cor()}`"></div>

        <div class="card-body pt-0 d-flex flex-column">
            <div class="d-flex align-items-end justify-content-between cartao-utilizador__topo">
                <UsuarioAvatar :usuario="usuario" class="cartao-utilizador__avatar me-0" />
                <UsuarioAcoesMenu
                    :usuario="usuario"
                    class="cartao-utilizador__menu"
                    compacto
                    @visualizar="$emit('visualizar', $event)"
                    @editar="$emit('editar', $event)"
                    @alternar-estado="$emit('alternar-estado', $event)"
                    @redefinir-senha="$emit('redefinir-senha', $event)"
                    @eliminar="$emit('eliminar', $event)"
                />
            </div>

            <div class="mt-4 min-w-0">
                <a href="#" class="fs-5 fw-bold text-gray-800 text-hover-primary text-truncate d-block" @click.prevent="$emit('visualizar', usuario)">{{ usuario.name }}</a>
                <div class="text-muted fs-7 text-truncate">{{ usuario.email ?? '—' }}</div>
                <div v-if="usuario.matricula" class="text-muted fs-8">Nº {{ usuario.matricula }}</div>
            </div>

            <div class="d-flex flex-wrap gap-1 mt-3">
                <UsuarioPerfis :nome="usuario.name" :perfis="usuario.perfis" />
            </div>

            <div class="separator separator-dashed my-4 mt-auto"></div>

            <div class="d-flex align-items-center justify-content-between fs-7">
                <UsuarioStatusBadge :estado="usuario.estado" />
                <span v-if="usuario.online" class="d-inline-flex align-items-center text-success fw-bold"><span class="mosi-pisca rounded-circle bg-success me-2 w-10px h-10px"></span>Online agora</span>
                <span v-else-if="usuario.ultimo_acesso" class="text-gray-700">{{ tempoRelativo(usuario.ultimo_acesso) }}</span>
                <span v-else class="text-muted">Sem sessão ativa</span>
            </div>
            <div class="text-muted fs-8 mt-2">Criado em {{ usuario.created_at }}</div>
        </div>
    </div>
</template>

<style scoped>
.cartao-utilizador { transition: box-shadow 0.15s ease, border-color 0.15s ease; }
/* Sem `transform`: desloca os menus `position: fixed` das «Ações» (o ancestral transformado vira o seu referencial). */
.cartao-utilizador:hover { border-color: var(--bs-success); box-shadow: 0 10px 28px rgba(15, 23, 42, 0.12); }
.cartao-utilizador--inativo { opacity: 0.78; }
.cartao-utilizador__faixa { height: 52px; opacity: 0.9; background-image: linear-gradient(135deg, rgba(255, 255, 255, 0.18), transparent 60%); }
.cartao-utilizador__topo { margin-top: -34px; }
.cartao-utilizador__menu { position: absolute; top: 0.75rem; right: 0.75rem; }
.cartao-utilizador__avatar { --bs-border-width: 3px; }
.cartao-utilizador__avatar :deep(.symbol-label) { border: 3px solid var(--bs-body-bg); }
</style>
