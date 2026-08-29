<script setup>
defineProps({
    show: { type: Boolean, default: false },
    titulo: { type: String, default: 'Confirmar' },
    mensagem: { type: String, required: true },
    textoConfirmar: { type: String, default: 'Eliminar' },
    processando: { type: Boolean, default: false },
});
const emit = defineEmits(['confirmar', 'cancelar']);
</script>

<template>
    <div v-if="show" class="modal d-block" style="background: rgba(0,0,0,0.5);" @click.self="emit('cancelar')">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content p-6">
                <h3 class="mb-4">{{ titulo }}</h3>
                <p class="text-gray-700 mb-6">{{ mensagem }}</p>
                <div class="text-end">
                    <button type="button" class="btn btn-light-primary me-2" :disabled="processando" @click="emit('cancelar')">
                        Cancelar
                    </button>
                    <button type="button" class="btn btn-danger" :disabled="processando" @click="emit('confirmar')">
                        {{ textoConfirmar }}
                    </button>
                </div>
            </div>
        </div>
    </div>
</template>
