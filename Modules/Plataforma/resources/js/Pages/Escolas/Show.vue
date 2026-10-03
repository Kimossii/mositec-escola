<script setup>
import { onBeforeUnmount, ref, watch } from 'vue';
import { Link, router, usePage } from '@inertiajs/vue3';
import LayoutPlataforma from '../../Components/LayoutPlataforma.vue';
import EstadoBadge from '../../Components/EstadoBadge.vue';
import SenhaTemporariaModal from '../../Components/SenhaTemporariaModal.vue';
import { formatarDataHora } from '../../Composables/formatarData';

defineProps({
    escola: { type: Object, required: true },
    auditoria: { type: Array, default: () => [] },
});
defineOptions({ layout: LayoutPlataforma });

const page = usePage();

// Senha temporária (flash, só na resposta que sucede à criação): passa para memória local e é
// descartada ao fechar. Nunca vai para localStorage, store, URL ou consola. Fechar roda a chave do
// histórico, para as entradas antigas (com a senha cifrada) ficarem ilegíveis.
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
        <div class="d-flex justify-content-between align-items-center flex-wrap gap-3 mb-6">
            <div class="d-flex align-items-center gap-4">
                <h1 class="fs-2 fw-bold mb-0">{{ escola.nome }}</h1>
                <EstadoBadge :estado="escola.estado" />
            </div>
            <div class="d-flex align-items-center gap-3">
                <!-- Espaço reservado: as acções de ciclo de vida da escola entram aqui (Task 5). -->
                <slot name="acoes" />
                <Link href="/plataforma/escolas" class="btn btn-light">Voltar</Link>
            </div>
        </div>

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
                    <template v-if="escola.estado !== 1">
                        <div v-if="escola.suspenso_em" class="col-12 col-md-4">
                            <div class="text-muted fs-7 fw-semibold">Suspensa em</div>
                            <div class="fw-bold text-gray-800">{{ formatarDataHora(escola.suspenso_em) }}</div>
                        </div>
                        <div v-if="escola.encerrado_em" class="col-12 col-md-4">
                            <div class="text-muted fs-7 fw-semibold">Encerrada em</div>
                            <div class="fw-bold text-gray-800">{{ formatarDataHora(escola.encerrado_em) }}</div>
                        </div>
                        <div v-if="escola.motivo_suspensao" class="col-12">
                            <div class="text-muted fs-7 fw-semibold">Motivo da suspensão</div>
                            <div class="bg-body-secondary rounded p-4 text-gray-800">{{ escola.motivo_suspensao }}</div>
                        </div>
                    </template>
                </div>
            </div>
        </div>

        <div class="card mb-6">
            <div class="card-header"><h3 class="card-title">Domínios</h3></div>
            <div class="card-body p-0">
                <table class="table align-middle table-row-dashed fs-6 gy-4 mb-0">
                    <thead>
                        <tr class="text-start text-muted fw-bold fs-7 text-uppercase gs-0">
                            <th class="min-w-200px">Domínio</th>
                            <th class="min-w-150px">Tipo</th>
                        </tr>
                    </thead>
                    <tbody class="text-gray-600 fw-semibold">
                        <tr v-for="dominio in escola.dominios" :key="dominio.dominio">
                            <td>
                                {{ dominio.dominio }}
                                <span v-if="dominio.is_principal" class="badge badge-light-primary fw-bold ms-2">Principal</span>
                            </td>
                            <td>{{ dominio.tipo_descricao }}</td>
                        </tr>
                    </tbody>
                </table>
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
                            <td colspan="4" class="text-center text-muted py-6">Sem actividade registada.</td>
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

        <SenhaTemporariaModal :resultado="senhaTemporaria" @fechar="fecharSenha" />
    </div>
</template>
