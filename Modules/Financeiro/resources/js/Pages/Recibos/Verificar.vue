<script setup>
import { computed } from 'vue';
import { Head } from '@inertiajs/vue3';
import { formatarDinheiro } from '../../Support/dinheiro';

// Página PÚBLICA aberta pelo QR de um recibo (layout de convidado, sem login): confirma a autenticidade com o mínimo de dados.
const props = defineProps({
    escola: { type: Object, required: true },
    recibo: { type: Object, default: null }, // null = QR inválido ou adulterado
});

const data = (d) => (d ? new Intl.DateTimeFormat('pt-PT', { day: 'numeric', month: 'long', year: 'numeric' }).format(new Date(`${d}T00:00:00`)) : '—');
const estado = computed(() => {
    if (!props.recibo) return { cor: 'danger', icone: 'ki-cross-circle', titulo: 'Recibo não encontrado', texto: 'Este código não corresponde a nenhum recibo emitido por esta escola. Confirme se leu o QR completo.' };
    if (props.recibo.anulado) return { cor: 'warning', icone: 'ki-information-5', titulo: 'Recibo anulado', texto: `Este recibo existe, mas foi anulado${props.recibo.anulado_em ? ` a ${data(props.recibo.anulado_em)}` : ''}. Já não é válido.` };

    return { cor: 'success', icone: 'ki-check-circle', titulo: 'Recibo autêntico', texto: 'Este recibo foi emitido por esta escola e está válido.' };
});
const linhas = computed(() => (props.recibo ? [
    ['Recibo n.º', props.recibo.numero],
    ['Data do pagamento', data(props.recibo.data_pagamento)],
    ['Valor', formatarDinheiro(props.recibo.valor, props.recibo.moeda)],
    ['Aluno', props.recibo.aluno ?? '—'],
] : []));
</script>

<template>
    <Head title="Verificação de recibo" />

    <div class="d-flex flex-center min-vh-100 p-5" style="background: linear-gradient(135deg, #0f172a 0%, #12302f 60%, #166534 140%)">
        <div class="card shadow-lg w-100" style="max-width: 460px">
            <div class="card-body p-9 text-center">
                <img v-if="escola.logotipo_url" :src="escola.logotipo_url" alt="" class="d-block mx-auto mb-3" style="max-height: 56px; max-width: 200px; object-fit: contain" />
                <div class="fw-bold fs-5 text-gray-800">{{ escola.nome }}</div>
                <div class="text-muted fs-8 text-uppercase fw-semibold mb-7" style="letter-spacing: 0.08em">Verificação de recibo</div>

                <span class="symbol symbol-80px mb-4"><span class="symbol-label" :class="`bg-light-${estado.cor}`">
                    <i class="ki-duotone fs-3x" :class="[estado.icone, `text-${estado.cor}`]"><span class="path1"></span><span class="path2"></span><span class="path3"></span></i>
                </span></span>
                <h1 class="fs-2 fw-bolder mb-2" :class="`text-${estado.cor}`">{{ estado.titulo }}</h1>
                <p class="text-gray-600 fs-6 mb-6">{{ estado.texto }}</p>

                <div v-if="recibo" class="text-start">
                    <div v-for="[rotulo, valor] in linhas" :key="rotulo" class="d-flex justify-content-between gap-4 py-3 border-top border-gray-200">
                        <span class="text-muted fs-7 text-uppercase fw-semibold">{{ rotulo }}</span>
                        <span class="fw-bold text-gray-800 text-end">{{ valor }}</span>
                    </div>
                </div>

                <div class="text-muted fs-8 mt-6">Para proteger o aluno, só se mostram os dados mínimos. Em caso de dúvida, contacte a secretaria da escola.</div>
            </div>
        </div>
    </div>
</template>
