# Portal do Encarregado — desenho

Data: 2026-11-25 · Estado: **à espera de aprovação do dono**

## 1. Intenção

O encarregado é quem paga as propinas e consulta as notas do filho. Hoje não tem nenhuma área na app, e o boletim "para consulta" está parado à espera dele. Queremos um portal onde o encarregado entra com o seu login, escolhe o filho e vê o que o filho veria.

Prioridade comercial seguinte, retomada pelo dono em 2026-11-25 (estava adiada desde 2026-10-29).

## 2. Objectivos, não-objectivos, anti-objectivos

**Objectivos**
- O encarregado vê, para cada filho ligado a si, as mesmas páginas que o próprio aluno vê.
- Um encarregado com vários filhos troca de filho num selector.
- Cada página nova do aluno passa a existir também para o encarregado, sem trabalho duplicado.

**Não-objectivos (por agora)**
- Pagar online ou registar pagamentos pelo portal. Primeira versão é só consulta.
- Calendário da escola, avisos e dados da matrícula.
- Notificações (email, SMS).
- **Bloqueio por atraso de propina** (ver §11).
- Perfis personalizados para encarregados.

**Anti-objectivos (seria falha, mesmo que "funcione")**
- Um encarregado ver dados de um aluno que não é seu filho, ou de outra escola.
- Manter duas listas de permissões (uma do aluno, outra do encarregado) que se desencontram.
- Criar um limite novo de encarregados por aluno ou por escola.

## 3. Decisões do dono (fechadas)

1. **Sem limite por aluno.** A escola liga quantos encarregados quiser a cada aluno.
2. **Sem "responsável financeiro".** Não há coluna nem regra de "só um". Todo encarregado ligado ao aluno vê tudo o que o aluno vê (notas, faltas, propinas, etc.).
3. **O limite é o da licença.** Encarregado conta como utilizador, como já acontece (ver §6).
4. **O encarregado herda as permissões do perfil Aluno.** O que o Aluno puder ver, o encarregado vê, sobre o filho escolhido.
5. **Páginas, por esta ordem** (aprovada em 2026-11-25): selector + início; notas; faltas; propinas e multas; pagamentos e recibos; boletim; horário.

## 4. Abordagens consideradas

| | Como | Troca |
|---|---|---|
| **A. Módulo `Portal`** (escolhida por mim) | Módulo novo com páginas próprias. Controllers finos chamam os Services de leitura dos outros módulos. Uma única regra decide se o educando é do encarregado. | A segurança fica num só sítio e o portal do Aluno reaproveita-o. Custa registar um módulo novo. |
| B. Rotas em cada módulo | Cada módulo ganha as suas rotas "do encarregado". | A regra de acesso espalha-se por vários módulos. Esquecer uma expõe dados de outra família. |
| C. Reutilizar as páginas de staff | Filtrar as páginas de staff por educando. | Mistura staff e família. As permissões ficam confusas. |

A abordagem A ainda não foi confirmada explicitamente pelo dono. Foi a minha recomendação e a conversa seguiu nela.

## 5. Desenho

### 5.1 Herança de permissões
- O encarregado não tem lista própria. As permissões efectivas dele são as do perfil Aluno.
- Implementação: o encarregado **não** recebe as permissões do Aluno no seu próprio conjunto (senão o menu mostrava «As minhas notas» e as rotas do aluno abriam para quem não é aluno). O portal lê o perfil Aluno por `PermissionResolver::conjuntoDoPerfilAluno()`, sempre a lista actual, sem cópia. O encarregado tem só `area-encarregado.ver`.
- **Conta de encarregado é fixa, como a do Aluno:** só tem o perfil Encarregado, sem perfis extra e sem overrides. Quem for encarregado **e** funcionário/professor tem **duas contas separadas**, cada uma com o seu perfil. Não se acumula nada. (Decisão do dono, 2026-11-25.) Implementação: o mesmo bloqueio que o `AtribuirPerfilAction` já faz para o Aluno (`PerfilDeAlunoFixo`) passa a valer também para o Encarregado.

### 5.2 Regra de acesso a um educando
- Um serviço único devolve os educandos de um encarregado (via `encarregados_alunos`, já com `tenant_id`).
- Toda a página do portal recebe o educando como parâmetro. O servidor confirma sempre que o aluno é filho do utilizador autenticado, caso contrário responde 404.
- O aluno, ao abrir as suas próprias páginas, continua a ver só os seus dados (o educando é ele mesmo).

### 5.3 Reaproveitar as páginas do aluno
- "Minhas notas" e "Minhas faltas" hoje assumem que o utilizador autenticado é o aluno. Passam a receber o aluno alvo (o próprio, ou o filho validado), sem duplicar Services nem Vue.
- Cada página nova (propinas, recibos, boletim, horário) nasce já assim: uma permissão `*.ver-proprias` no perfil Aluno, um Service que recebe o aluno, uma página que serve aluno e encarregado.

### 5.4 Dados e tabelas
- Não há tabelas novas. `encarregados_alunos` (`encarregado_id`, `aluno_id`, `parentesco`, `tenant_id`) fica como está.
- O portal só lê: matrículas, cobranças/multas/pagamentos/recibos (Financeiro), notas e boletins (Avaliacao), faltas (Frequencia), horário (Horario).

### 5.5 Selector e navegação
- O selector de filho vive no cabeçalho do portal. Com um só filho, já vem seleccionado.
- Menu: um item "Portal" (ou equivalente) só para quem tem perfil Encarregado, com as páginas que as permissões do Aluno permitem.

## 6. Limites da licença
- `ContaUtilizadores` já conta como utilizador quem está activo e tem algum perfil que não seja só Aluno. O encarregado entra aí sem alterações.
- `UsuarioAction` já recusa o cadastro quando o limite está atingido.
- A confirmar na fatia 1: teste que prova que o encarregado gasta vaga de utilizador e que desactivá-lo a liberta.

## 7. Restrições do projecto
- Controllers finos: Service para leitura, Action para escrita.
- Módulo novo só carrega se estiver em `modules_statuses.json`.
- Permissões novas: precisam de slug real no enum `Modulo`/`Acao` e entrada no seeder de perfis de sistema.
- Uma tabela por migração (não há migrações previstas).
- Tenancy: tudo filtrado por tenant; testar isolamento entre escolas.
- UI: reutilizar o padrão visual existente, usável em telemóvel (cards abaixo de 768px), `bg-body-secondary` nos cartões internos, botão Cancelar vermelho.
- Testar o fluxo do ecrã com os mesmos payloads do Vue, não só as Actions.
- Nada de commit sem pedido do dono. Só `git add`.

## 8. Fatias

0. **Fundação:** herança de permissões, serviço de educandos, regra de acesso, selector, página de início, menu, módulo `Portal`.
1. **Notas:** refactor de "Minhas notas" para servir aluno e encarregado.
2. **Faltas:** idem.
3. **Propinas e multas:** permissão nova no perfil Aluno, Service, página.
4. **Pagamentos e recibos.**
5. **Boletim** (consulta e PDF).
6. **Horário.**

Cada fatia tem o seu plano e é entregue e testada antes da seguinte.

## 9. Fica para quem constrói
- Nomes de rotas, estrutura de pastas, desenho dos cartões do início.
- Como o selector guarda o filho escolhido (parâmetro de rota vs sessão), desde que o servidor valide sempre.
- (Fechado pelo dono) O encarregado **reflecte a situação do filho**: se o aluno estiver inactivo, trancado ou transferido, o que o encarregado vê é o mesmo que o próprio aluno veria nessa situação. Não há regra própria do encarregado. Quem constrói deve reutilizar a mesma verificação de estado que as páginas do aluno usam.

## 10. Riscos
- **Fuga entre famílias:** mitigada por regra única, 404 e testes de isolamento (encarregado A pedindo filho de B; cross-tenant).
- **Refactor de notas e faltas:** pode partir o que o aluno já usa. Mitigado mantendo os testes existentes e acrescentando os do encarregado.

## 11. Futuro (não agora): bloqueio de acesso por atraso de pagamento
O dono quer, mais tarde, que cada escola configure que, após X dias de atraso na propina (valor definido pela escola, por exemplo 2 meses), o aluno deixe de ver as suas notas no portal. **Não faz parte desta entrega.**

O que este desenho já faz para não o impedir: toda a página do portal passa por **um só ponto** que decide "este educando pode ser visto agora?" (hoje: ligação válida + estado do aluno). No futuro, a regra do atraso entra nesse ponto, e o efeito chega de uma vez ao aluno e ao encarregado. Não criar verificações espalhadas pelas páginas.
