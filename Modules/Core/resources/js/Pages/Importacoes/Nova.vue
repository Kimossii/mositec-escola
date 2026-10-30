<script setup>
import { useForm } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import BotaoVoltar from '@/Components/Shared/BotaoVoltar.vue';

const props = defineProps({
    entidade: { type: String, required: true },
    titulo: { type: String, required: true },
    colunas: { type: Array, required: true }, // [{ chave, rotulo, obrigatoria, exemplo, ajuda }]
});
defineOptions({ layout: AppLayout });

const form = useForm({ ficheiro: null });
const enviar = () => form.post(`/importacoes/${props.entidade}`, { forceFormData: true });
</script>

<template>
    <div class="app-container container-xxl py-6">
        <div class="mb-6">
            <BotaoVoltar href="/importacoes" />
            <h1 class="fs-2 fw-bold mb-1">Importar {{ titulo }}</h1>
            <div class="text-muted">Descarregue o modelo, preencha-o e carregue-o. Nada é gravado antes de ver o resultado da validação e confirmar.</div>
        </div>

        <div class="card card-flush mb-6">
            <div class="card-body">
                <h3 class="fs-5 fw-bold mb-4">1. Modelo</h3>
                <a :href="`/importacoes/${entidade}/modelo?formato=excel`" class="btn btn-light-primary me-2">Descarregar Excel (.xlsx)</a>
                <a :href="`/importacoes/${entidade}/modelo?formato=csv`" class="btn btn-light">Descarregar CSV</a>
                <div class="table-responsive mt-6">
                    <table class="table align-middle table-row-dashed fs-7 mb-0">
                        <thead>
                            <tr class="text-muted fw-bold text-uppercase"><th>Coluna</th><th>Obrigatória</th><th>Exemplo</th><th>Regra</th></tr>
                        </thead>
                        <tbody>
                            <tr v-for="c in colunas" :key="c.chave">
                                <td class="fw-semibold">{{ c.rotulo }}</td>
                                <td>{{ c.obrigatoria ? 'Sim' : 'Não' }}</td>
                                <td>{{ c.exemplo || '—' }}</td>
                                <td>{{ c.ajuda }}</td>
                            </tr>
                        </tbody>
                    </table>
                </div>
                <div class="text-muted fs-8 mt-4">Para mais de 20 000 linhas prefira CSV (UTF-8). Os registos que já existem são ignorados, não alterados.</div>
            </div>
        </div>

        <div class="card card-flush">
            <div class="card-body">
                <h3 class="fs-5 fw-bold mb-4">2. Ficheiro preenchido</h3>
                <form @submit.prevent="enviar">
                    <input type="file" class="form-control form-control-solid mw-500px mb-2" accept=".xlsx,.csv,.txt" @input="form.ficheiro = $event.target.files[0]" />
                    <div v-if="form.errors.ficheiro" class="text-danger fs-7 mb-2">{{ form.errors.ficheiro }}</div>
                    <button type="submit" class="btn btn-primary mt-3" :disabled="form.processing || !form.ficheiro">{{ form.processing ? 'A enviar…' : 'Carregar e validar' }}</button>
                </form>
            </div>
        </div>
    </div>
</template>
