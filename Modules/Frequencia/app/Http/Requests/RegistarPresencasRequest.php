<?php

namespace Modules\Frequencia\Http\Requests;

use App\Http\Requests\BaseRequest;
use Illuminate\Validation\Rule;
use Modules\Frequencia\Enums\EstadoPresenca;

class RegistarPresencasRequest extends BaseRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('frequencia.editar') ?? false;
    }

    public function rules(): array
    {
        return [
            'presencas' => ['required', 'array', 'min:1'],
            'presencas.*.matricula_id' => ['required', 'integer'],
            'presencas.*.estado' => ['required', Rule::enum(EstadoPresenca::class)],
            'presencas.*.observacao' => ['nullable', 'string', 'max:500'],
            'motivo' => ['nullable', 'string', 'max:500'],
        ];
    }

    public function messages(): array
    {
        return [
            'presencas.required' => 'Indique as presenças dos alunos.',
            'presencas.min' => 'Indique as presenças dos alunos.',
            'presencas.*.matricula_id.required' => 'O aluno é obrigatório.',
            'presencas.*.estado.required' => 'O estado da presença é obrigatório.',
            'presencas.*.estado.enum' => 'O estado da presença é inválido.',
            'presencas.*.observacao.max' => 'A observação não pode ter mais de 500 caracteres.',
            'motivo.max' => 'O motivo não pode ter mais de 500 caracteres.',
        ];
    }
}
