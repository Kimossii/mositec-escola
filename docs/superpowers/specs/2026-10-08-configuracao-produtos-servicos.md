# Especificação: Configurações → Financeiro → Produtos / Serviços

**Data:** 2026-10-08
**Módulo:** Configurações / Financeiro
**Área:** Produtos / Serviços
**Tipo:** Especificação funcional e técnica
**Estado:** Pronto para implementação

---

## 1. Objetivo

Implementar a área:

```text
⚙️ Configurações
└── 💰 Financeiro
    ├── Configuração de Propinas
    ├── Regras de Cobrança
    ├── Métodos de Pagamento
    └── Produtos / Serviços
```

O objectivo deste componente é permitir que cada Tenant mantenha o seu **catálogo de produtos e serviços** que poderão, futuramente, ser utilizados em operações financeiras.

Esta área representa **configuração/catálogo**, e não uma operação de venda ou cobrança.

O estabelecimento define previamente os itens que disponibiliza. Os módulos operacionais poderão posteriormente utilizar esses itens.

---

# 2. Princípio fundamental

**Produtos e Serviços são conceitos diferentes, mas pertencem à mesma área funcional de configuração.**

Na navegação do sistema devem aparecer como:

```text
Produtos / Serviços
```

Não criar duas entradas de menu:

```text
Produtos
Serviços
```

A separação entre Produto e Serviço existe ao nível do domínio e do modelo de dados, não necessariamente ao nível da navegação.

---

# 3. Contexto funcional

O catálogo deverá servir como base para futuras operações.

Exemplo:

```text
Configurações
     │
     ▼
Produtos / Serviços
     │
     ├── Produto
     │
     └── Serviço
            │
            │ posteriormente utilizado por
            ▼
       Operação Financeira
            │
            ▼
         Pagamento
```

Exemplo de Produto:

```text
Uniforme Escolar
25.000 Kz
```

Exemplo de Serviço:

```text
Emissão de Certificado
5.000 Kz
```

Neste issue **não se implementa a operação financeira** decorrente desses itens.

---

# 4. Conceitos de domínio

## 4.1 Produto

Um Produto representa um **bem físico ou material** que o estabelecimento disponibiliza.

Exemplos:

* Uniforme Escolar
* Livro
* Caderno
* Cartão de Estudante
* Material Escolar

Um Produto poderá futuramente estar relacionado com Stock, Inventário, Compras e Vendas.

Esses comportamentos não fazem parte deste issue.

---

## 4.2 Serviço

Um Serviço representa uma **prestação disponibilizada pelo estabelecimento**.

Exemplos:

* Emissão de Certificado
* Emissão de Declaração
* Exame de Recurso
* Reprografia
* Transporte Escolar
* Outros serviços administrativos ou escolares

Um Serviço não representa um bem físico e não deverá possuir controlo de stock.

---

# 5. Produtos e Serviços não devem ser uma entidade genérica

Não adoptar uma tabela única deste género:

```text
produtos_servicos
├── id
├── tipo
├── nome
└── ...
```

com:

```text
tipo = PRODUTO
tipo = SERVICO
```

como mecanismo principal de modelação.

A decisão é manter entidades distintas:

```text
produtos
servicos
```

Isto permite que cada conceito evolua independentemente.

Por exemplo:

```text
Produto
   └── futuramente poderá possuir stock

Serviço
   └── não possui stock
```

A interface pode apresentar ambos dentro da mesma área funcional:

```text
Produtos / Serviços
```

sem obrigar o domínio a tratá-los como a mesma entidade.

---

# 6. Estrutura de navegação

A navegação deve ser:

```text
⚙️ Configurações
└── 💰 Financeiro
    └── Produtos / Serviços
```

Ao entrar na área:

```text
Produtos / Serviços
```

a interface deve permitir gerir ambos os tipos.

Uma abordagem aceitável é utilizar filtros ou separadores:

```text
[ Todos ] [ Produtos ] [ Serviços ]
```

A decisão exacta de UI pode seguir os padrões já utilizados pelo projecto, mantendo fidelidade ao template e às convenções existentes.

Não criar duas páginas independentes na navegação apenas para separar Produto e Serviço, salvo se a arquitectura existente justificar isso.

---

# 7. Produto — atributos

A entidade Produto deve possuir, no mínimo:

```text
id
tenant_id
nome
descricao
codigo
preco
activo
created_at
updated_at
```

### Nome

Obrigatório.

Identifica o produto no catálogo.

Exemplo:

```text
Uniforme Escolar
```

### Descrição

Opcional.

Permite fornecer informação adicional.

### Código

Opcional ou obrigatório conforme as convenções existentes do projecto.

O código deve identificar o produto dentro do Tenant.

Não assumir unicidade global.

Se for definida unicidade, ela deve ser **por Tenant**.

Exemplo:

```text
UNI-001
```

### Preço

Obrigatório.

Representa o preço configurado para utilização futura.

O valor deve ser armazenado utilizando a estratégia monetária já adoptada pelo projecto.

Não utilizar `float` para representar valores monetários.

### Estado

Deve permitir:

```text
Activo
Inactivo
```

Um Produto inactivo permanece registado, mas não deve ser disponibilizado para novas operações que exijam itens activos.

---

# 8. Serviço — atributos

A entidade Serviço deve possuir, no mínimo:

```text
id
tenant_id
nome
descricao
codigo
preco
activo
created_at
updated_at
```

### Nome

Obrigatório.

Exemplo:

```text
Emissão de Certificado
```

### Descrição

Opcional.

### Código

Opcional ou obrigatório conforme a decisão adoptada para o Produto.

A unicidade, caso exista, deve ser **por Tenant**.

### Preço

Obrigatório.

Deve utilizar a mesma estratégia monetária definida para Produtos.

### Estado

Deve permitir:

```text
Activo
Inactivo
```

Um Serviço inactivo não deve ser disponibilizado para novas operações que dependam de serviços activos.

---

# 9. Multi-tenancy

Esta funcionalidade é obrigatoriamente **tenant-aware**.

Cada Produto pertence a exactamente um Tenant:

```text
Tenant
  │
  └── 1:N Produtos
```

Cada Serviço pertence a exactamente um Tenant:

```text
Tenant
  │
  └── 1:N Serviços
```

O `tenant_id` é obrigatório.

Todas as operações devem respeitar o isolamento de Tenant:

* listar;
* visualizar;
* criar;
* editar;
* activar;
* desactivar;
* eliminar, caso eliminação seja permitida.

Um utilizador de um Tenant nunca deve conseguir consultar ou alterar Produtos/Serviços de outro Tenant através de manipulação de IDs, URLs ou requests.

Aplicar as convenções de tenancy já existentes no projecto.

Não introduzir uma segunda estratégia de isolamento.

---

# 10. Separação entre configuração e operação

Este issue implementa:

```text
CONFIGURAÇÃO
```

Não implementa:

```text
OPERAÇÃO
```

A configuração cria o catálogo:

```text
Produto
Serviço
```

A operação futura poderá utilizar esses registos:

```text
Produto
   ↓
Venda / Cobrança
   ↓
Pagamento
```

ou:

```text
Serviço
   ↓
Cobrança
   ↓
Pagamento
```

O simples cadastro de um Produto ou Serviço **não cria qualquer movimento financeiro**.

Não criar neste issue:

* pagamentos;
* recibos;
* dívidas;
* vendas;
* movimentos contabilísticos;
* stock;
* inventário.

---

# 11. Relação com Propinas

Produtos/Serviços não devem ser confundidos com Propinas.

São conceitos independentes:

```text
Plano de Propina
       │
       ▼
Propina operacional
       │
       └── Matrícula
```

Enquanto:

```text
Produto
       │
       ▼
futura operação de venda/cobrança
```

e:

```text
Serviço
       │
       ▼
futura operação de cobrança
```

Não criar neste issue qualquer relação entre:

```text
produtos
servicos
```

e:

```text
matriculas
propinas
```

---

# 12. Estado activo/inactivo

A desactivação deve ser preferida à eliminação quando o item já tiver potencial de utilização histórica.

Regra conceptual:

```text
Activo
  ↓
pode ser utilizado por novas operações

Inactivo
  ↓
não pode ser utilizado por novas operações
```

A existência de histórico futuro não deve ser quebrada pela remoção física do cadastro.

A estratégia exacta de `delete` deve respeitar os padrões já existentes no projecto.

Não implementar neste issue uma estratégia complexa de soft delete se ela não fizer parte das convenções actuais do projecto.

---

# 13. Histórico e alterações de preço

O preço configurado no Produto/Serviço representa o **preço actual do catálogo**.

Não implementar neste issue versionamento de preços.

Importante para a arquitectura futura:

> Operações financeiras não devem depender exclusivamente do preço actual do catálogo depois de terem sido registadas.

Quando uma futura operação utilizar um Produto ou Serviço, deverá existir uma estratégia própria para preservar o valor efectivamente aplicado naquela operação.

Exemplo futuro:

```text
Produto
Preço actual: 25.000 Kz

        ↓

Operação criada
Valor aplicado: 25.000 Kz
```

Se posteriormente:

```text
Produto
Preço actual: 30.000 Kz
```

a operação histórica anterior não deverá ser alterada para 30.000 Kz.

A implementação dessa regra pertence ao módulo operacional futuro, mas a modelação actual não deve impedir esse comportamento.

---

# 14. Código

Caso sejam utilizados códigos:

```text
Produto:
UNI-001
UNI-002

Serviço:
SER-001
SER-002
```

O sistema não deve exigir que o estabelecimento siga uma convenção específica de nomenclatura além das regras mínimas definidas pela aplicação.

Se houver unicidade, ela deve ser limitada ao Tenant e à entidade correspondente, salvo decisão posterior em contrário.

---

# 15. Preços

Os preços devem ser tratados como valores monetários.

Não utilizar:

```text
float
double
```

para persistência de valores monetários.

Utilizar a estratégia já estabelecida no projecto para valores financeiros.

A moeda não deve ser duplicada em cada Produto/Serviço caso o sistema já possua uma configuração monetária a nível superior.

Para o contexto actual, assumir a moeda configurada pelo estabelecimento/sistema conforme a arquitectura existente.

Não introduzir neste issue um sistema completo de moedas.

---

# 16. Interface

A interface deve seguir os padrões existentes do projecto:

* Vue 3;
* Inertia;
* template visual já adoptado;
* PT-PT;
* componentes existentes;
* tabelas/listagens existentes;
* modais/formulários existentes, quando aplicável.

Não introduzir Livewire.

Não criar uma nova linguagem visual.

A página deve permitir, no mínimo:

### Listagem

* pesquisar;
* filtrar por tipo;
* visualizar estado;
* visualizar preço;
* distinguir Produto de Serviço.

### Criação

Permitir criar:

```text
Produto
```

ou:

```text
Serviço
```

### Edição

Permitir alterar os dados configuráveis.

### Estado

Permitir activar/desactivar.

A UX exacta deve reutilizar padrões já existentes no projecto.

---

# 17. Arquitectura

Respeitar a arquitectura existente do projecto.

Sempre que aplicável, utilizar a separação:

```text
DTO
Repository
Service
Action
```

Não introduzir lógica de negócio directamente nos Controllers.

A implementação deve seguir os módulos e namespaces existentes.

Antes de criar novas abstrações, verificar como funcionalidades semelhantes já estão implementadas no projecto e reutilizar os padrões existentes.

---

# 18. Modelo de dados

Criar:

```text
produtos
servicos
```

Ambas devem possuir:

```text
tenant_id
```

e os campos definidos anteriormente.

Devem existir índices apropriados para:

* `tenant_id`;
* estado;
* código, caso utilizado em pesquisa/unicidade.

As constraints devem reforçar o isolamento e as regras de integridade sempre que possível.

---

# 19. Segurança

A implementação deve garantir:

### Isolamento de Tenant

Não confiar apenas no `tenant_id` enviado pelo frontend.

O Tenant deve ser determinado pelo contexto autenticado/actual da aplicação.

### Autorização

As operações devem respeitar o sistema de permissões existente.

Não criar um mecanismo paralelo de autorização.

### Mass assignment

Utilizar as práticas já existentes no projecto para impedir atribuição indevida de campos.

### IDs externos

Não assumir que conhecer o ID de um registo concede acesso ao mesmo.

Todas as queries devem estar limitadas ao Tenant/contexto autorizado.

---

# 20. Testes

Criar testes cobrindo pelo menos:

### Produtos

* criar Produto;
* listar Produtos;
* visualizar Produto;
* editar Produto;
* activar Produto;
* desactivar Produto;
* impedir acesso a Produto de outro Tenant.

### Serviços

* criar Serviço;
* listar Serviços;
* visualizar Serviço;
* editar Serviço;
* activar Serviço;
* desactivar Serviço;
* impedir acesso a Serviço de outro Tenant.

### Validação

Testar:

* nome obrigatório;
* preço válido;
* código, quando aplicável;
* estado;
* isolamento por Tenant.

### Regra fundamental

Um Tenant A nunca deve conseguir:

```text
GET /produtos/{id-do-tenant-B}
```

ou equivalente através de qualquer endpoint disponível.

O mesmo se aplica a Serviços.

---

# 21. Fora do âmbito

Não implementar neste issue:

## Vendas

* carrinho;
* venda;
* linha de venda;
* factura;
* checkout;
* devoluções.

## Stock

* inventário;
* entradas;
* saídas;
* ajustes;
* armazéns;
* fornecedores;
* compras;
* stock mínimo.

## Financeiro operacional

* pagamentos;
* recibos;
* dívidas;
* distribuição de pagamentos;
* créditos/saldo a favor.

## Propinas

* geração de propinas;
* associação de planos a matrículas;
* cobrança de propinas;
* vencimentos;
* atrasos;
* pagamentos parciais.

## Fiscalidade

* impostos;
* IVA;
* retenções;
* documentos fiscais;
* séries fiscais.

Esses temas deverão possuir os seus próprios issues/especificações.

---

# 22. Critérios de aceitação

## Navegação

* [ ] Existe `Configurações → Financeiro → Produtos / Serviços`.
* [ ] Produtos e Serviços aparecem como uma única área funcional.
* [ ] Não existem duas entradas independentes de menu para Produtos e Serviços.

## Produtos

* [ ] É possível criar Produtos.
* [ ] É possível editar Produtos.
* [ ] É possível activar/desactivar Produtos.
* [ ] Produto possui nome.
* [ ] Produto possui preço.
* [ ] Produto pertence a um Tenant.
* [ ] Produto pode possuir descrição.
* [ ] Produto pode possuir código.

## Serviços

* [ ] É possível criar Serviços.
* [ ] É possível editar Serviços.
* [ ] É possível activar/desactivar Serviços.
* [ ] Serviço possui nome.
* [ ] Serviço possui preço.
* [ ] Serviço pertence a um Tenant.
* [ ] Serviço pode possuir descrição.
* [ ] Serviço pode possuir código.

## Domínio

* [ ] Produto e Serviço são entidades distintas.
* [ ] Não existe uma entidade genérica `produtos_servicos` apenas para representar ambos.
* [ ] Produtos não possuem regras de Stock neste issue.
* [ ] Serviços não possuem regras de Stock.
* [ ] Produtos/Serviços não são associados directamente a Matrículas.
* [ ] Produtos/Serviços não são Propinas.

## Tenancy

* [ ] Existe isolamento completo por Tenant.
* [ ] Um Tenant não consegue consultar dados de outro Tenant.
* [ ] Um Tenant não consegue alterar dados de outro Tenant.
* [ ] O `tenant_id` não é confiado ao frontend como mecanismo de segurança.

## Operação

* [ ] Nenhuma venda é implementada.
* [ ] Nenhum pagamento é implementado.
* [ ] Nenhum movimento financeiro é criado pelo cadastro.
* [ ] Nenhum Stock é implementado.
* [ ] Nenhum Inventário é implementado.

## Qualidade

* [ ] Testes automatizados cobrem as regras de negócio.
* [ ] A implementação segue os padrões arquitecturais existentes.
* [ ] A interface segue os padrões visuais existentes.
* [ ] Não são introduzidas dependências desnecessárias.
* [ ] O build frontend continua funcional.
* [ ] A suite de testes existente continua a passar.

---

# 23. Resultado esperado

No final deste issue, o estabelecimento deverá conseguir manter um catálogo:

```text
⚙️ Configurações
└── 💰 Financeiro
    └── Produtos / Serviços
```

com itens como:

```text
┌──────────────────────────┬───────────┬───────────┬──────────┐
│ Nome                     │ Tipo      │ Preço     │ Estado   │
├──────────────────────────┼───────────┼───────────┼──────────┤
│ Uniforme Escolar         │ Produto   │ 25.000 Kz │ Activo   │
│ Livro de Matemática      │ Produto   │  8.000 Kz │ Activo   │
│ Cartão de Estudante      │ Produto   │  2.000 Kz │ Activo   │
│ Emissão de Certificado   │ Serviço   │  5.000 Kz │ Activo   │
│ Emissão de Declaração    │ Serviço   │  3.000 Kz │ Activo   │
└──────────────────────────┴───────────┴───────────┴──────────┘
```

Este catálogo será a base para futuras funcionalidades de:

```text
Produtos
   ├── Stock
   └── Vendas

Serviços
   └── Cobranças

Ambos
   └── Pagamentos
```

A implementação deste issue **não deve antecipar esses módulos**.

---

# 24. Decisão arquitectural final

A decisão deste documento é:

```text
NAVEGAÇÃO
────────────────────────────
Configurações
└── Financeiro
    └── Produtos / Serviços


DOMÍNIO
────────────────────────────
Produtos
    └── entidade própria

Serviços
    └── entidade própria


FUTURO
────────────────────────────
Produto  → Vendas / Stock
Serviço  → Cobranças
Ambos    → Pagamentos
```

**Unificar a experiência de configuração não significa unificar as entidades de domínio.**

Essa distinção deve ser preservada durante toda a implementação.
