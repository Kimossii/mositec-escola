# Inscrição em Disciplinas — Plano de Implementação

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Criar o vínculo aluno↔disciplina que falta entre `Matricula` (vínculo a uma Turma) e `PlanoCurricular` (o currículo de um Curso/Nível) — uma nova entidade `InscricaoDisciplina`, com inscrição automática em massa para Ensino Geral/Técnico (toda a turma segue o mesmo currículo) e inscrição manual explícita para Ensino Superior (electivas, cadeiras em atraso de anos anteriores). Fora do Ensino Superior, um `PlanoCurricular` confirmado passa a ser pré-condição obrigatória para criar a matrícula — sem best-effort.

**Architecture:** Vive dentro do módulo `Modules/Matricula` (não é módulo novo — é extensão directa da Matrícula, evita over-engineering). Segue byte-a-byte o padrão já estabelecido nesta sessão para o próprio módulo Matrícula: Migration → Enum (`estado` int-backed, `label()`, `podeTransitarPara()`, `eTerminal()`) → Model (sem `RegistaAutoria`/`SincronizaEstadoDescricao` — cast directo do enum, como `Matricula` e `AlunoEnquadramentoAcademico`) → Action (`executar()`, `ValidationException::withMessages()` para regras de negócio) → Service (`Gestao*Service` agrega Actions) → FormRequest (`extends App\Http\Requests\BaseRequest`) → Controller fino (Inertia, `$this->authorize()`) → `routes/web.php` (`can:matricula.<acao>` — reaproveita as permissões já existentes do módulo, sem `Modulo`/`Acao` novos) → Tests Feature (`RefreshDatabase`, `app(Action::class)` para resolver dependências injectadas).

**Tech Stack:** Laravel + `nwidart/laravel-modules`, Postgres (dev) / SQLite em memória (testes), PHPUnit (`RefreshDatabase`).

**Spec:** Não há ficheiro de spec em disco — este plano incorpora a análise de domínio feita ao longo da sessão de desenvolvimento do módulo Matrícula (decisão de manter `Matricula` sem `curso_id`/disciplinas, `PlanoCurricular` já existente como fonte do currículo, distinção Ensino Geral/Técnico vs Superior via `TipoEnsinoEnum`).

## Global Constraints

- PT-PT em toda a interface, mensagens de validação e labels.
- **Só backend nesta fase.** Frontend (páginas Vue, listagem de disciplinas na página do Aluno/Matrícula) fica para um plano seguinte — não incluído aqui.
- **Sem notas/avaliação/frequência nesta fase.** `InscricaoDisciplina` só regista o vínculo e o seu estado (Inscrita/Concluída/Reprovada/Desistida), não `nota_final` nem faltas.
- Não criar `Modulo`/`Acao` novos em `Modules\Permissao` — reaproveitar `matricula.ver/criar/editar/eliminar`, já concedidas ao `ADMIN_ESCOLA`.
- Não alterar `Matricula`, `ValidadorMatriculaService`, nem qualquer teste já existente do módulo Matrícula — excepto duas chamadas aditivas em `CriarMatriculaAction` (Task 4): uma validação antes de criar a Matrícula (`garantirPlanoCurricularConfirmado`) e um hook depois (`executar`), ambas em `InscreverDisciplinasAutomaticamenteAction`.
- Não implementar edição/eliminação em cascata quando uma Matrícula é eliminada/editada — está fora de escopo, documentado como risco conhecido na Task 1.
- **Sem best-effort.** Fora do Ensino Superior, criar uma Matrícula exige um `PlanoCurricular` confirmado (via `PlanoCurricularAnoLectivo`) para o Curso/Nível/Ano Lectivo da turma — sem isso, a criação é recusada com `ValidationException` em `turma_id` (Task 3/4). No Ensino Superior não há esta exigência, porque a inscrição em disciplinas aí é sempre manual (electivas, cadeiras em atraso).
- Não criar commits automaticamente.

---

## Decisões arquitecturais tomadas nesta análise (a validar antes de codar)

1. **`InscricaoDisciplina` pertence a `Matricula`, não a `Aluno` directamente.** Um aluno pode ter duas matrículas em simultâneo (ex.: duas licenciaturas no Superior, ou "cadeira em atraso" — matrícula do ano anterior ainda Activa/Pendente); cada inscrição em disciplina tem de saber *sob qual matrícula* está a ser feita, para relatórios e para a UI futura conseguir agrupar "disciplinas deste ano" vs "disciplinas em atraso".
2. **`plano_curricular_disciplina_id` é obrigatório, nunca `disciplina_id` directo.** `PlanoCurricularDisciplina` já carrega `carga_horaria`/`creditos`/`componente`/`tipo`/`obrigatoria` — apontar para lá em vez de para `Disciplina` evita duplicar esses dados e mantém a inscrição ligada a *qual versão do currículo* o aluno seguiu (importante se o plano curricular for revisto no futuro).
3. **Duplicado é validado pelo aluno, não pela matrícula.** Um aluno não pode ter duas `InscricaoDisciplina` em estado `Inscrita` para a mesma `Disciplina` (via `plano_curricular_disciplina.disciplina_id`) em simultâneo — mesmo que uma seja da matrícula deste ano e outra de uma matrícula antiga ainda aberta. Resolver via `whereHas('matricula', fn ($q) => $q->where('aluno_id', ...))`.
4. **Inscrição automática só fora do Ensino Superior.** Reaproveita `Estabelecimento::current()?->tipo_ensino` (já usado em `ValidadorMatriculaService::garantirEnquadramentoAcademico`) — exactamente a mesma distinção já decidida para o enquadramento académico: no Superior a escolha é sempre manual (electivas, cadeiras específicas); fora dele, a turma inteira segue o mesmo currículo, logo a inscrição é automática ao criar a matrícula — e, fora dele, essa mesma turma tem de ter currículo confirmado para poder receber matrículas (Decisão 10).
5. **Resolução do `PlanoCurricular` da Turma:** `PlanoCurricular::where('curso_id', $turma->curso_id)->where('nivel_academico_id', $turma->nivel_academico_id)->whereHas('anosLectivos', fn ($q) => $q->where('ano_lectivo_id', $turma->ano_lectivo_id)->where('estado', 1))->first()` — método único (`resolverPlanoCurricular()`, Task 3), reaproveitado tanto pela validação (`garantirPlanoCurricularConfirmado()`) como pela inscrição (`executar()`). Dentro de `executar()`, um `null` continua a devolver `0` sem excepção — é só defensivo, porque fora do Superior a validação já bloqueou esse estado antes de a Matrícula existir (Decisão 10).
6. **FK deletes:** `matricula_id` e `plano_curricular_disciplina_id` usam `restrictOnDelete()` — replica a decisão já tomada para `plano_curricular_anos_lectivos` (registo histórico, não deve desaparecer por cascata). `InscricaoDisciplina` usa `SoftDeletes`, tal como `Matricula`.
7. **Sem `Modulo`/`Acao` novos.** `matricula.criar` cobre criar inscrição (manual ou automática), `matricula.editar` cobre alterar estado, `matricula.eliminar` cobre eliminar — mesma filosofia "uma permissão por módulo inteiro" já usada em `PlanoCurricular`/`Infraestrutura`.
8. **Estado machine idêntica à de `Matricula`:** `INSCRITA=1, CONCLUIDA=2, REPROVADA=3, DESISTIDA=4`; `INSCRITA` transita para qualquer um dos três terminais; terminais não transitam. `REPROVADA` não cria automaticamente uma nova inscrição no ano seguinte — isso fica para quando existir "renovação de inscrição em disciplina", fora de escopo aqui.
9. **A decisão "auto-inscrever ou não" vive só dentro de `InscreverDisciplinasAutomaticamenteAction`, nunca em quem a chama.** `CriarMatriculaAction` (Task 4) chama-a sempre, incondicionalmente — não sabe nada sobre `TipoEnsinoEnum` nem tem `if`. Se a regra mudar no futuro (outra excepção além do Superior, uma flag por Curso, etc.), há um único sítio a alterar. Isto generaliza a Decisão 4: o "quando" da automação é um detalhe de implementação da Action, não um contrato entre módulos.
10. **Sem best-effort: currículo confirmado é pré-condição para matricular fora do Ensino Superior.** `InscreverDisciplinasAutomaticamenteAction::garantirPlanoCurricularConfirmado(Turma $turma): void` (Task 3) lança `ValidationException` se não houver `PlanoCurricular` confirmado e o estabelecimento não for `TipoEnsinoEnum::UNIVERSITARIO`; `CriarMatriculaAction` (Task 4) chama-a antes de criar a Matrícula. Motivo: uma matrícula sem currículo definido deixaria o aluno numa turma sem a estrutura curricular necessária. Mesma filosofia da Decisão 9 — a mesma Action que decide "quando automatizar" também decide "quando é obrigatório ter currículo", porque é a mesma regra de `tipo_ensino` a determinar as duas coisas; não fica espalhada por `ValidadorMatriculaService` nem por `CriarMatriculaAction`.

---

## Task 1 — Migração, Enum e Model

**Files:**
- Create: `Modules/Matricula/database/migrations/2026_09_16_090000_create_inscricoes_disciplinas_table.php`
- Create: `Modules/Matricula/app/Enums/EstadoInscricaoDisciplinaEnum.php`
- Create: `Modules/Matricula/app/Models/InscricaoDisciplina.php`
- Modify: `Modules/Matricula/app/Models/Matricula.php`
- Create: `Modules/Matricula/tests/Unit/EstadoInscricaoDisciplinaEnumTest.php`
- Create: `Modules/Matricula/tests/Feature/InscricaoDisciplinaModelTest.php`

**Interfaces:**
- Produces: `Modules\Matricula\Enums\EstadoInscricaoDisciplinaEnum` (casos `INSCRITA`, `CONCLUIDA`, `REPROVADA`, `DESISTIDA`; métodos `label(): string`, `podeTransitarPara(self $estado): bool`, `eTerminal(): bool`).
- Produces: `Modules\Matricula\Models\InscricaoDisciplina` — colunas `matricula_id`, `plano_curricular_disciplina_id`, `estado` (cast ao enum), `data_inscricao` (date), `data_conclusao` (nullable date), `observacoes`, `criado_por`, `editado_por`; relações `matricula(): BelongsTo`, `planoCurricularDisciplina(): BelongsTo`, `criadoPor(): BelongsTo`, `editadoPor(): BelongsTo`.
- Produces: `Matricula::inscricoesDisciplinas(): HasMany`.

- [ ] **Step 1: Migração**

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('inscricoes_disciplinas', function (Blueprint $table) {
            $table->id();
            $table->foreignId('matricula_id')->constrained('matriculas')->restrictOnDelete();
            $table->foreignId('plano_curricular_disciplina_id')->constrained('plano_curricular_disciplinas')->restrictOnDelete();
            $table->date('data_inscricao');
            $table->date('data_conclusao')->nullable();
            $table->unsignedTinyInteger('estado');
            $table->text('observacoes')->nullable();
            $table->foreignId('criado_por')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('editado_por')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->index('matricula_id');
            $table->index('plano_curricular_disciplina_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('inscricoes_disciplinas');
    }
};
```

- [ ] **Step 2: Correr a migração**

Run: `php artisan migrate --path=Modules/Matricula/database/migrations`
Expected: `2026_09_16_090000_create_inscricoes_disciplinas_table ... DONE`

- [ ] **Step 3: Enum**

```php
<?php

namespace Modules\Matricula\Enums;

enum EstadoInscricaoDisciplinaEnum: int
{
    case INSCRITA = 1;
    case CONCLUIDA = 2;
    case REPROVADA = 3;
    case DESISTIDA = 4;

    public function label(): string
    {
        return match ($this) {
            self::INSCRITA => 'Inscrita',
            self::CONCLUIDA => 'Concluída',
            self::REPROVADA => 'Reprovada',
            self::DESISTIDA => 'Desistida',
        };
    }

    public function podeTransitarPara(self $estado): bool
    {
        return match ($this) {
            self::INSCRITA => in_array($estado, [
                self::CONCLUIDA,
                self::REPROVADA,
                self::DESISTIDA,
            ], true),

            self::CONCLUIDA,
            self::REPROVADA,
            self::DESISTIDA => false,
        };
    }

    public function eTerminal(): bool
    {
        return match ($this) {
            self::CONCLUIDA, self::REPROVADA, self::DESISTIDA => true,
            self::INSCRITA => false,
        };
    }
}
```

- [ ] **Step 4: Teste unitário do enum**

```php
<?php

namespace Modules\Matricula\Tests\Unit;

use Modules\Matricula\Enums\EstadoInscricaoDisciplinaEnum;
use Tests\TestCase;

class EstadoInscricaoDisciplinaEnumTest extends TestCase
{
    public function test_inscrita_pode_transitar_para_os_tres_terminais(): void
    {
        $this->assertTrue(EstadoInscricaoDisciplinaEnum::INSCRITA->podeTransitarPara(EstadoInscricaoDisciplinaEnum::CONCLUIDA));
        $this->assertTrue(EstadoInscricaoDisciplinaEnum::INSCRITA->podeTransitarPara(EstadoInscricaoDisciplinaEnum::REPROVADA));
        $this->assertTrue(EstadoInscricaoDisciplinaEnum::INSCRITA->podeTransitarPara(EstadoInscricaoDisciplinaEnum::DESISTIDA));
    }

    public function test_estados_terminais_nao_transitam(): void
    {
        $this->assertFalse(EstadoInscricaoDisciplinaEnum::CONCLUIDA->podeTransitarPara(EstadoInscricaoDisciplinaEnum::INSCRITA));
        $this->assertTrue(EstadoInscricaoDisciplinaEnum::CONCLUIDA->eTerminal());
        $this->assertTrue(EstadoInscricaoDisciplinaEnum::REPROVADA->eTerminal());
        $this->assertTrue(EstadoInscricaoDisciplinaEnum::DESISTIDA->eTerminal());
        $this->assertFalse(EstadoInscricaoDisciplinaEnum::INSCRITA->eTerminal());
    }
}
```

- [ ] **Step 5: Run do teste unitário para confirmar que passa**

Run: `php artisan test Modules/Matricula/tests/Unit/EstadoInscricaoDisciplinaEnumTest.php`
Expected: `2 passed`

- [ ] **Step 6: Model**

```php
<?php

namespace Modules\Matricula\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Modules\Matricula\Enums\EstadoInscricaoDisciplinaEnum;
use Modules\PlanoCurricular\Models\PlanoCurricularDisciplina;
use Modules\Usuario\Models\User;

class InscricaoDisciplina extends Model
{
    use SoftDeletes;

    protected $table = 'inscricoes_disciplinas';

    protected $fillable = [
        'matricula_id',
        'plano_curricular_disciplina_id',
        'data_inscricao',
        'data_conclusao',
        'estado',
        'observacoes',
        'criado_por',
        'editado_por',
    ];

    protected $casts = [
        'data_inscricao' => 'date',
        'data_conclusao' => 'date',
        'estado' => EstadoInscricaoDisciplinaEnum::class,
    ];

    public function matricula(): BelongsTo
    {
        return $this->belongsTo(Matricula::class);
    }

    public function planoCurricularDisciplina(): BelongsTo
    {
        return $this->belongsTo(PlanoCurricularDisciplina::class);
    }

    public function criadoPor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'criado_por');
    }

    public function editadoPor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'editado_por');
    }
}
```

- [ ] **Step 7: Adicionar a relação inversa em `Matricula`**

Modify `Modules/Matricula/app/Models/Matricula.php` — adicionar o import `use Illuminate\Database\Eloquent\Relations\HasMany;` (se ainda não existir) e, a seguir ao método `historico()`, adicionar:

```php
    public function inscricoesDisciplinas(): HasMany
    {
        return $this->hasMany(InscricaoDisciplina::class);
    }
```

- [ ] **Step 8: Teste Feature do model**

```php
<?php

namespace Modules\Matricula\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Aluno\Actions\CriarEnquadramentoAcademicoAlunoAction;
use Modules\Aluno\Models\Aluno;
use Modules\AnoLectivo\Enums\EstadoAnoLectivo;
use Modules\AnoLectivo\Models\AnoLectivo;
use Modules\Curso\Models\Curso;
use Modules\Estabelecimento\Enums\TipoEstabelecimentoEnum;
use Modules\Estabelecimento\Models\Estabelecimento;
use Modules\Matricula\Actions\CriarMatriculaAction;
use Modules\Matricula\DTO\MatriculaDTO;
use Modules\Matricula\Enums\EstadoInscricaoDisciplinaEnum;
use Modules\Matricula\Models\InscricaoDisciplina;
use Modules\PlanoCurricular\Models\PlanoCurricular;
use Modules\PlanoCurricular\Models\PlanoCurricularDisciplina;
use Modules\Disciplina\Models\Disciplina;
use Modules\Turma\Models\NivelAcademico;
use Modules\Turma\Models\Turma;
use Modules\Usuario\Models\DadosPessoal;
use Tests\TestCase;

class InscricaoDisciplinaModelTest extends TestCase
{
    use RefreshDatabase;

    public function test_cria_inscricao_e_carrega_relacoes(): void
    {
        $estabelecimento = Estabelecimento::create(['nome' => 'Escola Teste', 'tipo' => TipoEstabelecimentoEnum::PUBLICO->value, 'is_active' => true]);
        $anoLectivo = AnoLectivo::create(['estabelecimento_id' => $estabelecimento->id, 'nome' => '2026', 'data_inicio' => '2026-01-01', 'data_fim' => '2026-12-31', 'estado' => EstadoAnoLectivo::ATIVO]);
        $nivel = NivelAcademico::create(['estabelecimento_id' => $estabelecimento->id, 'codigo' => '1C', 'nome' => '1ª Classe', 'ordem' => 1, 'etapa_ensino' => 1]);
        $turma = Turma::create(['ano_lectivo_id' => $anoLectivo->id, 'nivel_academico_id' => $nivel->id, 'codigo' => 'T1', 'nome' => 'Turma 1']);
        $pessoa = DadosPessoal::create(['nome_completo' => 'Aluno Teste', 'numero_identificacao' => 'BI0001', 'tipo_pessoa' => DadosPessoal::TIPO_ALUNO]);
        $aluno = Aluno::create(['estabelecimento_id' => $estabelecimento->id, 'dados_pessoa_id' => $pessoa->id, 'numero_matricula' => '2026-0001']);
        (new CriarEnquadramentoAcademicoAlunoAction())->executar($aluno, nivelAcademicoId: $nivel->id);
        $matricula = app(CriarMatriculaAction::class)->executar($aluno, new MatriculaDTO(turmaId: $turma->id, anoLectivoId: $anoLectivo->id, dataMatricula: null, estado: null, observacoes: null));

        $plano = PlanoCurricular::create(['estabelecimento_id' => $estabelecimento->id, 'nivel_academico_id' => $nivel->id, 'codigo' => 'PL1', 'nome' => 'Plano 1']);
        $disciplina = Disciplina::create(['estabelecimento_id' => $estabelecimento->id, 'codigo' => 'MAT1', 'nome' => 'Matemática I']);
        $planoDisciplina = PlanoCurricularDisciplina::create(['plano_curricular_id' => $plano->id, 'disciplina_id' => $disciplina->id]);

        $inscricao = InscricaoDisciplina::create([
            'matricula_id' => $matricula->id,
            'plano_curricular_disciplina_id' => $planoDisciplina->id,
            'data_inscricao' => '2026-02-01',
            'estado' => EstadoInscricaoDisciplinaEnum::INSCRITA->value,
        ]);

        $this->assertSame(EstadoInscricaoDisciplinaEnum::INSCRITA, $inscricao->fresh()->estado);
        $this->assertSame($matricula->id, $inscricao->matricula->id);
        $this->assertSame($disciplina->id, $inscricao->planoCurricularDisciplina->disciplina_id);
        $this->assertTrue($matricula->fresh()->inscricoesDisciplinas->contains($inscricao));
    }
}
```

- [ ] **Step 9: Run do teste feature para confirmar que passa**

Run: `php artisan test Modules/Matricula/tests/Feature/InscricaoDisciplinaModelTest.php`
Expected: `1 passed`

- [ ] **Step 10: Commit**

```bash
git add Modules/Matricula/database/migrations/2026_09_16_090000_create_inscricoes_disciplinas_table.php Modules/Matricula/app/Enums/EstadoInscricaoDisciplinaEnum.php Modules/Matricula/app/Models/InscricaoDisciplina.php Modules/Matricula/app/Models/Matricula.php Modules/Matricula/tests/Unit/EstadoInscricaoDisciplinaEnumTest.php Modules/Matricula/tests/Feature/InscricaoDisciplinaModelTest.php
git commit -m "feat(matricula): add InscricaoDisciplina model, enum and migration"
```

---

## Task 2 — `CriarInscricaoDisciplinaAction` (inscrição manual/explícita)

**Files:**
- Create: `Modules/Matricula/app/Actions/CriarInscricaoDisciplinaAction.php`
- Create: `Modules/Matricula/tests/Feature/CriarInscricaoDisciplinaActionTest.php`

**Interfaces:**
- Consumes: `Modules\Matricula\Models\Matricula`, `Modules\PlanoCurricular\Models\PlanoCurricularDisciplina` (Task 1).
- Produces: `CriarInscricaoDisciplinaAction::executar(Matricula $matricula, PlanoCurricularDisciplina $planoCurricularDisciplina, ?int $utilizadorId = null): InscricaoDisciplina` — usado directamente aqui e reutilizado pela Task 3.

- [ ] **Step 1: Escrever o teste que falha (duplicado)**

```php
<?php

namespace Modules\Matricula\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Modules\Aluno\Actions\CriarEnquadramentoAcademicoAlunoAction;
use Modules\Aluno\Models\Aluno;
use Modules\AnoLectivo\Enums\EstadoAnoLectivo;
use Modules\AnoLectivo\Models\AnoLectivo;
use Modules\Estabelecimento\Enums\TipoEstabelecimentoEnum;
use Modules\Estabelecimento\Models\Estabelecimento;
use Modules\Matricula\Actions\CriarInscricaoDisciplinaAction;
use Modules\Matricula\Actions\CriarMatriculaAction;
use Modules\Matricula\DTO\MatriculaDTO;
use Modules\Matricula\Enums\EstadoInscricaoDisciplinaEnum;
use Modules\Disciplina\Models\Disciplina;
use Modules\PlanoCurricular\Models\PlanoCurricular;
use Modules\PlanoCurricular\Models\PlanoCurricularDisciplina;
use Modules\Turma\Models\NivelAcademico;
use Modules\Turma\Models\Turma;
use Modules\Usuario\Models\DadosPessoal;
use Tests\TestCase;

class CriarInscricaoDisciplinaActionTest extends TestCase
{
    use RefreshDatabase;

    private function criarCenario(): array
    {
        $estabelecimento = Estabelecimento::create(['nome' => 'Escola Teste', 'tipo' => TipoEstabelecimentoEnum::PUBLICO->value, 'is_active' => true]);
        $anoLectivo = AnoLectivo::create(['estabelecimento_id' => $estabelecimento->id, 'nome' => '2026', 'data_inicio' => '2026-01-01', 'data_fim' => '2026-12-31', 'estado' => EstadoAnoLectivo::ATIVO]);
        $nivel = NivelAcademico::create(['estabelecimento_id' => $estabelecimento->id, 'codigo' => '1C', 'nome' => '1ª Classe', 'ordem' => 1, 'etapa_ensino' => 1]);
        $turma = Turma::create(['ano_lectivo_id' => $anoLectivo->id, 'nivel_academico_id' => $nivel->id, 'codigo' => 'T1', 'nome' => 'Turma 1']);
        $pessoa = DadosPessoal::create(['nome_completo' => 'Aluno Teste', 'numero_identificacao' => 'BI0001', 'tipo_pessoa' => DadosPessoal::TIPO_ALUNO]);
        $aluno = Aluno::create(['estabelecimento_id' => $estabelecimento->id, 'dados_pessoa_id' => $pessoa->id, 'numero_matricula' => '2026-0001']);
        (new CriarEnquadramentoAcademicoAlunoAction())->executar($aluno, nivelAcademicoId: $nivel->id);
        $matricula = app(CriarMatriculaAction::class)->executar($aluno, new MatriculaDTO(turmaId: $turma->id, anoLectivoId: $anoLectivo->id, dataMatricula: null, estado: null, observacoes: null));

        $plano = PlanoCurricular::create(['estabelecimento_id' => $estabelecimento->id, 'nivel_academico_id' => $nivel->id, 'codigo' => 'PL1', 'nome' => 'Plano 1']);
        $disciplina = Disciplina::create(['estabelecimento_id' => $estabelecimento->id, 'codigo' => 'MAT1', 'nome' => 'Matemática I']);
        $planoDisciplina = PlanoCurricularDisciplina::create(['plano_curricular_id' => $plano->id, 'disciplina_id' => $disciplina->id]);

        return [$matricula, $planoDisciplina];
    }

    public function test_cria_inscricao_inscrita(): void
    {
        [$matricula, $planoDisciplina] = $this->criarCenario();

        $inscricao = app(CriarInscricaoDisciplinaAction::class)->executar($matricula, $planoDisciplina);

        $this->assertSame(EstadoInscricaoDisciplinaEnum::INSCRITA, $inscricao->estado);
        $this->assertSame($matricula->id, $inscricao->matricula_id);
    }

    public function test_rejeita_inscricao_duplicada_na_mesma_disciplina(): void
    {
        [$matricula, $planoDisciplina] = $this->criarCenario();
        app(CriarInscricaoDisciplinaAction::class)->executar($matricula, $planoDisciplina);

        $this->expectException(ValidationException::class);

        app(CriarInscricaoDisciplinaAction::class)->executar($matricula, $planoDisciplina);
    }

    public function test_rejeita_inscricao_em_matricula_concluida(): void
    {
        [$matricula, $planoDisciplina] = $this->criarCenario();
        app(\Modules\Matricula\Actions\AlterarEstadoMatriculaAction::class)->executar($matricula, \Modules\Matricula\Enums\EstadoMatriculaEnum::ACTIVA);
        $matricula = app(\Modules\Matricula\Actions\AlterarEstadoMatriculaAction::class)->executar($matricula, \Modules\Matricula\Enums\EstadoMatriculaEnum::CONCLUIDA);

        $this->expectException(ValidationException::class);

        app(CriarInscricaoDisciplinaAction::class)->executar($matricula, $planoDisciplina);
    }
}
```

- [ ] **Step 2: Correr os testes e confirmar que falham** (classe `CriarInscricaoDisciplinaAction` ainda não existe)

Run: `php artisan test Modules/Matricula/tests/Feature/CriarInscricaoDisciplinaActionTest.php`
Expected: FAIL — `Class "Modules\Matricula\Actions\CriarInscricaoDisciplinaAction" not found`

- [ ] **Step 3: Implementação**

```php
<?php

namespace Modules\Matricula\Actions;

use Illuminate\Validation\ValidationException;
use Modules\Matricula\Enums\EstadoInscricaoDisciplinaEnum;
use Modules\Matricula\Models\InscricaoDisciplina;
use Modules\Matricula\Models\Matricula;
use Modules\PlanoCurricular\Models\PlanoCurricularDisciplina;

class CriarInscricaoDisciplinaAction
{
    public function executar(
        Matricula $matricula,
        PlanoCurricularDisciplina $planoCurricularDisciplina,
        ?int $utilizadorId = null,
    ): InscricaoDisciplina {
        if ($matricula->estado->eTerminal()) {
            throw ValidationException::withMessages([
                'plano_curricular_disciplina_id' => 'Não é possível inscrever numa matrícula já concluída, cancelada ou transferida.',
            ]);
        }

        $jaInscrito = InscricaoDisciplina::query()
            ->where('estado', EstadoInscricaoDisciplinaEnum::INSCRITA->value)
            ->whereHas('planoCurricularDisciplina', fn ($query) => $query->where('disciplina_id', $planoCurricularDisciplina->disciplina_id))
            ->whereHas('matricula', fn ($query) => $query->where('aluno_id', $matricula->aluno_id))
            ->exists();

        if ($jaInscrito) {
            throw ValidationException::withMessages([
                'plano_curricular_disciplina_id' => 'O aluno já está inscrito nesta disciplina.',
            ]);
        }

        return InscricaoDisciplina::create([
            'matricula_id' => $matricula->id,
            'plano_curricular_disciplina_id' => $planoCurricularDisciplina->id,
            'data_inscricao' => now()->toDateString(),
            'estado' => EstadoInscricaoDisciplinaEnum::INSCRITA->value,
            'criado_por' => $utilizadorId,
        ]);
    }
}
```

- [ ] **Step 4: Correr os testes e confirmar que passam**

Run: `php artisan test Modules/Matricula/tests/Feature/CriarInscricaoDisciplinaActionTest.php`
Expected: `3 passed`

- [ ] **Step 5: Commit**

```bash
git add Modules/Matricula/app/Actions/CriarInscricaoDisciplinaAction.php Modules/Matricula/tests/Feature/CriarInscricaoDisciplinaActionTest.php
git commit -m "feat(matricula): add CriarInscricaoDisciplinaAction"
```

---

## Task 3 — `InscreverDisciplinasAutomaticamenteAction` (inscrição em massa, Ensino Geral/Técnico)

**Files:**
- Create: `Modules/Matricula/app/Actions/InscreverDisciplinasAutomaticamenteAction.php`
- Create: `Modules/Matricula/tests/Feature/InscreverDisciplinasAutomaticamenteActionTest.php`

**Interfaces:**
- Consumes: `CriarInscricaoDisciplinaAction` (Task 2), `Modules\PlanoCurricular\Models\PlanoCurricular`, `Modules\Estabelecimento\Enums\TipoEnsinoEnum`, `Modules\Estabelecimento\Models\Estabelecimento`, `Modules\Turma\Models\Turma`.
- Produces:
  - `InscreverDisciplinasAutomaticamenteAction::resolverPlanoCurricular(Turma $turma): ?PlanoCurricular` — único sítio que resolve "qual o Plano Curricular confirmado desta turma"; reaproveitado pelos dois métodos abaixo.
  - `InscreverDisciplinasAutomaticamenteAction::garantirPlanoCurricularConfirmado(Turma $turma): void` — lança `ValidationException::withMessages(['turma_id' => ...])` se o estabelecimento não for `TipoEnsinoEnum::UNIVERSITARIO` e não houver plano confirmado; não faz nada (nem no Superior, nem com plano confirmado). **A decisão "é obrigatório ter currículo confirmado?" vive inteiramente aqui dentro** (Decisão 10).
  - `InscreverDisciplinasAutomaticamenteAction::executar(Matricula $matricula, ?int $utilizadorId = null): int` (devolve o número de inscrições criadas; `0` se o estabelecimento for `TipoEnsinoEnum::UNIVERSITARIO` ou se não encontrar plano confirmado — nunca lança excepção). **A decisão "é para auto-inscrever?" vive inteiramente aqui dentro** (Decisão 9) — quem chama esta Action (Task 4) não sabe nada sobre `TipoEnsinoEnum`.

- [ ] **Step 1: Escrever o teste que falha**

```php
<?php

namespace Modules\Matricula\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Modules\Aluno\Actions\CriarEnquadramentoAcademicoAlunoAction;
use Modules\Aluno\Models\Aluno;
use Modules\AnoLectivo\Enums\EstadoAnoLectivo;
use Modules\AnoLectivo\Models\AnoLectivo;
use Modules\Curso\Models\Curso;
use Modules\Disciplina\Models\Disciplina;
use Modules\Estabelecimento\Enums\TipoEnsinoEnum;
use Modules\Estabelecimento\Enums\TipoEstabelecimentoEnum;
use Modules\Estabelecimento\Models\Estabelecimento;
use Modules\Matricula\Actions\InscreverDisciplinasAutomaticamenteAction;
use Modules\Matricula\Enums\EstadoMatriculaEnum;
use Modules\Matricula\Models\Matricula;
use Modules\PlanoCurricular\Models\PlanoCurricular;
use Modules\PlanoCurricular\Models\PlanoCurricularAnoLectivo;
use Modules\PlanoCurricular\Models\PlanoCurricularDisciplina;
use Modules\Turma\Models\NivelAcademico;
use Modules\Turma\Models\Turma;
use Modules\Usuario\Models\DadosPessoal;
use Tests\TestCase;

class InscreverDisciplinasAutomaticamenteActionTest extends TestCase
{
    use RefreshDatabase;

    // Constrói a Matrícula directamente no modelo, sem passar por
    // CriarMatriculaAction — estes testes verificam a Action em isolamento,
    // e não podem depender de o hook da Task 4 (ainda não existe nesta
    // Task) nem deixar de funcionar depois de ele existir.
    private function criarMatricula(AnoLectivo $anoLectivo, Turma $turma, Aluno $aluno): Matricula
    {
        return Matricula::create([
            'aluno_id' => $aluno->id,
            'turma_id' => $turma->id,
            'ano_lectivo_id' => $anoLectivo->id,
            'numero_registo_matricula' => '2026-0001',
            'data_matricula' => '2026-02-01',
            'estado' => EstadoMatriculaEnum::ACTIVA->value,
        ]);
    }

    public function test_inscreve_em_todas_as_disciplinas_do_plano_confirmado(): void
    {
        $estabelecimento = Estabelecimento::create(['nome' => 'Escola Teste', 'tipo' => TipoEstabelecimentoEnum::PUBLICO->value, 'is_active' => true]);
        $anoLectivo = AnoLectivo::create(['estabelecimento_id' => $estabelecimento->id, 'nome' => '2026', 'data_inicio' => '2026-01-01', 'data_fim' => '2026-12-31', 'estado' => EstadoAnoLectivo::ATIVO]);
        $nivel = NivelAcademico::create(['estabelecimento_id' => $estabelecimento->id, 'codigo' => '1C', 'nome' => '1ª Classe', 'ordem' => 1, 'etapa_ensino' => 1]);
        $turma = Turma::create(['ano_lectivo_id' => $anoLectivo->id, 'nivel_academico_id' => $nivel->id, 'codigo' => 'T1', 'nome' => 'Turma 1']);
        $pessoa = DadosPessoal::create(['nome_completo' => 'Aluno Teste', 'numero_identificacao' => 'BI0001', 'tipo_pessoa' => DadosPessoal::TIPO_ALUNO]);
        $aluno = Aluno::create(['estabelecimento_id' => $estabelecimento->id, 'dados_pessoa_id' => $pessoa->id, 'numero_matricula' => '2026-0001']);
        (new CriarEnquadramentoAcademicoAlunoAction())->executar($aluno, nivelAcademicoId: $nivel->id);

        $plano = PlanoCurricular::create(['estabelecimento_id' => $estabelecimento->id, 'nivel_academico_id' => $nivel->id, 'codigo' => 'PL1', 'nome' => 'Plano 1']);
        PlanoCurricularAnoLectivo::create(['plano_curricular_id' => $plano->id, 'ano_lectivo_id' => $anoLectivo->id]);
        $disciplinaA = Disciplina::create(['estabelecimento_id' => $estabelecimento->id, 'codigo' => 'MAT1', 'nome' => 'Matemática I']);
        $disciplinaB = Disciplina::create(['estabelecimento_id' => $estabelecimento->id, 'codigo' => 'POR1', 'nome' => 'Português I']);
        PlanoCurricularDisciplina::create(['plano_curricular_id' => $plano->id, 'disciplina_id' => $disciplinaA->id]);
        PlanoCurricularDisciplina::create(['plano_curricular_id' => $plano->id, 'disciplina_id' => $disciplinaB->id]);

        $matricula = $this->criarMatricula($anoLectivo, $turma, $aluno);

        $total = app(InscreverDisciplinasAutomaticamenteAction::class)->executar($matricula);

        $this->assertSame(2, $total);
        $this->assertSame(2, $matricula->inscricoesDisciplinas()->count());
    }

    public function test_devolve_zero_sem_plano_confirmado(): void
    {
        $estabelecimento = Estabelecimento::create(['nome' => 'Escola Teste', 'tipo' => TipoEstabelecimentoEnum::PUBLICO->value, 'is_active' => true]);
        $anoLectivo = AnoLectivo::create(['estabelecimento_id' => $estabelecimento->id, 'nome' => '2026', 'data_inicio' => '2026-01-01', 'data_fim' => '2026-12-31', 'estado' => EstadoAnoLectivo::ATIVO]);
        $nivel = NivelAcademico::create(['estabelecimento_id' => $estabelecimento->id, 'codigo' => '1C', 'nome' => '1ª Classe', 'ordem' => 1, 'etapa_ensino' => 1]);
        $turma = Turma::create(['ano_lectivo_id' => $anoLectivo->id, 'nivel_academico_id' => $nivel->id, 'codigo' => 'T1', 'nome' => 'Turma 1']);
        $pessoa = DadosPessoal::create(['nome_completo' => 'Aluno Teste', 'numero_identificacao' => 'BI0001', 'tipo_pessoa' => DadosPessoal::TIPO_ALUNO]);
        $aluno = Aluno::create(['estabelecimento_id' => $estabelecimento->id, 'dados_pessoa_id' => $pessoa->id, 'numero_matricula' => '2026-0001']);
        (new CriarEnquadramentoAcademicoAlunoAction())->executar($aluno, nivelAcademicoId: $nivel->id);
        // Sem PlanoCurricular/PlanoCurricularAnoLectivo criado. Na prática, fora do Ensino
        // Superior, `garantirPlanoCurricularConfirmado()` já teria bloqueado a criação desta
        // Matrícula antes de chegarmos aqui (Task 4) — este teste cobre `executar()` isolado,
        // que continua defensivo mesmo que seja chamado directamente noutro contexto.

        $matricula = $this->criarMatricula($anoLectivo, $turma, $aluno);

        $total = app(InscreverDisciplinasAutomaticamenteAction::class)->executar($matricula);

        $this->assertSame(0, $total);
    }

    public function test_nao_inscreve_automaticamente_no_ensino_superior(): void
    {
        $estabelecimento = Estabelecimento::create(['nome' => 'Universidade Teste', 'tipo' => TipoEstabelecimentoEnum::PUBLICO->value, 'is_active' => true, 'tipo_ensino' => TipoEnsinoEnum::UNIVERSITARIO->value]);
        $anoLectivo = AnoLectivo::create(['estabelecimento_id' => $estabelecimento->id, 'nome' => '2026', 'data_inicio' => '2026-01-01', 'data_fim' => '2026-12-31', 'estado' => EstadoAnoLectivo::ATIVO]);
        $curso = Curso::create(['estabelecimento_id' => $estabelecimento->id, 'codigo' => 'INF', 'nome' => 'Informática']);
        $nivel = NivelAcademico::create(['estabelecimento_id' => $estabelecimento->id, 'codigo' => '1A', 'nome' => '1º Ano', 'ordem' => 1, 'etapa_ensino' => 5]);
        $turma = Turma::create(['ano_lectivo_id' => $anoLectivo->id, 'nivel_academico_id' => $nivel->id, 'curso_id' => $curso->id, 'codigo' => 'T1', 'nome' => 'Turma 1']);
        $pessoa = DadosPessoal::create(['nome_completo' => 'Aluno Teste', 'numero_identificacao' => 'BI0001', 'tipo_pessoa' => DadosPessoal::TIPO_ALUNO]);
        $aluno = Aluno::create(['estabelecimento_id' => $estabelecimento->id, 'dados_pessoa_id' => $pessoa->id, 'numero_matricula' => '2026-0001']);
        (new CriarEnquadramentoAcademicoAlunoAction())->executar($aluno, cursoId: $curso->id);

        $plano = PlanoCurricular::create(['estabelecimento_id' => $estabelecimento->id, 'curso_id' => $curso->id, 'nivel_academico_id' => $nivel->id, 'codigo' => 'PL1', 'nome' => 'Plano 1']);
        PlanoCurricularAnoLectivo::create(['plano_curricular_id' => $plano->id, 'ano_lectivo_id' => $anoLectivo->id]);
        $disciplina = Disciplina::create(['estabelecimento_id' => $estabelecimento->id, 'codigo' => 'MAT1', 'nome' => 'Matemática I']);
        PlanoCurricularDisciplina::create(['plano_curricular_id' => $plano->id, 'disciplina_id' => $disciplina->id]);

        $matricula = $this->criarMatricula($anoLectivo, $turma, $aluno);

        $total = app(InscreverDisciplinasAutomaticamenteAction::class)->executar($matricula);

        $this->assertSame(0, $total);
    }

    public function test_garantir_plano_curricular_confirmado_lanca_excecao_sem_plano_fora_do_superior(): void
    {
        $estabelecimento = Estabelecimento::create(['nome' => 'Escola Teste', 'tipo' => TipoEstabelecimentoEnum::PUBLICO->value, 'is_active' => true]);
        $anoLectivo = AnoLectivo::create(['estabelecimento_id' => $estabelecimento->id, 'nome' => '2026', 'data_inicio' => '2026-01-01', 'data_fim' => '2026-12-31', 'estado' => EstadoAnoLectivo::ATIVO]);
        $nivel = NivelAcademico::create(['estabelecimento_id' => $estabelecimento->id, 'codigo' => '1C', 'nome' => '1ª Classe', 'ordem' => 1, 'etapa_ensino' => 1]);
        $turma = Turma::create(['ano_lectivo_id' => $anoLectivo->id, 'nivel_academico_id' => $nivel->id, 'codigo' => 'T1', 'nome' => 'Turma 1']);
        // Sem PlanoCurricular/PlanoCurricularAnoLectivo.

        $this->expectException(ValidationException::class);

        app(InscreverDisciplinasAutomaticamenteAction::class)->garantirPlanoCurricularConfirmado($turma);
    }

    public function test_garantir_plano_curricular_confirmado_nao_lanca_excecao_com_plano(): void
    {
        $estabelecimento = Estabelecimento::create(['nome' => 'Escola Teste', 'tipo' => TipoEstabelecimentoEnum::PUBLICO->value, 'is_active' => true]);
        $anoLectivo = AnoLectivo::create(['estabelecimento_id' => $estabelecimento->id, 'nome' => '2026', 'data_inicio' => '2026-01-01', 'data_fim' => '2026-12-31', 'estado' => EstadoAnoLectivo::ATIVO]);
        $nivel = NivelAcademico::create(['estabelecimento_id' => $estabelecimento->id, 'codigo' => '1C', 'nome' => '1ª Classe', 'ordem' => 1, 'etapa_ensino' => 1]);
        $turma = Turma::create(['ano_lectivo_id' => $anoLectivo->id, 'nivel_academico_id' => $nivel->id, 'codigo' => 'T1', 'nome' => 'Turma 1']);
        $plano = PlanoCurricular::create(['estabelecimento_id' => $estabelecimento->id, 'nivel_academico_id' => $nivel->id, 'codigo' => 'PL1', 'nome' => 'Plano 1']);
        PlanoCurricularAnoLectivo::create(['plano_curricular_id' => $plano->id, 'ano_lectivo_id' => $anoLectivo->id]);

        app(InscreverDisciplinasAutomaticamenteAction::class)->garantirPlanoCurricularConfirmado($turma);

        $this->assertTrue(true); // não lançou excepção
    }

    public function test_garantir_plano_curricular_confirmado_nao_lanca_excecao_no_ensino_superior_mesmo_sem_plano(): void
    {
        $estabelecimento = Estabelecimento::create(['nome' => 'Universidade Teste', 'tipo' => TipoEstabelecimentoEnum::PUBLICO->value, 'is_active' => true, 'tipo_ensino' => TipoEnsinoEnum::UNIVERSITARIO->value]);
        $anoLectivo = AnoLectivo::create(['estabelecimento_id' => $estabelecimento->id, 'nome' => '2026', 'data_inicio' => '2026-01-01', 'data_fim' => '2026-12-31', 'estado' => EstadoAnoLectivo::ATIVO]);
        $curso = Curso::create(['estabelecimento_id' => $estabelecimento->id, 'codigo' => 'INF', 'nome' => 'Informática']);
        $nivel = NivelAcademico::create(['estabelecimento_id' => $estabelecimento->id, 'codigo' => '1A', 'nome' => '1º Ano', 'ordem' => 1, 'etapa_ensino' => 5]);
        $turma = Turma::create(['ano_lectivo_id' => $anoLectivo->id, 'nivel_academico_id' => $nivel->id, 'curso_id' => $curso->id, 'codigo' => 'T1', 'nome' => 'Turma 1']);
        // Sem PlanoCurricular — continua sem lançar, porque no Superior não é exigido.

        app(InscreverDisciplinasAutomaticamenteAction::class)->garantirPlanoCurricularConfirmado($turma);

        $this->assertTrue(true);
    }
}
```

- [ ] **Step 2: Correr os testes e confirmar que falham**

Run: `php artisan test Modules/Matricula/tests/Feature/InscreverDisciplinasAutomaticamenteActionTest.php`
Expected: FAIL — classe não existe

- [ ] **Step 3: Implementação**

```php
<?php

namespace Modules\Matricula\Actions;

use Illuminate\Validation\ValidationException;
use Modules\Estabelecimento\Enums\TipoEnsinoEnum;
use Modules\Estabelecimento\Models\Estabelecimento;
use Modules\Matricula\Models\Matricula;
use Modules\PlanoCurricular\Models\PlanoCurricular;
use Modules\Turma\Models\Turma;

class InscreverDisciplinasAutomaticamenteAction
{
    public function __construct(
        private CriarInscricaoDisciplinaAction $criarInscricao,
    ) {
    }

    /**
     * Único sítio que sabe resolver "qual o Plano Curricular confirmado
     * desta turma" — reaproveitado tanto por `garantirPlanoCurricularConfirmado()`
     * (validação, chamada antes de criar a Matrícula) como por `executar()`
     * (inscrição, chamada depois).
     */
    public function resolverPlanoCurricular(Turma $turma): ?PlanoCurricular
    {
        return PlanoCurricular::query()
            ->where('curso_id', $turma->curso_id)
            ->where('nivel_academico_id', $turma->nivel_academico_id)
            ->whereHas('anosLectivos', function ($query) use ($turma) {
                $query->where('ano_lectivo_id', $turma->ano_lectivo_id)->where('estado', 1);
            })
            ->with('disciplinas')
            ->first();
    }

    /**
     * Fora do Ensino Superior, uma Matrícula sem Plano Curricular confirmado
     * deixaria o aluno numa turma sem estrutura curricular — por isso esta
     * exigência bloqueia a criação da Matrícula (`CriarMatriculaAction`,
     * Task 4, chama isto antes de persistir). No Ensino Superior a
     * inscrição é sempre manual, logo não há exigência: é a MESMA decisão
     * de `tipo_ensino` da Decisão 9, aplicada aqui à pré-condição em vez de
     * à execução (Decisão 10).
     */
    public function garantirPlanoCurricularConfirmado(Turma $turma): void
    {
        if (Estabelecimento::current()?->tipo_ensino === TipoEnsinoEnum::UNIVERSITARIO) {
            return;
        }

        if ($this->resolverPlanoCurricular($turma) === null) {
            throw ValidationException::withMessages([
                'turma_id' => 'Não existe um Plano Curricular confirmado para esta turma. Contacte a coordenação pedagógica antes de matricular alunos.',
            ]);
        }
    }

    /**
     * Só inscreve automaticamente fora do Ensino Superior — no Superior a
     * escolha de disciplinas é sempre manual (electivas, cadeiras em
     * atraso). Esta é a ÚNICA decisão sobre "quando automatizar" em todo o
     * fluxo: quem chama esta Action (`CriarMatriculaAction`, Task 4) chama-a
     * sempre, sem saber desta regra. O `null` de `resolverPlanoCurricular()`
     * aqui dentro é só defensivo — fora do Superior,
     * `garantirPlanoCurricularConfirmado()` já garantiu que existe plano
     * antes de a Matrícula sequer existir.
     */
    public function executar(Matricula $matricula, ?int $utilizadorId = null): int
    {
        if (Estabelecimento::current()?->tipo_ensino === TipoEnsinoEnum::UNIVERSITARIO) {
            return 0;
        }

        $plano = $this->resolverPlanoCurricular($matricula->turma);

        if ($plano === null) {
            return 0;
        }

        $total = 0;

        foreach ($plano->disciplinas as $planoCurricularDisciplina) {
            try {
                $this->criarInscricao->executar($matricula, $planoCurricularDisciplina, $utilizadorId);
                $total++;
            } catch (ValidationException) {
                // Já inscrito (ex.: reinscrição na mesma turma) — ignora e segue para a próxima.
            }
        }

        return $total;
    }
}
```

- [ ] **Step 4: Correr os testes e confirmar que passam**

Run: `php artisan test Modules/Matricula/tests/Feature/InscreverDisciplinasAutomaticamenteActionTest.php`
Expected: `6 passed`

- [ ] **Step 5: Commit**

```bash
git add Modules/Matricula/app/Actions/InscreverDisciplinasAutomaticamenteAction.php Modules/Matricula/tests/Feature/InscreverDisciplinasAutomaticamenteActionTest.php
git commit -m "feat(matricula): add bulk auto-enrollment and mandatory curriculum precondition, both centralized in the same Action"
```

---

## Task 4 — Hook em `CriarMatriculaAction`

**Files:**
- Modify: `Modules/Matricula/app/Actions/CriarMatriculaAction.php`
- Modify: `Modules/Matricula/tests/Feature/MatriculaActionTest.php`

**Interfaces:**
- Consumes: `InscreverDisciplinasAutomaticamenteAction` (Task 3) — dois métodos: `garantirPlanoCurricularConfirmado(Turma $turma): void` (chamado antes de criar a Matrícula) e `executar(Matricula $matricula, ?int $utilizadorId = null): int` (chamado depois). `CriarMatriculaAction` não importa `TipoEnsinoEnum` nem `Estabelecimento` — as duas decisões (quando exigir currículo confirmado, quando inscrever automaticamente) vivem só na Action (Decisões 9 e 10).

- [ ] **Step 1: Escrever o teste que falha**

Adicionar a `Modules/Matricula/tests/Feature/MatriculaActionTest.php` (reutiliza os `use` já existentes no ficheiro; adicionar só os que faltam: `PlanoCurricular`, `PlanoCurricularAnoLectivo`, `PlanoCurricularDisciplina`, `Disciplina`, `InscricaoDisciplina`):

```php
    public function test_criar_matricula_fora_do_superior_inscreve_automaticamente_nas_disciplinas(): void
    {
        $estabelecimento = $this->criarEstabelecimento(); // GERAL por omissão
        $anoLectivo = $this->criarAnoLectivo($estabelecimento);
        $nivel = $this->criarNivelAcademico($estabelecimento);
        $turma = $this->criarTurma($anoLectivo, $nivel);
        $aluno = $this->criarAluno($estabelecimento);
        $this->enquadrar($aluno, nivelAcademicoId: $nivel->id);

        $plano = \Modules\PlanoCurricular\Models\PlanoCurricular::create(['estabelecimento_id' => $estabelecimento->id, 'nivel_academico_id' => $nivel->id, 'codigo' => 'PL1', 'nome' => 'Plano 1']);
        \Modules\PlanoCurricular\Models\PlanoCurricularAnoLectivo::create(['plano_curricular_id' => $plano->id, 'ano_lectivo_id' => $anoLectivo->id]);
        $disciplina = \Modules\Disciplina\Models\Disciplina::create(['estabelecimento_id' => $estabelecimento->id, 'codigo' => 'MAT1', 'nome' => 'Matemática I']);
        \Modules\PlanoCurricular\Models\PlanoCurricularDisciplina::create(['plano_curricular_id' => $plano->id, 'disciplina_id' => $disciplina->id]);

        $matricula = app(CriarMatriculaAction::class)->executar($aluno, $this->dto($turma->id, $anoLectivo->id));

        $this->assertSame(1, $matricula->inscricoesDisciplinas()->count());
    }

    public function test_criar_matricula_no_superior_nao_inscreve_automaticamente(): void
    {
        $estabelecimento = $this->criarEstabelecimento(\Modules\Estabelecimento\Enums\TipoEnsinoEnum::UNIVERSITARIO);
        $anoLectivo = $this->criarAnoLectivo($estabelecimento);
        $curso = $this->criarCurso($estabelecimento);
        $nivel = $this->criarNivelAcademico($estabelecimento);
        $turma = $this->criarTurma($anoLectivo, $nivel, $curso);
        $aluno = $this->criarAluno($estabelecimento);
        $this->enquadrar($aluno, cursoId: $curso->id);

        $plano = \Modules\PlanoCurricular\Models\PlanoCurricular::create(['estabelecimento_id' => $estabelecimento->id, 'curso_id' => $curso->id, 'nivel_academico_id' => $nivel->id, 'codigo' => 'PL1', 'nome' => 'Plano 1']);
        \Modules\PlanoCurricular\Models\PlanoCurricularAnoLectivo::create(['plano_curricular_id' => $plano->id, 'ano_lectivo_id' => $anoLectivo->id]);
        $disciplina = \Modules\Disciplina\Models\Disciplina::create(['estabelecimento_id' => $estabelecimento->id, 'codigo' => 'MAT1', 'nome' => 'Matemática I']);
        \Modules\PlanoCurricular\Models\PlanoCurricularDisciplina::create(['plano_curricular_id' => $plano->id, 'disciplina_id' => $disciplina->id]);

        $matricula = app(CriarMatriculaAction::class)->executar($aluno, $this->dto($turma->id, $anoLectivo->id));

        $this->assertSame(0, $matricula->inscricoesDisciplinas()->count());
    }

    public function test_bloqueia_criar_matricula_fora_do_superior_sem_plano_curricular_confirmado(): void
    {
        $estabelecimento = $this->criarEstabelecimento(); // GERAL por omissão
        $anoLectivo = $this->criarAnoLectivo($estabelecimento);
        $nivel = $this->criarNivelAcademico($estabelecimento);
        $turma = $this->criarTurma($anoLectivo, $nivel);
        $aluno = $this->criarAluno($estabelecimento);
        $this->enquadrar($aluno, nivelAcademicoId: $nivel->id);
        // Sem PlanoCurricular/PlanoCurricularAnoLectivo criado.

        try {
            app(CriarMatriculaAction::class)->executar($aluno, $this->dto($turma->id, $anoLectivo->id));
            $this->fail('Esperava ValidationException por falta de Plano Curricular confirmado.');
        } catch (\Illuminate\Validation\ValidationException $e) {
            $this->assertArrayHasKey('turma_id', $e->errors());
        }

        $this->assertSame(0, Matricula::where('aluno_id', $aluno->id)->count());
    }

    public function test_permite_criar_matricula_no_superior_sem_plano_curricular(): void
    {
        $estabelecimento = $this->criarEstabelecimento(\Modules\Estabelecimento\Enums\TipoEnsinoEnum::UNIVERSITARIO);
        $anoLectivo = $this->criarAnoLectivo($estabelecimento);
        $curso = $this->criarCurso($estabelecimento);
        $nivel = $this->criarNivelAcademico($estabelecimento);
        $turma = $this->criarTurma($anoLectivo, $nivel, $curso);
        $aluno = $this->criarAluno($estabelecimento);
        $this->enquadrar($aluno, cursoId: $curso->id);
        // Sem PlanoCurricular — e mesmo assim a matrícula é criada, porque no Superior a
        // inscrição em disciplinas é sempre manual e não exige currículo confirmado.

        $matricula = app(CriarMatriculaAction::class)->executar($aluno, $this->dto($turma->id, $anoLectivo->id));

        $this->assertNotNull($matricula->id);
        $this->assertSame(0, $matricula->inscricoesDisciplinas()->count());
    }
```

(assumir `use Modules\Matricula\Models\Matricula;` já presente no ficheiro — usado extensivamente pelos testes já existentes.)

- [ ] **Step 2: Correr os testes e confirmar que falham**

Run: `php artisan test Modules/Matricula/tests/Feature/MatriculaActionTest.php --filter=inscreve_automaticamente|bloqueia_criar_matricula_fora_do_superior|permite_criar_matricula_no_superior`
Expected: FALHAM `test_criar_matricula_fora_do_superior_inscreve_automaticamente_nas_disciplinas` (contagem `0 !== 1`, hook ainda não chamado) e `test_bloqueia_criar_matricula_fora_do_superior_sem_plano_curricular_confirmado` (nenhuma excepção é lançada ainda, cai no `$this->fail(...)`). `test_criar_matricula_no_superior_nao_inscreve_automaticamente` e `test_permite_criar_matricula_no_superior_sem_plano_curricular` já passam neste ponto — servem de salvaguarda de não-regressão para o Ensino Superior, que não é afectado por esta Task.

- [ ] **Step 3: Implementação — editar `CriarMatriculaAction`**

Injectar `InscreverDisciplinasAutomaticamenteAction` no construtor de `Modules/Matricula/app/Actions/CriarMatriculaAction.php` (adicionar o `use Modules\Matricula\Actions\InscreverDisciplinasAutomaticamenteAction;` não é necessário — mesmo namespace):

```php
    public function __construct(
        private GeradorNumeroRegistoMatriculaService $geradorNumeroRegisto,
        private ValidadorMatriculaService $validador,
        private RegistarHistoricoMatriculaAction $registarHistorico,
        private InscreverDisciplinasAutomaticamenteAction $inscreverDisciplinas,
    ) {
    }
```

Logo a seguir às validações já existentes, antes de gerar o número de registo — bloqueia a criação se faltar currículo confirmado fora do Superior:

```php
            $this->validador->validarTurma($turma, $dto->anoLectivoId);
            $this->validador->garantirEnquadramentoAcademico($aluno, $turma, $utilizadorId);
            $this->validador->validarMatriculaNaoDuplicada($aluno, $turma);
            $this->inscreverDisciplinas->garantirPlanoCurricularConfirmado($turma);

            $anoLectivoId = $turma->ano_lectivo_id;
```

E, imediatamente antes do `return $matricula;` final — chamada incondicional, sem `if`, sem saber nada sobre `TipoEnsinoEnum`:

```php
            $this->inscreverDisciplinas->executar($matricula, $utilizadorId);

            return $matricula;
```

- [ ] **Step 4: Correr os testes e confirmar que passam**

Run: `php artisan test Modules/Matricula/tests/Feature/MatriculaActionTest.php`
Expected: todos passam (incluindo os já existentes — confirma que os dois hooks não quebraram nada)

- [ ] **Step 5: Correr a suite completa do módulo**

Run: `php artisan test Modules/Matricula`
Expected: todos passam

- [ ] **Step 6: Commit**

```bash
git add Modules/Matricula/app/Actions/CriarMatriculaAction.php Modules/Matricula/tests/Feature/MatriculaActionTest.php
git commit -m "feat(matricula): require confirmed curriculum and auto-enroll in disciplinas outside university"
```

---

## Task 5 — `AlterarEstadoInscricaoDisciplinaAction`

**Files:**
- Create: `Modules/Matricula/app/Actions/AlterarEstadoInscricaoDisciplinaAction.php`
- Create: `Modules/Matricula/tests/Feature/AlterarEstadoInscricaoDisciplinaActionTest.php`

**Interfaces:**
- Consumes: `Modules\Matricula\Models\InscricaoDisciplina`, `Modules\Matricula\Enums\EstadoInscricaoDisciplinaEnum` (Task 1).
- Produces: `AlterarEstadoInscricaoDisciplinaAction::executar(InscricaoDisciplina $inscricao, EstadoInscricaoDisciplinaEnum $novoEstado, ?int $utilizadorId = null): InscricaoDisciplina`.

- [ ] **Step 1: Escrever o teste que falha**

```php
<?php

namespace Modules\Matricula\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Modules\Aluno\Actions\CriarEnquadramentoAcademicoAlunoAction;
use Modules\Aluno\Models\Aluno;
use Modules\AnoLectivo\Enums\EstadoAnoLectivo;
use Modules\AnoLectivo\Models\AnoLectivo;
use Modules\Estabelecimento\Enums\TipoEstabelecimentoEnum;
use Modules\Estabelecimento\Models\Estabelecimento;
use Modules\Matricula\Actions\AlterarEstadoInscricaoDisciplinaAction;
use Modules\Matricula\Actions\CriarInscricaoDisciplinaAction;
use Modules\Matricula\Actions\CriarMatriculaAction;
use Modules\Matricula\DTO\MatriculaDTO;
use Modules\Matricula\Enums\EstadoInscricaoDisciplinaEnum;
use Modules\Disciplina\Models\Disciplina;
use Modules\PlanoCurricular\Models\PlanoCurricular;
use Modules\PlanoCurricular\Models\PlanoCurricularDisciplina;
use Modules\Turma\Models\NivelAcademico;
use Modules\Turma\Models\Turma;
use Modules\Usuario\Models\DadosPessoal;
use Tests\TestCase;

class AlterarEstadoInscricaoDisciplinaActionTest extends TestCase
{
    use RefreshDatabase;

    private function criarInscricao(): \Modules\Matricula\Models\InscricaoDisciplina
    {
        $estabelecimento = Estabelecimento::create(['nome' => 'Escola Teste', 'tipo' => TipoEstabelecimentoEnum::PUBLICO->value, 'is_active' => true]);
        $anoLectivo = AnoLectivo::create(['estabelecimento_id' => $estabelecimento->id, 'nome' => '2026', 'data_inicio' => '2026-01-01', 'data_fim' => '2026-12-31', 'estado' => EstadoAnoLectivo::ATIVO]);
        $nivel = NivelAcademico::create(['estabelecimento_id' => $estabelecimento->id, 'codigo' => '1C', 'nome' => '1ª Classe', 'ordem' => 1, 'etapa_ensino' => 1]);
        $turma = Turma::create(['ano_lectivo_id' => $anoLectivo->id, 'nivel_academico_id' => $nivel->id, 'codigo' => 'T1', 'nome' => 'Turma 1']);
        $pessoa = DadosPessoal::create(['nome_completo' => 'Aluno Teste', 'numero_identificacao' => 'BI0001', 'tipo_pessoa' => DadosPessoal::TIPO_ALUNO]);
        $aluno = Aluno::create(['estabelecimento_id' => $estabelecimento->id, 'dados_pessoa_id' => $pessoa->id, 'numero_matricula' => '2026-0001']);
        (new CriarEnquadramentoAcademicoAlunoAction())->executar($aluno, nivelAcademicoId: $nivel->id);
        $matricula = app(CriarMatriculaAction::class)->executar($aluno, new MatriculaDTO(turmaId: $turma->id, anoLectivoId: $anoLectivo->id, dataMatricula: null, estado: null, observacoes: null));

        $plano = PlanoCurricular::create(['estabelecimento_id' => $estabelecimento->id, 'nivel_academico_id' => $nivel->id, 'codigo' => 'PL1', 'nome' => 'Plano 1']);
        $disciplina = Disciplina::create(['estabelecimento_id' => $estabelecimento->id, 'codigo' => 'MAT1', 'nome' => 'Matemática I']);
        $planoDisciplina = PlanoCurricularDisciplina::create(['plano_curricular_id' => $plano->id, 'disciplina_id' => $disciplina->id]);

        return app(CriarInscricaoDisciplinaAction::class)->executar($matricula, $planoDisciplina);
    }

    public function test_conclui_inscricao_e_preenche_data_conclusao(): void
    {
        $inscricao = $this->criarInscricao();

        $actualizada = app(AlterarEstadoInscricaoDisciplinaAction::class)->executar($inscricao, EstadoInscricaoDisciplinaEnum::CONCLUIDA);

        $this->assertSame(EstadoInscricaoDisciplinaEnum::CONCLUIDA, $actualizada->estado);
        $this->assertSame(now()->toDateString(), $actualizada->data_conclusao->toDateString());
    }

    public function test_rejeita_transicao_de_estado_terminal(): void
    {
        $inscricao = $this->criarInscricao();
        $inscricao = app(AlterarEstadoInscricaoDisciplinaAction::class)->executar($inscricao, EstadoInscricaoDisciplinaEnum::REPROVADA);

        $this->expectException(ValidationException::class);

        app(AlterarEstadoInscricaoDisciplinaAction::class)->executar($inscricao, EstadoInscricaoDisciplinaEnum::CONCLUIDA);
    }
}
```

- [ ] **Step 2: Correr os testes e confirmar que falham**

Run: `php artisan test Modules/Matricula/tests/Feature/AlterarEstadoInscricaoDisciplinaActionTest.php`
Expected: FAIL — classe não existe

- [ ] **Step 3: Implementação**

```php
<?php

namespace Modules\Matricula\Actions;

use Illuminate\Validation\ValidationException;
use Modules\Matricula\Enums\EstadoInscricaoDisciplinaEnum;
use Modules\Matricula\Models\InscricaoDisciplina;

class AlterarEstadoInscricaoDisciplinaAction
{
    public function executar(
        InscricaoDisciplina $inscricao,
        EstadoInscricaoDisciplinaEnum $novoEstado,
        ?int $utilizadorId = null,
    ): InscricaoDisciplina {
        $estadoActual = $inscricao->estado;

        if (! $estadoActual->podeTransitarPara($novoEstado)) {
            throw ValidationException::withMessages([
                'estado' => "Não é possível alterar o estado de {$estadoActual->label()} para {$novoEstado->label()}.",
            ]);
        }

        $inscricao->estado = $novoEstado;
        $inscricao->editado_por = $utilizadorId;

        if ($novoEstado->eTerminal()) {
            $inscricao->data_conclusao = now()->toDateString();
        }

        $inscricao->save();

        return $inscricao->fresh();
    }
}
```

- [ ] **Step 4: Correr os testes e confirmar que passam**

Run: `php artisan test Modules/Matricula/tests/Feature/AlterarEstadoInscricaoDisciplinaActionTest.php`
Expected: `2 passed`

- [ ] **Step 5: Commit**

```bash
git add Modules/Matricula/app/Actions/AlterarEstadoInscricaoDisciplinaAction.php Modules/Matricula/tests/Feature/AlterarEstadoInscricaoDisciplinaActionTest.php
git commit -m "feat(matricula): add AlterarEstadoInscricaoDisciplinaAction"
```

---

## Task 6 — `EliminarInscricaoDisciplinaAction`

**Files:**
- Create: `Modules/Matricula/app/Actions/EliminarInscricaoDisciplinaAction.php`
- Create: `Modules/Matricula/tests/Feature/EliminarInscricaoDisciplinaActionTest.php`

**Interfaces:**
- Consumes: `Modules\Matricula\Models\InscricaoDisciplina`, `Modules\Matricula\Enums\EstadoInscricaoDisciplinaEnum`.
- Produces: `EliminarInscricaoDisciplinaAction::executar(InscricaoDisciplina $inscricao): void`.

- [ ] **Step 1: Escrever o teste que falha**

```php
<?php

namespace Modules\Matricula\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Modules\Aluno\Actions\CriarEnquadramentoAcademicoAlunoAction;
use Modules\Aluno\Models\Aluno;
use Modules\AnoLectivo\Enums\EstadoAnoLectivo;
use Modules\AnoLectivo\Models\AnoLectivo;
use Modules\Estabelecimento\Enums\TipoEstabelecimentoEnum;
use Modules\Estabelecimento\Models\Estabelecimento;
use Modules\Matricula\Actions\AlterarEstadoInscricaoDisciplinaAction;
use Modules\Matricula\Actions\CriarInscricaoDisciplinaAction;
use Modules\Matricula\Actions\CriarMatriculaAction;
use Modules\Matricula\Actions\EliminarInscricaoDisciplinaAction;
use Modules\Matricula\DTO\MatriculaDTO;
use Modules\Matricula\Enums\EstadoInscricaoDisciplinaEnum;
use Modules\Matricula\Models\InscricaoDisciplina;
use Modules\Disciplina\Models\Disciplina;
use Modules\PlanoCurricular\Models\PlanoCurricular;
use Modules\PlanoCurricular\Models\PlanoCurricularDisciplina;
use Modules\Turma\Models\NivelAcademico;
use Modules\Turma\Models\Turma;
use Modules\Usuario\Models\DadosPessoal;
use Tests\TestCase;

class EliminarInscricaoDisciplinaActionTest extends TestCase
{
    use RefreshDatabase;

    private function criarInscricao(): InscricaoDisciplina
    {
        $estabelecimento = Estabelecimento::create(['nome' => 'Escola Teste', 'tipo' => TipoEstabelecimentoEnum::PUBLICO->value, 'is_active' => true]);
        $anoLectivo = AnoLectivo::create(['estabelecimento_id' => $estabelecimento->id, 'nome' => '2026', 'data_inicio' => '2026-01-01', 'data_fim' => '2026-12-31', 'estado' => EstadoAnoLectivo::ATIVO]);
        $nivel = NivelAcademico::create(['estabelecimento_id' => $estabelecimento->id, 'codigo' => '1C', 'nome' => '1ª Classe', 'ordem' => 1, 'etapa_ensino' => 1]);
        $turma = Turma::create(['ano_lectivo_id' => $anoLectivo->id, 'nivel_academico_id' => $nivel->id, 'codigo' => 'T1', 'nome' => 'Turma 1']);
        $pessoa = DadosPessoal::create(['nome_completo' => 'Aluno Teste', 'numero_identificacao' => 'BI0001', 'tipo_pessoa' => DadosPessoal::TIPO_ALUNO]);
        $aluno = Aluno::create(['estabelecimento_id' => $estabelecimento->id, 'dados_pessoa_id' => $pessoa->id, 'numero_matricula' => '2026-0001']);
        (new CriarEnquadramentoAcademicoAlunoAction())->executar($aluno, nivelAcademicoId: $nivel->id);
        $matricula = app(CriarMatriculaAction::class)->executar($aluno, new MatriculaDTO(turmaId: $turma->id, anoLectivoId: $anoLectivo->id, dataMatricula: null, estado: null, observacoes: null));

        $plano = PlanoCurricular::create(['estabelecimento_id' => $estabelecimento->id, 'nivel_academico_id' => $nivel->id, 'codigo' => 'PL1', 'nome' => 'Plano 1']);
        $disciplina = Disciplina::create(['estabelecimento_id' => $estabelecimento->id, 'codigo' => 'MAT1', 'nome' => 'Matemática I']);
        $planoDisciplina = PlanoCurricularDisciplina::create(['plano_curricular_id' => $plano->id, 'disciplina_id' => $disciplina->id]);

        return app(CriarInscricaoDisciplinaAction::class)->executar($matricula, $planoDisciplina);
    }

    public function test_elimina_inscricao_ainda_inscrita(): void
    {
        $inscricao = $this->criarInscricao();

        app(EliminarInscricaoDisciplinaAction::class)->executar($inscricao);

        $this->assertSoftDeleted('inscricoes_disciplinas', ['id' => $inscricao->id]);
    }

    public function test_rejeita_eliminar_inscricao_concluida(): void
    {
        $inscricao = $this->criarInscricao();
        $inscricao = app(AlterarEstadoInscricaoDisciplinaAction::class)->executar($inscricao, EstadoInscricaoDisciplinaEnum::CONCLUIDA);

        $this->expectException(ValidationException::class);

        app(EliminarInscricaoDisciplinaAction::class)->executar($inscricao);
    }
}
```

Este ficheiro repete a fixture da Task 5 de propósito — cada teste de Action neste projecto constrói o seu próprio cenário (ver `MatriculaActionTest.php`, `AlterarEstadoAnoLectivoActionTest.php`), nunca instancia outra classe de teste para reutilizar um helper (o construtor de `TestCase` não corre `setUp()` fora do ciclo do PHPUnit).

- [ ] **Step 2: Correr os testes e confirmar que falham**

Run: `php artisan test Modules/Matricula/tests/Feature/EliminarInscricaoDisciplinaActionTest.php`
Expected: FAIL — classe `EliminarInscricaoDisciplinaAction` não existe

- [ ] **Step 3: Implementação**

```php
<?php

namespace Modules\Matricula\Actions;

use Illuminate\Validation\ValidationException;
use Modules\Matricula\Enums\EstadoInscricaoDisciplinaEnum;
use Modules\Matricula\Models\InscricaoDisciplina;

class EliminarInscricaoDisciplinaAction
{
    public function executar(InscricaoDisciplina $inscricao): void
    {
        if ($inscricao->estado !== EstadoInscricaoDisciplinaEnum::INSCRITA) {
            throw ValidationException::withMessages([
                'inscricao' => 'Só é possível eliminar uma inscrição ainda Inscrita. Para as restantes, altere o estado para Desistida.',
            ]);
        }

        $inscricao->delete();
    }
}
```

- [ ] **Step 4: Correr os testes e confirmar que passam**

Run: `php artisan test Modules/Matricula/tests/Feature/EliminarInscricaoDisciplinaActionTest.php Modules/Matricula/tests/Feature/AlterarEstadoInscricaoDisciplinaActionTest.php`
Expected: `4 passed`

- [ ] **Step 5: Commit**

```bash
git add Modules/Matricula/app/Actions/EliminarInscricaoDisciplinaAction.php Modules/Matricula/tests/Feature/EliminarInscricaoDisciplinaActionTest.php Modules/Matricula/tests/Feature/AlterarEstadoInscricaoDisciplinaActionTest.php
git commit -m "feat(matricula): add EliminarInscricaoDisciplinaAction"
```

---

## Task 7 — `GestaoInscricaoDisciplinaService` + FormRequests

**Files:**
- Create: `Modules/Matricula/app/Http/Requests/CriarInscricaoDisciplinaRequest.php`
- Create: `Modules/Matricula/app/Http/Requests/AlterarEstadoInscricaoDisciplinaRequest.php`
- Create: `Modules/Matricula/app/Services/GestaoInscricaoDisciplinaService.php`
- Create: `Modules/Matricula/tests/Feature/GestaoInscricaoDisciplinaServiceTest.php`

**Interfaces:**
- Consumes: `CriarInscricaoDisciplinaAction`, `AlterarEstadoInscricaoDisciplinaAction`, `EliminarInscricaoDisciplinaAction` (Tasks 2/5/6).
- Produces: `GestaoInscricaoDisciplinaService::criar(Matricula $matricula, CriarInscricaoDisciplinaRequest $request): InscricaoDisciplina`, `::alterarEstado(InscricaoDisciplina $inscricao, EstadoInscricaoDisciplinaEnum $novoEstado): InscricaoDisciplina`, `::eliminar(InscricaoDisciplina $inscricao): void` — consumido pelo Controller na Task 8.

- [ ] **Step 1: `CriarInscricaoDisciplinaRequest`**

```php
<?php

namespace Modules\Matricula\Http\Requests;

use App\Http\Requests\BaseRequest;

class CriarInscricaoDisciplinaRequest extends BaseRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('matricula.criar') ?? false;
    }

    public function rules(): array
    {
        return [
            'plano_curricular_disciplina_id' => ['required', 'integer', 'exists:plano_curricular_disciplinas,id'],
        ];
    }

    public function messages(): array
    {
        return [
            'plano_curricular_disciplina_id.required' => 'A disciplina é obrigatória.',
            'plano_curricular_disciplina_id.exists' => 'A disciplina seleccionada não existe.',
        ];
    }
}
```

- [ ] **Step 2: `AlterarEstadoInscricaoDisciplinaRequest`**

```php
<?php

namespace Modules\Matricula\Http\Requests;

use App\Http\Requests\BaseRequest;
use Illuminate\Validation\Rules\Enum;
use Modules\Matricula\Enums\EstadoInscricaoDisciplinaEnum;

class AlterarEstadoInscricaoDisciplinaRequest extends BaseRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('matricula.editar') ?? false;
    }

    public function rules(): array
    {
        return [
            'estado' => ['required', new Enum(EstadoInscricaoDisciplinaEnum::class)],
        ];
    }

    public function messages(): array
    {
        return [
            'estado.required' => 'O novo estado é obrigatório.',
        ];
    }
}
```

- [ ] **Step 3: Escrever o teste do Service que falha**

```php
<?php

namespace Modules\Matricula\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Modules\Aluno\Actions\CriarEnquadramentoAcademicoAlunoAction;
use Modules\Aluno\Models\Aluno;
use Modules\AnoLectivo\Enums\EstadoAnoLectivo;
use Modules\AnoLectivo\Models\AnoLectivo;
use Modules\Estabelecimento\Enums\TipoEstabelecimentoEnum;
use Modules\Estabelecimento\Models\Estabelecimento;
use Modules\Matricula\Actions\CriarMatriculaAction;
use Modules\Matricula\DTO\MatriculaDTO;
use Modules\Matricula\Enums\EstadoInscricaoDisciplinaEnum;
use Modules\Matricula\Http\Requests\AlterarEstadoInscricaoDisciplinaRequest;
use Modules\Matricula\Http\Requests\CriarInscricaoDisciplinaRequest;
use Modules\Matricula\Services\GestaoInscricaoDisciplinaService;
use Modules\Disciplina\Models\Disciplina;
use Modules\PlanoCurricular\Models\PlanoCurricular;
use Modules\PlanoCurricular\Models\PlanoCurricularDisciplina;
use Modules\Permissao\Database\Seeders\PermissaoDatabaseSeeder;
use Modules\Permissao\Enums\Perfil;
use Modules\Permissao\Models\Role;
use Modules\Turma\Models\NivelAcademico;
use Modules\Turma\Models\Turma;
use Modules\Usuario\Models\DadosPessoal;
use Modules\Usuario\Models\User;
use Tests\TestCase;

class GestaoInscricaoDisciplinaServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_criar_e_alterar_estado_via_service(): void
    {
        $this->seed(PermissaoDatabaseSeeder::class);
        $staff = User::create(['name' => 'Staff', 'email' => 'staff@example.com', 'password' => Hash::make('x')]);
        $staff->roles()->syncWithoutDetaching([Role::where('nome', Perfil::ADMIN_ESCOLA->value)->first()->id]);
        $this->actingAs($staff);

        $estabelecimento = Estabelecimento::create(['nome' => 'Escola Teste', 'tipo' => TipoEstabelecimentoEnum::PUBLICO->value, 'is_active' => true]);
        $anoLectivo = AnoLectivo::create(['estabelecimento_id' => $estabelecimento->id, 'nome' => '2026', 'data_inicio' => '2026-01-01', 'data_fim' => '2026-12-31', 'estado' => EstadoAnoLectivo::ATIVO]);
        $nivel = NivelAcademico::create(['estabelecimento_id' => $estabelecimento->id, 'codigo' => '1C', 'nome' => '1ª Classe', 'ordem' => 1, 'etapa_ensino' => 1]);
        $turma = Turma::create(['ano_lectivo_id' => $anoLectivo->id, 'nivel_academico_id' => $nivel->id, 'codigo' => 'T1', 'nome' => 'Turma 1']);
        $pessoa = DadosPessoal::create(['nome_completo' => 'Aluno Teste', 'numero_identificacao' => 'BI0001', 'tipo_pessoa' => DadosPessoal::TIPO_ALUNO]);
        $aluno = Aluno::create(['estabelecimento_id' => $estabelecimento->id, 'dados_pessoa_id' => $pessoa->id, 'numero_matricula' => '2026-0001']);
        (new CriarEnquadramentoAcademicoAlunoAction())->executar($aluno, nivelAcademicoId: $nivel->id);
        $matricula = app(CriarMatriculaAction::class)->executar($aluno, new MatriculaDTO(turmaId: $turma->id, anoLectivoId: $anoLectivo->id, dataMatricula: null, estado: null, observacoes: null));

        $plano = PlanoCurricular::create(['estabelecimento_id' => $estabelecimento->id, 'nivel_academico_id' => $nivel->id, 'codigo' => 'PL1', 'nome' => 'Plano 1']);
        $disciplina = Disciplina::create(['estabelecimento_id' => $estabelecimento->id, 'codigo' => 'MAT1', 'nome' => 'Matemática I']);
        $planoDisciplina = PlanoCurricularDisciplina::create(['plano_curricular_id' => $plano->id, 'disciplina_id' => $disciplina->id]);

        $criarRequest = CriarInscricaoDisciplinaRequest::create('/x', 'POST', ['plano_curricular_disciplina_id' => $planoDisciplina->id]);
        $criarRequest->setUserResolver(fn () => $staff);
        $service = app(GestaoInscricaoDisciplinaService::class);
        $inscricao = $service->criar($matricula, $criarRequest);

        $this->assertSame(EstadoInscricaoDisciplinaEnum::INSCRITA, $inscricao->estado);

        $alterarRequest = AlterarEstadoInscricaoDisciplinaRequest::create('/x', 'PATCH', ['estado' => EstadoInscricaoDisciplinaEnum::CONCLUIDA->value]);
        $alterarRequest->setUserResolver(fn () => $staff);
        $alterarRequest->validateResolved();
        $concluida = $service->alterarEstado($inscricao, EstadoInscricaoDisciplinaEnum::from((int) $alterarRequest->validated('estado')));

        $this->assertSame(EstadoInscricaoDisciplinaEnum::CONCLUIDA, $concluida->estado);
    }
}
```

- [ ] **Step 4: Correr o teste e confirmar que falha**

Run: `php artisan test Modules/Matricula/tests/Feature/GestaoInscricaoDisciplinaServiceTest.php`
Expected: FAIL — classe `GestaoInscricaoDisciplinaService` não existe

- [ ] **Step 5: Implementação do Service**

```php
<?php

namespace Modules\Matricula\Services;

use Modules\Matricula\Actions\AlterarEstadoInscricaoDisciplinaAction;
use Modules\Matricula\Actions\CriarInscricaoDisciplinaAction;
use Modules\Matricula\Actions\EliminarInscricaoDisciplinaAction;
use Modules\Matricula\Enums\EstadoInscricaoDisciplinaEnum;
use Modules\Matricula\Http\Requests\CriarInscricaoDisciplinaRequest;
use Modules\Matricula\Models\InscricaoDisciplina;
use Modules\Matricula\Models\Matricula;
use Modules\PlanoCurricular\Models\PlanoCurricularDisciplina;

class GestaoInscricaoDisciplinaService
{
    public function __construct(
        private CriarInscricaoDisciplinaAction $criarInscricao,
        private AlterarEstadoInscricaoDisciplinaAction $alterarEstadoInscricao,
        private EliminarInscricaoDisciplinaAction $eliminarInscricao,
    ) {
    }

    public function criar(Matricula $matricula, CriarInscricaoDisciplinaRequest $request): InscricaoDisciplina
    {
        $planoCurricularDisciplina = PlanoCurricularDisciplina::findOrFail($request->validated('plano_curricular_disciplina_id'));

        return $this->criarInscricao->executar($matricula, $planoCurricularDisciplina, auth()->id());
    }

    public function alterarEstado(InscricaoDisciplina $inscricao, EstadoInscricaoDisciplinaEnum $novoEstado): InscricaoDisciplina
    {
        return $this->alterarEstadoInscricao->executar($inscricao, $novoEstado, auth()->id());
    }

    public function eliminar(InscricaoDisciplina $inscricao): void
    {
        $this->eliminarInscricao->executar($inscricao);
    }
}
```

- [ ] **Step 6: Correr o teste e confirmar que passa**

Run: `php artisan test Modules/Matricula/tests/Feature/GestaoInscricaoDisciplinaServiceTest.php`
Expected: `1 passed`

- [ ] **Step 7: Commit**

```bash
git add Modules/Matricula/app/Http/Requests/CriarInscricaoDisciplinaRequest.php Modules/Matricula/app/Http/Requests/AlterarEstadoInscricaoDisciplinaRequest.php Modules/Matricula/app/Services/GestaoInscricaoDisciplinaService.php Modules/Matricula/tests/Feature/GestaoInscricaoDisciplinaServiceTest.php
git commit -m "feat(matricula): add GestaoInscricaoDisciplinaService and requests"
```

---

## Task 8 — Controller, rotas e permissões

**Files:**
- Create: `Modules/Matricula/app/Http/Controllers/InscricaoDisciplinaController.php`
- Modify: `Modules/Matricula/routes/web.php`
- Create: `Modules/Matricula/tests/Feature/InscricaoDisciplinaHttpTest.php`

**Interfaces:**
- Consumes: `GestaoInscricaoDisciplinaService` (Task 7).
- Produces: rotas `POST alunos/{aluno}/matriculas/{matricula}/disciplinas`, `PATCH alunos/{aluno}/matriculas/{matricula}/disciplinas/{inscricao}/estado`, `DELETE alunos/{aluno}/matriculas/{matricula}/disciplinas/{inscricao}`.

- [ ] **Step 1: Controller**

```php
<?php

namespace Modules\Matricula\Http\Controllers;

use App\Http\Controllers\Controller;
use Modules\Aluno\Models\Aluno;
use Modules\Matricula\Enums\EstadoInscricaoDisciplinaEnum;
use Modules\Matricula\Http\Requests\AlterarEstadoInscricaoDisciplinaRequest;
use Modules\Matricula\Http\Requests\CriarInscricaoDisciplinaRequest;
use Modules\Matricula\Models\InscricaoDisciplina;
use Modules\Matricula\Models\Matricula;
use Modules\Matricula\Services\GestaoInscricaoDisciplinaService;

class InscricaoDisciplinaController extends Controller
{
    public function __construct(
        private GestaoInscricaoDisciplinaService $service,
    ) {
    }

    public function store(CriarInscricaoDisciplinaRequest $request, Aluno $aluno, Matricula $matricula)
    {
        $this->authorize('matricula.criar');

        $this->service->criar($matricula, $request);

        return redirect()->back()->with('success', 'Inscrição em disciplina criada com sucesso.');
    }

    public function alterarEstado(AlterarEstadoInscricaoDisciplinaRequest $request, Aluno $aluno, Matricula $matricula, InscricaoDisciplina $inscricao)
    {
        $this->authorize('matricula.editar');

        $this->service->alterarEstado(
            $inscricao,
            EstadoInscricaoDisciplinaEnum::from((int) $request->validated('estado')),
        );

        return redirect()->back()->with('success', 'Estado da inscrição actualizado com sucesso.');
    }

    public function destroy(Aluno $aluno, Matricula $matricula, InscricaoDisciplina $inscricao)
    {
        $this->authorize('matricula.eliminar');

        $this->service->eliminar($inscricao);

        return redirect()->back()->with('success', 'Inscrição eliminada com sucesso.');
    }
}
```

- [ ] **Step 2: Rotas — adicionar dentro do grupo `alunos/{aluno}/matriculas` já existente em `Modules/Matricula/routes/web.php`**

```php
        Route::post('/{matricula}/disciplinas', [InscricaoDisciplinaController::class, 'store'])->middleware('can:matricula.criar')->name('disciplinas.store');
        Route::patch('/{matricula}/disciplinas/{inscricao}/estado', [InscricaoDisciplinaController::class, 'alterarEstado'])->middleware('can:matricula.editar')->name('disciplinas.alterar-estado');
        Route::delete('/{matricula}/disciplinas/{inscricao}', [InscricaoDisciplinaController::class, 'destroy'])->middleware('can:matricula.eliminar')->name('disciplinas.destroy');
```

Adicionar também o `use Modules\Matricula\Http\Controllers\InscricaoDisciplinaController;` no topo do ficheiro.

- [ ] **Step 3: Escrever o teste HTTP que falha**

```php
<?php

namespace Modules\Matricula\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Modules\Aluno\Actions\CriarEnquadramentoAcademicoAlunoAction;
use Modules\Aluno\Models\Aluno;
use Modules\AnoLectivo\Enums\EstadoAnoLectivo;
use Modules\AnoLectivo\Models\AnoLectivo;
use Modules\Estabelecimento\Enums\TipoEstabelecimentoEnum;
use Modules\Estabelecimento\Models\Estabelecimento;
use Modules\Matricula\Actions\CriarMatriculaAction;
use Modules\Matricula\DTO\MatriculaDTO;
use Modules\Matricula\Enums\EstadoInscricaoDisciplinaEnum;
use Modules\Disciplina\Models\Disciplina;
use Modules\PlanoCurricular\Models\PlanoCurricular;
use Modules\PlanoCurricular\Models\PlanoCurricularDisciplina;
use Modules\Permissao\Database\Seeders\PermissaoDatabaseSeeder;
use Modules\Permissao\Enums\Perfil;
use Modules\Permissao\Models\Role;
use Modules\Turma\Models\NivelAcademico;
use Modules\Turma\Models\Turma;
use Modules\Usuario\Models\DadosPessoal;
use Modules\Usuario\Models\User;
use Tests\TestCase;

class InscricaoDisciplinaHttpTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PermissaoDatabaseSeeder::class);
    }

    private function actingAsStaff(): User
    {
        $staff = User::create(['name' => 'Staff', 'email' => 'staff@example.com', 'password' => Hash::make('x')]);
        $staff->roles()->syncWithoutDetaching([Role::where('nome', Perfil::ADMIN_ESCOLA->value)->first()->id]);
        $this->actingAs($staff);

        return $staff;
    }

    public function test_cria_altera_estado_e_elimina_inscricao_via_http(): void
    {
        $this->actingAsStaff();
        $estabelecimento = Estabelecimento::create(['nome' => 'Escola Teste', 'tipo' => TipoEstabelecimentoEnum::PUBLICO->value, 'is_active' => true]);
        $anoLectivo = AnoLectivo::create(['estabelecimento_id' => $estabelecimento->id, 'nome' => '2026', 'data_inicio' => '2026-01-01', 'data_fim' => '2026-12-31', 'estado' => EstadoAnoLectivo::ATIVO]);
        $nivel = NivelAcademico::create(['estabelecimento_id' => $estabelecimento->id, 'codigo' => '1C', 'nome' => '1ª Classe', 'ordem' => 1, 'etapa_ensino' => 1]);
        $turma = Turma::create(['ano_lectivo_id' => $anoLectivo->id, 'nivel_academico_id' => $nivel->id, 'codigo' => 'T1', 'nome' => 'Turma 1']);
        $pessoa = DadosPessoal::create(['nome_completo' => 'Aluno Teste', 'numero_identificacao' => 'BI0001', 'tipo_pessoa' => DadosPessoal::TIPO_ALUNO]);
        $aluno = Aluno::create(['estabelecimento_id' => $estabelecimento->id, 'dados_pessoa_id' => $pessoa->id, 'numero_matricula' => '2026-0001']);
        (new CriarEnquadramentoAcademicoAlunoAction())->executar($aluno, nivelAcademicoId: $nivel->id);
        $matricula = app(CriarMatriculaAction::class)->executar($aluno, new MatriculaDTO(turmaId: $turma->id, anoLectivoId: $anoLectivo->id, dataMatricula: null, estado: null, observacoes: null));

        $plano = PlanoCurricular::create(['estabelecimento_id' => $estabelecimento->id, 'nivel_academico_id' => $nivel->id, 'codigo' => 'PL1', 'nome' => 'Plano 1']);
        $disciplina = Disciplina::create(['estabelecimento_id' => $estabelecimento->id, 'codigo' => 'MAT1', 'nome' => 'Matemática I']);
        $planoDisciplina = PlanoCurricularDisciplina::create(['plano_curricular_id' => $plano->id, 'disciplina_id' => $disciplina->id]);

        $this->post(route('matriculas.disciplinas.store', [$aluno, $matricula]), [
            'plano_curricular_disciplina_id' => $planoDisciplina->id,
        ])->assertSessionHasNoErrors()->assertRedirect();

        $inscricao = \Modules\Matricula\Models\InscricaoDisciplina::firstWhere('matricula_id', $matricula->id);
        $this->assertNotNull($inscricao);

        $this->patch(route('matriculas.disciplinas.alterar-estado', [$aluno, $matricula, $inscricao]), [
            'estado' => EstadoInscricaoDisciplinaEnum::DESISTIDA->value,
        ])->assertSessionHasNoErrors()->assertRedirect();
        $this->assertSame(EstadoInscricaoDisciplinaEnum::DESISTIDA, $inscricao->fresh()->estado);

        $this->delete(route('matriculas.disciplinas.destroy', [$aluno, $matricula, $inscricao]))
            ->assertSessionHasErrors('inscricao');
        $this->assertDatabaseHas('inscricoes_disciplinas', ['id' => $inscricao->id, 'deleted_at' => null]);
    }

    public function test_professor_recebe_403_ao_criar_inscricao(): void
    {
        $professor = User::create(['name' => 'Professor', 'email' => 'professor@example.com', 'password' => Hash::make('x')]);
        $professor->roles()->syncWithoutDetaching([Role::where('nome', Perfil::PROFESSOR->value)->first()->id]);
        $this->actingAs($professor);

        $this->post(route('matriculas.disciplinas.store', [1, 1]), ['plano_curricular_disciplina_id' => 1])->assertForbidden();
    }
}
```

- [ ] **Step 4: Correr o teste e confirmar que falha**

Run: `php artisan test Modules/Matricula/tests/Feature/InscricaoDisciplinaHttpTest.php`
Expected: FAIL — rota `matriculas.disciplinas.store` não existe

- [ ] **Step 5: Confirmar que as rotas ficaram registadas**

Run: `php artisan route:list --name=disciplinas`
Expected: as 3 rotas da Task 8 listadas

- [ ] **Step 6: Correr o teste e confirmar que passa**

Run: `php artisan test Modules/Matricula/tests/Feature/InscricaoDisciplinaHttpTest.php`
Expected: `2 passed`

- [ ] **Step 7: Correr a suite completa do módulo Matrícula**

Run: `php artisan test Modules/Matricula`
Expected: todos passam

- [ ] **Step 8: Commit**

```bash
git add Modules/Matricula/app/Http/Controllers/InscricaoDisciplinaController.php Modules/Matricula/routes/web.php Modules/Matricula/tests/Feature/InscricaoDisciplinaHttpTest.php
git commit -m "feat(matricula): add InscricaoDisciplina HTTP endpoints"
```

---

## Task 9 — Consulta: listar disciplinas de uma matrícula

**Files:**
- Modify: `Modules/Matricula/app/Services/MatriculaConsultaService.php`
- Create: `Modules/Matricula/tests/Feature/MatriculaConsultaServiceInscricoesTest.php`

**Interfaces:**
- Produces: `MatriculaConsultaService::listarDisciplinasDaMatricula(Matricula $matricula): Collection` — base para a UI da Fase 2 (fora deste plano).

- [ ] **Step 1: Escrever o teste que falha**

```php
<?php

namespace Modules\Matricula\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Aluno\Actions\CriarEnquadramentoAcademicoAlunoAction;
use Modules\Aluno\Models\Aluno;
use Modules\AnoLectivo\Enums\EstadoAnoLectivo;
use Modules\AnoLectivo\Models\AnoLectivo;
use Modules\Estabelecimento\Enums\TipoEstabelecimentoEnum;
use Modules\Estabelecimento\Models\Estabelecimento;
use Modules\Matricula\Actions\CriarInscricaoDisciplinaAction;
use Modules\Matricula\Actions\CriarMatriculaAction;
use Modules\Matricula\DTO\MatriculaDTO;
use Modules\Matricula\Services\MatriculaConsultaService;
use Modules\Disciplina\Models\Disciplina;
use Modules\PlanoCurricular\Models\PlanoCurricular;
use Modules\PlanoCurricular\Models\PlanoCurricularDisciplina;
use Modules\Turma\Models\NivelAcademico;
use Modules\Turma\Models\Turma;
use Modules\Usuario\Models\DadosPessoal;
use Tests\TestCase;

class MatriculaConsultaServiceInscricoesTest extends TestCase
{
    use RefreshDatabase;

    public function test_lista_disciplinas_da_matricula_com_nome_da_disciplina_carregado(): void
    {
        $estabelecimento = Estabelecimento::create(['nome' => 'Escola Teste', 'tipo' => TipoEstabelecimentoEnum::PUBLICO->value, 'is_active' => true]);
        $anoLectivo = AnoLectivo::create(['estabelecimento_id' => $estabelecimento->id, 'nome' => '2026', 'data_inicio' => '2026-01-01', 'data_fim' => '2026-12-31', 'estado' => EstadoAnoLectivo::ATIVO]);
        $nivel = NivelAcademico::create(['estabelecimento_id' => $estabelecimento->id, 'codigo' => '1C', 'nome' => '1ª Classe', 'ordem' => 1, 'etapa_ensino' => 1]);
        $turma = Turma::create(['ano_lectivo_id' => $anoLectivo->id, 'nivel_academico_id' => $nivel->id, 'codigo' => 'T1', 'nome' => 'Turma 1']);
        $pessoa = DadosPessoal::create(['nome_completo' => 'Aluno Teste', 'numero_identificacao' => 'BI0001', 'tipo_pessoa' => DadosPessoal::TIPO_ALUNO]);
        $aluno = Aluno::create(['estabelecimento_id' => $estabelecimento->id, 'dados_pessoa_id' => $pessoa->id, 'numero_matricula' => '2026-0001']);
        (new CriarEnquadramentoAcademicoAlunoAction())->executar($aluno, nivelAcademicoId: $nivel->id);
        $matricula = app(CriarMatriculaAction::class)->executar($aluno, new MatriculaDTO(turmaId: $turma->id, anoLectivoId: $anoLectivo->id, dataMatricula: null, estado: null, observacoes: null));

        $plano = PlanoCurricular::create(['estabelecimento_id' => $estabelecimento->id, 'nivel_academico_id' => $nivel->id, 'codigo' => 'PL1', 'nome' => 'Plano 1']);
        $disciplina = Disciplina::create(['estabelecimento_id' => $estabelecimento->id, 'codigo' => 'MAT1', 'nome' => 'Matemática I']);
        $planoDisciplina = PlanoCurricularDisciplina::create(['plano_curricular_id' => $plano->id, 'disciplina_id' => $disciplina->id]);
        app(CriarInscricaoDisciplinaAction::class)->executar($matricula, $planoDisciplina);

        $lista = app(MatriculaConsultaService::class)->listarDisciplinasDaMatricula($matricula);

        $this->assertCount(1, $lista);
        $this->assertSame('Matemática I', $lista->first()->planoCurricularDisciplina->disciplina->nome);
    }
}
```

- [ ] **Step 2: Correr o teste e confirmar que falha**

Run: `php artisan test Modules/Matricula/tests/Feature/MatriculaConsultaServiceInscricoesTest.php`
Expected: FAIL — método não existe

- [ ] **Step 3: Implementação — adicionar a `Modules/Matricula/app/Services/MatriculaConsultaService.php`**

Adicionar o import `use Modules\Matricula\Models\InscricaoDisciplina;` e, a seguir a `historicoDaMatricula()`, o método:

```php
    public function listarDisciplinasDaMatricula(Matricula $matricula): Collection
    {
        return InscricaoDisciplina::with('planoCurricularDisciplina.disciplina')
            ->where('matricula_id', $matricula->id)
            ->orderBy('data_inscricao')
            ->get();
    }
```

- [ ] **Step 4: Correr o teste e confirmar que passa**

Run: `php artisan test Modules/Matricula/tests/Feature/MatriculaConsultaServiceInscricoesTest.php`
Expected: `1 passed`

- [ ] **Step 5: Correr a suite completa do módulo e a suite completa do projecto**

Run: `php artisan test Modules/Matricula`
Expected: todos passam

Run: `php artisan test`
Expected: todos passam (nenhuma regressão noutros módulos)

- [ ] **Step 6: Commit**

```bash
git add Modules/Matricula/app/Services/MatriculaConsultaService.php Modules/Matricula/tests/Feature/MatriculaConsultaServiceInscricoesTest.php
git commit -m "feat(matricula): add listarDisciplinasDaMatricula query"
```

---

## Fora de escopo (Fase 2 e seguintes, não incluído neste plano)

- **Frontend** — páginas/componentes Vue para gerir inscrições (listagem na página do Aluno, modal de inscrição manual para o Superior, badges de estado). Precisa de um plano próprio, depois de validado o backend.
- **Notas/avaliação** — `nota_final`, faltas, pauta. Depende de decisões pedagógicas ainda não tomadas.
- **"Cadeira em atraso" assistida** — sugerir automaticamente ao aluno as disciplinas em atraso de anos anteriores (hoje a inscrição em disciplinas de um plano antigo é manual, via `CriarInscricaoDisciplinaAction` apontando para o `PlanoCurricularDisciplina` certo).
- **Efeitos em cascata ao eliminar/editar uma Matrícula** — hoje `InscricaoDisciplina` não reage a isso.
- **Renovação de inscrição** — uma `REPROVADA` não gera automaticamente uma nova inscrição no ano seguinte.
