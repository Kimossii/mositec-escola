# 2FA Fase 2 (super admin) — design curto

Estado: aprovado pelo dono em 2026-11-25.

## Intenção
Dar ao super admin (conta mais poderosa: licenças, todas as escolas) uma camada extra opcional de segurança no login da Plataforma. Funciona offline (só TOTP).

## Objectivos
- Activar/desactivar 2FA num ecrã da Plataforma (senha + QR + confirmação por código + códigos de recuperação).
- Desafio no login só para quem activou; os restantes entram como hoje.
- Recuperação por comando artisan, ao lado de `RedefinirSuperAdminCommand`.
- Registo na auditoria da Plataforma: activar, desactivar, desafio falhado, recuperação.

## Não-objectivos / anti-objectivos
- **Nunca obrigatório** (igual à Fase 1).
- Sem email/SMS/push.
- Não tocar no Fortify nem no login da escola.

## Restrições
- Guard `auth:plataforma` e tabela `super_admins` próprios; sem colunas de 2FA hoje.
- Controllers finos (Service/Action), uma tabela/alteração por migração, `use` em vez de FQN, Cancelar em vermelho, reaproveitar padrões visuais.
- Testar o fluxo real (HTTP com payloads do Vue), não só Actions.

## Abordagens consideradas
1. **Fluxo próprio no módulo Plataforma, reaproveitando TOTP e códigos de recuperação da Fase 1** (escolhida): isolado, pequeno.
2. Guard `plataforma` no Fortify: Fortify é acoplado a `User`; arrisca o login da escola. Rejeitada.
3. Pacote externo: dependência desnecessária. Rejeitada.

## Desenho
- Migração com colunas de segredo, códigos de recuperação e confirmação em `super_admins`.
- Login: após `AutenticarSuperAdminAction`, se 2FA activo, guardar desafio pendente na sessão e pedir código (ou código de recuperação); só então `AbrirSessaoDoSuperAdminAction`. Limitador de tentativas aplica-se ao código.
- Desactivar pede a senha.

## Deixado ao implementador
Nomes de rotas, estrutura das páginas Inertia, formato exacto das colunas, reutilização concreta dos serviços da Fase 1.
