<script setup>
import { computed } from 'vue';
import { Head } from '@inertiajs/vue3';
import { formatarDinheiro } from '../../Support/dinheiro';
import { dataCurta } from '../../Support/periodos';
import '../../../css/recibo.css';

const props = defineProps({
    pagamento: { type: Object, required: true },
    recibo: { type: Object, required: true },
    moeda: { type: Object, required: true },
    escola: { type: Object, required: true },
    aluno: { type: Object, required: true },
    numero: { type: String, required: true },
    rotulo: { type: String, required: true },
    propinaId: { type: Number, default: null },
});

// Duas vias do mesmo recibo na mesma folha A4: a de cima fica com quem pagou, a de baixo com a instituição.
const vias = ['Via do pagador', 'Via da instituição'];

const periodosVisiveis = computed(() => {
    if (props.propinaId === null || props.propinaId === undefined) {
        return props.recibo.periodos;
    }

    return props.recibo.periodos.filter((periodo) => periodo.propina_id === props.propinaId);
});

const pagoCom = (dinheiro, credito) => {
    const metodo = props.pagamento.metodo?.nome ?? '—';

    if (dinheiro > 0 && credito > 0) {
        return `Dinheiro (${metodo}) + Crédito do aluno`;
    }

    return credito > 0 ? 'Crédito do aluno' : `Dinheiro (${metodo})`;
};

// Uma linha por componente: a propina (capital) e, quando existir, a multa de cada período.
const linhas = computed(() => {
    const linhas = [];

    for (const periodo of periodosVisiveis.value) {
        const capital = periodo.capital_dinheiro + periodo.capital_credito;
        const multa = periodo.multa_dinheiro + periodo.multa_credito;

        if (capital > 0) {
            linhas.push({
                descricao: `Propina ${periodo.periodo}`,
                pagoCom: pagoCom(periodo.capital_dinheiro, periodo.capital_credito),
                valor: capital,
            });
        }

        if (multa > 0) {
            linhas.push({
                descricao: `Multa por atraso ${periodo.periodo}`,
                pagoCom: pagoCom(periodo.multa_dinheiro, periodo.multa_credito),
                valor: multa,
            });
        }
    }

    return linhas;
});

const totais = computed(() => {
    const recebido = periodosVisiveis.value.reduce((soma, periodo) => soma + periodo.capital_dinheiro + periodo.multa_dinheiro, 0);
    const credito = periodosVisiveis.value.reduce((soma, periodo) => soma + periodo.capital_credito + periodo.multa_credito, 0);

    return { recebido_em_dinheiro: recebido, pago_com_credito: credito, total_liquidado: recebido + credito };
});

// Com muitas linhas, reduz-se a letra (e o espaçamento) em vez de cortar o conteúdo fora da folha.
const classeFolha = computed(() => {
    const quantidade = linhas.value.length;

    if (quantidade <= 6) return '';
    if (quantidade <= 12) return 'recibo-folha--densa';

    return 'recibo-folha--compacta';
});

const anulado = computed(() => props.pagamento.anulacao !== null);
const dinheiro = (valor) => formatarDinheiro(valor, props.moeda);
const imprimir = () => window.print();

const TIPO_CARTEIRA_DIGITAL = 7;

const dadosMetodo = computed(() => {
    const metodo = props.pagamento.metodo ?? {};
    const carteira = metodo.tipo === TIPO_CARTEIRA_DIGITAL;

    return [
        { rotulo: 'Titular', valor: metodo.titular },
        { rotulo: 'Banco', valor: metodo.banco },
        { rotulo: 'IBAN', valor: metodo.iban },
        { rotulo: carteira ? 'Outro identificador' : 'Nº conta', valor: metodo.numero_conta },
        { rotulo: 'Telefone', valor: metodo.telefone },
    ].filter((dado) => dado.valor);
});
</script>

<template>
    <Head :title="`Recibo ${numero}`" />

    <div class="recibo-ecra no-print">
        <button type="button" class="btn btn-primary" @click="imprimir">Imprimir recibo</button>
        <a :href="`/financeiro/pagamentos/${pagamento.id}`" class="btn btn-light ms-2">Voltar ao pagamento</a>
    </div>

    <div class="recibo-folha" :class="classeFolha">
        <template v-for="(via, indice) in vias" :key="via">
            <div v-if="indice === 1" class="recibo-corte"></div>

            <section class="recibo-via">
                <img v-if="escola.logotipo_url" :src="escola.logotipo_url" alt="" class="recibo-marca" />
                <div v-if="anulado" class="recibo-anulado">ANULADO</div>

                <div v-if="escola.logotipo_url" class="recibo-logo"><img :src="escola.logotipo_url" alt="" /></div>

                <header class="recibo-cabecalho">
                    <div>
                        <div class="recibo-escola">{{ escola.nome ?? '—' }}</div>
                        <div v-if="escola.nif">NIF {{ escola.nif }}</div>
                        <div v-if="escola.morada">{{ escola.morada }}</div>
                        <div v-if="escola.telefone || escola.email">{{ [escola.telefone, escola.email].filter(Boolean).join(' · ') }}</div>
                    </div>
                    <div class="recibo-titulo">
                        <div class="recibo-nome">RECIBO</div>
                        <div class="recibo-numero">N.º {{ numero }}</div>
                        <div class="recibo-etiqueta">{{ rotulo }}</div>
                        <div class="recibo-etiqueta">{{ via }}</div>
                    </div>
                </header>

                <table class="recibo-dados">
                    <tbody>
                        <tr>
                            <th>Aluno</th>
                            <td colspan="3" class="destaque">{{ pagamento.matricula?.aluno_nome ?? '—' }}</td>
                        </tr>
                        <tr>
                            <th>Nº aluno</th>
                            <td>{{ pagamento.matricula?.aluno_numero ?? '—' }}</td>
                            <th>Identificação</th>
                            <td>{{ aluno.identificacao ?? '—' }}</td>
                        </tr>
                        <tr v-if="aluno.telefone || aluno.email">
                            <th>Telefone</th>
                            <td>{{ aluno.telefone ?? '—' }}</td>
                            <th>Email</th>
                            <td>{{ aluno.email ?? '—' }}</td>
                        </tr>
                        <tr>
                            <th>Matrícula</th>
                            <td>{{ pagamento.matricula?.numero ?? '—' }}</td>
                            <th>Turma</th>
                            <td>{{ pagamento.matricula?.turma_nome ?? '—' }}</td>
                        </tr>
                        <tr>
                            <th>Ano lectivo</th>
                            <td colspan="3">{{ pagamento.matricula?.ano_lectivo_nome ?? '—' }}</td>
                        </tr>
                        <tr class="recibo-seccao">
                            <th colspan="4">Pagamento</th>
                        </tr>
                        <tr>
                            <th>Tipo</th>
                            <td>{{ pagamento.metodo?.tipo_descricao ?? '—' }}</td>
                            <th>Nome</th>
                            <td>{{ pagamento.metodo?.nome ?? '—' }}</td>
                        </tr>
                        <tr v-for="dado in dadosMetodo" :key="dado.rotulo">
                            <th>{{ dado.rotulo }}</th>
                            <td colspan="3">{{ dado.valor }}</td>
                        </tr>
                        <tr v-if="pagamento.referencia">
                            <th>Referência</th>
                            <td colspan="3">{{ pagamento.referencia }}</td>
                        </tr>
                        <tr>
                            <th>Data</th>
                            <td colspan="3">{{ dataCurta(pagamento.data_pagamento) }}</td>
                        </tr>
                    </tbody>
                </table>

                <table class="recibo-tabela">
                    <thead>
                        <tr><th>Descrição</th><th>Pago com</th><th class="direita">Valor</th></tr>
                    </thead>
                    <tbody>
                        <tr v-for="(linha, i) in linhas" :key="i">
                            <td>{{ linha.descricao }}</td>
                            <td>{{ linha.pagoCom }}</td>
                            <td class="direita">{{ dinheiro(linha.valor) }}</td>
                        </tr>
                        <tr v-if="linhas.length === 0"><td colspan="3" class="vazio">Sem propinas associadas (valor lançado como crédito).</td></tr>
                    </tbody>
                    <tfoot>
                        <tr><th colspan="2">Recebido em dinheiro</th><th class="direita">{{ dinheiro(totais.recebido_em_dinheiro) }}</th></tr>
                        <tr><th colspan="2">Pago com crédito do aluno</th><th class="direita">{{ dinheiro(totais.pago_com_credito) }}</th></tr>
                        <tr><th colspan="2">Total liquidado</th><th class="direita">{{ dinheiro(totais.total_liquidado) }}</th></tr>
                    </tfoot>
                </table>

                <div class="recibo-assinaturas">
                    <div class="assinatura">
                        <div class="assinatura-nome">&nbsp;</div>
                        <div class="assinatura-linha">Assinatura</div>
                    </div>
                    <div class="carimbo"></div>
                    <div class="assinatura">
                        <div class="assinatura-nome">&nbsp;</div>
                        <div class="assinatura-linha">{{ pagamento.registado?.por ?? 'Atendente' }}</div>
                    </div>
                </div>
            </section>
        </template>
    </div>
</template>
