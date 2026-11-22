<script setup>
import { Link, router } from '@inertiajs/vue3';
import Pagination from '@/Components/Shared/Pagination.vue';
import EstadoVazio from '@/Components/Shared/EstadoVazio.vue';
import LayoutPlataforma from '../../Components/LayoutPlataforma.vue';
import ValidadeBadge from '../../Components/ValidadeBadge.vue';
import AvisoEmissorIndisponivel from '../../Components/AvisoEmissorIndisponivel.vue';
import { formatarData } from '../../Composables/formatarData';
import { diasAte } from '../../Composables/licencas';

defineProps({
    licencas: { type: Object, required: true }, // paginator: { data, links, total, ... }
    proximas_expiracoes: { type: Array, default: () => [] },
    emissor: { type: Object, required: true }, // { disponivel, mensagem }
});
defineOptions({ layout: LayoutPlataforma });

const abrir = (licenca) => router.visit(`/plataforma/licencas/${licenca.id}`);
const destino = (licenca) => licenca.escola ?? licenca.instalacao ?? '—';

function quando(licenca) {
    const dias = diasAte(licenca.expira_em);
    if (dias === null) return '';
    if (dias < 0) return `expirou há ${-dias} ${-dias === 1 ? 'dia' : 'dias'}`;

    return dias === 0 ? 'expira hoje' : `faltam ${dias} ${dias === 1 ? 'dia' : 'dias'}`;
}
</script>

<template>
    <div>
        <div class="d-flex justify-content-between align-items-center mb-6">
            <div>
                <h1 class="fs-2 fw-bold mb-0">Licenças</h1>
                <div class="text-muted fs-7">{{ licencas.total ?? licencas.data.length }} {{ (licencas.total ?? licencas.data.length) === 1 ? 'licença' : 'licenças' }}</div>
            </div>
            <Link v-if="emissor.disponivel" href="/plataforma/licencas/emitir" class="btn btn-primary">Emitir licença</Link>
            <button v-else type="button" class="btn btn-primary" disabled title="Este servidor não emite licenças">Emitir licença</button>
        </div>

        <AvisoEmissorIndisponivel v-if="!emissor.disponivel" :mensagem="emissor.mensagem" :motivo="emissor.motivo" />

        <div class="card mb-6">
            <div class="card-header"><h3 class="card-title">Próximas expirações</h3></div>
            <div class="card-body">
                <EstadoVazio v-if="proximas_expiracoes.length === 0" titulo="Nenhuma licença activa." />
                <div v-else class="d-flex flex-column gap-3" data-testid="proximas-expiracoes">
                    <Link
                        v-for="licenca in proximas_expiracoes"
                        :key="licenca.id"
                        :href="`/plataforma/licencas/${licenca.id}`"
                        class="d-flex flex-wrap justify-content-between align-items-center gap-2 rounded p-4 bg-body-secondary text-decoration-none"
                    >
                        <div>
                            <div class="fw-bold text-gray-800">{{ destino(licenca) }}</div>
                            <div class="text-muted fs-7">{{ licenca.plano ?? 'Plano removido' }} · {{ formatarData(licenca.expira_em) }} ({{ quando(licenca) }})</div>
                        </div>
                        <ValidadeBadge :validade="licenca.validade" />
                    </Link>
                </div>
            </div>
        </div>

        <div class="card">
            <div class="card-header"><h3 class="card-title">Todas as licenças</h3></div>
            <div class="card-body p-0">
                <EstadoVazio v-if="licencas.data.length === 0" titulo="Ainda não foram emitidas licenças." />

                <!-- Computador: tabela -->
                <table v-else class="table align-middle table-row-dashed table-hover fs-6 gy-5 mb-0 d-none d-md-table">
                    <thead>
                        <tr class="text-start text-muted fw-bold fs-7 text-uppercase gs-0">
                            <th class="min-w-150px">Escola / Instalação</th>
                            <th>Plano</th>
                            <th>Modalidade</th>
                            <th>Emitida em</th>
                            <th>Expira em</th>
                            <th>Validade</th>
                            <th>Estado</th>
                        </tr>
                    </thead>
                    <tbody class="text-gray-600 fw-semibold">
                        <tr v-for="licenca in licencas.data" :key="licenca.id" role="button" @click="abrir(licenca)">
                            <td>
                                <Link :href="`/plataforma/licencas/${licenca.id}`" class="text-gray-800 text-hover-primary fw-bold" @click.stop>{{ destino(licenca) }}</Link>
                            </td>
                            <td>{{ licenca.plano ?? '—' }}</td>
                            <td>{{ licenca.modalidade_descricao }}</td>
                            <td>{{ formatarData(licenca.emitida_em) }}</td>
                            <td>{{ formatarData(licenca.expira_em) }}</td>
                            <td><ValidadeBadge :validade="licenca.validade" /></td>
                            <td>{{ licenca.estado_comercial_descricao }}</td>
                        </tr>
                    </tbody>
                </table>

                <!-- Telemóvel: um cartão por licença -->
                <div v-if="licencas.data.length" class="d-md-none">
                    <Link
                        v-for="licenca in licencas.data"
                        :key="licenca.id"
                        :href="`/plataforma/licencas/${licenca.id}`"
                        class="d-block p-5 border-bottom border-gray-200 text-decoration-none"
                    >
                        <div class="d-flex justify-content-between align-items-start gap-3 mb-1">
                            <div class="text-gray-800 fw-bold fs-5 text-break">{{ destino(licenca) }}</div>
                            <ValidadeBadge :validade="licenca.validade" />
                        </div>
                        <div class="text-muted fs-7">{{ licenca.plano ?? '—' }} · {{ licenca.modalidade_descricao }}</div>
                        <div class="text-gray-700 fs-7">Expira em {{ formatarData(licenca.expira_em) }} · {{ licenca.estado_comercial_descricao }}</div>
                    </Link>
                </div>
            </div>
            <div v-if="licencas.data.length" class="card-footer d-flex justify-content-end">
                <Pagination :links="licencas.links" />
            </div>
        </div>
    </div>
</template>
