import { computed } from 'vue';
import { usuariosMock } from '../Models/Usuario';

/**
 * Fonte da lista de usuários usada pelas páginas do módulo.
 *
 * Hoje devolve o mock filtrado em memória; quando o backend passar a
 * enviar os usuários via Inertia props, só este arquivo precisa mudar
 * (as páginas e componentes continuam consumindo `usuarios` normalmente).
 *
 * @param {{ tipoPessoa?: number }} [options] - filtra por Models/Usuario.js#TIPO_PESSOA,
 *   usado pelas futuras páginas Alunos/Professores/Funcionários/Administradores.
 */
export function useUsuarios(options = {}) {
    const { tipoPessoa } = options;

    const usuarios = computed(() => {
        if (tipoPessoa === undefined) {
            return usuariosMock;
        }

        return usuariosMock.filter((usuario) => usuario.tipo_pessoa === tipoPessoa);
    });

    return { usuarios };
}
