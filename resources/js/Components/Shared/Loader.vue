<script setup>
// Spinner genérico reutilizável — use enquanto algo carrega assíncrono
// dentro de uma página/componente (ex.: tabela buscando dados), não é
// o boot do app (esse já usa a barra de progresso do Inertia, configurada
// em resources/js/app.js).
defineProps({
    /** navy da sidebar (resources/css/app.css, .app-sidebar background-color) */
    color1: {
        type: String,
        default: '#0F172A',
    },
    /** verde de destaque usado nos itens ativos da sidebar (border-left/texto) */
    color2: {
        type: String,
        default: '#86EFAC',
    },
    /** múltiplo de tamanho do CSS original (não é um px direto) — 2.25px = ~108px de diâmetro */
    size: {
        type: String,
        default: '2.25px',
    },
});
</script>

<template>
    <span class="loader" :style="{ '--color-1': color1, '--color-2': color2, '--size': size }"></span>
</template>

<style scoped>
.loader {
    position: relative;
    display: inline-block;
    transform: rotateZ(45deg);
    perspective: calc(1000 * var(--size));
    border-radius: 50%;
    width: calc(48 * var(--size));
    height: calc(48 * var(--size));
    color: var(--color-1);
}

.loader:before,
.loader:after {
    content: '';
    display: block;
    position: absolute;
    top: 0;
    left: 0;
    width: inherit;
    height: inherit;
    border-radius: 50%;
    transform: rotateX(70deg);
    animation: loader-spin 1s linear infinite;
}

.loader:after {
    color: var(--color-2);
    transform: rotateY(70deg);
    animation-delay: 0.4s;
}

@keyframes loader-spin {
    0%,
    100% {
        box-shadow: 0.2em 0 0 0 currentcolor;
    }
    12% {
        box-shadow: 0.2em 0.2em 0 0 currentcolor;
    }
    25% {
        box-shadow: 0 0.2em 0 0 currentcolor;
    }
    37% {
        box-shadow: -0.2em 0.2em 0 0 currentcolor;
    }
    50% {
        box-shadow: -0.2em 0 0 0 currentcolor;
    }
    62% {
        box-shadow: -0.2em -0.2em 0 0 currentcolor;
    }
    75% {
        box-shadow: 0 -0.2em 0 0 currentcolor;
    }
    87% {
        box-shadow: 0.2em -0.2em 0 0 currentcolor;
    }
}
</style>
