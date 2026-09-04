<script setup>
import { computed, ref } from 'vue';
import { router } from '@inertiajs/vue3';
import { toast } from 'vue-sonner';
import AppLayout from '@/Layouts/AppLayout.vue';
import { can } from '@/Composables/usePermissoes';
import EstabelecimentoCabecalho from '../Components/EstabelecimentoCabecalho.vue';
import EstabelecimentoTabs from '../Components/EstabelecimentoTabs.vue';

const props = defineProps({
    estabelecimento: {
        type: Object,
        default: null,
    },
});
defineOptions({ layout: AppLayout });

const ficheiro = ref(null);
const preview = ref(null);
const processing = ref(false);
const errors = ref({});
const inputFile = ref(null);

const previewSrc = computed(() => preview.value ?? props.estabelecimento?.logotipo_url ?? null);
const nomeCurto = computed(() => props.estabelecimento?.nome_abreviado || props.estabelecimento?.nome || 'A sua escola');

function onFileChange(event) {
    const file = event.target.files?.[0] ?? null;
    ficheiro.value = file;
    preview.value = file ? URL.createObjectURL(file) : null;
}

function cancelar() {
    ficheiro.value = null;
    preview.value = null;
    if (inputFile.value) inputFile.value.value = '';
}

function submeter() {
    if (!ficheiro.value) return;

    processing.value = true;
    errors.value = {};

    router.post('/estabelecimento/logotipo', { logotipo: ficheiro.value }, {
        forceFormData: true,
        preserveScroll: true,
        onSuccess: () => {
            toast.success('Logótipo atualizado com sucesso.');
            cancelar();
        },
        onError: (erros) => {
            errors.value = erros;
            toast.error(Object.values(erros)[0] ?? 'Não foi possível atualizar o logótipo.');
        },
        onFinish: () => {
            processing.value = false;
        },
    });
}
</script>

<template>
    <div class="app-container container-xxl py-6">
        <EstabelecimentoCabecalho :estabelecimento="estabelecimento" />
        <EstabelecimentoTabs atual="aparencia" />

        <div v-if="!estabelecimento" class="alert alert-warning d-flex align-items-center">
            <i class="ki-duotone ki-information-5 fs-2 me-3"><span class="path1"></span><span class="path2"></span><span class="path3"></span></i>
            <div>Cadastre primeiro os <a href="/estabelecimento" class="fw-bold">dados da escola</a> antes de definir o logótipo.</div>
        </div>

        <div v-else class="card">
            <div class="card-header min-h-auto py-4">
                <h3 class="card-title fs-6 fw-bold text-uppercase text-gray-600">Logótipo</h3>
            </div>
            <div class="card-body">
                <p class="text-muted fs-6" style="max-width: 640px">
                    Usado na barra lateral, no cabeçalho do sistema e em documentos institucionais. Cores e tema da interface
                    são definidos globalmente e não fazem parte desta configuração.
                </p>

                <div class="row align-items-center g-6 mt-2">
                    <div class="col-md-5">
                        <div class="ficha-preview-sidebar">
                            <div class="ficha-preview-sidebar__emblema">
                                <img v-if="previewSrc" :src="previewSrc" alt="Logótipo" />
                                <i v-else class="ki-duotone ki-picture fs-2x text-gray-500"><span class="path1"></span><span class="path2"></span></i>
                            </div>
                            <span class="ficha-preview-sidebar__nome">{{ nomeCurto }}</span>
                        </div>
                        <div class="text-muted fs-8 text-center mt-2">Pré-visualização na barra lateral</div>
                    </div>

                    <div v-if="can('estabelecimento.editar')" class="col-md-7">
                        <form @submit.prevent="submeter">
                            <label class="fw-semibold fs-6 mb-2">Novo ficheiro</label>
                            <input
                                ref="inputFile"
                                type="file"
                                class="form-control form-control-solid"
                                accept="image/png,image/jpeg,image/webp"
                                @change="onFileChange"
                            />
                            <div class="text-muted fs-7 mt-1">PNG, JPG ou WEBP, até 2MB. Fundo transparente recomendado.</div>
                            <div class="text-danger fs-7 mt-1" v-if="errors.logotipo">{{ errors.logotipo[0] }}</div>

                            <div class="d-flex justify-content-end gap-2 mt-6">
                                <button v-if="ficheiro" type="button" class="btn btn-light" :disabled="processing" @click="cancelar">Cancelar</button>
                                <button type="submit" class="btn btn-primary" :disabled="processing || !ficheiro">Guardar logótipo</button>
                            </div>
                        </form>
                    </div>
                </div>
            </div>
        </div>
    </div>
</template>

<style scoped>
.ficha-preview-sidebar {
    display: flex;
    align-items: center;
    gap: 0.75rem;
    padding: 0.85rem 1rem;
    border-radius: 0.6rem;
    background: #0f172a;
}

.ficha-preview-sidebar__emblema {
    flex: 0 0 auto;
    width: 40px;
    height: 40px;
    border-radius: 0.5rem;
    background: rgba(248, 250, 252, 0.08);
    border: 1px solid rgba(134, 239, 172, 0.35);
    display: flex;
    align-items: center;
    justify-content: center;
    overflow: hidden;
}

.ficha-preview-sidebar__emblema img {
    width: 100%;
    height: 100%;
    object-fit: contain;
    padding: 0.25rem;
}

.ficha-preview-sidebar__nome {
    color: #f8fafc;
    font-weight: 600;
    font-size: 0.95rem;
}
</style>
