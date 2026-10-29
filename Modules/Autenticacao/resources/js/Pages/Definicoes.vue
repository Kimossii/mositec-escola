<script setup>
import AcaoIcone from '@/Components/Shared/AcaoIcone.vue';
import { computed, reactive, ref } from 'vue';
import { router, usePage } from '@inertiajs/vue3';
import { toast } from 'vue-sonner';
import AppLayout from '@/Layouts/AppLayout.vue';
import AvatarUtilizador from '@/Components/Shared/AvatarUtilizador.vue';
import PerfisDoUtilizador from '@/Components/Shared/PerfisDoUtilizador.vue';
import Loader from '@/Components/Shared/Loader.vue';
import { definirTema, temaActual } from '@/Composables/useTema';
import { MENU_DE, ORDEM_DOS_MENUS } from '../../../../Permissao/resources/js/Support/menusDosModulos';
import CampoSenha from '../Components/CampoSenha.vue';
import ForcaDaSenha from '../Components/ForcaDaSenha.vue';

defineOptions({ layout: AppLayout });

const props = defineProps({
    aba: { type: String, default: 'perfil' },
    membroDesde: { type: String, default: null },
    sessoes: { type: Array, default: () => [] },
});

const user = usePage().props.auth.user;
const conta = computed(() => usePage().props.conta);
const foto = computed(() => usePage().props.auth.foto);
const permissoes = computed(() => usePage().props.permissoes ?? []);

const abas = [
    { chave: 'perfil', texto: 'Perfil', icone: 'ki-profile-circle', paths: 3 },
    { chave: 'seguranca', texto: 'Segurança', icone: 'ki-shield-tick', paths: 2 },
    { chave: 'aparencia', texto: 'Aparência', icone: 'ki-night-day', paths: 10 },
    { chave: 'permissoes', texto: 'O que posso fazer', icone: 'ki-key', paths: 2 },
];
const abaActiva = ref(props.aba);
function escolher(chave) {
    abaActiva.value = chave;
    window.history.replaceState(window.history.state, '', `/definicoes?aba=${chave}`);
}

const iniciais = computed(() => (user.name ?? '').split(/\s+/).filter(Boolean).slice(0, 2).map((p) => p[0].toUpperCase()).join(''));
const formatarData = (d) => (d ? new Intl.DateTimeFormat('pt-PT', { day: 'numeric', month: 'long', year: 'numeric' }).format(new Date(`${d}T00:00:00`)) : '—');

// Foto de perfil
const MAX_FOTO = 5 * 1024 * 1024;
const entradaFoto = ref(null);
const novaFoto = ref(null);
const previa = ref(null);
const aEnviarFoto = ref(false);
function escolherFoto(evento) {
    const ficheiro = evento.target.files?.[0];
    evento.target.value = '';
    if (!ficheiro) return;
    if (!['image/png', 'image/jpeg', 'image/webp'].includes(ficheiro.type)) return toast.error('A foto deve ser PNG, JPG ou WEBP.');
    if (ficheiro.size > MAX_FOTO) return toast.error('A foto não pode exceder 5MB.');
    cancelarFoto();
    novaFoto.value = ficheiro;
    previa.value = URL.createObjectURL(ficheiro);
}
function cancelarFoto() {
    if (previa.value) URL.revokeObjectURL(previa.value);
    novaFoto.value = null;
    previa.value = null;
}
function guardarFoto() {
    aEnviarFoto.value = true;
    router.post('/definicoes/foto', { foto: novaFoto.value }, {
        forceFormData: true,
        preserveScroll: true,
        onSuccess: () => {
            cancelarFoto();
            toast.success('Foto atualizada.');
        },
        onError: (e) => toast.error(Object.values(e)[0]),
        onFinish: () => {
            aEnviarFoto.value = false;
        },
    });
}
function removerFoto() {
    router.delete('/definicoes/foto', { preserveScroll: true, onSuccess: () => toast.success('Foto removida.') });
}

// Segurança — alterar senha
const form = reactive({ current_password: '', password: '', password_confirmation: '' });
const erros = ref({});
const aGuardar = ref(false);
function guardarSenha() {
    aGuardar.value = true;
    erros.value = {};

    router.put('/alterar-senha', form, {
        preserveScroll: true,
        onSuccess: () => {
            Object.assign(form, { current_password: '', password: '', password_confirmation: '' });
            toast.success('Senha alterada com sucesso.');
        },
        onError: (e) => {
            erros.value = e;
            toast.error(Object.values(e)[0]);
        },
        onFinish: () => {
            aGuardar.value = false;
        },
    });
}

// Segurança — sessões
const outras = computed(() => props.sessoes.filter((s) => !s.actual));
const aTerminar = ref(false);
function terminarOutras() {
    aTerminar.value = true;
    router.post('/definicoes/sessoes/terminar-outras', {}, {
        preserveScroll: true,
        onSuccess: () => toast.success('As outras sessões foram terminadas.'),
        onFinish: () => {
            aTerminar.value = false;
        },
    });
}
const aTerminarUma = ref(null);
function terminarSessao(sessao) {
    aTerminarUma.value = sessao.referencia;
    router.delete(`/definicoes/sessoes/${sessao.referencia}`, {
        preserveScroll: true,
        onSuccess: () => toast.success('Sessão terminada.'),
        onFinish: () => {
            aTerminarUma.value = null;
        },
    });
}
const haQuanto = (iso) => {
    const segundos = Math.round((new Date(iso).getTime() - Date.now()) / 1000);
    const rtf = new Intl.RelativeTimeFormat('pt-PT', { numeric: 'auto' });
    if (Math.abs(segundos) < 60) return 'agora mesmo';
    if (Math.abs(segundos) < 3600) return rtf.format(Math.round(segundos / 60), 'minute');
    if (Math.abs(segundos) < 86400) return rtf.format(Math.round(segundos / 3600), 'hour');

    return rtf.format(Math.round(segundos / 86400), 'day');
};

// Aparência
const tema = ref(temaActual());
function escolherTema(modo) {
    tema.value = modo;
    definirTema(modo);
}

// Permissões agrupadas por menu do sistema, com pesquisa e menus recolhíveis (como nas Permissões dos utilizadores).
const pesquisa = ref('');
const rotuloAcao = (acao) => acao.charAt(0).toUpperCase() + acao.slice(1).replace(/-/g, ' ');
const todosOsGrupos = computed(() => {
    const porEntrada = {};
    for (const slug of permissoes.value) {
        const [modulo, ...acao] = slug.split('.');
        const info = MENU_DE[modulo] ?? { menu: 'Outros', entrada: modulo };
        const chave = `${info.menu}|${info.entrada}`;
        (porEntrada[chave] ??= { menu: info.menu, entrada: info.entrada, acoes: [] }).acoes.push(acao.join('.'));
    }

    return ORDEM_DOS_MENUS
        .map((menu) => ({ menu, entradas: Object.values(porEntrada).filter((e) => e.menu === menu).sort((a, b) => a.entrada.localeCompare(b.entrada)) }))
        .filter((g) => g.entradas.length);
});
const grupos = computed(() => {
    const termo = pesquisa.value.trim().toLowerCase();
    if (!termo) return todosOsGrupos.value;

    return todosOsGrupos.value
        .map((g) => ({
            ...g,
            entradas: g.menu.toLowerCase().includes(termo)
                ? g.entradas
                : g.entradas.filter((e) => e.entrada.toLowerCase().includes(termo) || e.acoes.some((a) => rotuloAcao(a).toLowerCase().includes(termo))),
        }))
        .filter((g) => g.entradas.length);
});
const recolhidos = reactive(new Set());
const estaRecolhido = (g) => pesquisa.value.trim() === '' && recolhidos.has(g.menu);
const alternarRecolhido = (g) => (recolhidos.has(g.menu) ? recolhidos.delete(g.menu) : recolhidos.add(g.menu));
const todosRecolhidos = computed(() => todosOsGrupos.value.length > 0 && todosOsGrupos.value.every((g) => recolhidos.has(g.menu)));
const alternarTodos = () => (todosRecolhidos.value ? recolhidos.clear() : todosOsGrupos.value.forEach((g) => recolhidos.add(g.menu)));
const totalPermissoes = computed(() => permissoes.value.length);
</script>

<template>
    <div class="app-container container-xxl py-6">
        <!-- Cabeçalho da conta -->
        <div class="card mb-6 overflow-hidden">
            <div class="h-100px" style="background: linear-gradient(135deg, #0F172A 0%, #166534 100%)"></div>
            <div class="card-body pt-0">
                <div class="d-flex flex-wrap align-items-end gap-5" style="margin-top: -40px">
                    <AvatarUtilizador :foto="foto" :iniciais="iniciais" tamanho="symbol-100px fs-1" circulo class="border border-4 border-body rounded-circle" />
                    <div class="pb-2">
                        <h1 class="fs-2 fw-bold mb-1">{{ user.name }}</h1>
                        <div class="d-flex flex-wrap align-items-center gap-4 text-muted fs-7">
                            <span>{{ user.email }}</span>
                            <PerfisDoUtilizador v-if="conta?.perfis?.length" :nome="user.name" :perfis="conta.perfis" />
                            <span v-if="conta?.escola" class="d-flex align-items-center"><i class="ki-duotone ki-bank fs-5 me-1"><span class="path1"></span><span class="path2"></span></i>{{ conta.escola }}</span>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <div class="row g-6">
            <!-- Navegação -->
            <div class="col-12 col-lg-3">
                <div class="card">
                    <div class="card-body p-3">
                        <button
                            v-for="a in abas"
                            :key="a.chave"
                            type="button"
                            class="btn btn-flex w-100 text-start align-items-center px-4 py-3 mb-1 border-0"
                            :class="abaActiva === a.chave ? 'bg-light-success text-success fw-bold' : 'btn-active-light text-gray-700'"
                            @click="escolher(a.chave)"
                        >
                            <i class="ki-duotone fs-3 me-3" :class="a.icone"><span v-for="n in a.paths" :key="n" :class="`path${n}`"></span></i>
                            {{ a.texto }}
                        </button>
                    </div>
                </div>
            </div>

            <div class="col-12 col-lg-9">
                <!-- Perfil -->
                <div v-if="abaActiva === 'perfil'" class="card mb-6">
                    <div class="card-header"><h3 class="card-title fw-bold fs-5">Foto de perfil</h3></div>
                    <div class="card-body pt-4 d-flex flex-wrap align-items-center gap-6">
                        <AvatarUtilizador :foto="previa ?? foto" :iniciais="iniciais" tamanho="symbol-100px fs-1" circulo />
                        <div>
                            <div class="text-muted fs-7 mb-4">PNG, JPG ou WEBP, até 5MB. Ao trocar a foto, a anterior é eliminada.</div>
                            <input ref="entradaFoto" type="file" class="d-none" accept="image/png,image/jpeg,image/webp" @change="escolherFoto" />
                            <div class="d-flex flex-wrap gap-3">
                                <template v-if="novaFoto">
                                    <button type="button" class="btn btn-primary btn-sm" :disabled="aEnviarFoto" @click="guardarFoto">
                                        <span v-if="!aEnviarFoto">Guardar foto</span>
                                        <span v-else>A enviar... <Loader size="0.3px" class="align-middle ms-2" /></span>
                                    </button>
                                    <button type="button" class="btn btn-light btn-sm" :disabled="aEnviarFoto" @click="cancelarFoto">Cancelar</button>
                                </template>
                                <template v-else>
                                    <button type="button" class="btn btn-light-success btn-sm" @click="entradaFoto.click()">{{ foto ? 'Trocar foto' : 'Escolher foto' }}</button>
                                    <button v-if="foto" type="button" class="btn btn-light-danger btn-sm" @click="removerFoto"><AcaoIcone acao="remover" herda-cor class="me-1" /> Remover foto</button>
                                </template>
                            </div>
                        </div>
                    </div>
                </div>

                <div v-if="abaActiva === 'perfil'" class="card">
                    <div class="card-header"><h3 class="card-title fw-bold fs-5">Os meus dados</h3></div>
                    <div class="card-body pt-2">
                        <div v-for="[rotulo, valor] in [['Nome', user.name], ['Email de acesso', user.email], ['Perfil', null], ['Escola', conta?.escola ?? '—'], ['Membro desde', formatarData(membroDesde)]]" :key="rotulo" class="d-flex justify-content-between py-4 border-bottom border-gray-200">
                            <span class="text-muted">{{ rotulo }}</span>
                            <PerfisDoUtilizador v-if="rotulo === 'Perfil'" :nome="user.name" :perfis="conta?.perfis ?? []" />
                            <span v-else class="fw-semibold text-end">{{ valor }}</span>
                        </div>
                        <div class="d-flex align-items-start bg-body-secondary rounded p-4 mt-6 text-gray-700 fs-7">
                            <i class="ki-duotone ki-information-5 fs-3 text-info me-3"><span class="path1"></span><span class="path2"></span><span class="path3"></span></i>
                            Para alterar o seu nome ou email de acesso, fale com o administrador da escola.
                        </div>
                    </div>
                </div>

                <!-- Segurança -->
                <template v-if="abaActiva === 'seguranca'">
                    <div class="card mb-6">
                        <div class="card-header"><h3 class="card-title fw-bold fs-5">Alterar senha</h3></div>
                        <div class="card-body pt-4">
                            <form class="mw-500px" novalidate @submit.prevent="guardarSenha">
                                <CampoSenha v-model="form.current_password" rotulo="Senha atual" autocomplete="current-password" :erro="erros.current_password" />
                                <CampoSenha v-model="form.password" rotulo="Nova senha" autocomplete="new-password" :erro="erros.password" />
                                <ForcaDaSenha :senha="form.password" :confirmacao="form.password_confirmation" />
                                <CampoSenha v-model="form.password_confirmation" rotulo="Confirmar nova senha" autocomplete="new-password" :erro="erros.password_confirmation" />
                                <button type="submit" class="btn btn-primary" :disabled="aGuardar">
                                    <span v-if="!aGuardar">Guardar nova senha</span>
                                    <span v-else>A guardar... <Loader size="0.3px" class="align-middle ms-2" /></span>
                                </button>
                                <div class="text-muted fs-8 mt-4">Ao alterar a senha, as suas outras sessões são terminadas.</div>
                            </form>
                        </div>
                    </div>

                    <div class="card">
                        <div class="card-header">
                            <h3 class="card-title fw-bold fs-5">Sessões ativas <span class="text-muted fs-7 fw-semibold ms-2">{{ sessoes.length }} dispositivo(s)</span></h3>
                            <div v-if="outras.length" class="card-toolbar">
                                <button type="button" class="btn btn-sm btn-light-danger" :disabled="aTerminar" @click="terminarOutras">Terminar as outras sessões</button>
                            </div>
                        </div>
                        <div class="card-body pt-2">
                            <div v-for="(s, i) in sessoes" :key="i" class="d-flex align-items-center justify-content-between py-4 border-bottom border-gray-200 gap-4">
                                <div class="d-flex align-items-center">
                                    <span class="symbol symbol-45px me-4"><span class="symbol-label" :class="s.actual ? 'bg-light-success' : 'bg-light'">
                                        <i class="ki-duotone fs-2" :class="[s.tipo === 'telemovel' ? 'ki-phone' : 'ki-monitor-mobile', s.actual ? 'text-success' : 'text-gray-600']"><span class="path1"></span><span class="path2"></span></i>
                                    </span></span>
                                    <div>
                                        <div class="fw-semibold">{{ s.navegador }} em {{ s.sistema }}
                                            <span v-if="s.actual" class="badge badge-light-success ms-2">Esta sessão</span>
                                        </div>
                                        <div class="text-muted fs-7">{{ s.ip ?? 'IP desconhecido' }} · {{ s.actual ? 'ativa agora' : `última atividade ${haQuanto(s.ultima_actividade)}` }}</div>
                                    </div>
                                </div>
                                <button v-if="!s.actual" type="button" class="btn btn-sm btn-light-danger flex-shrink-0" :disabled="aTerminarUma === s.referencia" @click="terminarSessao(s)">Terminar</button>
                            </div>
                            <div v-if="!sessoes.length" class="text-muted text-center py-8">Sem sessões registadas.</div>
                        </div>
                    </div>
                </template>

                <!-- Aparência -->
                <div v-if="abaActiva === 'aparencia'" class="card">
                    <div class="card-header"><h3 class="card-title fw-bold fs-5">Aparência</h3></div>
                    <div class="card-body pt-4">
                        <div class="text-muted mb-5">Escolha como a aplicação se apresenta neste dispositivo.</div>
                        <div class="row g-5">
                            <div v-for="t in [{ v: 'light', texto: 'Claro', fundo: '#F8FAFC', barra: '#E2E8F0', cartao: '#FFFFFF' }, { v: 'dark', texto: 'Escuro', fundo: '#0F172A', barra: '#1E293B', cartao: '#1E293B' }]" :key="t.v" class="col-12 col-sm-6">
                                <button type="button" class="w-100 p-0 bg-transparent text-start border-0" @click="escolherTema(t.v)">
                                    <span class="d-block rounded p-3 border border-2" :class="tema === t.v ? 'border-success' : 'border-gray-300'" :style="{ background: t.fundo }">
                                        <span class="d-block rounded mb-2" :style="{ background: t.barra, height: '10px', width: '40%' }"></span>
                                        <span class="d-flex gap-2"><span class="rounded flex-grow-1" :style="{ background: t.cartao, height: '46px' }"></span><span class="rounded flex-grow-1" :style="{ background: t.cartao, height: '46px' }"></span></span>
                                    </span>
                                    <span class="d-flex align-items-center mt-3 fw-semibold" :class="tema === t.v ? 'text-success' : 'text-gray-700'">
                                        <i v-if="tema === t.v" class="ki-duotone ki-check-circle fs-4 me-2"><span class="path1"></span><span class="path2"></span></i>{{ t.texto }}
                                    </span>
                                </button>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Permissões -->
                <div v-if="abaActiva === 'permissoes'">
                    <div class="d-flex flex-wrap align-items-center justify-content-between gap-3 mb-5">
                        <div class="fw-bold fs-5">O que posso fazer <span class="text-muted fs-7 fw-semibold ms-2">{{ totalPermissoes }} permissões</span></div>
                        <div class="d-flex flex-wrap gap-3">
                            <button v-if="todosOsGrupos.length" type="button" class="btn btn-light" @click="alternarTodos">{{ todosRecolhidos ? 'Expandir tudo' : 'Recolher tudo' }}</button>
                            <input v-model="pesquisa" type="search" class="form-control form-control-solid w-250px" placeholder="Pesquisar menu ou permissão…" />
                        </div>
                    </div>

                    <div v-if="!todosOsGrupos.length" class="card"><div class="card-body text-center text-muted py-10">Este perfil ainda não tem permissões atribuídas.</div></div>
                    <div v-else-if="!grupos.length" class="card"><div class="card-body text-center text-muted py-10">Nada corresponde à pesquisa.</div></div>

                    <div v-for="g in grupos" :key="g.menu" class="card mb-5">
                        <div class="card-header">
                            <h3 class="card-title fw-bold fs-6 text-uppercase text-success" style="letter-spacing: 0.06em">
                                {{ g.menu }}
                                <span class="text-muted fs-8 fw-semibold text-lowercase ms-3">{{ g.entradas.length }} {{ g.entradas.length === 1 ? 'área' : 'áreas' }}</span>
                            </h3>
                            <div class="card-toolbar">
                                <button type="button" class="btn btn-sm btn-light-primary" :aria-expanded="!estaRecolhido(g)" @click="alternarRecolhido(g)">
                                    {{ estaRecolhido(g) ? 'Mostrar' : 'Ocultar' }}
                                    <span class="seta" :class="{ 'seta--recolhida': estaRecolhido(g) }">&#9662;</span>
                                </button>
                            </div>
                        </div>
                        <div v-show="!estaRecolhido(g)" class="card-body pt-0">
                            <div v-for="e in g.entradas" :key="e.entrada" class="d-flex flex-wrap align-items-center justify-content-between gap-3 py-3 border-bottom border-gray-200">
                                <span class="fw-semibold">{{ e.entrada }}</span>
                                <span class="d-flex flex-wrap gap-2"><span v-for="a in e.acoes" :key="a" class="badge badge-light-primary">{{ rotuloAcao(a) }}</span></span>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</template>

<style scoped>
.seta { display: inline-block; margin-left: 0.35rem; transition: transform 0.15s; }
.seta--recolhida { transform: rotate(-90deg); }
</style>
