# Módulo AnoLectivo (Frontend) Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Construir as páginas Vue 3 + Inertia do módulo `Modules\AnoLectivo` (Ano Lectivo, Períodos, Calendário Escolar), consumindo as rotas já existentes e aprovadas do backend.

**Architecture:** `Pages/Index.vue` e `Pages/Show.vue` como orquestradores (donos do estado dos modais e das chamadas `router.*`); `Components/Periodos/PeriodoTable.vue` e `Components/Calendario/AnoLectivoCalendario.vue` como componentes de apresentação puros que só emitem eventos; três modais de formulário "burros" (recebem dados por prop, só emitem `submit`/`cancelar`); calendário via `@fullcalendar/vue3`.

**Tech Stack:** Vue 3, Inertia.js, `@fullcalendar/vue3` (novo), Bootstrap/Metronic (tema existente), `vue-sonner`.

**Spec:** [docs/superpowers/specs/2026-09-01-modulo-anolectivo-frontend-design.md](../specs/2026-09-01-modulo-anolectivo-frontend-design.md)

## Global Constraints

- **Este projecto não tem testes automatizados de frontend** (nenhum Vitest/Jest/Cypress instalado — confirmado por exploração). A verificação de cada task é: `npm run build` sem erros + verificação manual no browser (`npm run dev` + navegar até à página). Não inventar testes automatizados que não existem no stack.
- **Nunca fazer `git commit`** — só `git add` (stage). Regra explícita e já violada uma vez neste projecto; ver memória `feedback_never_commit_without_explicit_ask`.
- `useForm` do Inertia **nunca** é usado neste projecto — sempre `reactive`/`ref` manual + `router.post/put/patch/delete(...)` com `onSuccess`/`onError`/`onFinish`.
- Sem paginação de servidor em nenhuma listagem — enviar sempre o array completo.
- Toasts (`vue-sonner`, `import { toast } from 'vue-sonner'`) disparados manualmente em cada `onSuccess`/`onError` — nunca ler flash do backend (o backend não a partilha como prop Inertia).
- `PeriodoTable.vue` e `AnoLectivoCalendario.vue` são componentes de apresentação puros — só recebem props e emitem eventos (`editar-periodo`, `eliminar-periodo`, `criar-evento`, `editar-evento`). As páginas (`Index.vue`, `Show.vue`) são as únicas a abrir modais e a chamar `router.*`.
- Os três modais de formulário (`AnoLectivoFormModal`, `PeriodoFormModal`, `EventoFormModal`) também não chamam `router.*` — só emitem `submit` com o payload preenchido; quem faz o pedido HTTP é sempre a página-pai.
- Nenhuma regra de negócio nova no frontend (autorização, transições de estado válidas, validação de intervalo de datas continuam só no backend). Os enums em `Models/AnoLectivo.js` são só para apresentação (labels/cores).
- Sem sistema de permissões novo no frontend — nenhum botão escondido por permissão; o gate `can:gerir-ano-letivo` nas rotas já é suficiente.
- Eventos do Calendário Escolar podem sobrepor-se — nenhuma validação de conflito no frontend.
- Rotas exactas já existentes (`Modules/AnoLectivo/routes/web.php`): `GET/POST /ano-lectivos`, `GET/PUT/DELETE /ano-lectivos/{anoLectivo}`, `PATCH /ano-lectivos/{anoLectivo}/estado`, `POST /ano-lectivos/{anoLectivo}/periodos`, `PUT/DELETE /periodos/{periodo}`, `POST /ano-lectivos/{anoLectivo}/eventos-calendario`, `PUT/DELETE /eventos-calendario/{evento}`.
- Prop de `AnoLectivoController::show()` inclui as relações **`periodos`** e **`eventosCalendario`** (camelCase — nome exacto do método de relação Eloquent, não convertido para snake_case na serialização). Usar sempre `anoLectivo.eventosCalendario`, nunca `anoLectivo.eventos_calendario`.

---

## File Structure

```
Modules/AnoLectivo/resources/js/
├── Models/
│   └── AnoLectivo.js
├── Components/
│   ├── AnoLectivoStatusBadge.vue
│   ├── AnoLectivoFormModal.vue
│   ├── Periodos/
│   │   ├── PeriodoFormModal.vue
│   │   └── PeriodoTable.vue
│   └── Calendario/
│       ├── EventoFormModal.vue
│       └── AnoLectivoCalendario.vue
└── Pages/
    ├── Index.vue
    └── Show.vue

resources/js/Components/Shared/acoesLista.js      (modificado: + chave "encerrar")
resources/js/Components/Layout/SidebarMenuWrapper.vue  (modificado: + bloco Ano Lectivo)
resources/js/Components/Layout/menus/AppsMenu.vue      (modificado: + grupo Ano Lectivo)
package.json                                            (modificado: + @fullcalendar/*)
```

---

### Task 1: Instalar FullCalendar + `Models/AnoLectivo.js`

**Files:**
- Modify: `package.json` (via `npm install`)
- Create: `Modules/AnoLectivo/resources/js/Models/AnoLectivo.js`

**Interfaces:**
- Produces: `ESTADO_ANO_LECTIVO`, `TIPO_PERIODO`, `TIPO_PERIODO_OPCOES`, `TIPO_EVENTO_CALENDARIO`, `TIPO_EVENTO_CALENDARIO_OPCOES`, `estadoAnoLectivoLabel(estado)`, `estadoAnoLectivoBadgeClass(estado)`, `tipoPeriodoLabel(tipo)`, `tipoEventoCalendarioLabel(tipo)`, `tipoEventoCalendarioCor(tipo)` — usados por todas as tasks seguintes.

- [ ] **Step 1: Instalar as dependências do FullCalendar**

```bash
npm install @fullcalendar/vue3 @fullcalendar/core @fullcalendar/daygrid @fullcalendar/timegrid @fullcalendar/interaction
```

- [ ] **Step 2: Criar `Models/AnoLectivo.js`**

```js
/**
 * Espelha Modules/AnoLectivo/app/Enums/{EstadoAnoLectivo,TipoPeriodo,TipoEventoCalendario}.php
 * — só para apresentação (labels, cores de badge/calendário). Nenhuma regra
 * de negócio aqui; a autoridade continua inteiramente no backend.
 */

export const ESTADO_ANO_LECTIVO = Object.freeze({ PLANEADO: 0, ATIVO: 1, ENCERRADO: 2 });

export const estadoAnoLectivoLabel = (estado) => {
    switch (estado) {
        case ESTADO_ANO_LECTIVO.PLANEADO: return 'Planeado';
        case ESTADO_ANO_LECTIVO.ATIVO: return 'Activo';
        case ESTADO_ANO_LECTIVO.ENCERRADO: return 'Encerrado';
        default: return '—';
    }
};

export const estadoAnoLectivoBadgeClass = (estado) => {
    switch (estado) {
        case ESTADO_ANO_LECTIVO.PLANEADO: return 'badge-light-secondary';
        case ESTADO_ANO_LECTIVO.ATIVO: return 'badge-light-success';
        case ESTADO_ANO_LECTIVO.ENCERRADO: return 'badge-light-dark';
        default: return 'badge-light-secondary';
    }
};

export const TIPO_PERIODO = Object.freeze({ TRIMESTRE: 0, SEMESTRE: 1, OUTRO: 2 });

export const TIPO_PERIODO_OPCOES = [
    { value: TIPO_PERIODO.TRIMESTRE, label: 'Trimestre' },
    { value: TIPO_PERIODO.SEMESTRE, label: 'Semestre' },
    { value: TIPO_PERIODO.OUTRO, label: 'Outro' },
];

export const tipoPeriodoLabel = (tipo) =>
    TIPO_PERIODO_OPCOES.find((opcao) => opcao.value === tipo)?.label ?? '—';

export const TIPO_EVENTO_CALENDARIO = Object.freeze({
    AULA: 0,
    AVALIACAO: 1,
    REUNIAO: 2,
    FERIAS: 3,
    FERIADO: 4,
    ACTIVIDADE: 5,
    EVENTO: 6,
    OUTRO: 7,
});

export const TIPO_EVENTO_CALENDARIO_OPCOES = [
    { value: TIPO_EVENTO_CALENDARIO.AULA, label: 'Aula' },
    { value: TIPO_EVENTO_CALENDARIO.AVALIACAO, label: 'Avaliação' },
    { value: TIPO_EVENTO_CALENDARIO.REUNIAO, label: 'Reunião' },
    { value: TIPO_EVENTO_CALENDARIO.FERIAS, label: 'Férias' },
    { value: TIPO_EVENTO_CALENDARIO.FERIADO, label: 'Feriado' },
    { value: TIPO_EVENTO_CALENDARIO.ACTIVIDADE, label: 'Actividade' },
    { value: TIPO_EVENTO_CALENDARIO.EVENTO, label: 'Evento' },
    { value: TIPO_EVENTO_CALENDARIO.OUTRO, label: 'Outro' },
];

export const tipoEventoCalendarioLabel = (tipo) =>
    TIPO_EVENTO_CALENDARIO_OPCOES.find((opcao) => opcao.value === tipo)?.label ?? '—';

// Cor por tipo de evento, usada pelo AnoLectivoCalendario (FullCalendar).
// Usa as variáveis CSS Bootstrap já definidas no tema, para acompanhar
// automaticamente a paleta actual em vez de valores hexadecimais fixos.
export const tipoEventoCalendarioCor = (tipo) => {
    switch (tipo) {
        case TIPO_EVENTO_CALENDARIO.AULA: return 'var(--bs-primary)';
        case TIPO_EVENTO_CALENDARIO.AVALIACAO: return 'var(--bs-danger)';
        case TIPO_EVENTO_CALENDARIO.REUNIAO: return 'var(--bs-info)';
        case TIPO_EVENTO_CALENDARIO.FERIAS: return 'var(--bs-warning)';
        case TIPO_EVENTO_CALENDARIO.FERIADO: return 'var(--bs-dark)';
        case TIPO_EVENTO_CALENDARIO.ACTIVIDADE: return 'var(--bs-success)';
        case TIPO_EVENTO_CALENDARIO.EVENTO: return 'var(--bs-secondary)';
        case TIPO_EVENTO_CALENDARIO.OUTRO: return 'var(--bs-gray-500)';
        default: return 'var(--bs-secondary)';
    }
};
```

- [ ] **Step 3: Verificar que o build continua limpo**

Run: `npm run build`
Expected: build termina sem erros (o ficheiro `Models/AnoLectivo.js` ainda não é importado por nada, é só verificação de que a instalação do FullCalendar não quebrou nada).

- [ ] **Step 4: Stage (nunca commit)**

```bash
git add package.json package-lock.json Modules/AnoLectivo/resources/js/Models/AnoLectivo.js
```

---

### Task 2: `Components/AnoLectivoStatusBadge.vue`

**Files:**
- Create: `Modules/AnoLectivo/resources/js/Components/AnoLectivoStatusBadge.vue`

**Interfaces:**
- Consumes: `estadoAnoLectivoBadgeClass` de `Models/AnoLectivo.js` (Task 1).
- Produces: componente `AnoLectivoStatusBadge` com props `estado` (Number) e `estadoDescricao` (String) — usado pelas Tasks 4 e 9.

- [ ] **Step 1: Criar o componente**

```vue
<script setup>
import { estadoAnoLectivoBadgeClass } from '../Models/AnoLectivo';

defineProps({
    estado: { type: Number, required: true },
    estadoDescricao: { type: String, required: true },
});
</script>

<template>
    <div class="badge fw-bold" :class="estadoAnoLectivoBadgeClass(estado)">
        {{ estadoDescricao }}
    </div>
</template>
```

- [ ] **Step 2: Verificar build**

Run: `npm run build`
Expected: sem erros.

- [ ] **Step 3: Stage**

```bash
git add Modules/AnoLectivo/resources/js/Components/AnoLectivoStatusBadge.vue
```

---

### Task 3: `Components/AnoLectivoFormModal.vue`

**Files:**
- Create: `Modules/AnoLectivo/resources/js/Components/AnoLectivoFormModal.vue`

**Interfaces:**
- Consumes: `ESTADO_ANO_LECTIVO` de `Models/AnoLectivo.js` (Task 1); `Modules/AnoLectivo/resources/js/Components/../../../../../resources/js/Components/Shared/SelectSolid.vue` (via alias `@/Components/Shared/SelectSolid.vue`, já existente).
- Produces: componente `AnoLectivoFormModal` com props `show` (Boolean), `anoLectivo` (Object|null, default `null` — `null` = modo criação), `processing` (Boolean), `errors` (Object); emite `submit(payload)` com `{ nome, data_inicio, data_fim, estado }` e `cancelar`. Usado pelas Tasks 4 e 9. **Não chama `router.*`** — quem faz o pedido é sempre a página-pai.

- [ ] **Step 1: Criar o componente**

```vue
<script setup>
import { reactive, watch } from 'vue';
import SelectSolid from '@/Components/Shared/SelectSolid.vue';
import { ESTADO_ANO_LECTIVO } from '../Models/AnoLectivo';

const props = defineProps({
    show: { type: Boolean, default: false },
    anoLectivo: { type: Object, default: null },
    processing: { type: Boolean, default: false },
    errors: { type: Object, default: () => ({}) },
});
const emit = defineEmits(['submit', 'cancelar']);

const ESTADO_OPCOES = [
    { value: ESTADO_ANO_LECTIVO.PLANEADO, label: 'Planeado' },
    { value: ESTADO_ANO_LECTIVO.ATIVO, label: 'Activo' },
    { value: ESTADO_ANO_LECTIVO.ENCERRADO, label: 'Encerrado' },
];

const form = reactive({
    nome: '',
    data_inicio: '',
    data_fim: '',
    estado: ESTADO_ANO_LECTIVO.PLANEADO,
});

watch(() => props.show, (show) => {
    if (!show) return;
    form.nome = props.anoLectivo?.nome ?? '';
    form.data_inicio = props.anoLectivo?.data_inicio ?? '';
    form.data_fim = props.anoLectivo?.data_fim ?? '';
    form.estado = props.anoLectivo?.estado ?? ESTADO_ANO_LECTIVO.PLANEADO;
});

function submeter() {
    emit('submit', { ...form });
}
</script>

<template>
    <div v-if="show" class="modal d-block" style="background: rgba(0,0,0,0.5);" @click.self="emit('cancelar')">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content p-6">
                <h3 class="mb-5">{{ anoLectivo ? 'Editar Ano Lectivo' : 'Novo Ano Lectivo' }}</h3>
                <form @submit.prevent="submeter">
                    <div class="fv-row mb-7">
                        <label class="required fw-semibold fs-6 mb-2">Nome</label>
                        <input v-model="form.nome" type="text" class="form-control form-control-solid" placeholder="ex: 2026/2027" />
                        <div class="text-danger fs-7 mt-1" v-if="errors.nome">{{ errors.nome[0] }}</div>
                    </div>

                    <div class="row">
                        <div class="col-md-6 fv-row mb-7">
                            <label class="required fw-semibold fs-6 mb-2">Data de início</label>
                            <input v-model="form.data_inicio" type="date" class="form-control form-control-solid" />
                            <div class="text-danger fs-7 mt-1" v-if="errors.data_inicio">{{ errors.data_inicio[0] }}</div>
                        </div>
                        <div class="col-md-6 fv-row mb-7">
                            <label class="required fw-semibold fs-6 mb-2">Data de fim</label>
                            <input v-model="form.data_fim" type="date" class="form-control form-control-solid" />
                            <div class="text-danger fs-7 mt-1" v-if="errors.data_fim">{{ errors.data_fim[0] }}</div>
                        </div>
                    </div>

                    <div class="fv-row mb-7">
                        <label class="required fw-semibold fs-6 mb-2">Estado</label>
                        <SelectSolid v-model="form.estado" :options="ESTADO_OPCOES" />
                        <div class="text-danger fs-7 mt-1" v-if="errors.estado">{{ errors.estado[0] }}</div>
                    </div>

                    <div class="text-end">
                        <button type="button" class="btn btn-light-danger me-2" :disabled="processing" @click="emit('cancelar')">
                            Cancelar
                        </button>
                        <button type="submit" class="btn btn-primary" :disabled="processing">Guardar</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
</template>
```

- [ ] **Step 2: Verificar build**

Run: `npm run build`
Expected: sem erros.

- [ ] **Step 3: Stage**

```bash
git add Modules/AnoLectivo/resources/js/Components/AnoLectivoFormModal.vue
```

---

### Task 4: `Pages/Index.vue`

**Files:**
- Modify: `resources/js/Components/Shared/acoesLista.js`
- Create: `Modules/AnoLectivo/resources/js/Pages/Index.vue`

**Interfaces:**
- Consumes: `AnoLectivoStatusBadge` (Task 2), `AnoLectivoFormModal` (Task 3), `ESTADO_ANO_LECTIVO` (Task 1), `ConfirmModal`/`AcaoIcone` (já existentes em `resources/js/Components/Shared/`).
- Produces: página Inertia `AnoLectivo/Index` — recebe prop `anoLectivos` (Array).

- [ ] **Step 1: Adicionar a chave `encerrar` a `acoesLista.js`**

Ler primeiro `resources/js/Components/Shared/acoesLista.js` (já existe, ver conteúdo actual). Adicionar UMA chave nova ao objecto `ACOES_LISTA`, sem alterar nenhuma das existentes:

```js
    encerrar: { icone: 'ki-lock-2', paths: 5, cor: 'dark', texto: 'Encerrar' },
```

(`ki-lock-2` com 5 `path`s confirmado em `_theme/assets/plugins/global/plugins.bundle.css` — `.ki-lock-2 .path1` até `.path5`.)

- [ ] **Step 2: Criar `Pages/Index.vue`**

```vue
<script setup>
import { ref } from 'vue';
import { router } from '@inertiajs/vue3';
import { toast } from 'vue-sonner';
import AppLayout from '@/Layouts/AppLayout.vue';
import AcaoIcone from '@/Components/Shared/AcaoIcone.vue';
import ConfirmModal from '@/Components/Shared/ConfirmModal.vue';
import AnoLectivoStatusBadge from '../Components/AnoLectivoStatusBadge.vue';
import AnoLectivoFormModal from '../Components/AnoLectivoFormModal.vue';
import { ESTADO_ANO_LECTIVO } from '../Models/AnoLectivo';

defineProps({
    anoLectivos: { type: Array, required: true },
});
defineOptions({ layout: AppLayout });

// --- Criar Ano Lectivo ---
const modalAberto = ref(false);
const processing = ref(false);
const errors = ref({});

function abrirCriacao() {
    errors.value = {};
    modalAberto.value = true;
}

function fecharModal() {
    modalAberto.value = false;
}

function criar(payload) {
    processing.value = true;
    errors.value = {};
    router.post('/ano-lectivos', payload, {
        preserveScroll: true,
        onSuccess: () => {
            toast.success('Ano Lectivo criado com sucesso.');
            fecharModal();
        },
        onError: (erros) => {
            errors.value = erros;
            toast.error(Object.values(erros)[0] ?? 'Não foi possível criar o Ano Lectivo.');
        },
        onFinish: () => {
            processing.value = false;
        },
    });
}

// --- Alterar estado (Activar / Encerrar) ---
const anoLectivoParaAlterarEstado = ref(null);
const novoEstado = ref(null);
const alterandoEstado = ref(false);

function pedirActivacao(anoLectivo) {
    anoLectivoParaAlterarEstado.value = anoLectivo;
    novoEstado.value = ESTADO_ANO_LECTIVO.ATIVO;
}

function pedirEncerramento(anoLectivo) {
    anoLectivoParaAlterarEstado.value = anoLectivo;
    novoEstado.value = ESTADO_ANO_LECTIVO.ENCERRADO;
}

function cancelarAlteracaoEstado() {
    anoLectivoParaAlterarEstado.value = null;
    novoEstado.value = null;
}

function confirmarAlteracaoEstado() {
    alterandoEstado.value = true;
    router.patch(`/ano-lectivos/${anoLectivoParaAlterarEstado.value.id}/estado`, { estado: novoEstado.value }, {
        preserveScroll: true,
        onSuccess: () => toast.success(
            novoEstado.value === ESTADO_ANO_LECTIVO.ATIVO ? 'Ano Lectivo activado.' : 'Ano Lectivo encerrado.',
        ),
        onError: (erros) => toast.error(Object.values(erros)[0] ?? 'Não foi possível alterar o estado.'),
        onFinish: () => {
            alterandoEstado.value = false;
            anoLectivoParaAlterarEstado.value = null;
            novoEstado.value = null;
        },
    });
}

// --- Eliminar ---
const anoLectivoParaEliminar = ref(null);
const eliminando = ref(false);

function pedirEliminacao(anoLectivo) {
    anoLectivoParaEliminar.value = anoLectivo;
}

function cancelarEliminacao() {
    anoLectivoParaEliminar.value = null;
}

function confirmarEliminacao() {
    eliminando.value = true;
    router.delete(`/ano-lectivos/${anoLectivoParaEliminar.value.id}`, {
        preserveScroll: true,
        onSuccess: () => toast.success('Ano Lectivo eliminado com sucesso.'),
        onError: (erros) => toast.error(Object.values(erros)[0] ?? 'Não foi possível eliminar o Ano Lectivo.'),
        onFinish: () => {
            eliminando.value = false;
            anoLectivoParaEliminar.value = null;
        },
    });
}
</script>

<template>
    <div class="app-container container-xxl py-6">
        <div class="d-flex justify-content-between align-items-center mb-6">
            <h1 class="fs-2 fw-bold">Anos Lectivos</h1>
            <button class="btn btn-primary" @click="abrirCriacao">Novo Ano Lectivo</button>
        </div>

        <div class="card">
            <div class="card-body p-0">
                <table class="table align-middle table-row-dashed table-hover fs-6 gy-5 mb-0">
                    <thead>
                        <tr class="text-start text-muted fw-bold fs-7 text-uppercase gs-0">
                            <th class="min-w-200px">Nome</th>
                            <th class="min-w-200px">Período</th>
                            <th class="min-w-100px">Estado</th>
                            <th class="text-end min-w-125px">Ações</th>
                        </tr>
                    </thead>
                    <tbody class="text-gray-600 fw-semibold">
                        <tr v-if="anoLectivos.length === 0">
                            <td colspan="4" class="text-center text-muted py-6">Nenhum Ano Lectivo criado.</td>
                        </tr>
                        <tr v-for="anoLectivo in anoLectivos" :key="anoLectivo.id">
                            <td>{{ anoLectivo.nome }}</td>
                            <td>{{ anoLectivo.data_inicio }} — {{ anoLectivo.data_fim }}</td>
                            <td>
                                <AnoLectivoStatusBadge :estado="anoLectivo.estado" :estado-descricao="anoLectivo.estado_descricao" />
                            </td>
                            <td class="text-end">
                                <a href="#" class="btn btn-light btn-active-light-primary btn-flex btn-center btn-sm" data-kt-menu-trigger="click" data-kt-menu-placement="bottom-end">
                                    Ações
                                    <i class="ki-duotone ki-down fs-5 ms-1"></i>
                                </a>
                                <div class="menu menu-sub menu-sub-dropdown menu-column menu-rounded menu-gray-600 menu-state-bg-light-primary fw-semibold fs-7 w-175px py-4" data-kt-menu="true">
                                    <div class="menu-item px-3">
                                        <a :href="`/ano-lectivos/${anoLectivo.id}`" class="menu-link px-3">
                                            <AcaoIcone acao="visualizar" class="me-2" />
                                            Ver
                                        </a>
                                    </div>
                                    <div v-if="anoLectivo.estado === 0" class="menu-item px-3">
                                        <a href="#" class="menu-link px-3" @click.prevent="pedirActivacao(anoLectivo)">
                                            <AcaoIcone acao="ativar" class="me-2" />
                                            Activar
                                        </a>
                                    </div>
                                    <div v-if="anoLectivo.estado === 1" class="menu-item px-3">
                                        <a href="#" class="menu-link px-3" @click.prevent="pedirEncerramento(anoLectivo)">
                                            <AcaoIcone acao="encerrar" class="me-2" />
                                            Encerrar
                                        </a>
                                    </div>
                                    <div class="menu-item px-3">
                                        <a href="#" class="menu-link px-3 text-danger" @click.prevent="pedirEliminacao(anoLectivo)">
                                            <AcaoIcone acao="eliminar" class="me-2" />
                                            Eliminar
                                        </a>
                                    </div>
                                </div>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>

        <AnoLectivoFormModal
            :show="modalAberto"
            :processing="processing"
            :errors="errors"
            @submit="criar"
            @cancelar="fecharModal"
        />

        <ConfirmModal
            :show="!!anoLectivoParaAlterarEstado"
            titulo="Alterar estado"
            :mensagem="novoEstado === 1
                ? `Activar o Ano Lectivo ${anoLectivoParaAlterarEstado?.nome}?`
                : `Encerrar o Ano Lectivo ${anoLectivoParaAlterarEstado?.nome}?`"
            texto-confirmar="Confirmar"
            :processando="alterandoEstado"
            @confirmar="confirmarAlteracaoEstado"
            @cancelar="cancelarAlteracaoEstado"
        />

        <ConfirmModal
            :show="!!anoLectivoParaEliminar"
            titulo="Eliminar Ano Lectivo"
            :mensagem="`Tem certeza que deseja eliminar o Ano Lectivo ${anoLectivoParaEliminar?.nome}?`"
            :processando="eliminando"
            @confirmar="confirmarEliminacao"
            @cancelar="cancelarEliminacao"
        />
    </div>
</template>
```

- [ ] **Step 3: Verificar build**

Run: `npm run build`
Expected: sem erros.

- [ ] **Step 4: Stage**

```bash
git add resources/js/Components/Shared/acoesLista.js Modules/AnoLectivo/resources/js/Pages/Index.vue
```

---

### Task 5: `Components/Periodos/PeriodoFormModal.vue`

**Files:**
- Create: `Modules/AnoLectivo/resources/js/Components/Periodos/PeriodoFormModal.vue`

**Interfaces:**
- Consumes: `TIPO_PERIODO`, `TIPO_PERIODO_OPCOES` de `Models/AnoLectivo.js` (Task 1, caminho relativo `../../Models/AnoLectivo` a partir de `Components/Periodos/`).
- Produces: componente `PeriodoFormModal` com props `show` (Boolean), `periodo` (Object|null), `processing` (Boolean), `errors` (Object); emite `submit(payload)` com `{ nome, tipo, numero, data_inicio, data_fim }` e `cancelar`. Usado pela Task 9. **Não chama `router.*`**.

- [ ] **Step 1: Criar o componente**

```vue
<script setup>
import { reactive, watch } from 'vue';
import SelectSolid from '@/Components/Shared/SelectSolid.vue';
import { TIPO_PERIODO, TIPO_PERIODO_OPCOES } from '../../Models/AnoLectivo';

const props = defineProps({
    show: { type: Boolean, default: false },
    periodo: { type: Object, default: null },
    processing: { type: Boolean, default: false },
    errors: { type: Object, default: () => ({}) },
});
const emit = defineEmits(['submit', 'cancelar']);

const form = reactive({
    nome: '',
    tipo: TIPO_PERIODO.TRIMESTRE,
    numero: null,
    data_inicio: '',
    data_fim: '',
});

watch(() => props.show, (show) => {
    if (!show) return;
    form.nome = props.periodo?.nome ?? '';
    form.tipo = props.periodo?.tipo ?? TIPO_PERIODO.TRIMESTRE;
    form.numero = props.periodo?.numero ?? null;
    form.data_inicio = props.periodo?.data_inicio ?? '';
    form.data_fim = props.periodo?.data_fim ?? '';
});

function submeter() {
    emit('submit', { ...form });
}
</script>

<template>
    <div v-if="show" class="modal d-block" style="background: rgba(0,0,0,0.5);" @click.self="emit('cancelar')">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content p-6">
                <h3 class="mb-5">{{ periodo ? 'Editar Período' : 'Novo Período' }}</h3>
                <form @submit.prevent="submeter">
                    <div class="fv-row mb-7">
                        <label class="required fw-semibold fs-6 mb-2">Nome</label>
                        <input v-model="form.nome" type="text" class="form-control form-control-solid" placeholder="ex: 1.º Trimestre" />
                        <div class="text-danger fs-7 mt-1" v-if="errors.nome">{{ errors.nome[0] }}</div>
                    </div>

                    <div class="row">
                        <div class="col-md-8 fv-row mb-7">
                            <label class="required fw-semibold fs-6 mb-2">Tipo</label>
                            <SelectSolid v-model="form.tipo" :options="TIPO_PERIODO_OPCOES" />
                            <div class="text-danger fs-7 mt-1" v-if="errors.tipo">{{ errors.tipo[0] }}</div>
                        </div>
                        <div class="col-md-4 fv-row mb-7">
                            <label class="fw-semibold fs-6 mb-2">Número</label>
                            <input v-model.number="form.numero" type="number" min="1" class="form-control form-control-solid" />
                            <div class="text-danger fs-7 mt-1" v-if="errors.numero">{{ errors.numero[0] }}</div>
                        </div>
                    </div>

                    <div class="row">
                        <div class="col-md-6 fv-row mb-7">
                            <label class="required fw-semibold fs-6 mb-2">Data de início</label>
                            <input v-model="form.data_inicio" type="date" class="form-control form-control-solid" />
                            <div class="text-danger fs-7 mt-1" v-if="errors.data_inicio">{{ errors.data_inicio[0] }}</div>
                        </div>
                        <div class="col-md-6 fv-row mb-7">
                            <label class="required fw-semibold fs-6 mb-2">Data de fim</label>
                            <input v-model="form.data_fim" type="date" class="form-control form-control-solid" />
                            <div class="text-danger fs-7 mt-1" v-if="errors.data_fim">{{ errors.data_fim[0] }}</div>
                        </div>
                    </div>

                    <div class="text-end">
                        <button type="button" class="btn btn-light-danger me-2" :disabled="processing" @click="emit('cancelar')">
                            Cancelar
                        </button>
                        <button type="submit" class="btn btn-primary" :disabled="processing">Guardar</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
</template>
```

- [ ] **Step 2: Verificar build**

Run: `npm run build`
Expected: sem erros.

- [ ] **Step 3: Stage**

```bash
git add Modules/AnoLectivo/resources/js/Components/Periodos/PeriodoFormModal.vue
```

---

### Task 6: `Components/Periodos/PeriodoTable.vue`

**Files:**
- Create: `Modules/AnoLectivo/resources/js/Components/Periodos/PeriodoTable.vue`

**Interfaces:**
- Consumes: `tipoPeriodoLabel` de `Models/AnoLectivo.js` (Task 1).
- Produces: componente `PeriodoTable` com prop `periodos` (Array); emite `editar-periodo(periodo)` e `eliminar-periodo(periodo)`. Usado pela Task 9. **Componente de apresentação puro** — não chama `router.*` nem abre modais.

- [ ] **Step 1: Criar o componente**

```vue
<script setup>
import { tipoPeriodoLabel } from '../../Models/AnoLectivo';

defineProps({
    periodos: { type: Array, required: true },
});
defineEmits(['editar-periodo', 'eliminar-periodo']);
</script>

<template>
    <table class="table align-middle table-row-dashed table-hover fs-6 gy-5 mb-0">
        <thead>
            <tr class="text-start text-muted fw-bold fs-7 text-uppercase gs-0">
                <th class="min-w-150px">Nome</th>
                <th class="min-w-100px">Tipo</th>
                <th class="min-w-75px">Número</th>
                <th class="min-w-200px">Período</th>
                <th class="text-end min-w-125px">Ações</th>
            </tr>
        </thead>
        <tbody class="text-gray-600 fw-semibold">
            <tr v-if="periodos.length === 0">
                <td colspan="5" class="text-center text-muted py-6">Nenhum período criado.</td>
            </tr>
            <tr v-for="periodo in periodos" :key="periodo.id">
                <td>{{ periodo.nome }}</td>
                <td>{{ tipoPeriodoLabel(periodo.tipo) }}</td>
                <td>{{ periodo.numero ?? '—' }}</td>
                <td>{{ periodo.data_inicio }} — {{ periodo.data_fim }}</td>
                <td class="text-end">
                    <button class="btn btn-light-primary btn-sm me-2" @click="$emit('editar-periodo', periodo)">
                        Editar
                    </button>
                    <button class="btn btn-light-danger btn-sm" @click="$emit('eliminar-periodo', periodo)">
                        Eliminar
                    </button>
                </td>
            </tr>
        </tbody>
    </table>
</template>
```

- [ ] **Step 2: Verificar build**

Run: `npm run build`
Expected: sem erros.

- [ ] **Step 3: Stage**

```bash
git add Modules/AnoLectivo/resources/js/Components/Periodos/PeriodoTable.vue
```

---

### Task 7: `Components/Calendario/EventoFormModal.vue`

**Files:**
- Create: `Modules/AnoLectivo/resources/js/Components/Calendario/EventoFormModal.vue`

**Interfaces:**
- Consumes: `TIPO_EVENTO_CALENDARIO`, `TIPO_EVENTO_CALENDARIO_OPCOES` de `Models/AnoLectivo.js` (Task 1, caminho relativo `../../Models/AnoLectivo` a partir de `Components/Calendario/`).
- Produces: componente `EventoFormModal` com props `show` (Boolean), `evento` (Object|null), `dataInicial` (String|null — data pré-preenchida quando aberto a partir de um clique no calendário), `processing` (Boolean), `errors` (Object); emite `submit(payload)` com `{ titulo, descricao, tipo, data_inicio, data_fim, dia_inteiro }` e `cancelar`. Usado pela Task 9. **Não chama `router.*`**.

- [ ] **Step 1: Criar o componente**

```vue
<script setup>
import { reactive, watch } from 'vue';
import SelectSolid from '@/Components/Shared/SelectSolid.vue';
import { TIPO_EVENTO_CALENDARIO, TIPO_EVENTO_CALENDARIO_OPCOES } from '../../Models/AnoLectivo';

const props = defineProps({
    show: { type: Boolean, default: false },
    evento: { type: Object, default: null },
    dataInicial: { type: String, default: null },
    processing: { type: Boolean, default: false },
    errors: { type: Object, default: () => ({}) },
});
const emit = defineEmits(['submit', 'cancelar']);

const form = reactive({
    titulo: '',
    descricao: '',
    tipo: TIPO_EVENTO_CALENDARIO.EVENTO,
    data_inicio: '',
    data_fim: '',
    dia_inteiro: true,
});

watch(() => props.show, (show) => {
    if (!show) return;
    form.titulo = props.evento?.titulo ?? '';
    form.descricao = props.evento?.descricao ?? '';
    form.tipo = props.evento?.tipo ?? TIPO_EVENTO_CALENDARIO.EVENTO;
    form.data_inicio = props.evento?.data_inicio ?? props.dataInicial ?? '';
    form.data_fim = props.evento?.data_fim ?? props.dataInicial ?? '';
    form.dia_inteiro = props.evento?.dia_inteiro ?? true;
});

function submeter() {
    emit('submit', { ...form });
}
</script>

<template>
    <div v-if="show" class="modal d-block" style="background: rgba(0,0,0,0.5);" @click.self="emit('cancelar')">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content p-6">
                <h3 class="mb-5">{{ evento ? 'Editar Evento' : 'Novo Evento' }}</h3>
                <form @submit.prevent="submeter">
                    <div class="fv-row mb-7">
                        <label class="required fw-semibold fs-6 mb-2">Título</label>
                        <input v-model="form.titulo" type="text" class="form-control form-control-solid" placeholder="ex: Início das aulas" />
                        <div class="text-danger fs-7 mt-1" v-if="errors.titulo">{{ errors.titulo[0] }}</div>
                    </div>

                    <div class="fv-row mb-7">
                        <label class="fw-semibold fs-6 mb-2">Descrição</label>
                        <textarea v-model="form.descricao" class="form-control form-control-solid" rows="3"></textarea>
                        <div class="text-danger fs-7 mt-1" v-if="errors.descricao">{{ errors.descricao[0] }}</div>
                    </div>

                    <div class="fv-row mb-7">
                        <label class="required fw-semibold fs-6 mb-2">Tipo</label>
                        <SelectSolid v-model="form.tipo" :options="TIPO_EVENTO_CALENDARIO_OPCOES" />
                        <div class="text-danger fs-7 mt-1" v-if="errors.tipo">{{ errors.tipo[0] }}</div>
                    </div>

                    <div class="row">
                        <div class="col-md-6 fv-row mb-7">
                            <label class="required fw-semibold fs-6 mb-2">Data de início</label>
                            <input v-model="form.data_inicio" type="date" class="form-control form-control-solid" />
                            <div class="text-danger fs-7 mt-1" v-if="errors.data_inicio">{{ errors.data_inicio[0] }}</div>
                        </div>
                        <div class="col-md-6 fv-row mb-7">
                            <label class="required fw-semibold fs-6 mb-2">Data de fim</label>
                            <input v-model="form.data_fim" type="date" class="form-control form-control-solid" />
                            <div class="text-danger fs-7 mt-1" v-if="errors.data_fim">{{ errors.data_fim[0] }}</div>
                        </div>
                    </div>

                    <div class="fv-row mb-7">
                        <div class="form-check form-check-custom form-check-solid">
                            <input v-model="form.dia_inteiro" class="form-check-input" type="checkbox" id="evento-dia-inteiro" />
                            <label class="form-check-label fw-semibold fs-6" for="evento-dia-inteiro">Dia inteiro</label>
                        </div>
                        <div class="text-danger fs-7 mt-1" v-if="errors.dia_inteiro">{{ errors.dia_inteiro[0] }}</div>
                    </div>

                    <div class="text-end">
                        <button type="button" class="btn btn-light-danger me-2" :disabled="processing" @click="emit('cancelar')">
                            Cancelar
                        </button>
                        <button type="submit" class="btn btn-primary" :disabled="processing">Guardar</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
</template>
```

- [ ] **Step 2: Verificar build**

Run: `npm run build`
Expected: sem erros.

- [ ] **Step 3: Stage**

```bash
git add Modules/AnoLectivo/resources/js/Components/Calendario/EventoFormModal.vue
```

---

### Task 8: `Components/Calendario/AnoLectivoCalendario.vue`

**Files:**
- Create: `Modules/AnoLectivo/resources/js/Components/Calendario/AnoLectivoCalendario.vue`

**Interfaces:**
- Consumes: `@fullcalendar/vue3`, `@fullcalendar/daygrid`, `@fullcalendar/timegrid`, `@fullcalendar/interaction` (Task 1, `npm install`); `tipoEventoCalendarioCor` de `Models/AnoLectivo.js` (Task 1).
- Produces: componente `AnoLectivoCalendario` com props `anoLectivo` (Object, precisa de `data_inicio`/`data_fim`), `eventos` (Array de `EventoCalendario`); emite `criar-evento(dataISO)` e `editar-evento(evento)`. Usado pela Task 9. **Componente de apresentação puro** — não chama `router.*` nem abre modais.

- [ ] **Step 1: Criar o componente**

```vue
<script setup>
import { computed } from 'vue';
import FullCalendar from '@fullcalendar/vue3';
import dayGridPlugin from '@fullcalendar/daygrid';
import timeGridPlugin from '@fullcalendar/timegrid';
import interactionPlugin from '@fullcalendar/interaction';
import { tipoEventoCalendarioCor } from '../../Models/AnoLectivo';

const props = defineProps({
    anoLectivo: { type: Object, required: true },
    eventos: { type: Array, required: true },
});
const emit = defineEmits(['criar-evento', 'editar-evento']);

// O `end` do FullCalendar (validRange e eventos multi-dia) é EXCLUSIVO —
// sem +1 dia, o próprio último dia do intervalo fica fora dele/invisível.
function proximoDia(dataISO) {
    const data = new Date(`${dataISO}T00:00:00`);
    data.setDate(data.getDate() + 1);
    return data.toISOString().slice(0, 10);
}

const calendarOptions = computed(() => ({
    plugins: [dayGridPlugin, timeGridPlugin, interactionPlugin],
    initialView: 'dayGridMonth',
    headerToolbar: {
        left: 'prev,next today',
        center: 'title',
        right: 'dayGridMonth,timeGridWeek',
    },
    initialDate: props.anoLectivo.data_inicio,
    validRange: {
        start: props.anoLectivo.data_inicio,
        end: proximoDia(props.anoLectivo.data_fim),
    },
    selectable: true,
    editable: false,
    events: props.eventos.map((evento) => ({
        id: String(evento.id),
        title: evento.titulo,
        start: evento.data_inicio,
        end: proximoDia(evento.data_fim),
        allDay: evento.dia_inteiro,
        backgroundColor: tipoEventoCalendarioCor(evento.tipo),
        borderColor: tipoEventoCalendarioCor(evento.tipo),
        extendedProps: { original: evento },
    })),
    dateClick: (info) => emit('criar-evento', info.dateStr),
    eventClick: (info) => emit('editar-evento', info.event.extendedProps.original),
}));
</script>

<template>
    <FullCalendar :options="calendarOptions" />
</template>

<style scoped>
/* Ajuste mínimo para a cor primária do FullCalendar acompanhar o tema —
   sem importar o bundle CSS jQuery do tema (evita colidir com Bootstrap/
   Tailwind, já há uma colisão documentada com .collapse). */
:deep(.fc) {
    --fc-button-bg-color: var(--bs-primary);
    --fc-button-border-color: var(--bs-primary);
    --fc-button-hover-bg-color: var(--bs-primary);
    --fc-button-active-bg-color: var(--bs-primary);
    --fc-today-bg-color: rgba(var(--bs-primary-rgb), 0.08);
}
</style>
```

- [ ] **Step 2: Verificar build**

Run: `npm run build`
Expected: sem erros.

- [ ] **Step 3: Stage**

```bash
git add Modules/AnoLectivo/resources/js/Components/Calendario/AnoLectivoCalendario.vue
```

---

### Task 9: `Pages/Show.vue`

**Files:**
- Create: `Modules/AnoLectivo/resources/js/Pages/Show.vue`

**Interfaces:**
- Consumes: `AnoLectivoStatusBadge` (Task 2), `AnoLectivoFormModal` (Task 3), `PeriodoFormModal`/`PeriodoTable` (Tasks 5-6), `EventoFormModal`/`AnoLectivoCalendario` (Tasks 7-8), `ConfirmModal` (já existente).
- Produces: página Inertia `AnoLectivo/Show` — recebe prop `anoLectivo` (Object, com `periodos` e `eventosCalendario` já carregados). **É o orquestrador**: único ponto que abre/fecha modais e chama `router.*`.

- [ ] **Step 1: Criar `Pages/Show.vue`**

```vue
<script setup>
import { ref } from 'vue';
import { router } from '@inertiajs/vue3';
import { toast } from 'vue-sonner';
import AppLayout from '@/Layouts/AppLayout.vue';
import ConfirmModal from '@/Components/Shared/ConfirmModal.vue';
import AnoLectivoStatusBadge from '../Components/AnoLectivoStatusBadge.vue';
import AnoLectivoFormModal from '../Components/AnoLectivoFormModal.vue';
import PeriodoTable from '../Components/Periodos/PeriodoTable.vue';
import PeriodoFormModal from '../Components/Periodos/PeriodoFormModal.vue';
import AnoLectivoCalendario from '../Components/Calendario/AnoLectivoCalendario.vue';
import EventoFormModal from '../Components/Calendario/EventoFormModal.vue';

const props = defineProps({
    anoLectivo: { type: Object, required: true },
});
defineOptions({ layout: AppLayout });

const abaAtual = ref('periodos'); // 'periodos' | 'calendario'

// ========== Editar Ano Lectivo ==========
const editModalAberto = ref(false);
const editProcessing = ref(false);
const editErrors = ref({});

function abrirEdicaoAnoLectivo() {
    editErrors.value = {};
    editModalAberto.value = true;
}

function fecharEdicaoAnoLectivo() {
    editModalAberto.value = false;
}

function guardarAnoLectivo(payload) {
    editProcessing.value = true;
    editErrors.value = {};
    router.put(`/ano-lectivos/${props.anoLectivo.id}`, payload, {
        preserveScroll: true,
        onSuccess: () => {
            toast.success('Ano Lectivo atualizado com sucesso.');
            fecharEdicaoAnoLectivo();
        },
        onError: (erros) => {
            editErrors.value = erros;
            toast.error(Object.values(erros)[0] ?? 'Não foi possível atualizar o Ano Lectivo.');
        },
        onFinish: () => {
            editProcessing.value = false;
        },
    });
}

// ========== Períodos ==========
const periodoModalAberto = ref(false);
const periodoEmEdicao = ref(null);
const periodoProcessing = ref(false);
const periodoErrors = ref({});

function abrirCriacaoPeriodo() {
    periodoEmEdicao.value = null;
    periodoErrors.value = {};
    periodoModalAberto.value = true;
}

function abrirEdicaoPeriodo(periodo) {
    periodoEmEdicao.value = periodo;
    periodoErrors.value = {};
    periodoModalAberto.value = true;
}

function fecharPeriodoModal() {
    periodoModalAberto.value = false;
}

function guardarPeriodo(payload) {
    periodoProcessing.value = true;
    periodoErrors.value = {};

    const url = periodoEmEdicao.value
        ? `/periodos/${periodoEmEdicao.value.id}`
        : `/ano-lectivos/${props.anoLectivo.id}/periodos`;
    const metodo = periodoEmEdicao.value ? 'put' : 'post';

    router[metodo](url, payload, {
        preserveScroll: true,
        onSuccess: () => {
            toast.success(periodoEmEdicao.value ? 'Período atualizado com sucesso.' : 'Período criado com sucesso.');
            fecharPeriodoModal();
        },
        onError: (erros) => {
            periodoErrors.value = erros;
            toast.error(Object.values(erros)[0] ?? 'Não foi possível guardar o período.');
        },
        onFinish: () => {
            periodoProcessing.value = false;
        },
    });
}

const periodoParaEliminar = ref(null);
const eliminandoPeriodo = ref(false);

function pedirEliminacaoPeriodo(periodo) {
    periodoParaEliminar.value = periodo;
}

function cancelarEliminacaoPeriodo() {
    periodoParaEliminar.value = null;
}

function confirmarEliminacaoPeriodo() {
    eliminandoPeriodo.value = true;
    router.delete(`/periodos/${periodoParaEliminar.value.id}`, {
        preserveScroll: true,
        onSuccess: () => toast.success('Período eliminado com sucesso.'),
        onError: (erros) => toast.error(Object.values(erros)[0] ?? 'Não foi possível eliminar o período.'),
        onFinish: () => {
            eliminandoPeriodo.value = false;
            periodoParaEliminar.value = null;
        },
    });
}

// ========== Eventos do Calendário ==========
const eventoModalAberto = ref(false);
const eventoEmEdicao = ref(null);
const eventoDataInicial = ref(null);
const eventoProcessing = ref(false);
const eventoErrors = ref({});

function abrirCriacaoEvento(dataISO) {
    eventoEmEdicao.value = null;
    eventoDataInicial.value = dataISO;
    eventoErrors.value = {};
    eventoModalAberto.value = true;
}

function abrirEdicaoEvento(evento) {
    eventoEmEdicao.value = evento;
    eventoDataInicial.value = null;
    eventoErrors.value = {};
    eventoModalAberto.value = true;
}

function fecharEventoModal() {
    eventoModalAberto.value = false;
}

function guardarEvento(payload) {
    eventoProcessing.value = true;
    eventoErrors.value = {};

    const url = eventoEmEdicao.value
        ? `/eventos-calendario/${eventoEmEdicao.value.id}`
        : `/ano-lectivos/${props.anoLectivo.id}/eventos-calendario`;
    const metodo = eventoEmEdicao.value ? 'put' : 'post';

    router[metodo](url, payload, {
        preserveScroll: true,
        onSuccess: () => {
            toast.success(eventoEmEdicao.value ? 'Evento atualizado com sucesso.' : 'Evento criado com sucesso.');
            fecharEventoModal();
        },
        onError: (erros) => {
            eventoErrors.value = erros;
            toast.error(Object.values(erros)[0] ?? 'Não foi possível guardar o evento.');
        },
        onFinish: () => {
            eventoProcessing.value = false;
        },
    });
}
</script>

<template>
    <div class="app-container container-xxl py-6">
        <div class="card mb-6">
            <div class="card-body d-flex justify-content-between align-items-center">
                <div>
                    <h1 class="fs-2 fw-bold mb-2">{{ anoLectivo.nome }}</h1>
                    <div class="text-muted fs-6 mb-2">{{ anoLectivo.data_inicio }} — {{ anoLectivo.data_fim }}</div>
                    <AnoLectivoStatusBadge :estado="anoLectivo.estado" :estado-descricao="anoLectivo.estado_descricao" />
                </div>
                <button class="btn btn-light-primary" @click="abrirEdicaoAnoLectivo">Editar</button>
            </div>
        </div>

        <ul class="nav nav-line-tabs nav-line-tabs-2x fs-6 mb-8">
            <li class="nav-item">
                <a class="nav-link" :class="{ active: abaAtual === 'periodos' }" href="#" @click.prevent="abaAtual = 'periodos'">
                    Períodos
                </a>
            </li>
            <li class="nav-item">
                <a class="nav-link" :class="{ active: abaAtual === 'calendario' }" href="#" @click.prevent="abaAtual = 'calendario'">
                    Calendário Escolar
                </a>
            </li>
        </ul>

        <div v-show="abaAtual === 'periodos'" class="card">
            <div class="card-header d-flex justify-content-end align-items-center">
                <button class="btn btn-primary btn-sm" @click="abrirCriacaoPeriodo">Novo Período</button>
            </div>
            <div class="card-body p-0">
                <PeriodoTable
                    :periodos="anoLectivo.periodos"
                    @editar-periodo="abrirEdicaoPeriodo"
                    @eliminar-periodo="pedirEliminacaoPeriodo"
                />
            </div>
        </div>

        <div v-show="abaAtual === 'calendario'" class="card">
            <div class="card-header d-flex justify-content-end align-items-center">
                <button class="btn btn-primary btn-sm" @click="abrirCriacaoEvento(anoLectivo.data_inicio)">Novo Evento</button>
            </div>
            <div class="card-body">
                <AnoLectivoCalendario
                    :ano-lectivo="anoLectivo"
                    :eventos="anoLectivo.eventosCalendario"
                    @criar-evento="abrirCriacaoEvento"
                    @editar-evento="abrirEdicaoEvento"
                />
            </div>
        </div>

        <AnoLectivoFormModal
            :show="editModalAberto"
            :ano-lectivo="anoLectivo"
            :processing="editProcessing"
            :errors="editErrors"
            @submit="guardarAnoLectivo"
            @cancelar="fecharEdicaoAnoLectivo"
        />

        <PeriodoFormModal
            :show="periodoModalAberto"
            :periodo="periodoEmEdicao"
            :processing="periodoProcessing"
            :errors="periodoErrors"
            @submit="guardarPeriodo"
            @cancelar="fecharPeriodoModal"
        />

        <ConfirmModal
            :show="!!periodoParaEliminar"
            titulo="Eliminar Período"
            :mensagem="`Tem certeza que deseja eliminar o período ${periodoParaEliminar?.nome}?`"
            :processando="eliminandoPeriodo"
            @confirmar="confirmarEliminacaoPeriodo"
            @cancelar="cancelarEliminacaoPeriodo"
        />

        <EventoFormModal
            :show="eventoModalAberto"
            :evento="eventoEmEdicao"
            :data-inicial="eventoDataInicial"
            :processing="eventoProcessing"
            :errors="eventoErrors"
            @submit="guardarEvento"
            @cancelar="fecharEventoModal"
        />
    </div>
</template>
```

- [ ] **Step 2: Verificar build**

Run: `npm run build`
Expected: sem erros.

- [ ] **Step 3: Stage**

```bash
git add Modules/AnoLectivo/resources/js/Pages/Show.vue
```

---

### Task 10: Menu — Sidebar e Header

**Files:**
- Modify: `resources/js/Components/Layout/SidebarMenuWrapper.vue`
- Modify: `resources/js/Components/Layout/menus/AppsMenu.vue`

**Interfaces:**
- Não produz nem consome interfaces de código — é só navegação para as páginas das Tasks 4 e 9 (`/ano-lectivos`).

- [ ] **Step 1: Adicionar o bloco "Ano Lectivo" ao `SidebarMenuWrapper.vue`**

Ler primeiro o ficheiro para confirmar que o bloco "Estabelecimento" (comentário `<!-- Estabelecimento -->`) ainda está exactamente como abaixo antes de inserir a seguir a ele (mesmo `<div>` de fecho, sem alterar o bloco existente):

```vue
            <!-- Ano Lectivo -->
            <div data-kt-menu-trigger="click" :class="['menu-item', 'menu-accordion', { here: anoLectivoActive, show: anoLectivoActive }]">
                <span class="menu-link">
                    <span class="menu-icon">
                        <i class="ki-duotone ki-calendar-8 fs-2">
                            <span class="path1"></span><span class="path2"></span><span class="path3"></span><span class="path4"></span>
                        </i>
                    </span>
                    <span class="menu-title">Ano Lectivo</span>
                    <span class="menu-arrow"></span>
                </span>
                <div class="menu-sub menu-sub-accordion">
                    <div v-for="item in anoLectivoItems" :key="item.href" class="menu-item">
                        <a :class="['menu-link', { active: isActive(item.href, { exact: item.exact }) }]" :href="item.href">
                            <span class="menu-bullet"><span class="bullet bullet-dot"></span></span>
                            <span class="menu-title">{{ item.title }}</span>
                        </a>
                    </div>
                </div>
            </div>
```

Inserir imediatamente a seguir ao `</div>` de fecho do bloco `<!-- Estabelecimento -->` (linha 348 no momento da exploração — confirmar antes de editar).

No `<script setup>`, imediatamente a seguir à definição de `estabelecimentoActive` (`const estabelecimentoActive = computed(() => isGroupActive(estabelecimentoItems))`), adicionar:

```js
const anoLectivoItems = [
    { href: '/ano-lectivos', title: 'Anos Lectivos', exact: true },
]
const anoLectivoActive = computed(() => isGroupActive(anoLectivoItems))
```

(`ki-calendar-8` com 4 `path`s — confirmar o número exacto de `path`s existente no CSS do tema antes de finalizar, do mesmo modo que se confirmou `ki-lock-2` na Task 4; se o número não bater certo, ajustar a quantidade de `<span class="pathN">` em conformidade — não deixar `path`s a mais/a menos, isso desalinha as camadas do ícone duotone.)

- [ ] **Step 2: Adicionar o grupo "Ano Lectivo" a `AppsMenu.vue`**

No array `userManagementGroups` (`<script setup>`), adicionar um novo grupo depois do grupo `'Estabelecimento'` já existente:

```js
    {
        title: 'Ano Lectivo',
        links: [
            { href: '/ano-lectivos', label: 'Anos Lectivos' },
        ],
    },
```

- [ ] **Step 3: Verificar build**

Run: `npm run build`
Expected: sem erros.

- [ ] **Step 4: Stage**

```bash
git add resources/js/Components/Layout/SidebarMenuWrapper.vue resources/js/Components/Layout/menus/AppsMenu.vue
```

---

### Task 11: Verificação manual end-to-end

**Files:** nenhum ficheiro novo — esta task é só verificação.

- [ ] **Step 1: Arrancar o ambiente de desenvolvimento**

```bash
npm run dev
```
(noutro terminal, se o servidor Laravel ainda não estiver a correr) `php artisan serve` ou equivalente já usado no projecto.

- [ ] **Step 2: Percorrer o caminho feliz no browser, autenticado como um utilizador com `gerir-ano-letivo`**

1. Abrir `/ano-lectivos` — confirmar que a tabela carrega (vazia ou com dados) e que "Ano Lectivo" aparece na sidebar (secção Configurações) e no dropdown "Configurações" do header.
2. Clicar "Novo Ano Lectivo", preencher nome/datas/estado, guardar — confirmar toast de sucesso e a nova linha na tabela.
3. Na linha criada, usar "Activar" (se `Planeado`) — confirmar o modal de confirmação e o badge a mudar para "Activo".
4. Clicar "Ver" — confirmar que `Show.vue` abre com o nome/datas/estado correctos.
5. Na aba "Períodos", criar um período com datas dentro do intervalo do Ano Lectivo — confirmar sucesso. Tentar criar um período com datas fora do intervalo — confirmar que o erro de validação do backend aparece no campo certo (mensagem vinda de `ValidaIntervaloPeriodo`).
6. Editar e eliminar o período criado — confirmar ambos funcionam.
7. Na aba "Calendário Escolar", confirmar que a grelha do FullCalendar aparece a começar no mês de `data_inicio` do Ano Lectivo. Clicar numa data dentro do intervalo — confirmar que `EventoFormModal` abre com a data pré-preenchida. Criar o evento — confirmar que aparece no calendário com a cor correspondente ao `tipo`.
8. Tentar navegar no calendário para um mês fora do intervalo do Ano Lectivo — confirmar que o FullCalendar bloqueia a navegação (`validRange`) e que o mês do `data_fim` continua acessível (não fica cortado um dia antes, por causa do `+1 dia` no `end`).
9. Clicar no evento criado — confirmar que abre em modo edição com os dados correctos; editar e eliminar (eliminar é feito a partir de um botão dentro do próprio fluxo de edição a definir nesta verificação, se necessário adicionar um botão "Eliminar" ao `EventoFormModal` ou um `ConfirmModal` extra em `Show.vue` — se faltar, é um gap a resolver antes de fechar esta task, não a ignorar).
10. Voltar a `/ano-lectivos`, testar "Encerrar" no Ano Lectivo activo e "Eliminar" num Ano Lectivo sem períodos/eventos — confirmar sucesso. Tentar eliminar um Ano Lectivo COM períodos/eventos — confirmar que o backend bloqueia e a mensagem de erro aparece.
11. Sair e voltar a entrar com um utilizador sem `gerir-ano-letivo` — confirmar que `/ano-lectivos` devolve 403 (mesmo comportamento de qualquer outra rota protegida do projecto).

- [ ] **Step 2 (nota):** se o Step 1.9 revelar que falta um botão "Eliminar" no fluxo de edição de evento, adicionar agora: um `ConfirmModal` extra em `Show.vue` (mesmo padrão dos já existentes para Período), com um botão "Eliminar" dentro do `EventoFormModal` que emite um evento novo (`eliminar`) em vez de abrir o modal directamente (mantendo o modal "burro"). Repetir a verificação depois de corrigido.

- [ ] **Step 3: Stage de qualquer ajuste feito no Step 2**

```bash
git add -A -- Modules/AnoLectivo/resources/js resources/js/Components/Layout
```
(usar `git status` antes para confirmar que só ficheiros deste módulo/menu estão a ser adicionados, nunca um `git add -A` cego sobre o repositório inteiro.)

---

## Self-Review

**Cobertura da spec:** Estrutura de páginas (Tasks 4, 9) ✓; Componentes do módulo (Tasks 2, 3, 5, 6, 7, 8) ✓; Calendário Escolar com `validRange` exclusivo (Task 8) ✓; Modelo JS (Task 1) ✓; Validação e erros (todas as tasks de formulário) ✓; Menu (Task 10) ✓; Show.vue como orquestrador (Task 9) ✓; Regras de negócio só no backend, sem permissões novas, sem validação de conflito de eventos (respeitado em todas as tasks — nenhuma task duplica lógica do backend) ✓.

**Scan de placeholders:** Nenhum "TBD"/"TODO" nas tasks 1-10. A Task 11 (verificação manual) contém uma nota condicional explícita (Step 1.9/Step 2) em vez de assumir que o fluxo de eliminação de evento está coberto — isto é intencional (a spec não detalhou um botão de eliminar dentro do `EventoFormModal`, ao contrário de Período que tem `PeriodoTable` com botão próprio); fica resolvido durante a verificação manual, não escondido.

**Consistência de tipos:** `ESTADO_ANO_LECTIVO`, `TIPO_PERIODO`, `TIPO_EVENTO_CALENDARIO` (Task 1) usados com os mesmos nomes/valores em todas as tasks seguintes. Nomes de eventos (`editar-periodo`, `eliminar-periodo`, `criar-evento`, `editar-evento`, `submit`, `cancelar`) consistentes entre quem emite (Tasks 5-8) e quem escuta (Task 9). Prop `anoLectivo.eventosCalendario` (camelCase) usada consistentemente em Task 9 e Task 8 (via a prop `eventos` do `AnoLectivoCalendario`).
