<?php

namespace Modules\Autenticacao\Service;

use Illuminate\Support\Facades\Storage;
use InvalidArgumentException;
use Modules\Core\Tenancy\CaminhoTenant;
use Modules\Usuario\Models\User;
use Symfony\Component\HttpFoundation\StreamedResponse;

class FotoDoUtilizadorService
{
    /** Endereço da foto com uma versão (muda a cada troca) para o browser não mostrar a antiga em cache; `null` sem foto. */
    public function url(User $user): ?string
    {
        return $user->foto_path ? '/definicoes/foto?v='.substr(md5($user->foto_path), 0, 10) : null;
    }

    public function servir(User $user): StreamedResponse
    {
        $disco = Storage::disk('privado');

        try {
            $caminho = $user->foto_path ? CaminhoTenant::garantir($user->foto_path) : null;
        } catch (InvalidArgumentException) {
            $caminho = null;
        }

        abort_if($caminho === null || ! $disco->exists($caminho), 404);

        return $disco->response($caminho, null, [
            'Cache-Control' => 'private, max-age=0, must-revalidate',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}
