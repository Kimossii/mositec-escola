import { computed } from 'vue';
import { usuariosMock } from '../Models/Usuario';

/**
 * Fonte da lista de usuários usada pelas páginas do módulo.
 *
 * Hoje devolve o mock filtrado em memória; quando o backend passar a
 * enviar os usuários via Inertia props, só este arquivo precisa mudar
 * (as páginas e componentes continuam consumindo `usuarios` normalmente).
 *
 * @param {{ tipoPessoa?: number, apenasAdministradores?: boolean, apenasEncarregados?: boolean }} [options]
 *   - `tipoPessoa`: filtra por Models/Usuario.js#TIPO_PESSOA (Alunos/Professores/Funcionários).
 *   - `apenasAdministradores`: filtra por `isAdministrador` — placeholder até existir
 *     integração real com roles/permissoes (ver nota em Models/Usuario.js).
 *   - `apenasEncarregados`: filtra por `isEncarregado` — mesmo placeholder que
 *     `apenasAdministradores`, até existir integração real com roles/permissoes
 *     (ver nota em Models/Usuario.js). Nenhuma linha do mock tem `isEncarregado: true`
 *     hoje, então a lista resultante fica vazia — é o esperado.
 */
export function useUsuarios(options = {}) {
    const { tipoPessoa, apenasAdministradores, apenasEncarregados } = options;

    const usuarios = computed(() => {
        if (apenasAdministradores) {
            return usuariosMock.filter((usuario) => usuario.isAdministrador === true);
        }

        if (apenasEncarregados) {
            return usuariosMock.filter((usuario) => usuario.isEncarregado === true);
        }

        if (tipoPessoa === undefined) {
            return usuariosMock;
        }

        return usuariosMock.filter((usuario) => usuario.tipo_pessoa === tipoPessoa);
    });

    return { usuarios };
}
