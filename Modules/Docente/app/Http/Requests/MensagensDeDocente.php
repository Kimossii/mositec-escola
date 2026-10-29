<?php

namespace Modules\Docente\Http\Requests;

/** Mensagens de validação partilhadas pelos pedidos de criar e editar docente. */
final class MensagensDeDocente
{
    /** @return array<string, string> */
    public static function todas(): array
    {
        return [
            'nome_completo.required' => 'O nome completo é obrigatório.',
            'nome_completo.max' => 'O nome completo não pode ultrapassar 255 caracteres.',
            'numero_identificacao.required' => 'O número de identificação é obrigatório.',
            'numero_identificacao.max' => 'O número de identificação não pode ultrapassar 100 caracteres.',
            'numero_identificacao.unique' => 'Já existe uma pessoa com este número de identificação.',
            'email.email' => 'O email indicado é inválido.',
            'data_nascimento.date' => 'A data de nascimento é inválida.',
            'sexo.in' => 'O sexo indicado é inválido.',
        ];
    }
}
