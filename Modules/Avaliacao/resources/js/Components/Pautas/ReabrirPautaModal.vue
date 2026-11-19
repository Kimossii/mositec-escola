<script setup>
import { ref, watch } from 'vue';

const props = defineProps({
    show: { type: Boolean, default: false },
    processando: { type: Boolean, default: false },
    erro: { type: String, default: '' },
});
const emit = defineEmits(['confirmar', 'cancelar']);

const motivo = ref('');
// Cada abertura começa com o motivo vazio.
watch(() => props.show, (aberto) => { if (aberto) motivo.value = ''; });
</script>

<template>
    <div v-if="show" class="modal d-block" style="background: rgba(0,0,0,0.5);" @click.self="emit('cancelar')">
        <div class="modal-dialog modal-dialog-centered" @keydown.esc="emit('cancelar')">
            <form class="modal-content" @submit.prevent="emit('confirmar', motivo.trim())">
                <div class="modal-header">
                    <h3 class="modal-title fw-bold">Reabrir esta pauta?</h3>
                    <button type="button" class="btn btn-sm btn-icon btn-active-light-primary" aria-label="Fechar" @click="emit('cancelar')">✕</button>
                </div>
                <div class="modal-body">
                    <div class="text-body-secondary fs-7 mb-4">A pauta volta a provisória e as notas da turma ficam outra vez editáveis. O motivo fica registado.</div>
                    <textarea v-model="motivo" class="form-control" rows="3" maxlength="500" placeholder="Motivo da reabertura" autofocus></textarea>
                    <div v-if="erro" class="text-danger fs-7 mt-2">{{ erro }}</div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-light-danger" :disabled="processando" @click="emit('cancelar')">Cancelar</button>
                    <button type="submit" class="btn btn-danger" :disabled="!motivo.trim() || processando">{{ processando ? 'A reabrir…' : 'Reabrir pauta' }}</button>
                </div>
            </form>
        </div>
    </div>
</template>
