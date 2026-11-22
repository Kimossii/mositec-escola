<script setup>
import { ref } from 'vue';
import { Link, usePage } from '@inertiajs/vue3';
import { toast } from 'vue-sonner';
import BotaoVoltar from '@/Components/Shared/BotaoVoltar.vue';
import { copiarParaAreaDeTransferencia } from '@/Composables/useCopiar';
import LayoutPlataforma from '../../Components/LayoutPlataforma.vue';
import ValidadeBadge from '../../Components/ValidadeBadge.vue';
import AcoesDeLicenca from '../../Components/AcoesDeLicenca.vue';
import AvisoEmissorIndisponivel from '../../Components/AvisoEmissorIndisponivel.vue';
import { formatarData, formatarDataHora } from '../../Composables/formatarData';
import { errosForaDosCampos, rotuloLimite, rotuloModulos, rotuloDeEvento } from '../../Composables/licencas';

defineProps({
    licenca: { type: Object, required: true },
    eventos: { type: Array, default: () => [] },
    accoes_permitidas: { type: Object, required: true },
    emissor: { type: Object, required: true },
});
defineOptions({ layout: LayoutPlataforma });

const page = usePage();
const copiado = ref(false);

async function copiar(token) {
    if (await copiarParaAreaDeTransferencia(token)) {
        copiado.value = true;
        setTimeout(() => { copiado.value = false; }, 2000);
    } else {
        toast.error('Não foi possível copiar. Use a descarga do ficheiro.');
    }
}

// Todos os erros que não são de um campo do modal (o modal só trata `motivo` e `tolerancia_dias`).
const errosGerais = () => errosForaDosCampos(page.props.errors, ['motivo', 'tolerancia_dias']);
</script>

<template>
    <div>
        <BotaoVoltar href="/plataforma/licencas" />
        <div class="d-flex justify-content-between align-items-center flex-wrap gap-3 mb-6">
            <div class="d-flex align-items-center flex-wrap gap-3">
                <h1 class="fs-2 fw-bold mb-0 text-break">{{ licenca.escola ?? licenca.instalacao ?? 'Licença' }}</h1>
                <ValidadeBadge :validade="licenca.validade" />
            </div>
            <AcoesDeLicenca :licenca="licenca" :accoes="accoes_permitidas" />
        </div>

        <AvisoEmissorIndisponivel v-if="!emissor.disponivel" :mensagem="emissor.mensagem" :motivo="emissor.motivo" />
        <div v-if="errosGerais().length" class="alert alert-danger mb-6" role="alert">
            <div v-for="mensagem in errosGerais()" :key="mensagem">{{ mensagem }}</div>
        </div>

        <div class="card mb-6">
            <div class="card-header"><h3 class="card-title">Direitos</h3></div>
            <div class="card-body">
                <div class="row g-6">
                    <div class="col-12 col-md-4">
                        <div class="text-muted fs-7 fw-semibold">Escola</div>
                        <div class="fw-bold text-gray-800">
                            <Link v-if="licenca.escola" :href="`/plataforma/escolas/${licenca.escola}`" class="text-gray-800 text-hover-primary">{{ licenca.escola }}</Link>
                            <span v-else>—</span>
                        </div>
                    </div>
                    <div class="col-12 col-md-4">
                        <div class="text-muted fs-7 fw-semibold">Instalação</div>
                        <div class="fw-bold text-gray-800 text-break">{{ licenca.instalacao ?? '—' }}</div>
                    </div>
                    <div class="col-12 col-md-4">
                        <div class="text-muted fs-7 fw-semibold">Plano</div>
                        <div class="fw-bold text-gray-800">{{ licenca.plano ?? '—' }}</div>
                    </div>
                    <div class="col-12 col-md-4">
                        <div class="text-muted fs-7 fw-semibold">Modalidade</div>
                        <div class="fw-bold text-gray-800">{{ licenca.modalidade_descricao }}{{ licenca.ligacao_obrigatoria ? ' · ligada à instalação' : '' }}</div>
                    </div>
                    <div class="col-12 col-md-4">
                        <div class="text-muted fs-7 fw-semibold">Estado comercial</div>
                        <div class="fw-bold text-gray-800">{{ licenca.estado_comercial_descricao }}</div>
                    </div>
                    <div class="col-12 col-md-4">
                        <div class="text-muted fs-7 fw-semibold">Emitida em</div>
                        <div class="fw-bold text-gray-800">{{ formatarDataHora(licenca.emitida_em) }}</div>
                    </div>
                    <div class="col-12 col-md-4">
                        <div class="text-muted fs-7 fw-semibold">Início</div>
                        <div class="fw-bold text-gray-800">{{ formatarData(licenca.inicio) }}</div>
                    </div>
                    <div class="col-12 col-md-4">
                        <div class="text-muted fs-7 fw-semibold">Expira em</div>
                        <div class="fw-bold text-gray-800">{{ formatarData(licenca.expira_em) }}</div>
                    </div>
                    <div class="col-12 col-md-4">
                        <div class="text-muted fs-7 fw-semibold">Tolerância / aviso</div>
                        <div class="fw-bold text-gray-800">{{ licenca.tolerancia_dias }} dias / {{ licenca.aviso_dias }} dias</div>
                    </div>
                    <div class="col-12 col-md-4">
                        <div class="text-muted fs-7 fw-semibold">Limites</div>
                        <div class="fw-bold text-gray-800">
                            Alunos: {{ rotuloLimite(licenca.limites.alunos) }}<br />
                            Utilizadores: {{ rotuloLimite(licenca.limites.utilizadores) }}
                        </div>
                    </div>
                    <div class="col-12 col-md-8">
                        <div class="text-muted fs-7 fw-semibold">Módulos</div>
                        <div class="fw-bold text-gray-800">{{ rotuloModulos(licenca.modulos) }}</div>
                    </div>
                </div>
            </div>
        </div>

        <div class="card mb-6">
            <div class="card-header"><h3 class="card-title">Ficheiro de licença</h3></div>
            <div class="card-body">
                <p class="text-muted fs-7">Entregue este ficheiro (ou o texto) ao administrador da instalação, que o importa na página da licença.</p>
                <textarea readonly rows="4" class="form-control form-control-solid font-monospace fs-8 mb-4" :value="licenca.token" data-testid="token-licenca" />
                <div class="d-flex flex-wrap gap-3">
                    <button type="button" class="btn btn-light-primary" @click="copiar(licenca.token)">{{ copiado ? 'Copiado' : 'Copiar texto' }}</button>
                    <a :href="`/plataforma/licencas/${licenca.id}/descarregar`" class="btn btn-primary">Descarregar ficheiro</a>
                </div>
            </div>
        </div>

        <div class="card">
            <div class="card-header"><h3 class="card-title">Histórico</h3></div>
            <div class="card-body p-0">
                <div v-if="eventos.length === 0" class="p-6 text-muted">Sem eventos registados.</div>
                <div v-for="evento in eventos" :key="evento.id" class="p-5 border-bottom border-gray-200">
                    <div class="d-flex flex-wrap justify-content-between gap-2">
                        <div class="fw-bold text-gray-800">{{ rotuloDeEvento(evento.tipo) }}</div>
                        <div class="text-muted fs-7">{{ formatarDataHora(evento.created_at) }} · {{ evento.autor ?? 'Sistema' }}</div>
                    </div>
                    <div v-if="evento.motivo" class="bg-body-secondary rounded p-3 mt-2 text-gray-800">{{ evento.motivo }}</div>
                    <div v-if="evento.detalhe?.nova_licenca_id" class="fs-7 mt-2">
                        Substituída por <Link :href="`/plataforma/licencas/${evento.detalhe.nova_licenca_id}`">a licença nova</Link>
                    </div>
                </div>
            </div>
        </div>
    </div>
</template>
