<script setup>
import { reactive, ref } from 'vue';
import { router } from '@inertiajs/vue3';
import { toast } from 'vue-sonner';
import AppLayout from '@/Layouts/AppLayout.vue';
import { can } from '@/Composables/usePermissoes';
import EstabelecimentoCabecalho from '../Components/EstabelecimentoCabecalho.vue';
import EstabelecimentoTabs from '../Components/EstabelecimentoTabs.vue';
import CampoFicha from '../Components/CampoFicha.vue';

const props = defineProps({
    estabelecimento: {
        type: Object,
        default: null,
    },
});
defineOptions({ layout: AppLayout });

const tipos = [
    { value: 1, label: 'Público' },
    { value: 2, label: 'Privado' },
    { value: 3, label: 'Cooperativo' },
];

function snapshot() {
    return {
        nome: props.estabelecimento?.nome ?? '',
        nome_abreviado: props.estabelecimento?.nome_abreviado ?? '',
        tipo: props.estabelecimento?.tipo ?? 2,
        nif: props.estabelecimento?.nif ?? '',
        codigo_mined: props.estabelecimento?.codigo_mined ?? '',
        numero_alvara: props.estabelecimento?.numero_alvara ?? '',
        email: props.estabelecimento?.email ?? '',
        telefone: props.estabelecimento?.telefone ?? '',
        telefone_alternativo: props.estabelecimento?.telefone_alternativo ?? '',
        website: props.estabelecimento?.website ?? '',
        endereco: props.estabelecimento?.endereco ?? '',
        caixa_postal: props.estabelecimento?.caixa_postal ?? '',
        municipio: props.estabelecimento?.municipio ?? '',
        provincia: props.estabelecimento?.provincia ?? '',
        responsavel_nome: props.estabelecimento?.responsavel_nome ?? '',
        responsavel_cargo: props.estabelecimento?.responsavel_cargo ?? '',
        ano_fundacao: props.estabelecimento?.ano_fundacao ?? '',
        observacoes: props.estabelecimento?.observacoes ?? '',
    };
}

const form = reactive(snapshot());
// Sem estabelecimento ainda (1º acesso) só entra logo em edição se o
// utilizador já tiver o direito — senão fica só a ver o formulário vazio.
const editando = ref(!props.estabelecimento && can('estabelecimento.editar'));
const processing = ref(false);
const errors = ref({});

function editar() {
    Object.assign(form, snapshot());
    errors.value = {};
    editando.value = true;
}

function cancelar() {
    Object.assign(form, snapshot());
    errors.value = {};
    editando.value = false;
}

function submeter() {
    processing.value = true;
    errors.value = {};

    router.put('/estabelecimento', form, {
        preserveScroll: true,
        onSuccess: () => {
            toast.success('Dados do estabelecimento atualizados com sucesso.');
            editando.value = false;
        },
        onError: (erros) => {
            errors.value = erros;
            toast.error(Object.values(erros)[0] ?? 'Não foi possível guardar os dados.');
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
        <EstabelecimentoTabs atual="dados" />

        <div class="d-flex justify-content-between align-items-start mb-2">
            <p class="text-muted fs-6 mb-6" style="max-width: 640px">
                Estes dados identificam oficialmente o estabelecimento e são usados em documentos como faturas, recibos e relatórios.
            </p>

            <button v-if="!editando && can('estabelecimento.editar')" type="button" class="btn btn-sm btn-light-primary" @click="editar">
                <i class="ki-duotone ki-pencil fs-4 me-1"><span class="path1"></span><span class="path2"></span></i>
                Editar dados
            </button>
        </div>

        <form @submit.prevent="submeter">
            <div class="card mb-6">
                <div class="card-header min-h-auto py-4">
                    <h3 class="card-title fs-6 fw-bold text-uppercase text-gray-600">Identificação</h3>
                </div>
                <div class="card-body pt-0">
                    <div class="row">
                        <div class="col-md-8">
                            <CampoFicha
                                v-model="form.nome" label="Nome do estabelecimento" required :editing="editando"
                                placeholder="ex: Escola Secundária Mositec" :error="errors.nome?.[0]"
                                icon="ki-bank"
                            />
                        </div>
                        <div class="col-md-4">
                            <CampoFicha
                                v-model="form.nome_abreviado" label="Nome abreviado" :editing="editando" placeholder="ex: Mositec"
                                :error="errors.nome_abreviado?.[0]" icon="ki-badge" :icon-paths="5"
                            />
                        </div>
                        <div class="col-md-4">
                            <CampoFicha
                                v-model="form.tipo" label="Tipo" type="select" :options="tipos" required
                                :editing="editando" :error="errors.tipo?.[0]" icon="ki-category" :icon-paths="4"
                            />
                        </div>
                        <div class="col-md-4">
                            <CampoFicha
                                v-model="form.ano_fundacao" label="Ano de fundação" type="number" :editing="editando" placeholder="ex: 2010"
                                :error="errors.ano_fundacao?.[0]" icon="ki-calendar-8" :icon-paths="6"
                            />
                        </div>
                    </div>
                </div>
            </div>

            <div class="card mb-6">
                <div class="card-header min-h-auto py-4">
                    <h3 class="card-title fs-6 fw-bold text-uppercase text-gray-600">Documentação</h3>
                </div>
                <div class="card-body pt-0 row">
                    <div class="col-md-4">
                        <CampoFicha v-model="form.nif" label="NIF" :editing="editando" :error="errors.nif?.[0]" icon="ki-document" />
                    </div>
                    <div class="col-md-4">
                        <CampoFicha
                            v-model="form.codigo_mined" label="Código MINED" :editing="editando" :error="errors.codigo_mined?.[0]"
                            icon="ki-code" :icon-paths="4"
                        />
                    </div>
                    <div class="col-md-4">
                        <CampoFicha v-model="form.numero_alvara" label="Número de alvará" :editing="editando" :error="errors.numero_alvara?.[0]" icon="ki-verify" />
                    </div>
                </div>
            </div>

            <div class="card mb-6">
                <div class="card-header min-h-auto py-4">
                    <h3 class="card-title fs-6 fw-bold text-uppercase text-gray-600">Contacto</h3>
                </div>
                <div class="card-body pt-0 row">
                    <div class="col-md-4">
                        <CampoFicha v-model="form.email" label="Email" type="email" :editing="editando" :error="errors.email?.[0]" icon="ki-sms" />
                    </div>
                    <div class="col-md-4">
                        <CampoFicha v-model="form.telefone" label="Telefone" :editing="editando" :error="errors.telefone?.[0]" icon="ki-phone" />
                    </div>
                    <div class="col-md-4">
                        <CampoFicha
                            v-model="form.telefone_alternativo" label="Telefone alternativo" :editing="editando"
                            :error="errors.telefone_alternativo?.[0]" icon="ki-phone"
                        />
                    </div>
                    <div class="col-md-6">
                        <CampoFicha
                            v-model="form.website" label="Website" :editing="editando" placeholder="https://"
                            :error="errors.website?.[0]" icon="ki-compass"
                        />
                    </div>
                </div>
            </div>

            <div class="card mb-6">
                <div class="card-header min-h-auto py-4">
                    <h3 class="card-title fs-6 fw-bold text-uppercase text-gray-600">Localização</h3>
                </div>
                <div class="card-body pt-0 row">
                    <div class="col-md-6">
                        <CampoFicha v-model="form.endereco" label="Endereço" :editing="editando" :error="errors.endereco?.[0]" icon="ki-map" :icon-paths="3" />
                    </div>
                    <div class="col-md-3">
                        <CampoFicha
                            v-model="form.caixa_postal" label="Caixa postal" :editing="editando" :error="errors.caixa_postal?.[0]"
                            icon="ki-directbox-default" :icon-paths="4"
                        />
                    </div>
                    <div class="col-md-3">
                        <CampoFicha
                            v-model="form.municipio" label="Município" :editing="editando" :error="errors.municipio?.[0]"
                            icon="ki-geolocation"
                        />
                    </div>
                    <div class="col-md-3">
                        <CampoFicha v-model="form.provincia" label="Província" :editing="editando" :error="errors.provincia?.[0]" icon="ki-flag" />
                    </div>
                </div>
            </div>

            <div class="card mb-6">
                <div class="card-header min-h-auto py-4">
                    <h3 class="card-title fs-6 fw-bold text-uppercase text-gray-600">Responsável</h3>
                </div>
                <div class="card-body pt-0 row">
                    <div class="col-md-6">
                        <CampoFicha
                            v-model="form.responsavel_nome" label="Nome" :editing="editando" :error="errors.responsavel_nome?.[0]"
                            icon="ki-profile-circle" :icon-paths="3"
                        />
                    </div>
                    <div class="col-md-6">
                        <CampoFicha
                            v-model="form.responsavel_cargo" label="Cargo" :editing="editando" :error="errors.responsavel_cargo?.[0]"
                            icon="ki-briefcase"
                        />
                    </div>
                </div>
            </div>

            <div class="card mb-6">
                <div class="card-header min-h-auto py-4">
                    <h3 class="card-title fs-6 fw-bold text-uppercase text-gray-600">Observações</h3>
                </div>
                <div class="card-body pt-0">
                    <CampoFicha
                        v-model="form.observacoes" label="Notas institucionais" type="textarea" :editing="editando"
                        placeholder="Usadas em faturas, recibos e relatórios" :error="errors.observacoes?.[0]"
                        icon="ki-notepad" :icon-paths="5"
                    />
                </div>
            </div>

            <div v-if="editando" class="d-flex justify-content-end gap-2">
                <button type="button" class="btn btn-light" :disabled="processing" @click="cancelar">Cancelar</button>
                <button type="submit" class="btn btn-primary" :disabled="processing">Guardar alterações</button>
            </div>
        </form>
    </div>
</template>
