<script setup>
import { onBeforeUnmount, ref, watch } from 'vue';
import { Link, router, usePage } from '@inertiajs/vue3';
import LayoutPlataforma from '../../Components/LayoutPlataforma.vue';
import BotaoVoltar from '@/Components/Shared/BotaoVoltar.vue';
import EstadoBadge from '../../Components/EstadoBadge.vue';
import AcoesCicloDeVida from '../../Components/AcoesCicloDeVida.vue';
import DominiosCard from '../../Components/DominiosCard.vue';
import RecuperarAdministradorModal from '../../Components/RecuperarAdministradorModal.vue';
import SenhaTemporariaModal from '../../Components/SenhaTemporariaModal.vue';
import { formatarDataHora } from '../../Composables/formatarData';
import ValidadeBadge from '../../Components/ValidadeBadge.vue';
import { formatarData } from '../../Composables/formatarData';
import EstadoVazio from '@/Components/Shared/EstadoVazio.vue';
import ConfirmModal from '@/Components/Shared/ConfirmModal.vue';

const props = defineProps({
    escola: { type: Object, required: true },
    auditoria: { type: Array, default: () => [] },
    licenca: { type: Object, default: null },
    consumo_email: { type: Object, default: null },
});
defineOptions({ layout: LayoutPlataforma });

const page = usePage();

// Senha temporária (flash, só na resposta que sucede à criação): passa para memória local e é
// descartada ao fechar. Nunca vai para localStorage, store, URL ou consola. Fechar roda a chave do
// histórico, para as entradas antigas (com a senha cifrada) ficarem ilegíveis.
const recuperarAberto = ref(false);

// «Forçar regresso à central»: só quando a escola envia pelo servidor SMTP próprio.
const regressoAberto = ref(false);
const aForcarRegresso = ref(false);

const aLevantar = ref(false);

function levantarBloqueio() {
    aLevantar.value = true;
    router.post(`/plataforma/escolas/${props.escola.codigo}/email/levantar-bloqueio`, {}, {
        preserveScroll: true,
        onFinish: () => (aLevantar.value = false),
    });
}

function forcarRegresso() {
    aForcarRegresso.value = true;
    router.post(`/plataforma/escolas/${props.escola.codigo}/email/regresso-central`, {}, {
        preserveScroll: true,
        onFinish: () => {
            aForcarRegresso.value = false;
            regressoAberto.value = false;
        },
    });
}

const senhaTemporaria = ref(page.props.flash?.senha_temporaria ?? null);

watch(
    () => page.props.flash?.senha_temporaria,
    (flash) => {
        if (flash) senhaTemporaria.value = flash;
    },
);

function fecharSenha() {
    const tinhaSenha = senhaTemporaria.value !== null;
    senhaTemporaria.value = null;
    if (tinhaSenha) router.clearHistory();
}

onBeforeUnmount(() => {
    if (senhaTemporaria.value !== null) router.clearHistory();
});
</script>

<template>
    <div>
        <BotaoVoltar href="/plataforma/escolas" />
        <div class="d-flex justify-content-between align-items-center flex-wrap gap-3 mb-6">
            <div class="d-flex align-items-center gap-4">
                <h1 class="fs-2 fw-bold mb-0">{{ escola.nome }}</h1>
                <EstadoBadge :estado="escola.estado" />
            </div>
            <div class="d-flex align-items-center gap-3">
                <button
                    v-if="escola.accoes_permitidas.recuperar_administrador"
                    type="button"
                    class="btn btn-light-primary"
                    @click="recuperarAberto = true"
                >
                    Recuperar administrador
                </button>
                <AcoesCicloDeVida :escola="escola" />
            </div>
        </div>

        <div v-if="page.props.errors?.geral" class="alert alert-danger mb-6" role="alert">{{ page.props.errors.geral }}</div>

        <div v-if="consumo_email" class="card mb-6">
            <div class="card-header"><h3 class="card-title">Emails opcionais — {{ consumo_email.mes }}</h3></div>
            <div class="card-body">
                <div v-if="consumo_email.limite === null" class="text-gray-700">{{ consumo_email.usado }} enviados este mês · sem limite no plano.</div>
                <template v-else>
                    <div class="d-flex justify-content-between mb-2">
                        <span class="text-gray-700">{{ consumo_email.usado }} de {{ consumo_email.limite }} ({{ consumo_email.percentagem }}%)</span>
                        <span v-if="consumo_email.estado === 'esgotado'" class="badge badge-light-danger">Esgotada</span>
                        <span v-else-if="consumo_email.estado === 'aviso'" class="badge badge-light-warning">A esgotar-se</span>
                    </div>
                    <div class="progress h-8px">
                        <div class="progress-bar" :class="{ 'bg-danger': consumo_email.estado === 'esgotado', 'bg-warning': consumo_email.estado === 'aviso' }" role="progressbar" :style="{ width: consumo_email.percentagem + '%' }" :aria-valuenow="consumo_email.percentagem" aria-valuemin="0" aria-valuemax="100"></div>
                    </div>
                </template>
            </div>
        </div>

        <div v-if="escola.email_proprio_activo || escola.email_bloqueado" class="card mb-6">
            <div class="card-header"><h3 class="card-title">Email</h3></div>
            <div class="card-body d-flex flex-wrap align-items-center justify-content-between gap-4">
                <div v-if="escola.email_bloqueado" class="text-gray-700">
                    O servidor SMTP ou o domínio próprio desta escola está <strong>bloqueado</strong>: a escola envia pela central e não o pode reactivar.
                </div>
                <div v-else class="text-gray-700">Esta escola envia os emails pelo seu próprio servidor SMTP ou domínio.</div>
                <button v-if="escola.email_bloqueado" type="button" class="btn btn-light-primary" :disabled="aLevantar" @click="levantarBloqueio">Levantar bloqueio</button>
                <button v-else type="button" class="btn btn-light-danger" @click="regressoAberto = true">Forçar regresso à central</button>
            </div>
        </div>

        <ConfirmModal
            :show="regressoAberto"
            titulo="Forçar regresso à central"
            mensagem="A escola deixa de enviar pelo seu servidor e passa a enviar pela central, e fica impedida de o reactivar até levantares o bloqueio. A configuração não é apagada."
            texto-confirmar="Forçar regresso"
            :processando="aForcarRegresso"
            @confirmar="forcarRegresso"
            @cancelar="regressoAberto = false"
        />

        <div class="card mb-6">
            <div class="card-header"><h3 class="card-title">Dados da escola</h3></div>
            <div class="card-body">
                <div class="row g-6">
                    <div class="col-12 col-md-4">
                        <div class="text-muted fs-7 fw-semibold">Código</div>
                        <div class="fw-bold text-gray-800">{{ escola.codigo }}</div>
                    </div>
                    <div class="col-12 col-md-4">
                        <div class="text-muted fs-7 fw-semibold">Estado</div>
                        <EstadoBadge :estado="escola.estado" />
                    </div>
                    <div class="col-12 col-md-4">
                        <div class="text-muted fs-7 fw-semibold">Criada em</div>
                        <div class="fw-bold text-gray-800">{{ formatarDataHora(escola.created_at) }}</div>
                    </div>
                    <div v-if="escola.reactivado_em" class="col-12 col-md-4">
                        <div class="text-muted fs-7 fw-semibold">Última reactivação</div>
                        <div class="fw-bold text-gray-800">{{ formatarDataHora(escola.reactivado_em) }}</div>
                    </div>
                    <template v-if="escola.estado !== 1">
                        <div v-if="escola.suspenso_em" class="col-12 col-md-4">
                            <div class="text-muted fs-7 fw-semibold">Suspensa em</div>
                            <div class="fw-bold text-gray-800">{{ formatarDataHora(escola.suspenso_em) }}</div>
                        </div>
                        <div v-if="escola.encerrado_em" class="col-12 col-md-4">
                            <div class="text-muted fs-7 fw-semibold">Encerrada em</div>
                            <div class="fw-bold text-gray-800">{{ formatarDataHora(escola.encerrado_em) }}</div>
                        </div>
                        <div v-if="escola.motivo_encerramento" class="col-12">
                            <div class="text-muted fs-7 fw-semibold">Motivo do encerramento</div>
                            <div class="bg-body-secondary rounded p-4 text-gray-800">{{ escola.motivo_encerramento }}</div>
                        </div>
                        <div v-if="escola.motivo_suspensao" class="col-12">
                            <div class="text-muted fs-7 fw-semibold">Motivo da suspensão</div>
                            <div class="bg-body-secondary rounded p-4 text-gray-800">{{ escola.motivo_suspensao }}</div>
                        </div>
                    </template>
                </div>
            </div>
        </div>

        <DominiosCard :escola="escola" />

        <div class="card mb-6">
            <div class="card-header"><h3 class="card-title">Licença</h3></div>
            <div class="card-body">
                <div v-if="licenca" class="d-flex flex-wrap justify-content-between align-items-center gap-3" data-testid="licenca-da-escola">
                    <div>
                        <div class="d-flex align-items-center gap-3 mb-1">
                            <ValidadeBadge :validade="licenca.validade" />
                            <span class="fw-bold text-gray-800">{{ licenca.plano ?? 'Plano removido' }}</span>
                        </div>
                        <div class="text-muted fs-7">Expira em {{ formatarData(licenca.expira_em) }} · {{ licenca.estado_comercial_descricao }}</div>
                    </div>
                    <Link :href="`/plataforma/licencas/${licenca.id}`" class="btn btn-light-primary">Ver licença</Link>
                </div>
                <div v-else class="d-flex flex-wrap justify-content-between align-items-center gap-3">
                    <span class="text-muted">Esta escola não tem licença em vigor.</span>
                    <Link :href="`/plataforma/licencas/emitir?escola=${escola.codigo}`" class="btn btn-light-primary">Emitir licença</Link>
                </div>
            </div>
        </div>

        <div class="card">
            <div class="card-header"><h3 class="card-title">Actividade recente</h3></div>
            <div class="card-body p-0">
                <table class="table align-middle table-row-dashed fs-6 gy-4 mb-0">
                    <thead>
                        <tr class="text-start text-muted fw-bold fs-7 text-uppercase gs-0">
                            <th class="min-w-150px">Quando</th>
                            <th class="min-w-150px">Acção</th>
                            <th class="min-w-150px">Operador</th>
                            <th class="min-w-150px">IP</th>
                        </tr>
                    </thead>
                    <tbody class="text-gray-600 fw-semibold">
                        <tr v-if="auditoria.length === 0">
                            <td colspan="4"><EstadoVazio titulo="Sem actividade registada." /></td>
                        </tr>
                        <tr v-for="registo in auditoria" :key="registo.id">
                            <td>{{ formatarDataHora(registo.created_at) }}</td>
                            <td>{{ registo.accao }}</td>
                            <td>{{ registo.autor ?? '—' }}</td>
                            <td>{{ registo.ip ?? '—' }}</td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>

        <RecuperarAdministradorModal :show="recuperarAberto" :escola="escola" @fechar="recuperarAberto = false" />
        <SenhaTemporariaModal :resultado="senhaTemporaria" @fechar="fecharSenha" />
    </div>
</template>
