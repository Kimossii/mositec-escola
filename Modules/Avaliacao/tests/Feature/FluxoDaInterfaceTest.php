<?php

namespace Modules\Avaliacao\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Modules\Avaliacao\Enums\EstadoPauta;
use Modules\Avaliacao\Enums\TipoInstrumento;
use Modules\Avaliacao\Models\AvaliacaoPauta;
use Modules\Avaliacao\Tests\Support\CenarioDeAvaliacao;
use Modules\Permissao\Enums\Perfil;
use Tests\TestCase;

/**
 * Percorre os ecrãs como a interface o faz: parte de uma escola sem nada configurado, usa os pedidos e os campos que o
 * Vue envia e lê as propriedades que o Vue lê. É o teste que apanha o que os testes por Action não vêem.
 */
class FluxoDaInterfaceTest extends TestCase
{
    use CenarioDeAvaliacao;
    use RefreshDatabase;

    private function props(\Illuminate\Testing\TestResponse $resposta): array
    {
        return Assert::fromTestResponse($resposta)->toArray()['props'];
    }

    public function test_o_professor_cria_avaliacoes_lanca_notas_e_ve_a_media_sem_configurar_nada(): void
    {
        $c = $this->cenarioDeAvaliacao();
        $this->inscreverAluno($c, 'Ana');
        $professor = $this->professorAtribuido($c);
        $this->travelTo('2026-03-15 10:00:00');
        $url = '/avaliacao/notas?'.http_build_query(['turma' => $c['turma']->id, 'disciplina' => $c['disciplina']->id]);

        $pauta = $this->props($this->actingAs($professor)->get($url)->assertOk())['pauta'];
        $this->assertSame(10, $pauta['regra']['escala_maxima'], 'a regra por defeito chega ao ecrã');
        $this->assertCount(3, $pauta['periodos']);
        $this->assertSame([], $pauta['instrumentos']);

        foreach ([[TipoInstrumento::MAC, 'Avaliação 1'], [TipoInstrumento::PT, 'Prova do trimestre']] as [$tipo, $nome]) {
            $this->actingAs($professor)->post('/avaliacao/notas/instrumentos', [
                'turma_id' => $c['turma']->id, 'disciplina_id' => $c['disciplina']->id, 'periodo_id' => $pauta['periodo_id'],
                'nome' => $nome, 'tipo' => $tipo->value, 'obrigatorio' => true,
            ])->assertSessionHasNoErrors();
        }

        $pauta = $this->props($this->actingAs($professor)->get($url))['pauta'];
        $this->assertCount(2, $pauta['instrumentos']);
        $inscricao = $pauta['alunos'][0]['inscricao_id'];

        foreach ($pauta['instrumentos'] as $instrumento) {
            $this->actingAs($professor)->post('/avaliacao/notas', [
                'instrumento_id' => $instrumento['id'], 'inscricao_disciplina_id' => $inscricao, 'valor' => '8.5', 'motivo' => null,
            ])->assertSessionHasNoErrors();
        }

        $aluno = $this->props($this->actingAs($professor)->get($url))['pauta']['alunos'][0];
        $this->assertSame(8.5, (float) $aluno['mt']);
        $this->assertSame(8.5, (float) $aluno['mac']);
        $this->assertSame(8.5, (float) $aluno['pt']);
    }

    public function test_o_admin_edita_a_regra_que_o_ecra_mostra_e_define_prazos(): void
    {
        $c = $this->cenarioDeAvaliacao();
        $admin = $this->criarUtilizadorComPerfil(Perfil::ADMIN_ESCOLA, 'admin@example.com');

        $regra = $this->props($this->actingAs($admin)->get('/avaliacao/configuracao')->assertOk())['regras'][0];
        $this->assertSame('3.ª a 5.ª classe', $regra['nome'], 'a regra por defeito já existe quando o ecrã abre');

        // O Vue devolve a regra tal como a recebeu (com as classes ligadas), acrescentando o ano.
        $this->actingAs($admin)->post('/avaliacao/configuracao/regras', [
            ...$regra, 'regra_id' => $regra['id'], 'nota_minima' => '6', 'nome' => 'Primário', 'ano_lectivo_id' => $c['ano']->id, 'nivel_ids' => $regra['nivel_ids'],
        ])->assertSessionHasNoErrors();

        $regra = $this->props($this->actingAs($admin)->get('/avaliacao/configuracao'))['regras'][0];
        $this->assertSame([6.0, 'Primário'], [(float) $regra['nota_minima'], $regra['nome']]);

        $this->actingAs($admin)->post('/avaliacao/configuracao/prazos', [
            'periodo_id' => $c['periodos'][1]->id, 'tipo' => TipoInstrumento::MAC->value, 'data_limite' => '2026-04-10',
        ])->assertSessionHasNoErrors();

        $prazo = $this->props($this->actingAs($admin)->get('/avaliacao/configuracao'))['prazos'][0];
        $this->assertSame('2026-04-10', $prazo['data_limite']);

        $this->actingAs($admin)->post("/avaliacao/configuracao/prazos/{$prazo['id']}/excepcoes", [
            'data_limite' => '2026-04-20', 'motivo' => 'Greve', 'turma_id' => null, 'disciplina_id' => null, 'docente_id' => null,
        ])->assertSessionHasNoErrors();
    }

    public function test_da_nota_a_pauta_aprovada_e_aos_documentos(): void
    {
        $c = $this->cenarioDeAvaliacao();
        $inscricao = $this->inscreverAluno($c, 'Ana');
        $this->notasCompletas($c, $inscricao, [1 => ['6', '7'], 2 => ['6', '7'], 3 => ['8', '9']]);
        $admin = $this->criarUtilizadorComPerfil(Perfil::ADMIN_ESCOLA, 'admin@example.com');
        $turma = $c['turma']->id;

        $pauta = $this->props($this->actingAs($admin)->get("/avaliacao/pautas?turma={$turma}")->assertOk())['pauta'];
        $this->assertSame('provisoria', $pauta['estado']);
        $this->assertSame('Transita', $pauta['alunos'][0]['decisao']['rotulo']);

        $this->actingAs($admin)->post("/avaliacao/pautas/{$turma}/aprovar")->assertSessionHasNoErrors();
        $this->assertSame(EstadoPauta::DEFINITIVA, AvaliacaoPauta::firstOrFail()->estado);

        foreach (['pdf', 'excel'] as $formato) {
            $this->actingAs($admin)->get("/avaliacao/pautas/{$turma}/exportar?formato={$formato}")->assertOk();
            $this->actingAs($admin)->get('/avaliacao/notas/exportar?'.http_build_query(['turma' => $turma, 'disciplina' => $c['disciplina']->id, 'formato' => $formato]))->assertOk();
        }
        $this->actingAs($admin)->get("/avaliacao/pautas/{$turma}/boletins")->assertOk();
        $this->actingAs($admin)->get("/avaliacao/pautas/{$turma}/boletins?matricula={$inscricao->matricula_id}")->assertOk();
    }

    public function test_ano_sem_trimestres_abre_a_mini_pauta_com_explicacao_em_vez_de_vazia(): void
    {
        $c = $this->cenarioDeAvaliacao();
        $this->inscreverAluno($c, 'Ana');
        $admin = $this->criarUtilizadorComPerfil(Perfil::ADMIN_ESCOLA, 'admin@example.com');
        \Modules\AnoLectivo\Models\Periodo::query()->where('ano_lectivo_id', $c['ano']->id)->delete();
        $url = '/avaliacao/notas?'.http_build_query(['turma' => $c['turma']->id, 'disciplina' => $c['disciplina']->id]);

        $pauta = $this->props($this->actingAs($admin)->get($url)->assertOk())['pauta'];
        $this->assertTrue($pauta['sem_periodos']);
        $this->assertSame([], $pauta['prazos']);
    }

    public function test_anular_e_eliminar_pelo_ecra(): void
    {
        $c = $this->cenarioDeAvaliacao();
        $inscricao = $this->inscreverAluno($c, 'Ana');
        $professor = $this->professorAtribuido($c);
        $this->travelTo('2026-03-15 10:00:00');
        $url = '/avaliacao/notas?'.http_build_query(['turma' => $c['turma']->id, 'disciplina' => $c['disciplina']->id]);
        $periodo = $this->props($this->actingAs($professor)->get($url))['pauta']['periodo_id'];

        $this->actingAs($professor)->post('/avaliacao/notas/instrumentos', [
            'turma_id' => $c['turma']->id, 'disciplina_id' => $c['disciplina']->id, 'periodo_id' => $periodo, 'nome' => 'Teste', 'tipo' => TipoInstrumento::MAC->value, 'obrigatorio' => true,
        ])->assertSessionHasNoErrors();
        $instrumento = $this->props($this->actingAs($professor)->get($url))['pauta']['instrumentos'][0]['id'];
        $this->actingAs($professor)->post('/avaliacao/notas', ['instrumento_id' => $instrumento, 'inscricao_disciplina_id' => $inscricao->id, 'valor' => '7', 'motivo' => null])->assertSessionHasNoErrors();

        $nota = $this->props($this->actingAs($professor)->get($url))['pauta']['alunos'][0]['notas'][$instrumento];
        $this->actingAs($professor)->post("/avaliacao/notas/{$nota['id']}/anular", ['motivo' => 'Lançada por engano', 'conta_como_zero' => false])->assertSessionHasNoErrors();

        // Com notas (mesmo anuladas) a avaliação não se elimina: o histórico fica.
        $this->actingAs($professor)->delete("/avaliacao/notas/instrumentos/{$instrumento}")->assertSessionHasErrors();

        $this->actingAs($professor)->post('/avaliacao/notas/instrumentos', [
            'turma_id' => $c['turma']->id, 'disciplina_id' => $c['disciplina']->id, 'periodo_id' => $periodo, 'nome' => 'Vazia', 'tipo' => TipoInstrumento::PT->value, 'obrigatorio' => false,
        ])->assertSessionHasNoErrors();
        $vazia = collect($this->props($this->actingAs($professor)->get($url))['pauta']['instrumentos'])->firstWhere('nome', 'Vazia')['id'];
        $this->actingAs($professor)->delete("/avaliacao/notas/instrumentos/{$vazia}")->assertSessionHasNoErrors();
        $this->assertCount(1, $this->props($this->actingAs($professor)->get($url))['pauta']['instrumentos']);
    }

    public function test_a_avaliacao_sem_nome_recebe_um_automatico_e_so_ha_uma_prova(): void
    {
        $c = $this->cenarioDeAvaliacao();
        $professor = $this->professorAtribuido($c);
        $this->travelTo('2026-03-15 10:00:00');
        $url = '/avaliacao/notas?'.http_build_query(['turma' => $c['turma']->id, 'disciplina' => $c['disciplina']->id]);
        $periodo = $this->props($this->actingAs($professor)->get($url))['pauta']['periodo_id'];
        $dados = ['turma_id' => $c['turma']->id, 'disciplina_id' => $c['disciplina']->id, 'periodo_id' => $periodo, 'obrigatorio' => true];

        foreach ([TipoInstrumento::MAC, TipoInstrumento::MAC, TipoInstrumento::PT] as $tipo) {
            $this->actingAs($professor)->post('/avaliacao/notas/instrumentos', [...$dados, 'tipo' => $tipo->value, 'nome' => ''])->assertSessionHasNoErrors();
        }
        $this->actingAs($professor)->post('/avaliacao/notas/instrumentos', [...$dados, 'tipo' => TipoInstrumento::PT->value])->assertSessionHasErrors('tipo');

        $nomes = array_column($this->props($this->actingAs($professor)->get($url))['pauta']['instrumentos'], 'nome');
        $this->assertSame(['Avaliação 1', 'Avaliação 2', 'Prova trimestral'], $nomes);
    }

    public function test_guardar_notas_grava_tudo_de_uma_vez_e_nada_se_uma_for_recusada(): void
    {
        $c = $this->cenarioDeAvaliacao();
        $ana = $this->inscreverAluno($c, 'Ana');
        $rui = $this->inscreverNaDisciplina($ana, $c);
        $professor = $this->professorAtribuido($c);
        $this->travelTo('2026-03-15 10:00:00');
        $url = '/avaliacao/notas?'.http_build_query(['turma' => $c['turma']->id, 'disciplina' => $c['disciplina']->id]);
        $periodo = $this->props($this->actingAs($professor)->get($url))['pauta']['periodo_id'];
        $this->actingAs($professor)->post('/avaliacao/notas/instrumentos', [
            'turma_id' => $c['turma']->id, 'disciplina_id' => $c['disciplina']->id, 'periodo_id' => $periodo, 'tipo' => TipoInstrumento::MAC->value, 'nome' => '', 'obrigatorio' => true,
        ])->assertSessionHasNoErrors();
        $instrumento = $this->props($this->actingAs($professor)->get($url))['pauta']['instrumentos'][0]['id'];
        $dados = fn (array $notas) => ['turma_id' => $c['turma']->id, 'disciplina_id' => $c['disciplina']->id, 'notas' => $notas];
        $nota = fn ($inscricao, $valor) => ['instrumento_id' => $instrumento, 'inscricao_disciplina_id' => $inscricao->id, 'valor' => $valor, 'motivo' => null];

        // Uma nota acima da escala (0–10) recusa o lote inteiro e diz de que aluno é.
        $this->actingAs($professor)->post('/avaliacao/notas/lote', $dados([$nota($ana, '7'), $nota($rui, '15')]))->assertSessionHasErrors('lote');
        $this->assertSame(0, \Modules\Avaliacao\Models\AvaliacaoNota::count());

        $this->actingAs($professor)->post('/avaliacao/notas/lote', $dados([$nota($ana, '7'), $nota($rui, '9')]))->assertSessionHasNoErrors();
        $this->assertSame(2, \Modules\Avaliacao\Models\AvaliacaoNota::count());

        $outro = $this->criarUtilizadorComPerfil(Perfil::PROFESSOR, 'outro@example.com');
        $this->actingAs($outro)->post('/avaliacao/notas/lote', $dados([$nota($ana, '3')]))->assertForbidden();
    }

    public function test_o_admin_cria_uma_regra_nova_passa_uma_classe_para_ela_e_elimina_a_que_ficou_vazia(): void
    {
        $c = $this->cenarioDeAvaliacao();
        $quarta = \Modules\Turma\Models\NivelAcademico::create(['estabelecimento_id' => $c['nivel']->estabelecimento_id, 'codigo' => 'PRI_4', 'nome' => '4.ª Classe', 'ordem' => 4, 'etapa_ensino' => 3]);
        $admin = $this->criarUtilizadorComPerfil(Perfil::ADMIN_ESCOLA, 'admin@example.com');
        $props = fn () => $this->props($this->actingAs($admin)->get('/avaliacao/configuracao')->assertOk());

        $regra = $props()['regras'][0];

        // Nova regra só para a 4.ª (como o ecrã envia: regra_id nulo, o nome e as classes), tirando-a da regra onde estava.
        $this->actingAs($admin)->post('/avaliacao/configuracao/regras', [
            ...$regra, 'regra_id' => null, 'nova' => true, 'nome' => 'Só a 4.ª', 'nivel_ids' => [$quarta->id], 'ano_lectivo_id' => $c['ano']->id,
        ])->assertSessionHasNoErrors();

        $regras = collect($props()['regras'])->keyBy('nome');
        $this->assertSame([$quarta->id], $regras['Só a 4.ª']['nivel_ids']);
        $this->assertSame([$c['nivel']->id], $regras['3.ª a 5.ª classe']['nivel_ids']);

        // Passa a 4.ª de volta e a regra nova fica vazia: já se pode eliminar.
        $this->actingAs($admin)->post('/avaliacao/configuracao/regras', [
            ...$regras['3.ª a 5.ª classe'], 'regra_id' => $regras['3.ª a 5.ª classe']['id'], 'nivel_ids' => [$c['nivel']->id, $quarta->id], 'ano_lectivo_id' => $c['ano']->id,
        ])->assertSessionHasNoErrors();
        $this->actingAs($admin)->delete('/avaliacao/configuracao/regras/'.$regras['Só a 4.ª']['id'])->assertSessionHasNoErrors();

        $this->assertSame(['3.ª a 5.ª classe'], array_column($props()['regras'], 'nome'));
    }

    public function test_o_botao_cria_as_regras_por_defeito_que_faltam_sem_mexer_nas_que_existem(): void
    {
        $c = $this->cenarioDeAvaliacao();
        $admin = $this->criarUtilizadorComPerfil(Perfil::ADMIN_ESCOLA, 'admin@example.com');
        $this->actingAs($admin)->get('/avaliacao/configuracao')->assertOk(); // o ano arranca com a regra da 3.ª
        $estabelecimento = $c['nivel']->estabelecimento_id;
        $iniciacao = \Modules\Turma\Models\NivelAcademico::create(['estabelecimento_id' => $estabelecimento, 'codigo' => 'PRE_I', 'nome' => 'Iniciação', 'ordem' => 1, 'etapa_ensino' => 2]);
        $decima = \Modules\Turma\Models\NivelAcademico::create(['estabelecimento_id' => $estabelecimento, 'codigo' => 'SEC_10', 'nome' => '10.ª Classe', 'ordem' => 20, 'etapa_ensino' => 4]);
        $antes = collect($this->props($this->actingAs($admin)->get('/avaliacao/configuracao'))['regras'])->firstWhere('nome', '3.ª a 5.ª classe');

        $this->actingAs($admin)->post('/avaliacao/configuracao/regras/defeito', ['ano_lectivo_id' => $c['ano']->id])->assertSessionHasNoErrors();

        $pagina = $this->props($this->actingAs($admin)->get('/avaliacao/configuracao'));
        $regras = collect($pagina['regras'])->keyBy('nome');
        $this->assertSame([$iniciacao->id], $regras['Creche e Pré-Escolar']['nivel_ids']);
        $this->assertTrue($regras['Creche e Pré-Escolar']['qualitativa']);
        $this->assertSame($antes, $regras['3.ª a 5.ª classe'], 'a regra que já existia fica igual');
        $sem = collect($pagina['niveis'])->firstWhere('id', $decima->id);
        $this->assertTrue($sem['sem_regra'] && ! $sem['tem_regra_padrao'], 'a 10.ª fica sem regra e sem alarme');
    }
}
