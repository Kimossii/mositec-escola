<script setup>
import { reactive, ref } from 'vue';
import { router } from '@inertiajs/vue3';
import { toast } from 'vue-sonner';
import Loader from '@/Components/Shared/Loader.vue';
import PasswordInput from '@/Components/Shared/PasswordInput.vue';
import LayoutPlataforma from '../Components/LayoutPlataforma.vue';

defineOptions({ layout: LayoutPlataforma });

const form = reactive({
    current_password: '',
    password: '',
    password_confirmation: '',
});
const processing = ref(false);
const errors = ref({});

function submit() {
    processing.value = true;
    errors.value = {};

    router.put('/plataforma/alterar-senha', form, {
        onError: (erros) => {
            errors.value = erros;
            toast.error(Object.values(erros)[0]);
        },
        onFinish: () => {
            processing.value = false;
        },
    });
}
</script>

<template>
    <div class="card mw-600px mx-auto">
        <div class="card-body p-10">
            <form class="form w-100" novalidate @submit.prevent="submit">
                <div class="text-center mb-10">
                    <h1 class="text-dark fw-bolder mb-3">Alterar senha</h1>
                    <div class="text-gray-500 fw-semibold fs-6">
                        Mínimo de 12 caracteres, com maiúsculas, minúsculas, números e símbolos.
                    </div>
                </div>

                <div class="fv-row mb-8">
                    <label class="required fw-semibold fs-6 mb-2">Senha actual</label>
                    <PasswordInput
                        v-model="form.current_password"
                        name="current_password"
                        autocomplete="current-password"
                        placeholder="Senha actual"
                        :invalid="!!errors.current_password"
                    />
                    <div class="text-danger fs-7 mt-1" v-if="errors.current_password">{{ errors.current_password }}</div>
                </div>

                <div class="fv-row mb-8">
                    <label class="required fw-semibold fs-6 mb-2">Nova senha</label>
                    <PasswordInput
                        v-model="form.password"
                        name="password"
                        autocomplete="new-password"
                        placeholder="Nova senha"
                        :invalid="!!errors.password"
                    />
                    <div class="text-danger fs-7 mt-1" v-if="errors.password">{{ errors.password }}</div>
                </div>

                <div class="fv-row mb-10">
                    <label class="required fw-semibold fs-6 mb-2">Confirmar nova senha</label>
                    <PasswordInput
                        v-model="form.password_confirmation"
                        name="password_confirmation"
                        autocomplete="new-password"
                        placeholder="Confirmar nova senha"
                        :invalid="!!errors.password_confirmation"
                    />
                    <div class="text-danger fs-7 mt-1" v-if="errors.password_confirmation">{{ errors.password_confirmation }}</div>
                </div>

                <div class="d-grid">
                    <button type="submit" class="btn btn-primary" :disabled="processing">
                        <span v-if="!processing">Guardar nova senha</span>
                        <span v-else>
                            A guardar...
                            <Loader size="0.3px" class="align-middle ms-2" />
                        </span>
                    </button>
                </div>
            </form>
        </div>
    </div>
</template>
