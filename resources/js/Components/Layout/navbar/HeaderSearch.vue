<script setup>
import { computed, nextTick, onBeforeUnmount, onMounted, ref, watch } from 'vue'
import { Link } from '@inertiajs/vue3'
import { can } from '@/Composables/usePermissoes'

// Pesquisa global: resultados reais (alunos, matrículas, pagamentos) vindos de /pesquisa, mais atalhos «Ir para…»
// filtrados por permissão. Abre com clique ou Ctrl+K.
const MINIMO = 2
const IR_PARA = [
    { texto: 'Alunos', link: '/alunos', permissao: 'aluno.ver' },
    { texto: 'Matrículas', link: '/matriculas', permissao: 'matricula.ver' },
    { texto: 'Turmas', link: '/turmas', permissao: 'turmas.ver' },
    { texto: 'Propinas', link: '/financeiro/propinas', permissao: 'propina.ver' },
    { texto: 'Dívidas', link: '/financeiro/dividas', permissao: 'propina.ver' },
    { texto: 'Pagamentos', link: '/financeiro/pagamentos', permissao: 'pagamento.ver' },
    { texto: 'Recibos', link: '/financeiro/recibos', permissao: 'pagamento.ver' },
    { texto: 'Acordos de dívida', link: '/financeiro/acordos', permissao: 'acordo.ver' },
    { texto: 'Cobranças', link: '/financeiro/cobrancas', permissao: 'cobranca.ver' },
    { texto: 'Bolsas', link: '/financeiro/bolsas', permissao: 'bolsa.ver' },
    { texto: 'Relatórios financeiros', link: '/financeiro/relatorios', permissao: 'pagamento.ver' },
]

const gatilho = ref(null)
const campo = ref(null)
const termo = ref('')
const grupos = ref({})
const aProcurar = ref(false)
let temporizador = null
let pedido = null

const tem = computed(() => termo.value.trim().length >= MINIMO)
const semResultados = computed(() => tem.value && !aProcurar.value && Object.keys(grupos.value).length === 0)

const atalhos = computed(() => {
    const t = termo.value.trim().toLowerCase()

    return IR_PARA
        .filter((a) => can(a.permissao))
        .filter((a) => !t || a.texto.toLowerCase().includes(t))
        .slice(0, tem.value ? 4 : 8)
})

async function procurar() {
    pedido?.abort()
    if (!tem.value) {
        grupos.value = {}
        aProcurar.value = false

        return
    }

    pedido = new AbortController()
    aProcurar.value = true
    try {
        const resposta = await fetch(`/pesquisa?q=${encodeURIComponent(termo.value.trim())}`, {
            headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
            credentials: 'same-origin',
            signal: pedido.signal,
        })
        grupos.value = resposta.ok ? (await resposta.json()).grupos : {}
        aProcurar.value = false
    } catch (erro) {
        if (erro.name !== 'AbortError') aProcurar.value = false
    }
}

watch(termo, () => {
    clearTimeout(temporizador)
    temporizador = setTimeout(procurar, 250)
})

function abrir() {
    nextTick(() => setTimeout(() => campo.value?.focus(), 80))
}

// Fecha o menu do tema (clique fora) depois de escolher um resultado.
function fechar() {
    termo.value = ''
    grupos.value = {}
    document.body.click()
}

function teclado(evento) {
    if ((evento.ctrlKey || evento.metaKey) && evento.key.toLowerCase() === 'k') {
        evento.preventDefault()
        gatilho.value?.click()
        abrir()
    }
}

onMounted(() => window.addEventListener('keydown', teclado))
onBeforeUnmount(() => {
    window.removeEventListener('keydown', teclado)
    clearTimeout(temporizador)
    pedido?.abort()
})
</script>

<template>
    <div class="d-flex align-items-stretch">
        <div
            ref="gatilho"
            class="d-flex align-items-center"
            data-kt-menu-trigger="click"
            data-kt-menu-attach="parent"
            data-kt-menu-placement="bottom-end"
            title="Pesquisar (Ctrl+K)"
            @click="abrir"
        >
            <div class="btn btn-icon btn-custom btn-icon-muted btn-active-light btn-active-color-primary w-30px h-30px w-md-40px h-md-40px">
                <i class="ki-duotone ki-magnifier fs-2 fs-lg-1"><span class="path1"></span><span class="path2"></span></i>
            </div>
        </div>

        <div class="menu menu-sub menu-sub-dropdown p-7 w-325px w-md-400px" data-kt-menu="true">
            <div class="position-relative mb-3">
                <i class="ki-duotone ki-magnifier fs-2 text-gray-500 position-absolute top-50 translate-middle-y ms-0"><span class="path1"></span><span class="path2"></span></i>
                <input
                    ref="campo"
                    v-model="termo"
                    type="text"
                    class="form-control form-control-flush ps-10"
                    placeholder="Pesquisar aluno, matrícula, recibo…"
                    autocomplete="off"
                />
                <span v-if="aProcurar" class="position-absolute top-50 end-0 translate-middle-y lh-0 me-1"><span class="spinner-border h-15px w-15px align-middle text-gray-400"></span></span>
            </div>

            <div class="separator border-gray-200 mb-5"></div>

            <div class="scroll-y mh-400px">
                <template v-for="(itens, nome) in grupos" :key="nome">
                    <h3 class="fs-7 text-muted text-uppercase fw-bold mb-3">{{ nome }}</h3>
                    <Link
                        v-for="item in itens"
                        :key="item.link + item.titulo"
                        :href="item.link"
                        class="d-flex align-items-center justify-content-between gap-3 text-gray-800 text-hover-primary rounded px-2 py-2 mb-1 bg-hover-light"
                        @click="fechar"
                    >
                        <span class="min-w-0">
                            <span class="d-block fw-semibold fs-6 text-truncate">{{ item.titulo }}</span>
                            <span class="d-block text-muted fs-7 text-truncate">{{ item.subtitulo }}</span>
                        </span>
                        <span v-if="item.extra" class="badge badge-light-primary flex-shrink-0">{{ item.extra }}</span>
                    </Link>
                    <div class="mb-4"></div>
                </template>

                <div v-if="semResultados" class="text-center text-muted py-6">
                    Nenhum resultado para «{{ termo }}».
                </div>

                <template v-if="atalhos.length">
                    <h3 class="fs-7 text-muted text-uppercase fw-bold mb-3">Ir para</h3>
                    <Link
                        v-for="a in atalhos"
                        :key="a.link"
                        :href="a.link"
                        class="d-flex align-items-center text-gray-800 text-hover-primary rounded px-2 py-2 mb-1 bg-hover-light"
                        @click="fechar"
                    >
                        <i class="ki-duotone ki-arrow-right fs-4 text-muted me-3"><span class="path1"></span><span class="path2"></span></i>
                        <span class="fw-semibold">{{ a.texto }}</span>
                    </Link>
                </template>
            </div>
        </div>
    </div>
</template>
