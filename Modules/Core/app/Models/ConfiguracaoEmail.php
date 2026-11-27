<?php

namespace Modules\Core\Models;

use Illuminate\Database\Eloquent\Model;
use Modules\Core\Comunicacao\Email\EncriptacaoSmtp;
use Modules\Core\Comunicacao\Email\EstadoConfiguracaoEmail;
use Modules\Core\Tenancy\PertenceAoTenant;
use Modules\Core\Traits\RegistaAutoria;

/** Servidor SMTP próprio da escola. Só se escreve pelas Actions de Core\Actions\Email; a senha nunca sai daqui. */
class ConfiguracaoEmail extends Model
{
    use PertenceAoTenant, RegistaAutoria;

    protected $table = 'configuracoes_email';

    protected $fillable = [
        'servidor', 'porta', 'encriptacao', 'utilizador', 'senha', 'validar_certificado', 'dominio_ehlo',
        'tempo_limite', 'remetente_nome', 'remetente_endereco', 'responder_para',
        'estado', 'activo', 'verificada_em', 'ultimo_erro',
    ];

    protected $hidden = ['senha'];

    protected $casts = [
        'porta' => 'integer',
        'encriptacao' => EncriptacaoSmtp::class,
        'estado' => EstadoConfiguracaoEmail::class,
        'senha' => 'encrypted',
        'activo' => 'boolean',
        'bloqueada_pela_plataforma' => 'boolean',
        'validar_certificado' => 'boolean',
        'tempo_limite' => 'integer',
        'verificada_em' => 'datetime',
    ];

    protected static function booted(): void
    {
        static::saving(function (ConfiguracaoEmail $c) {
            $c->estado ??= EstadoConfiguracaoEmail::POR_VERIFICAR;
            $c->estado_descricao = $c->estado->label();

            if ($c->encriptacao !== null) {
                $c->encriptacao_descricao = $c->encriptacao->label();
            }
        });
    }

    /** Verificada, activa e não bloqueada pela Plataforma. */
    public function estaEmUso(): bool
    {
        return $this->activo && ! $this->bloqueada_pela_plataforma && $this->estado === EstadoConfiguracaoEmail::VERIFICADO;
    }

    /** Impressão dos campos que definem a ligação e o remetente (inclui a senha), para detectar alterações durante um teste. */
    public function impressaoDaLigacao(): string
    {
        return hash('sha256', json_encode([
            $this->servidor, $this->porta, $this->encriptacao?->value, $this->utilizador, $this->senha, $this->validar_certificado,
            $this->dominio_ehlo, $this->tempo_limite, $this->remetente_nome, $this->remetente_endereco, $this->responder_para,
        ]));
    }
}
