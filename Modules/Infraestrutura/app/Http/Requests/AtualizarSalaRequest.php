<?php

namespace Modules\Infraestrutura\Http\Requests;

use App\Http\Requests\BaseRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Enum;
use Modules\Estabelecimento\Models\Estabelecimento;
use Modules\Infraestrutura\Enums\EstadoSala;
use Modules\Infraestrutura\Enums\TipoSala;

class AtualizarSalaRequest extends BaseRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('infraestrutura.editar') ?? false;
    }

    public function rules(): array
    {
        return [
            'codigo' => [
                'required',
                'string',
                'max:20',
                Rule::unique('salas', 'codigo')
                    ->where(fn ($query) => $query->where('estabelecimento_id', Estabelecimento::current()?->id))
                    ->ignore($this->route('sala')),
            ],
            'nome' => ['required', 'string', 'max:255'],
            'tipo' => ['required', new Enum(TipoSala::class)],
            'capacidade' => ['nullable', 'integer', 'min:1', 'max:500'],
            'localizacao' => ['nullable', 'string', 'max:255'],
            'observacoes' => ['nullable', 'string'],
            'estado' => ['required', new Enum(EstadoSala::class)],
        ];
    }

    public function messages(): array
    {
        return [
            'codigo.required' => 'O código da sala é obrigatório.',
            'codigo.unique' => 'Já existe uma sala com este código neste estabelecimento.',
            'nome.required' => 'O nome da sala é obrigatório.',
            'tipo.required' => 'O tipo de sala é obrigatório.',
            'estado.required' => 'O estado da sala é obrigatório.',
        ];
    }
}
