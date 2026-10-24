<?php

namespace Modules\Usuario\Http\Controllers;

use App\Http\Controllers\Controller;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Modules\Autenticacao\Service\FotoDoUtilizadorService;
use Modules\Core\Exportacao\Exportador;
use Modules\Permissao\Enums\Perfil;
use Modules\Usuario\Actions\RedefinirSenhaUsuarioAction;
use Modules\Usuario\Http\Requests\AtualizarUsuarioRequest;
use Modules\Usuario\Http\Requests\CriarUsuarioRequest;
use Modules\Usuario\Http\Requests\ProcurarAlunoPorMatriculaRequest;
use Modules\Usuario\Models\User;
use Modules\Usuario\Services\AlunoParaContaConsultaService;
use Modules\Usuario\Services\ExportacaoDeUtilizadoresService;
use Modules\Usuario\Services\GestaoUsuarioService;
use Modules\Usuario\Services\UsuarioConsultaService;

class UsuarioController extends Controller
{
    public function __construct(
        private GestaoUsuarioService $service,
        private UsuarioConsultaService $consulta,
    ) {}

    /**
     * Display a listing of the resource.
     */
    public function index(Request $request)
    {
        return Inertia::render('Usuario/Index', array_merge($this->listagem($request, null), $this->consulta->dadosDeApoio()));
    }

    /**
     * Lista apenas usuários com o perfil Aluno.
     */
    public function alunos(Request $request)
    {
        return Inertia::render('Usuario/Alunos', array_merge($this->listagem($request, Perfil::ALUNO), $this->consulta->dadosDeApoio()));
    }

    /**
     * Lista apenas usuários com o perfil Professor.
     */
    public function professores(Request $request)
    {
        return Inertia::render('Usuario/Professores', array_merge($this->listagem($request, Perfil::PROFESSOR), $this->consulta->dadosDeApoio()));
    }

    /**
     * Lista apenas usuários com o perfil Funcionário.
     */
    public function funcionarios(Request $request)
    {
        return Inertia::render('Usuario/Funcionarios', array_merge($this->listagem($request, Perfil::FUNCIONARIO), $this->consulta->dadosDeApoio()));
    }

    public function administradores(Request $request)
    {
        return Inertia::render('Usuario/Administradores', array_merge($this->listagem($request, Perfil::ADMIN_ESCOLA), $this->consulta->dadosDeApoio()));
    }

    public function encarregados(Request $request)
    {
        return Inertia::render('Usuario/Encarregados', array_merge($this->listagem($request, Perfil::ENCARREGADO), $this->consulta->dadosDeApoio()));
    }

    /**
     * @return array{usuarios: mixed, filtros: array<string, mixed>}
     */
    private function listagem(Request $request, ?Perfil $perfil): array
    {
        $filtros = $request->only(['pesquisa', 'estado']);

        return [
            'usuarios' => $this->consulta->listar($perfil, $filtros),
            'filtros' => $filtros,
            // Diferido: cartões e contagens dos separadores chegam depois da lista.
            'indicadores' => Inertia::defer(fn () => $this->consulta->indicadores($perfil, $request->user()->can('autorizacao.ver'))),
        ];
    }

    public function store(CriarUsuarioRequest $request)
    {
        $this->service->criar($request);

        return redirect()->back()->with('success', 'Utilizador criado com sucesso.');
    }

    /**
     * Pesquisa (correspondência exacta) do aluno cuja conta se vai criar. Devolve só o que o ecrã
     * mostra; aluno inexistente e aluno de outra escola dão exactamente o mesmo 404.
     */
    public function procurarAluno(ProcurarAlunoPorMatriculaRequest $request, AlunoParaContaConsultaService $alunos)
    {
        $resumo = $alunos->resumo(
            trim($request->validated('matricula')),
            // Nascimento, identificação, curso e turma exigem também aluno.ver (decidido aqui, nunca pelo cliente).
            $request->user()->can('aluno.ver'),
        );

        if ($resumo === null) {
            return response()->json(['message' => 'Não foi encontrado nenhum aluno com este número de matrícula.'], 404);
        }

        return response()->json($resumo);
    }

    public function edit(User $user)
    {
        return response()->json($this->consulta->dadosParaEdicao($user));
    }

    public function update(AtualizarUsuarioRequest $request, User $user)
    {
        $this->service->atualizar($user, $request->validated());

        return redirect()->back()->with('success', 'Utilizador atualizado com sucesso.');
    }

    public function destroy(User $user)
    {
        $this->authorize('delete', $user);
        $this->service->eliminar($user);

        return redirect()->back()->with('success', 'Utilizador eliminado com sucesso.');
    }

    public function alternarEstado(User $user)
    {
        $this->authorize('alternarEstado', $user);
        $this->service->alternarEstado($user);

        return redirect()->back()->with('success', 'Estado do utilizador atualizado.');
    }

    public function redefinirSenha(Request $request, User $user, RedefinirSenhaUsuarioAction $action)
    {
        $this->authorize('redefinirSenha', $user);
        $senha = $action->executar($user, $request->user());

        return back()->with('senha_temporaria', [
            'user_id' => $user->id,
            'nome' => $user->name,
            // Cifrada: o payload da sessão (driver database) nunca guarda a senha em claro.
            'senha' => Crypt::encryptString($senha),
        ]);
    }

    /**
     * Exporta a lista (todos ou um perfil) com os filtros do ecrã: Excel, PDF, CSV ou ZIP. Os administradores só entram
     * no ficheiro para quem tem `autorizacao.ver`.
     */
    public function exportar(Request $request, ExportacaoDeUtilizadoresService $exportacao, Exportador $exportador)
    {
        $this->authorize('usuario.exportar');

        $dados = $request->validate([
            'formato' => ['required', 'string'],
            'perfil' => ['nullable', 'string', Rule::in(array_keys(ExportacaoDeUtilizadoresService::PERFIS))],
            'pesquisa' => ['nullable', 'string', 'max:100'],
            'estado' => ['nullable', 'in:0,1'],
        ]);

        $podeVerAdministradores = $request->user()->can('autorizacao.ver');

        if (($dados['perfil'] ?? null) === 'administradores') {
            $this->authorize('autorizacao.ver');
        }

        // Fecha por omissão: na lista geral, quem não pode ver administradores não os recebe no ficheiro.
        $tabela = $exportacao->tabela($dados['perfil'] ?? null, $request->only(['pesquisa', 'estado']), $podeVerAdministradores);

        return $exportador->responder($tabela, $dados['formato'], 'utilizadores'.(isset($dados['perfil']) ? '-'.$dados['perfil'] : ''));
    }

    /** Foto de perfil de um utilizador, para as listas e a ficha. A de um administrador exige também `autorizacao.ver`. */
    public function foto(User $user, FotoDoUtilizadorService $fotos)
    {
        $this->authorize('usuario.ver');

        if ($user->roles()->where('nome', Perfil::ADMIN_ESCOLA->value)->exists()) {
            $this->authorize('autorizacao.ver');
        }

        return $fotos->servir($user);
    }
}
