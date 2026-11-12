<script setup>
import { ref, watch } from 'vue';
import { router } from '@inertiajs/vue3';
import { toast } from 'vue-sonner';

// Regista a medida tomada para a negativa de um aluno numa disciplina. Serve a mini-pauta e a pauta final.
const props = defineProps({
    // { inscricao_id, aluno, disciplina, medida } — nulo = fechado
    alvo: { type: Object, default: null },
});
const emit = defineEmits(['fechar']);

const medida = ref('');
const aGuardar = ref(false);
const erro = ref('');

watch(() => props.alvo, (alvo) => { medida.value = alvo?.medida ?? ''; erro.value = ''; });

function guardar() {
    if (!medida.value.trim() || aGuardar.value) return;
    aGuardar.value = true;
    router.post('/avaliacao/notas/negativas', { inscricao_disciplina_id: props.alvo.inscricao_id, medida: medida.value }, {
        preserveScroll: true,
        preserveState: true,
        onError: (e) => { erro.value = Object.values(e)[0]; },
        onSuccess: () => { toast.success('Negativa tratada.'); emit('fechar'); },
        onFinish: () => { aGuardar.value = false; },
    });
}
</script>

<template>
    <div v-if="alvo" class="modal d-block" style="background: rgba(0,0,0,0.5);" @click.self="emit('fechar')">
        <div class="modal-dialog modal-dialog-centered" @keydown.esc="emit('fechar')">
            <form class="modal-content" @submit.prevent="guardar">
                <div class="modal-header">
                    <h3 class="modal-title fw-bold">Tratar negativa</h3>
                    <button type="button" class="btn btn-sm btn-icon btn-active-light-primary" aria-label="Fechar" @click="emit('fechar')">✕</button>
                </div>
                <div class="modal-body">
                    <div class="fw-semibold mb-1">{{ alvo.aluno }}<span v-if="alvo.disciplina"> · {{ alvo.disciplina }}</span></div>
                    <div class="text-body-secondary fs-7 mb-4">Descreva a medida para ajudar o aluno nesta disciplina (por exemplo, aulas de apoio ou trabalho de recuperação). A pauta só se aprova com todas as negativas tratadas.</div>
                    <textarea v-model="medida" class="form-control" rows="4" maxlength="1000" placeholder="Medida tomada" autofocus></textarea>
                    <div v-if="erro" class="text-danger fs-7 mt-2">{{ erro }}</div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-light" @click="emit('fechar')">Cancelar</button>
                    <button type="submit" class="btn btn-primary" :disabled="!medida.trim() || aGuardar">{{ aGuardar ? 'A guardar…' : 'Guardar' }}</button>
                </div>
            </form>
        </div>
    </div>
</template>
