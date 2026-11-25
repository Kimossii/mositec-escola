# Auditoria da escola: cobrir as lacunas e criar o ecrã de consulta

## Intenção
`auditoria_tenant` (`AuditoriaTenant`, só acrescenta) e `RegistarAuditoriaTenantAction` existem, mas só `regra_cobranca.actualizada` os usa e não há consulta. Quem mexe em permissões, matrículas e justificações de faltas não deixa rasto, e a escola não tem onde ver o que há.

## Objectivos
1. Gravar em `auditoria_tenant` (antes/depois) as acções de: permissões, justificação de faltas, matrículas (criar/editar/eliminar/trancar).
2. Ecrã "Auditoria" na escola, com filtros por acção, utilizador, entidade e data, protegido por permissão nova.

## Não-objectivos
- Notas, pautas, presenças e estado da matrícula: mantêm os históricos próprios (`*Historico`, `RegistoHistorico`, `RegistarHistoricoMatriculaAction`). Sem duplicação.
- Sem edição/exportação do rasto. Sem alterar a auditoria da Plataforma.

## Abordagens consideradas
- A) Escrever tudo em `auditoria_tenant`, incluindo notas: duplica dados e peso. Rejeitada.
- B) Ecrã a unir `auditoria_tenant` e todos os históricos: formatos diferentes, muito trabalho. Rejeitada.
- **C (escolhida, aprovada pelo dono):** só as lacunas em `auditoria_tenant`, ecrã sobre ela.

## Design
- Cada Action injecta `RegistarAuditoriaTenantAction` e regista na mesma transacção, como em `AtualizarRegraCobrancaAction`. Nomes de acção: `<entidade>.<verbo>` (ex. `perfil.atribuido`, `permissoes_utilizador.sincronizadas`, `falta.justificada`, `matricula.criada`).
- Gravar só ids e campos de negócio, nunca segredos.
- Leitura: `AuditoriaConsultaService` (Core) paginado com filtros; controller fino; página Inertia `Auditoria` com tabela (cards em < 768px) e diff antes/depois.
- Permissão nova (enum `Modulo` + slug + seed em `RolePermissaoSeeder`), ligada ao menu no placeholder existente se houver.
- Isolamento por tenant já vem de `PertenceAoTenant`.

## Por decidir pelo construtor
Nomes exactos das acções, colunas de filtro, localização do menu.
