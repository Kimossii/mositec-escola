<script setup>
import { ref } from 'vue';
import { corDoPerfil } from '../../../../Modules/Usuario/resources/js/Models/Usuario';

/**
 * Perfis de um utilizador em espaço curto: o primeiro em selo e, se houver mais, um «+N». Ao passar o rato mostra os
 * restantes (dica); ao clicar abre um modal com a lista completa.
 */
const props = defineProps({
    nome: { type: String, default: '' },
    perfis: { type: Array, default: () => [] },
    classe: { type: String, default: '' }, // classes extra dos selos (ex.: tamanho)
});

const aberto = ref(false);
</script>

<template>
    <span v-if="!perfis.length" class="text-muted">—</span>
    <span v-else class="d-inline-flex align-items-center gap-1">
        <span class="badge" :class="[`badge-light-${corDoPerfil(perfis[0])}`, classe]">{{ perfis[0] }}</span>
        <button
            v-if="perfis.length > 1"
            type="button"
            class="badge badge-circle badge-light-dark border-0 cursor-pointer fw-bold" :class="classe"
            :title="perfis.slice(1).join(', ')"
            :aria-label="`Ver os ${perfis.length} perfis de ${nome}`"
            @click.stop="aberto = true"
        >+{{ perfis.length - 1 }}</button>
    </span>

    <Teleport to="body">
        <div v-if="aberto" class="modal d-block" style="background: rgba(0,0,0,0.5);" @click.self="aberto = false" @keydown.esc="aberto = false">
            <div class="modal-dialog modal-dialog-centered modal-sm">
                <div class="modal-content p-6">
                    <h3 class="mb-1">Perfis</h3>
                    <div v-if="nome" class="text-muted fs-7 mb-4">{{ nome }}</div>
                    <div class="d-flex flex-column gap-2 mb-6">
                        <span v-for="perfil in perfis" :key="perfil" class="badge fs-7 align-self-start" :class="`badge-light-${corDoPerfil(perfil)}`">{{ perfil }}</span>
                    </div>
                    <div class="text-end">
                        <button type="button" class="btn btn-light-primary" @click="aberto = false">Fechar</button>
                    </div>
                </div>
            </div>
        </div>
    </Teleport>
</template>
