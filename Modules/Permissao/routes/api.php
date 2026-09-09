<?php

// Nenhuma rota de API pública para este módulo. `PermissaoController` só
// serve páginas Inertia (permissoes.* em routes/web.php), sem checagem de
// permissão própria nos métodos index/show — expor isso aqui sem gate
// deixaria a matriz de permissões (perfis, ações, quem tem o quê) visível a
// qualquer utilizador autenticado. Se um dia surgir consumo real por API
// (app mobile, etc.), criar endpoints dedicados com o mesmo `can:` das
// rotas web equivalentes, não reaproveitar o controller Inertia.
