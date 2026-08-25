import { reactive, ref } from 'vue';
import axios from 'axios';

/**
 * Estado e submissão do form de criar utilizador — POST /api/v1/usuarios/store.
 *
 * A rota hoje só grava em `users` (name/email/password/estado); matrícula,
 * tipo de pessoa e avatar pertencem a `dados_pessoas`, que ainda não tem
 * controller/rota própria nem está ligada ao User criado aqui — por isso
 * esses campos não são enviados.
 */
export function useUsuarioCriar() {
    const form = reactive({
        name: '',
        email: '',
        password: '',
    });
    const processing = ref(false);
    const errors = ref({});
    const errorMessage = ref('');

    async function criar(estado) {
        processing.value = true;
        errors.value = {};
        errorMessage.value = '';

        try {
            const { data } = await axios.post('/api/v1/usuarios/store', {
                name: form.name,
                email: form.email,
                password: form.password,
                estado,
            });

            form.name = '';
            form.email = '';
            form.password = '';

            return data;
        } catch (error) {
            if (error.response?.status === 422) {
                errors.value = error.response.data.errors ?? {};
            } else {
                errorMessage.value = 'Não foi possível guardar o utilizador. Tenta novamente.';
            }
            throw error;
        } finally {
            processing.value = false;
        }
    }

    return { form, processing, errors, errorMessage, criar };
}
