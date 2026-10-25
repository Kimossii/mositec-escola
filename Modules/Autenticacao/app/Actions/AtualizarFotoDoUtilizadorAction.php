<?php

namespace Modules\Autenticacao\Actions;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use InvalidArgumentException;
use Modules\Core\Tenancy\CaminhoTenant;
use Modules\Usuario\Models\User;
use Modules\Core\Traits\LogService;

class AtualizarFotoDoUtilizadorAction
{
    use LogService;

    /** Regista a operação (conclusão ou falha) e delega em `executarSemRegisto()`. */
    public function executar(User $user, UploadedFile $foto): void
    {
        $this->comLog('Atualizar foto do utilizador', $this->contextoDeArgumentos(func_get_args(), 'executar'), fn () => $this->executarSemRegisto($user, $foto));
    }

    /**
     * Guarda a nova foto do utilizador e elimina a anterior, para não acumular ficheiros. A antiga só se apaga
     * depois de a nova estar gravada e referenciada; se a gravação falhar, a foto actual mantém-se.
     */
    private function executarSemRegisto(User $user, UploadedFile $foto): void
    {
        $anterior = $user->foto_path;

        $novo = $foto->store(CaminhoTenant::para('utilizadores/fotos'), 'privado');
        abort_if($novo === false, 500, 'Não foi possível guardar a foto.');

        try {
            $user->forceFill(['foto_path' => $novo])->save();
        } catch (\Throwable $erro) {
            Storage::disk('privado')->delete($novo);

            throw $erro;
        }

        self::apagar($anterior);
    }

    /** Só apaga se o caminho pertencer mesmo ao tenant corrente (nunca um caminho adulterado). */
    public static function apagar(?string $caminho): void
    {
        if ($caminho === null || $caminho === '') {
            return;
        }

        try {
            Storage::disk('privado')->delete(CaminhoTenant::garantir($caminho));
        } catch (InvalidArgumentException) {
            // Fora do tenant: não se apaga nada.
        }
    }
}
