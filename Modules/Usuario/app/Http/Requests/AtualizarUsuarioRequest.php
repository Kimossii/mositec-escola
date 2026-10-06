<?php

namespace Modules\Usuario\Http\Requests;

use App\Http\Requests\BaseRequest;
use Illuminate\Validation\Rule;
use Modules\Permissao\Enums\Perfil;

class AtualizarUsuarioRequest extends BaseRequest
{
    public function authorize(): bool
    {
        // Autorizacao.editar é exigido sempre que o pedido toca em algo que
        // usuario.editar sozinho nunca devia poder mexer: o alvo já é (ou
        // vai passar a ser, via troca de perfil) Admin Escola, ou o pedido
        // traz overrides individuais (celulas) — sem isto, quem só gere
        // contas (ex: Funcionário) podia esconder num "editar" a concessão
        // de qualquer permissão, incluindo autorizacao.editar, a quem quisesse.
        $eraAdmin = $this->route('user')?->roles->contains('nome', Perfil::ADMIN_ESCOLA->value) ?? false;
        $vaiSerAdmin = $this->input('perfil') === Perfil::ADMIN_ESCOLA->slug();
        $temOverrides = !empty($this->input('celulas'));
        $precisaAutorizacao = $eraAdmin || $vaiSerAdmin || $temOverrides;

        return $this->user()?->can($precisaAutorizacao ? 'autorizacao.editar' : 'usuario.editar') ?? false;
    }

    /**
     * Utilizador aluno (já é, ou o pedido o torna aluno): o nome vem do registo do aluno e o email não
     * se usa. Esses campos ficam fora das regras, logo nunca chegam a validated().
     */
    private function eAluno(): bool
    {
        return $this->input('perfil') === Perfil::ALUNO->slug()
            || ($this->route('user')?->roles->contains('nome', Perfil::ALUNO->value) ?? false);
    }

    public function rules(): array
    {
        $identidade = $this->eAluno() ? [] : [
            'name' => 'required|string|max:255',
            'email' => ['nullable', 'email', Rule::unique('users', 'email')->ignore($this->route('user'))],
        ];

        return [
            ...$identidade,
            'perfil' => 'required|in:admin_escola,funcionario,professor,aluno,encarregado',
            'password' => 'nullable|min:6|confirmed',
            'celulas' => 'nullable|array',
            'celulas.*.modulo_id' => 'required_with:celulas|integer|exists:modulos,id',
            'celulas.*.acao_id' => 'required_with:celulas|integer|exists:acoes,id',
            'celulas.*.permitido' => 'required_with:celulas|boolean',
        ];
    }

    public function messages(): array
    {
        return [
            'name.required' => 'O nome é obrigatório.',
            'perfil.required' => 'O perfil é obrigatório.',
            'perfil.in' => 'O perfil indicado é inválido.',
            'email.email' => 'Informe um email válido.',
            'email.unique' => 'Este email já está em uso.',
            'password.min' => 'A senha deve ter no mínimo 6 caracteres.',
            'password.confirmed' => 'A confirmação da senha não coincide.',
        ];
    }
}
