# Financeiro: confirmação ao gravar um plano geral

Objectivo: um plano de propina sem alvos é o plano geral (todas as turmas do ano lectivo); gravá-lo nesse estado exige confirmação explícita.

## Servidor (autoritativo)
- Campo `confirmar_plano_geral` (boolean, opcional) em `ValidaPlanoPropina`; valores inválidos dão erro no próprio campo.
- `validarConfirmacaoDePlanoGeral()`: se a lista final de alvos (após `AlvosDoPlano::paraGravar`) é vazia e a confirmação não é verdadeira, erro em `confirmar_plano_geral`.
- Criar: sempre exigido sem alvos. Actualizar: só se o plano tinha alvos gravados; plano já geral não pergunta de novo. A regra de alvos obsoletos mantém-se.
- `CopiarPlanosPropinaAction` não passa pelo request: planos gerais copiados continuam válidos.

## Interface
- `PlanoPropinaFormModal.vue`: sem alvos ao submeter abre `ConfirmModal`; confirmar envia `confirmar_plano_geral: true`, cancelar mantém o formulário. Aviso inline (`bg-body-secondary`) sob o bloco de alvos e erro do campo visível.

## Testes
`PlanoPropinaTest`: sem flag 422 e nada criado; com flag cria; com alvos nunca pede; flags falsas/forjadas rejeitadas; específico para geral exige flag; geral para geral não exige. `AlvosObsoletosTest` ajustado.
