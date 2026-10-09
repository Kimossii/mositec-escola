# Financeiro — Copiar planos de propina de outro ano lectivo — Plano

**Goal:** Permitir copiar os planos de propina activos de um ano lectivo para outro, ficando os novos planos inactivos para revisão, com resumo do que foi copiado e do que foi ignorado (e porquê).

**Spec:** `docs/superpowers/specs/2026-10-08-modulo-financeiro-configuracao-design.md` (subsecção "Copiar planos de outro ano").

## Decisões

- Rota `POST /financeiro/configuracao/planos-propina/copiar` (`financeiro.configuracao.planos-propina.copiar`), `can:plano-propina.criar` no middleware, no controller e no FormRequest.
- Uma `DB::transaction`; planos processados por id. Só activos da origem; `tenant_id` nunca vem do cliente (vem de `PertenceAoTenant`), estado `INATIVO`, autoria pelos traits existentes.
- Ignorados com motivo PT-PT: inactivo na origem, alvo de turma, alvo eliminado, nome já existente no destino, colisão (reutiliza `PlanoPropinaConsultaService::colisaoDeAlvos` com as competências do calendário do destino; os copiados antes na mesma execução já estão na BD e contam).
- Resultado: `ResumoCopiaPlanosDTO`, enviado como flash `copia_planos` (partilhado em `HandleInertiaRequests`) e mostrado no modal.

## Ficheiros

- `Actions/CopiarPlanosPropinaAction.php`, `DTO/CopiarPlanosPropinaDTO.php`, `DTO/ResumoCopiaPlanosDTO.php`
- `Http/Requests/CopiarPlanosPropinaRequest.php`, `Http/Controllers/PlanoPropinaController.php` (`copiar`), `Services/GestaoPlanoPropinaService.php` (`copiar`), `routes/web.php`
- `app/Http/Middleware/HandleInertiaRequests.php` (flash `copia_planos`)
- `resources/js/Components/PlanosPropina/CopiarPlanosModal.vue`, `resources/js/Pages/PlanosPropina/Index.vue`
- `tests/Feature/CopiarPlanosPropinaTest.php`

## Tarefas

- [x] Testes (RED): 14 testes cobrindo permissões, campos copiados, origem intacta, cada motivo de exclusão, colisões, validação, tenant e idempotência.
- [x] DTOs, FormRequest, Action, Service, controller e rota (GREEN).
- [x] Flash `copia_planos` nas props partilhadas.
- [x] Modal e botão "Copiar de outro ano" (só com `plano-propina.criar`), com resumo e aviso de que os planos ficam inactivos.
- [x] Spec actualizado; suite completa e `npm run build`.
