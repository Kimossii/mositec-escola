<?php

namespace Modules\Permissao\Enums;

enum Modulo: int
{
    case USUARIO = 0;
    case AUTORIZACAO = 1;
    case ANO_LECTIVO = 2;
    case LICENCA = 3;
    case ALUNO = 4;
    case PROFESSOR = 5;
    case TURMAS = 6;
    case MATRICULA = 7;
    case DISCIPLINA = 8;
    case NOTA = 9;
    case ESTABELECIMENTO = 10;
    case HORARIO = 11;
    case INFRAESTRUTURA = 12;
    case CURSO = 13;
    case PLANO_CURRICULAR = 14;
    case DOCUMENTO_PESSOA = 15;
    case SENHA_UTILIZADOR = 16;
    case REGRA_COBRANCA = 17;
    case METODO_PAGAMENTO = 18;
    case CATALOGO_FINANCEIRO = 19;
    case PLANO_PROPINA = 20;
    case MOEDA_CAMBIO = 21;

    public function slug(): string
    {
        return match ($this) {
            self::USUARIO => 'usuario',
            self::AUTORIZACAO => 'autorizacao',
            self::ANO_LECTIVO => 'ano-lectivo',
            self::LICENCA => 'licenca',
            self::ALUNO => 'aluno',
            self::PROFESSOR => 'professor',
            self::TURMAS => 'turmas',
            self::MATRICULA => 'matricula',
            self::DISCIPLINA => 'disciplina',
            self::NOTA => 'nota',
            self::ESTABELECIMENTO => 'estabelecimento',
            self::HORARIO => 'horario',
            self::INFRAESTRUTURA => 'infraestrutura',
            self::CURSO => 'curso',
            self::PLANO_CURRICULAR => 'plano-curricular',
            self::DOCUMENTO_PESSOA => 'documento-pessoa',
            self::SENHA_UTILIZADOR => 'senha-utilizador',
            self::REGRA_COBRANCA => 'regra-cobranca',
            self::METODO_PAGAMENTO => 'metodo-pagamento',
            self::CATALOGO_FINANCEIRO => 'catalogo-financeiro',
            self::PLANO_PROPINA => 'plano-propina',
            self::MOEDA_CAMBIO => 'moeda-cambio',
        };
    }

    public static function fromSlug(string $slug): ?self
    {
        foreach (self::cases() as $modulo) {
            if ($modulo->slug() === $slug) {
                return $modulo;
            }
        }

        return null;
    }

    public function label(): string
    {
        return match ($this) {
            self::USUARIO => 'Utilizadores',
            self::AUTORIZACAO => 'Autorização',
            self::ANO_LECTIVO => 'Ano Lectivo',
            self::LICENCA => 'Licença',
            self::ALUNO => 'Aluno',
            self::PROFESSOR => 'Professor',
            self::TURMAS => 'Turmas',
            self::MATRICULA => 'Matrícula',
            self::DISCIPLINA => 'Disciplina',
            self::NOTA => 'Nota',
            self::ESTABELECIMENTO => 'Estabelecimento',
            self::HORARIO => 'Horário',
            self::INFRAESTRUTURA => 'Infraestrutura',
            self::CURSO => 'Curso',
            self::PLANO_CURRICULAR => 'Plano Curricular',
            self::DOCUMENTO_PESSOA => 'Documento Pessoa',
            self::SENHA_UTILIZADOR => 'Senha de Utilizador',
            self::REGRA_COBRANCA => 'Regra de Cobrança',
            self::METODO_PAGAMENTO => 'Método de Pagamento',
            self::CATALOGO_FINANCEIRO => 'Catálogo Financeiro',
            self::PLANO_PROPINA => 'Plano de Propina',
            self::MOEDA_CAMBIO => 'Moeda e Câmbio',
        };
    }
}
