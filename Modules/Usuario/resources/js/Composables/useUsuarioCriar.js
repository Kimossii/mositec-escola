import { reactive, ref } from 'vue';
import { router } from '@inertiajs/vue3';

/**
 * Estado e submissão do form de criar utilizador — POST /usuarios/cadastrarUsuario.
 *
 * Usa o router do Inertia (não axios) para que a visita passe pelo ciclo
 * normal do Inertia: se o backend cair num dd()/erro e devolver uma resposta
 * que não é do Inertia, o próprio Inertia mostra esse conteúdo num modal.
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

    function criar(estado) {
        processing.value = true;
        errors.value = {};
        errorMessage.value = '';

        return new Promise((resolve, reject) => {
            router.post(
                '/usuarios/cadastrarUsuario',
                {
                    name: form.name,
                    email: form.email,
                    password: form.password,
                    estado,
                },
                {
                    preserveScroll: true,
                    onSuccess: () => {
                        form.name = '';
                        form.email = '';
                        form.password = '';
                        resolve();
                    },
                    onError: (erros) => {
                        errors.value = erros;
                        if (Object.keys(erros).length === 0) {
                            errorMessage.value = 'Não foi possível guardar o utilizador. Tenta novamente.';
                        }
                        reject(erros);
                    },
                    onFinish: () => {
                        processing.value = false;
                    },
                },
            );
        });
    }

    return { form, processing, errors, errorMessage, criar };
}
