<script setup>
import { computed } from 'vue';

const props = defineProps({
    estabelecimento: { type: Object, default: null },
    etapas: { type: Array, default: () => [] }, // ids das etapas de ensino
    completude: { type: Object, default: null }, // { percentual: 0-100, faltam: string[] } — só na ficha de dados
});

const tipos = { 1: 'Público', 2: 'Privado', 3: 'Cooperativo' };
const tiposEnsino = { 1: 'Ensino Geral', 2: 'Ensino Técnico', 3: 'Ensino Universitário' };
const nomesEtapas = { 1: 'Creche', 2: 'Pré-Escolar', 3: 'Ensino Primário', 4: 'Ensino Secundário', 5: 'Ensino Superior' };

const iniciais = computed(() => {
    const nome = props.estabelecimento?.nome_abreviado || props.estabelecimento?.nome;
    if (!nome) return 'EE';

    return nome.trim().split(/\s+/).slice(0, 2).map((parte) => parte[0]?.toUpperCase()).join('');
});

const localizacao = computed(() => [props.estabelecimento?.municipio, props.estabelecimento?.provincia].filter(Boolean).join(', '));
const corDoAnel = computed(() => (props.completude?.percentual >= 100 ? '#86efac' : props.completude?.percentual >= 60 ? '#fde68a' : '#fca5a5'));
</script>

<template>
    <div class="hero">
        <span class="hero__brilho hero__brilho--a"></span>
        <span class="hero__brilho hero__brilho--b"></span>

        <div class="hero__conteudo">
            <div class="hero__emblema">
                <img v-if="estabelecimento?.logotipo_url" :src="estabelecimento.logotipo_url" alt="" />
                <span v-else>{{ iniciais }}</span>
            </div>

            <div class="hero__info">
                <div class="hero__eyebrow">Ficha institucional</div>
                <h1 class="hero__nome">{{ estabelecimento?.nome || 'Estabelecimento por cadastrar' }}</h1>

                <div class="hero__chips">
                    <span v-if="estabelecimento?.tipo" class="hero__chip hero__chip--forte">{{ tipos[estabelecimento.tipo] }}</span>
                    <span v-if="estabelecimento?.tipo_ensino" class="hero__chip">{{ tiposEnsino[estabelecimento.tipo_ensino] }}</span>
                    <span v-for="id in etapas" :key="id" class="hero__chip hero__chip--suave">{{ nomesEtapas[id] }}</span>
                </div>

                <div class="hero__meta">
                    <span v-if="localizacao" class="hero__meta-item">
                        <i class="ki-duotone ki-geolocation fs-5"><span class="path1"></span><span class="path2"></span></i>{{ localizacao }}
                    </span>
                    <span v-if="estabelecimento?.nif" class="hero__meta-item">NIF {{ estabelecimento.nif }}</span>
                    <span v-if="estabelecimento?.codigo_mined" class="hero__meta-item">MINED {{ estabelecimento.codigo_mined }}</span>
                </div>
            </div>

            <div v-if="completude" class="hero__completude">
                <div class="hero__anel" :style="{ '--p': completude.percentual, '--cor': corDoAnel }">
                    <span>{{ completude.percentual }}%</span>
                </div>
                <div>
                    <div class="hero__completude-titulo">{{ completude.percentual >= 100 ? 'Ficha completa' : 'Ficha incompleta' }}</div>
                    <div class="hero__completude-texto">
                        <template v-if="completude.faltam.length">Falta: {{ completude.faltam.slice(0, 3).join(', ') }}<template v-if="completude.faltam.length > 3"> e mais {{ completude.faltam.length - 3 }}</template></template>
                        <template v-else>Todos os dados preenchidos.</template>
                    </div>
                </div>
            </div>
        </div>
    </div>
</template>

<style scoped>
.hero {
    position: relative;
    overflow: hidden;
    border-radius: 1rem;
    margin-bottom: 1.5rem;
    padding: 2rem 2.25rem;
    color: #f8fafc;
    background: linear-gradient(120deg, #0f172a 0%, #12302f 55%, #166534 130%);
}

.hero__brilho {
    position: absolute;
    border-radius: 50%;
    pointer-events: none;
    background: radial-gradient(circle, rgba(134, 239, 172, 0.18), transparent 70%);
}
.hero__brilho--a { width: 340px; height: 340px; top: -160px; right: -60px; }
.hero__brilho--b { width: 220px; height: 220px; bottom: -120px; left: 30%; opacity: 0.7; }

.hero__conteudo {
    position: relative;
    display: flex;
    flex-wrap: wrap;
    align-items: center;
    gap: 1.5rem 1.75rem;
}

.hero__emblema {
    flex: 0 0 auto;
    width: 92px;
    height: 92px;
    border-radius: 1.1rem;
    display: flex;
    align-items: center;
    justify-content: center;
    background: rgba(248, 250, 252, 0.08);
    border: 1px solid rgba(134, 239, 172, 0.4);
    box-shadow: 0 8px 28px rgba(0, 0, 0, 0.25);
    font-size: 1.6rem;
    font-weight: 700;
    color: #86efac;
    overflow: hidden;
}
.hero__emblema img { width: 100%; height: 100%; object-fit: contain; padding: 0.55rem; background: rgba(255, 255, 255, 0.92); }

.hero__info { flex: 1 1 320px; min-width: 0; }

.hero__eyebrow {
    font-size: 0.7rem;
    font-weight: 600;
    letter-spacing: 0.1em;
    text-transform: uppercase;
    color: #86efac;
    margin-bottom: 0.25rem;
}

.hero__nome { font-size: 1.75rem; font-weight: 700; line-height: 1.2; margin: 0 0 0.75rem; color: #fff; }

.hero__chips { display: flex; flex-wrap: wrap; gap: 0.4rem; margin-bottom: 0.75rem; }
.hero__chip {
    font-size: 0.75rem;
    font-weight: 600;
    padding: 0.2rem 0.65rem;
    border-radius: 999px;
    border: 1px solid rgba(248, 250, 252, 0.25);
    color: rgba(248, 250, 252, 0.9);
}
.hero__chip--forte { background: #166534; border-color: #166534; color: #fff; }
.hero__chip--suave { border-color: rgba(134, 239, 172, 0.35); color: #bbf7d0; }

.hero__meta { display: flex; flex-wrap: wrap; gap: 0.35rem 1.25rem; font-size: 0.85rem; color: rgba(248, 250, 252, 0.7); }
.hero__meta-item { display: inline-flex; align-items: center; gap: 0.35rem; }

.hero__completude {
    flex: 0 1 280px;
    display: flex;
    align-items: center;
    gap: 0.9rem;
    padding: 0.85rem 1rem;
    border-radius: 0.85rem;
    background: rgba(248, 250, 252, 0.07);
    border: 1px solid rgba(248, 250, 252, 0.12);
}
.hero__anel {
    flex: 0 0 auto;
    width: 62px;
    height: 62px;
    border-radius: 50%;
    display: grid;
    place-items: center;
    background: conic-gradient(var(--cor) calc(var(--p) * 1%), rgba(248, 250, 252, 0.15) 0);
    position: relative;
}
.hero__anel::before { content: ''; position: absolute; inset: 6px; border-radius: 50%; background: #11262b; }
.hero__anel span { position: relative; font-size: 0.85rem; font-weight: 700; color: #fff; }
.hero__completude-titulo { font-weight: 700; font-size: 0.9rem; color: #fff; }
.hero__completude-texto { font-size: 0.78rem; color: rgba(248, 250, 252, 0.7); line-height: 1.35; }
</style>
