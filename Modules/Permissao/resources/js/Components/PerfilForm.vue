<script setup>
import { reactive, ref, watch } from 'vue';
import { router } from '@inertiajs/vue3';
import { toast } from 'vue-sonner';

const props = defineProps({
    perfil: {
        type: Object,
        default: null,
    },
});
const emit = defineEmits(['fechar']);

const form = reactive({ descricao: '', estado: 1 });
const processing = ref(false);
const errors = ref({});

watch(() => props.perfil, (perfil) => {
    form.descricao = perfil?.descricao ?? '';
    form.estado = perfil?.estado ?? 1;
}, { immediate: true });

function submeter() {
    processing.value = true;
    errors.value = {};

    const url = props.perfil ? `/permissoes/perfis/${props.perfil.id}` : '/permissoes/perfis';
    const metodo = props.perfil ? 'put' : 'post';

    router[metodo](url, form, {
        preserveScroll: true,
        onSuccess: () => {
            toast.success(props.perfil ? 'Perfil atualizado com sucesso.' : 'Perfil criado com sucesso.');
            emit('fechar');
        },
        onError: (erros) => {
            errors.value = erros;
            toast.error(Object.values(erros)[0] ?? 'Não foi possível guardar o perfil.');
        },
        onFinish: () => {
            processing.value = false;
        },
    });
}
</script>

<template>
    <form @submit.prevent="submeter">
        <div class="fv-row mb-7">
            <label class="required fw-semibold fs-6 mb-2">Nome do perfil</label>
            <input v-model="form.descricao" type="text" class="form-control form-control-solid" placeholder="ex: Diretor" />
            <div class="text-danger fs-7 mt-1" v-if="errors.descricao">{{ errors.descricao[0] }}</div>
        </div>

        <div class="text-end">
            <button type="button" class="btn btn-light me-2" @click="emit('fechar')">Cancelar</button>
            <button type="submit" class="btn btn-primary" :disabled="processing">Guardar</button>
        </div>
    </form>
</template>
