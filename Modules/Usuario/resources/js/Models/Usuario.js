/**
 * Shape de um usuário na listagem — espelha o model User + a relação
 * pessoa() (Modules/Usuario/app/Models/User.php e DadosPessoal.php) para que
 * trocar `usuariosMock` por props reais do Inertia seja uma troca direta.
 *
 * @typedef {Object} Usuario
 * @property {number} id
 * @property {string} name
 * @property {string} email
 * @property {string|null} avatar - URL do avatar; null usa avatarColor + iniciais
 * @property {string} [avatarColor] - sufixo de cor Bootstrap (ex.: 'danger'), só quando avatar é null
 * @property {string} matricula - dados_pessoas.numero_identificacao
 * @property {number} estado - 0 ou 1, ver ESTADO abaixo
 * @property {number} tipo_pessoa - 0 a 3, ver TIPO_PESSOA abaixo
 * @property {string} ultimo_acesso - texto relativo (ex.: "2 days ago")
 * @property {string} created_at - "DD MMM YYYY, LT" (formato usado pelo table.js legado)
 */

// Espelha Modules/Usuario/app/Enums/EstadoUsuario.php
export const ESTADO = Object.freeze({ INATIVO: 0, ATIVO: 1 });

// Espelha a coluna dados_pessoas.tipo_pessoa (ver migration
// 2026_03_31_103946_create_dados_pessoas_table.php). Ainda não existe um
// enum PHP para isso — seria o próximo passo natural no backend, seguindo
// o mesmo padrão de EstadoUsuario.
export const TIPO_PESSOA = Object.freeze({ ALUNO: 0, PROFESSOR: 1, FUNCIONARIO: 2, OUTRO: 3 });

export const estadoLabel = (estado) => (estado === ESTADO.ATIVO ? 'Ativo' : 'Inativo');

/** @type {Usuario[]} */
// TODO: substituir por `defineProps({ usuarios: Array })` quando
// UsuarioController@index passar a enviar os usuários reais via
// Inertia::render('Usuario/Index', ['usuarios' => ...]).
export const usuariosMock = [
    { id: 1, name: 'Emma Smith', email: 'smith@kpmg.com', avatar: '/themes/metronic/assets/media/avatars/300-6.jpg', matricula: '20240001', estado: ESTADO.ATIVO, tipo_pessoa: TIPO_PESSOA.ALUNO, ultimo_acesso: 'Yesterday', created_at: '20 Dec 2023, 10:10 pm' },
    { id: 2, name: 'Melody Macy', email: 'melody@altbox.com', avatar: null, avatarColor: 'danger', matricula: '20240002', estado: ESTADO.ATIVO, tipo_pessoa: TIPO_PESSOA.PROFESSOR, ultimo_acesso: '20 mins ago', created_at: '20 Jun 2023, 2:40 pm' },
    { id: 3, name: 'Max Smith', email: 'max@kt.com', avatar: '/themes/metronic/assets/media/avatars/300-1.jpg', matricula: '20240003', estado: ESTADO.INATIVO, tipo_pessoa: TIPO_PESSOA.ALUNO, ultimo_acesso: '3 days ago', created_at: '19 Aug 2023, 10:30 am' },
    { id: 4, name: 'Sean Bean', email: 'sean@dellito.com', avatar: '/themes/metronic/assets/media/avatars/300-5.jpg', matricula: '20240004', estado: ESTADO.ATIVO, tipo_pessoa: TIPO_PESSOA.FUNCIONARIO, ultimo_acesso: '5 hours ago', created_at: '05 May 2023, 6:43 am' },
    { id: 5, name: 'Brian Cox', email: 'brian@exchange.com', avatar: '/themes/metronic/assets/media/avatars/300-25.jpg', matricula: '20240005', estado: ESTADO.ATIVO, tipo_pessoa: TIPO_PESSOA.PROFESSOR, ultimo_acesso: '2 days ago', created_at: '25 Jul 2023, 11:05 am' },
    { id: 6, name: 'Mikaela Collins', email: 'mik@pex.com', avatar: null, avatarColor: 'warning', matricula: '20240006', estado: ESTADO.INATIVO, tipo_pessoa: TIPO_PESSOA.OUTRO, ultimo_acesso: '5 days ago', created_at: '10 Nov 2023, 5:20 pm' },
    { id: 7, name: 'Olivia Wild', email: 'olivia@corpmail.com', avatar: null, avatarColor: 'danger', matricula: '20240007', estado: ESTADO.ATIVO, tipo_pessoa: TIPO_PESSOA.ALUNO, ultimo_acesso: 'Yesterday', created_at: '21 Feb 2023, 11:30 am' },
    { id: 8, name: 'Neil Owen', email: 'owen.neil@gmail.com', avatar: null, avatarColor: 'primary', matricula: '20240008', estado: ESTADO.ATIVO, tipo_pessoa: TIPO_PESSOA.FUNCIONARIO, ultimo_acesso: '20 mins ago', created_at: '22 Sep 2023, 6:05 pm' },
    { id: 9, name: 'Dan Wilson', email: 'dam@consilting.com', avatar: '/themes/metronic/assets/media/avatars/300-23.jpg', matricula: '20240009', estado: ESTADO.INATIVO, tipo_pessoa: TIPO_PESSOA.PROFESSOR, ultimo_acesso: '3 days ago', created_at: '20 Dec 2023, 8:43 pm' },
    { id: 10, name: 'Ana Crown', email: 'ana.cf@limtel.com', avatar: '/themes/metronic/assets/media/avatars/300-12.jpg', matricula: '20240010', estado: ESTADO.ATIVO, tipo_pessoa: TIPO_PESSOA.ALUNO, ultimo_acesso: '2 days ago', created_at: '25 Jul 2023, 11:30 am' },
];
