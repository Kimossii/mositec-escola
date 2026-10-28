<script setup>
import { onBeforeUnmount, onMounted, ref, watch } from 'vue';
import AlternadorDeVista from './AlternadorDeVista.vue';
import { useVistaDeLista } from '@/Composables/useVistaDeLista';

/**
 * Envolve o cartão da tabela de uma lista e dá-lhe duas vistas: a tabela de sempre (por defeito) ou cards. Os cards são
 * a mesma tabela redesenhada (CSS), por isso as acções, menus e regras de cada lista continuam exactamente as mesmas.
 * Enquanto a lista recarrega, mostra esqueletos por cima. Uso: <ListaComVistas chave="financeiro-propinas" prefixo="/financeiro/propinas"> …cartão da tabela… </ListaComVistas>
 */
const props = defineProps({
    chave: { type: String, required: true }, // identifica a lista na preferência guardada
    prefixo: { type: String, required: true }, // caminho da lista, para o loader reagir só às suas visitas
});

const { vista, carregando } = useVistaDeLista(props.chave, props.prefixo);

// Rotula as células de cada linha com o cabeçalho da coluna (a vista de cards usa-os) e marca título, estado e acções.
const raiz = ref(null);
let observador = null;
let agendado = false;

function rotular() {
    agendado = false;
    raiz.value?.querySelectorAll('table').forEach((tabela) => {
        if (tabela.parentElement?.closest('table')) return; // tabelas dentro de linhas expandidas ficam como estão
        const cabecalhos = [...tabela.querySelectorAll('thead th')].map((th) => th.textContent.trim());
        const corpo = tabela.querySelector('tbody');
        if (!corpo) return;
        const linhas = [...corpo.children].filter((l) => l.tagName === 'TR');

        linhas.forEach((linha) => {
            const celulas = [...linha.children].filter((c) => c.tagName === 'TD');
            if (celulas.length === 1 && celulas[0].hasAttribute('colspan')) {
                // única linha com colspan = «nenhum registo»; com outras linhas é o detalhe de uma linha expandida
                linha.classList.toggle('cards-oculta', linhas.length > 1);
                linha.classList.toggle('cards-vazio', linhas.length === 1);
                return;
            }

            let temTitulo = false;
            celulas.forEach((celula, i) => {
                const rotulo = cabecalhos[i] ?? '';
                celula.dataset.label = rotulo;
                const oculta = rotulo === '' && celula.textContent.trim() === '';
                const estado = /^estado$/i.test(rotulo);
                const acoes = /^a(c?ç|c)[õo]es$/i.test(rotulo);
                celula.classList.toggle('cel-oculta', oculta);
                celula.classList.toggle('cel-estado', estado);
                celula.classList.toggle('cel-acoes', acoes);
                const titulo = !temTitulo && !oculta && !estado && !acoes;
                celula.classList.toggle('cel-titulo', titulo);
                if (titulo) temTitulo = true;
            });
        });
    });
}

const agendar = () => {
    if (agendado) return;
    agendado = true;
    requestAnimationFrame(rotular);
};

onMounted(() => {
    rotular();
    observador = new MutationObserver(agendar);
    observador.observe(raiz.value, { childList: true, subtree: true }); // só estrutura: marcar classes/atributos não dispara
});
onBeforeUnmount(() => observador?.disconnect());
watch(vista, agendar);
</script>

<template>
    <div>
        <div class="d-flex justify-content-end mb-4">
            <AlternadorDeVista v-model="vista" />
        </div>

        <div ref="raiz" class="position-relative" :class="{ 'lista-vista-cards': vista === 'cards' }">
            <slot />

            <!-- Loader: esqueletos por cima enquanto a lista recarrega -->
            <div v-if="carregando" class="lista-loader placeholder-glow" aria-busy="true">
                <template v-if="vista === 'lista'">
                    <div v-for="n in 7" :key="n" class="d-flex align-items-center gap-4 py-4 px-5 border-bottom border-gray-200 border-dashed">
                        <span class="placeholder col-2"></span><span class="placeholder col-3"></span><span class="placeholder col-2 ms-auto"></span><span class="placeholder col-1"></span>
                    </div>
                </template>
                <div v-else class="lista-loader__grade">
                    <div v-for="n in 6" :key="n" class="card"><div class="card-body">
                        <span class="placeholder col-7 d-block mb-3"></span><span class="placeholder col-10 d-block mb-2"></span><span class="placeholder col-8 d-block mb-2"></span><span class="placeholder col-5 d-block"></span>
                    </div></div>
                </div>
            </div>
        </div>
    </div>
</template>

<style>
.lista-loader {
    position: absolute;
    inset: 0;
    z-index: 6;
    padding-top: 0.5rem;
    background: color-mix(in srgb, var(--bs-body-bg) 92%, transparent);
    border-radius: 0.85rem;
    overflow: hidden;
}
.lista-loader__grade { display: grid; grid-template-columns: repeat(auto-fill, minmax(300px, 1fr)); gap: 1.25rem; }

/* ---- Vista de cards: a mesma tabela, desenhada como grelha de cartões ----
   Os seletores repetem `.table` para vencer as regras do tema e do app.css que zeram/forçam o padding das pontas e as bordas
   das linhas das tabelas (`.table:not(.table-bordered) tr:first-child …`, `.table > tbody > tr > td:first-child`). */
.lista-vista-cards .table-responsive { overflow: visible; }
.lista-vista-cards > .card { background: transparent !important; border: 0 !important; box-shadow: none !important; }
.lista-vista-cards > .card > .card-body { padding: 0 !important; }
.lista-vista-cards > .card > .card-footer { border-top: 0 !important; background: transparent !important; padding: 1.25rem 0 0 !important; }
.lista-vista-cards table.table.table,
.lista-vista-cards table.table.table > tbody { display: block; width: 100%; }
.lista-vista-cards table.table.table > thead { display: none; }
.lista-vista-cards table.table.table > tbody { display: grid; grid-template-columns: repeat(auto-fill, minmax(310px, 1fr)); gap: 1.25rem; }
.lista-vista-cards table.table.table > tbody > tr {
    position: relative;
    display: flex;
    flex-direction: column;
    background: var(--bs-body-bg);
    border: 1px solid var(--bs-gray-300) !important;
    border-radius: 0.85rem;
    padding: 1.1rem 1.3rem 0.9rem !important;
    transition: box-shadow 0.15s ease, border-color 0.15s ease;
}
/* Sem `transform` no hover: um ancestral transformado passa a ser o referencial dos menus `position: fixed` (as «Ações») e desloca-os. */
.lista-vista-cards table.table.table > tbody > tr:hover { border-color: var(--bs-success) !important; box-shadow: 0 10px 28px rgba(15, 23, 42, 0.12); }
.lista-vista-cards table.table.table > tbody > tr > td {
    display: block;
    min-width: 0;
    padding: 0.3rem 0 !important;
    border: 0 !important;
    box-shadow: none !important;
    border-radius: 0 !important;
    text-align: right !important;
}
.lista-vista-cards table.table.table > tbody > tr > td[data-label]::before {
    content: attr(data-label);
    float: left;
    margin-right: 1rem;
    font-size: 0.7rem;
    font-weight: 600;
    letter-spacing: 0.05em;
    text-transform: uppercase;
    color: var(--bs-gray-600);
}
.lista-vista-cards table.table.table > tbody > tr > td:empty,
.lista-vista-cards table.table.table > tbody > tr > td.cel-oculta { display: none; }

.lista-vista-cards table.table.table > tbody > tr > td.cel-titulo {
    text-align: left !important;
    font-size: 1.05rem;
    font-weight: 700;
    padding: 0 5.5rem 0.65rem 0 !important;
    margin-bottom: 0.4rem;
    border-bottom: 1px dashed var(--bs-gray-300) !important;
}
.lista-vista-cards table.table.table > tbody > tr > td.cel-titulo::before { display: none; }

.lista-vista-cards table.table.table > tbody > tr > td.cel-estado { position: absolute; top: 1rem; right: 1.2rem; padding: 0 !important; }
.lista-vista-cards table.table.table > tbody > tr > td.cel-estado::before { display: none; }

.lista-vista-cards table.table.table > tbody > tr > td.cel-acoes {
    display: flex;
    justify-content: flex-end;
    flex-wrap: wrap;
    gap: 0.4rem;
    margin-top: 0.6rem;
    padding-top: 0.75rem !important;
    border-top: 1px dashed var(--bs-gray-300) !important;
}
.lista-vista-cards table.table.table > tbody > tr > td.cel-acoes::before { display: none; }

.lista-vista-cards table.table.table > tbody > tr.cards-oculta { display: none; }
.lista-vista-cards table.table.table > tbody > tr.cards-vazio { grid-column: 1 / -1; border: 0 !important; background: transparent; }
.lista-vista-cards table.table.table > tbody > tr.cards-vazio:hover { box-shadow: none; }
.lista-vista-cards table.table.table > tbody > tr.cards-vazio > td { text-align: center !important; padding: 2rem 0 !important; }
.lista-vista-cards table.table.table > tbody > tr.cards-vazio > td::before { display: none; }
</style>
