<script setup>
import { ref, watch } from 'vue';
import { router } from '@inertiajs/vue3';
import { toast } from 'vue-sonner';

const props = defineProps({
    show: { type: Boolean, default: false },
    titulo: { type: String, default: '' },
    ids: { type: Array, default: () => [] }, // ficheiros a partilhar (um ou os de uma pasta)
    referencia: { type: Number, default: null }, // um dos ficheiros, para pesquisar quem pode receber
    actuais: { type: Array, default: () => [] }, // [{ id, nome }] já com acesso
});
const emit = defineEmits(['fechar']);

const pesquisa = ref('');
const resultados = ref([]);
const escolhidos = ref(new Map()); // id → nome
const a_gravar = ref(false);
let relogio = null;

watch(() => props.show, (aberto) => {
    if (!aberto) return;
    pesquisa.value = '';
    resultados.value = [];
    escolhidos.value = new Map(props.actuais.map((u) => [u.id, u.nome]));
    procurar();
});
watch(pesquisa, () => { clearTimeout(relogio); relogio = setTimeout(procurar, 300); });

async function procurar() {
    if (!props.referencia) return;
    const resposta = await fetch(`/ficheiros/${props.referencia}/destinatarios?q=${encodeURIComponent(pesquisa.value)}`, { headers: { Accept: 'application/json' }, credentials: 'same-origin' });
    resultados.value = resposta.ok ? await resposta.json() : [];
}

const alternar = (u) => { const m = new Map(escolhidos.value); m.has(u.id) ? m.delete(u.id) : m.set(u.id, u.nome); escolhidos.value = m; };

function gravar() {
    a_gravar.value = true;
    router.post('/ficheiros/partilhar', { ids: props.ids, destinatarios: [...escolhidos.value.keys()] }, {
        preserveScroll: true,
        onSuccess: () => emit('fechar'),
        onError: (e) => toast.error(e.destinatarios ?? 'Não foi possível partilhar.'),
        onFinish: () => { a_gravar.value = false; },
    });
}
</script>

<template>
    <div v-if="show" class="modal d-block" style="background: rgba(0,0,0,0.5)" @click.self="emit('fechar')">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content p-6">
                <h3 class="mb-1">Partilhar «{{ titulo }}»</h3>
                <p class="text-muted fs-7 mb-4">Os colegas escolhidos podem descarregar {{ ids.length === 1 ? 'este ficheiro' : `estes ${ids.length} ficheiros` }}, só para leitura. Só aparecem colegas com permissão para este tipo de dados. A partilha termina quando o ficheiro for eliminado (7 dias).</p>

                <input v-model="pesquisa" type="text" class="form-control form-control-solid mb-3" placeholder="Pesquisar colega por nome ou e-mail" />

                <div v-if="escolhidos.size" class="mb-3">
                    <span v-for="[id, nome] in escolhidos" :key="id" class="badge badge-light-primary me-2 mb-2 fs-7">
                        {{ nome }} <a href="#" class="ms-2 text-danger" @click.prevent="alternar({ id, nome })">×</a>
                    </span>
                </div>

                <div class="mh-250px overflow-auto mb-4">
                    <div v-if="!resultados.length" class="text-muted text-center py-4 fs-7">Nenhum colega encontrado com esta permissão.</div>
                    <label v-for="u in resultados" :key="u.id" class="d-flex align-items-center py-2 border-bottom cursor-pointer">
                        <span class="form-check form-check-sm form-check-custom form-check-solid me-3">
                            <input class="form-check-input" type="checkbox" :checked="escolhidos.has(u.id)" @change="alternar(u)" />
                        </span>
                        <span class="d-flex flex-column"><span class="fw-semibold">{{ u.nome }}</span><span class="text-muted fs-8">{{ u.email }}</span></span>
                    </label>
                </div>

                <div class="text-end">
                    <button type="button" class="btn btn-light-primary me-2" :disabled="a_gravar" @click="emit('fechar')">Cancelar</button>
                    <button type="button" class="btn btn-primary" :disabled="a_gravar" @click="gravar">Guardar partilha</button>
                </div>
            </div>
        </div>
    </div>
</template>
