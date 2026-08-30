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
 * @property {string} ultimo_acesso - texto relativo (ainda sem tracking real de login)
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
