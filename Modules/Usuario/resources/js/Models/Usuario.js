/**
 * Shape de um usuário na listagem — espelha o que UsuarioController agora
 * envia como prop `usuarios` (ver serializar() em UsuarioController.php).
 *
 * @typedef {Object} Usuario
 * @property {number} id
 * @property {string} name
 * @property {string|null} email
 * @property {string|null} avatar - URL do avatar; null usa avatarColor + iniciais
 * @property {string} [avatarColor] - sufixo de cor Bootstrap (ex.: 'danger'), só quando avatar é null
 * @property {string|null} matricula - users.numero_matricula
 * @property {number} estado - 0 ou 1, ver ESTADO abaixo
 * @property {string|null} ultimo_acesso - ISO da última actividade (da sessão ainda existente); null sem sessão
 * @property {boolean} online - actividade nos últimos 5 minutos
 * @property {string} created_at - "DD MMM YYYY, LT" (formato usado pelo table.js legado)
 */

// Espelha Modules/Core/app/Enums/Estado.php
export const ESTADO = Object.freeze({ INATIVO: 0, ATIVO: 1 });

// Espelha a coluna dados_pessoas.tipo_pessoa (ver migration
// 2026_03_31_103946_create_dados_pessoas_table.php) — ainda usado só pelos
// inputs hidden cosméticos dos Forms (AlunoForm.vue etc.), não pela listagem
// (que agora filtra por perfil/role, ver UsuarioController::listarPorPerfil).
export const TIPO_PESSOA = Object.freeze({ ALUNO: 0, PROFESSOR: 1, FUNCIONARIO: 2, OUTRO: 3 });

export const estadoLabel = (estado) => (estado === ESTADO.ATIVO ? 'Ativo' : 'Inativo');

/** Cor do selo de cada perfil (o nome vem de `roles.descricao`). */
export const COR_PERFIL = Object.freeze({
    'Admin escola': 'danger',
    'Funcionário': 'info',
    'Professor': 'warning',
    'Aluno': 'primary',
    'Encarregado': 'success',
});
export const corDoPerfil = (nome) => COR_PERFIL[nome] ?? 'secondary';

const CORES_AVATAR = ['primary', 'success', 'info', 'warning', 'danger'];
/** Cor estável para o avatar sem foto, a partir do nome. */
export const corDoAvatar = (nome = '') => CORES_AVATAR[[...nome].reduce((soma, c) => soma + c.charCodeAt(0), 0) % CORES_AVATAR.length];

/** «há 5 min», «há 2 h», «há 3 dias» a partir de uma data ISO. */
export function tempoRelativo(iso) {
    const segundos = Math.round((Date.now() - new Date(iso).getTime()) / 1000);
    if (segundos < 60) return 'agora mesmo';
    if (segundos < 3600) return `há ${Math.round(segundos / 60)} min`;
    if (segundos < 86400) return `há ${Math.round(segundos / 3600)} h`;

    return `há ${Math.round(segundos / 86400)} dia(s)`;
}
