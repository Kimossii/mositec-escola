<script setup>
import { computed, ref } from 'vue';
import { can } from '@/Composables/usePermissoes';
import EstadoVazio from '@/Components/Shared/EstadoVazio.vue';
import EntradaCelula from './EntradaCelula.vue';
import { DIAS, SABADO } from '../Support/grelha';

/**
 * Grelha semanal: tempos nas linhas, dias nas colunas (segunda a sexta; o sábado é auxiliar — aparece sozinho se
 * houver aulas nele, ou pelo botão «Mostrar sábado»). Em ecrãs estreitos vira uma lista por dia.
 */
const props = defineProps({
    tempos: { type: Array, required: true }, // [{ id, nome, hora_inicio, hora_fim, e_aula }]
    entradas: { type: Array, required: true }, // EntradaVista[]
    editavel: { type: Boolean, default: false },
    mostrarTurma: { type: Boolean, default: false },
    hoje: { type: String, required: true },
});
const emit = defineEmits(['criar', 'editar', 'encerrar', 'eliminar']);

const temSabado = computed(() => props.entradas.some((e) => e.dia_semana === SABADO));
const sabadoManual = ref(false);
const mostrarSabado = computed(() => temSabado.value || sabadoManual.value);
const dias = computed(() => DIAS.filter((d) => d.valor !== SABADO || mostrarSabado.value));
const podeCriar = computed(() => props.editavel && can('grelha-horaria.criar'));

const naCelula = (dia, tempo) => props.entradas.filter((e) => e.dia_semana === dia && e.horario_id === tempo.id);
const intervalo = (t) => `${t.hora_inicio}–${t.hora_fim}`;
</script>

<template>
    <EstadoVazio v-if="!tempos.length" titulo="Sem tempos para mostrar" descricao="A turma precisa de um turno com tempos de aula definidos." />

    <template v-else>
        <div v-if="!temSabado" class="d-flex justify-content-end mb-2">
            <button type="button" class="btn btn-sm btn-light" @click="sabadoManual = !sabadoManual">
                {{ sabadoManual ? 'Ocultar sábado' : 'Mostrar sábado' }}
            </button>
        </div>

        <!-- Ecrãs largos: tabela -->
        <div class="card d-none d-md-block">
            <div class="card-body p-0 table-responsive">
                <table class="table table-row-bordered align-middle gs-4 gy-3 mb-0">
                    <thead>
                        <tr class="fw-bold fs-7 text-muted text-uppercase">
                            <th class="w-125px">Tempo</th>
                            <th v-for="d in dias" :key="d.valor">{{ d.nome }}</th>
                        </tr>
                    </thead>
                    <tbody>
                        <template v-for="t in tempos" :key="t.id">
                            <tr v-if="!t.e_aula" class="bg-body-secondary">
                                <td :colspan="dias.length + 1" class="text-center text-muted fs-8 py-1">{{ t.nome }} · {{ intervalo(t) }}</td>
                            </tr>
                            <tr v-else>
                                <td class="text-nowrap">
                                    <div class="fw-semibold fs-7">{{ t.nome }}</div>
                                    <div class="text-muted fs-8">{{ intervalo(t) }}</div>
                                </td>
                                <td v-for="d in dias" :key="d.valor" class="align-top" style="min-width: 150px;">
                                    <EntradaCelula
                                        v-for="e in naCelula(d.valor, t)"
                                        :key="e.id"
                                        :entrada="e"
                                        :mostrar-turma="mostrarTurma"
                                        :editavel="editavel"
                                        :hoje="hoje"
                                        @editar="(x) => emit('editar', x)"
                                        @encerrar="(x) => emit('encerrar', x)"
                                        @eliminar="(x) => emit('eliminar', x)"
                                    />
                                    <button
                                        v-if="podeCriar && !naCelula(d.valor, t).length"
                                        type="button"
                                        class="btn btn-sm btn-light-primary w-100"
                                        @click="emit('criar', { dia_semana: d.valor, horario_id: t.id })"
                                    >+ atribuir</button>
                                </td>
                            </tr>
                        </template>
                    </tbody>
                </table>
            </div>
        </div>

        <!-- Ecrãs estreitos: um cartão por dia -->
        <div class="d-md-none">
            <div v-for="d in dias" :key="d.valor" class="card mb-4">
                <div class="card-header min-h-45px"><h3 class="card-title fs-6">{{ d.nome }}</h3></div>
                <div class="card-body py-3">
                    <template v-for="t in tempos" :key="t.id">
                        <div v-if="!t.e_aula" class="text-center text-muted fs-8 py-1">{{ t.nome }} · {{ intervalo(t) }}</div>
                        <div v-else class="d-flex gap-3 py-2 border-bottom border-gray-200">
                            <div class="w-75px flex-shrink-0">
                                <div class="fw-semibold fs-8">{{ t.nome }}</div>
                                <div class="text-muted fs-9">{{ intervalo(t) }}</div>
                            </div>
                            <div class="flex-grow-1">
                                <EntradaCelula
                                    v-for="e in naCelula(d.valor, t)"
                                    :key="e.id"
                                    :entrada="e"
                                    :mostrar-turma="mostrarTurma"
                                    :editavel="editavel"
                                    :hoje="hoje"
                                    @editar="(x) => emit('editar', x)"
                                    @encerrar="(x) => emit('encerrar', x)"
                                    @eliminar="(x) => emit('eliminar', x)"
                                />
                                <button
                                    v-if="podeCriar && !naCelula(d.valor, t).length"
                                    type="button"
                                    class="btn btn-sm btn-light-primary"
                                    @click="emit('criar', { dia_semana: d.valor, horario_id: t.id })"
                                >+ atribuir</button>
                                <span v-else-if="!naCelula(d.valor, t).length" class="text-muted fs-8">livre</span>
                            </div>
                        </div>
                    </template>
                </div>
            </div>
        </div>
    </template>
</template>
