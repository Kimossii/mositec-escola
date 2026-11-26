<?php

namespace Modules\Core\Tests\Unit;

use Modules\Core\Comunicacao\Email\EmailDisponivel;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class EmailDisponivelTest extends TestCase
{
    /** @return array<string, array{0: array<string, mixed>, 1: bool}> */
    public static function cenarios(): array
    {
        return [
            'log nao conta' => [['mail.default' => 'log'], false],
            'array nao conta' => [['mail.default' => 'array'], false],
            'mailer vazio' => [['mail.default' => ''], false],
            'mailer desconhecido' => [['mail.default' => 'inexistente'], false],
            'resend com chave' => [['mail.default' => 'resend', 'services.resend.key' => 're_abc'], true],
            'resend sem chave' => [['mail.default' => 'resend', 'services.resend.key' => null], false],
            'resend com chave so espacos' => [['mail.default' => 'resend', 'services.resend.key' => '   '], false],
            'smtp com servidor' => [['mail.default' => 'smtp', 'mail.mailers.smtp.host' => 'smtp.exemplo.ao'], true],
            'smtp sem servidor' => [['mail.default' => 'smtp', 'mail.mailers.smtp.host' => ''], false],
        ];
    }

    /** @param  array<string, mixed>  $config */
    #[DataProvider('cenarios')]
    public function test_so_conta_um_canal_realmente_configurado(array $config, bool $esperado): void
    {
        config($config);

        $this->assertSame($esperado, (new EmailDisponivel)->disponivel());
    }
}
