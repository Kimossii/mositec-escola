<script setup>
defineProps({
    show: { type: Boolean, default: false },
    historico: { type: Array, default: () => [] }, // [{ id, evento, evento_descricao, versao, motivo, por, ocorrido_em }]
});
const emit = defineEmits(['fechar']);

const dataHora = (v) => (v ? new Date(v).toLocaleString('pt-PT', { dateStyle: 'short', timeStyle: 'short' }) : '—');
</script>

<template>
    <div v-if="show" class="modal d-block" style="background: rgba(0,0,0,0.5);" @click.self="emit('fechar')">
        <div class="modal-dialog modal-dialog-centered modal-lg modal-dialog-scrollable" @keydown.esc="emit('fechar')">
            <div class="modal-content">
                <div class="modal-header">
                    <h3 class="modal-title fw-bold">Histórico da pauta</h3>
                    <button type="button" class="btn btn-sm btn-icon btn-active-light-primary" aria-label="Fechar" @click="emit('fechar')">✕</button>
                </div>
                <div class="modal-body">
                    <div v-if="!historico.length" class="text-center py-6">Esta pauta ainda não foi aprovada.</div>
                    <ol v-else class="list-unstyled mb-0">
                        <li v-for="h in historico" :key="h.id" class="d-flex gap-4 py-4 border-bottom">
                            <span class="badge fs-7 align-self-start" :class="h.evento === 0 ? 'badge-light-success' : 'badge-light-danger'">{{ h.evento_descricao }}</span>
                            <div class="flex-grow-1">
                                <div class="fw-semibold">{{ h.por ?? '—' }} <span class="fw-normal">· versão {{ h.versao }}</span></div>
                                <div class="fs-7">{{ dataHora(h.ocorrido_em) }}</div>
                                <div v-if="h.motivo" class="mt-2"><strong>Motivo:</strong> {{ h.motivo }}</div>
                            </div>
                        </li>
                    </ol>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-light-primary" @click="emit('fechar')">Fechar</button>
                </div>
            </div>
        </div>
    </div>
</template>
