<?php

namespace Modules\Aluno\Database\Seeders;

use Illuminate\Database\Seeder;
use Modules\Aluno\Actions\CriarAlunoAction;
use Modules\Aluno\DTO\AlunoDTO;
use Modules\Aluno\Models\Aluno;
use Modules\Core\Tenancy\TenantContext;
use Modules\Estabelecimento\Models\Estabelecimento;

class AlunoSeeder extends Seeder
{
    public function run(): void
    {
        // O seed corre dentro do contexto de cada escola: usa o tenant e o estabelecimento correntes (nunca o id 1 fixo).
        $tenantId = app(TenantContext::class)->id();
        $estabelecimentoId = (int) Estabelecimento::query()->value('id');

        app(TenantContext::class)->executarComo(
            app(TenantContext::class)->atual(),
            function () use ($tenantId, $estabelecimentoId): void {
                Estabelecimento::query()
                    ->where('id', $estabelecimentoId)
                    ->where('tenant_id', $tenantId)
                    ->firstOrFail();

                $alunos = [
                    [
                        'nome' => 'João Manuel',
                        'email' => 'joao.manuel@example.test',
                        'telefone' => '923000001',
                        'data_nascimento' => '2015-03-12',
                        'sexo' => 1,
                        'numero_identificacao' => 'DEMO-ALU-0001',
                    ],
                    [
                        'nome' => 'Maria José',
                        'email' => 'maria.jose@example.test',
                        'telefone' => '923000002',
                        'data_nascimento' => '2014-07-20',
                        'sexo' => 2,
                        'numero_identificacao' => 'DEMO-ALU-0002',
                    ],
                    [
                        'nome' => 'Pedro António',
                        'email' => 'pedro.antonio@example.test',
                        'telefone' => '923000003',
                        'data_nascimento' => '2016-01-08',
                        'sexo' => 1,
                        'numero_identificacao' => 'DEMO-ALU-0003',
                    ],
                    [
                        'nome' => 'Ana Paula',
                        'email' => 'ana.paula@example.test',
                        'telefone' => '923000004',
                        'data_nascimento' => '2015-11-25',
                        'sexo' => 2,
                        'numero_identificacao' => 'DEMO-ALU-0004',
                    ],
                    [
                        'nome' => 'Carlos Domingos',
                        'email' => 'carlos.domingos@example.test',
                        'telefone' => '923000005',
                        'data_nascimento' => '2014-05-16',
                        'sexo' => 1,
                        'numero_identificacao' => 'DEMO-ALU-0005',
                    ],
                ];

                $criarAluno = app(CriarAlunoAction::class);

                foreach ($alunos as $dados) {
                    // Não recriar alunos que já existem neste estabelecimento.
                    $existe = Aluno::query()
                        ->where('estabelecimento_id', $estabelecimentoId)
                        ->whereHas('dadosPessoa', function ($query) use ($dados) {
                            $query->where('email', $dados['email']);
                        })
                        ->exists();

                    if ($existe) {
                        continue;
                    }

                    $dto = new AlunoDTO(
                        dadosPessoaId: null,
                        nomeCompleto: $dados['nome'],
                        email: $dados['email'],
                        telefone: $dados['telefone'],
                        dataNascimento: $dados['data_nascimento'],
                        sexo: $dados['sexo'],
                        numeroIdentificacao: $dados['numero_identificacao'],
                    );

                    $criarAluno->executar($dto);
                }

                $this->command?->info(
                    'Alunos de demonstração criados ou já existentes.'
                );
            }
        );
    }
}
