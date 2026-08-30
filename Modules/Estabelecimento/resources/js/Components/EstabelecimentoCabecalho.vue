<script setup>
import { computed } from 'vue';

const props = defineProps({
    estabelecimento: { type: Object, default: null },
});

const tipos = {
    1: 'Público',
    2: 'Privado',
    3: 'Cooperativo',
};

const iniciais = computed(() => {
    const nome = props.estabelecimento?.nome_abreviado || props.estabelecimento?.nome;
    if (!nome) return 'EE';
    return nome
        .trim()
        .split(/\s+/)
        .slice(0, 2)
        .map((parte) => parte[0]?.toUpperCase())
        .join('');
});
</script>

<template>
    <div class="ficha-cabecalho">
        <div class="ficha-cabecalho__emblema">
            <img v-if="estabelecimento?.logotipo_url" :src="estabelecimento.logotipo_url" alt="" />
            <span v-else>{{ iniciais }}</span>
        </div>

        <div class="ficha-cabecalho__info">
            <div class="ficha-cabecalho__eyebrow">Ficha do estabelecimento</div>
            <h1 class="ficha-cabecalho__nome">{{ estabelecimento?.nome || 'Estabelecimento por cadastrar' }}</h1>
            <div class="ficha-cabecalho__meta">
                <span v-if="estabelecimento?.tipo">{{ tipos[estabelecimento.tipo] }}</span>
                <span v-if="estabelecimento?.nif">NIF {{ estabelecimento.nif }}</span>
                <span v-if="estabelecimento?.codigo_mined">Código MINED {{ estabelecimento.codigo_mined }}</span>
            </div>
        </div>
    </div>
</template>

<style scoped>
.ficha-cabecalho {
    display: flex;
    align-items: center;
    gap: 1.25rem;
    padding: 1.75rem 2rem;
    border-radius: 0.75rem;
    background: #0f172a;
    color: #f8fafc;
    margin-bottom: 1.75rem;
}

.ficha-cabecalho__emblema {
    flex: 0 0 auto;
    width: 64px;
    height: 64px;
    border-radius: 0.65rem;
    background: rgba(248, 250, 252, 0.08);
    border: 1px solid rgba(134, 239, 172, 0.35);
    display: flex;
    align-items: center;
    justify-content: center;
    font-weight: 700;
    font-size: 1.15rem;
    letter-spacing: 0.02em;
    color: #86efac;
    overflow: hidden;
}

.ficha-cabecalho__emblema img {
    width: 100%;
    height: 100%;
    object-fit: contain;
    padding: 0.4rem;
}

.ficha-cabecalho__eyebrow {
    font-size: 0.7rem;
    font-weight: 600;
    letter-spacing: 0.08em;
    text-transform: uppercase;
    color: #86efac;
    margin-bottom: 0.2rem;
}

.ficha-cabecalho__nome {
    font-size: 1.5rem;
    font-weight: 700;
    margin: 0 0 0.35rem;
    color: #ffffff;
}

.ficha-cabecalho__meta {
    display: flex;
    flex-wrap: wrap;
    gap: 0.35rem 1rem;
    font-size: 0.825rem;
    color: rgba(248, 250, 252, 0.65);
}
</style>
