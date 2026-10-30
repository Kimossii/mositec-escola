# Performance — notas de operação

## OPcache (PHP)
O PHP, por omissão, lê e compila os ficheiros `.php` a cada pedido. O OPcache guarda o resultado compilado em memória
e reutiliza-o: o mesmo código corre sem voltar a ser lido do disco. Num projecto com centenas de classes (módulos,
serviços, vendor) é das melhores relações esforço/ganho.

Importante: `php -i` na linha de comandos mostra o PHP **da consola** (`opcache.enable_cli=Off` é normal). O que conta é
o PHP **do servidor web** (FPM ou Apache), que tem o seu próprio `php.ini`. Para confirmar, numa rota temporária ou
no `php-fpm` com `php-fpm -i | grep opcache.enable`, ou `phpinfo()` servido pelo próprio servidor.

Valores recomendados em produção (`php.ini` do FPM):

    opcache.enable=1
    opcache.memory_consumption=256
    opcache.max_accelerated_files=20000
    opcache.validate_timestamps=0   ; com 0, reiniciar o FPM a cada deploy (php-fpm reload)

## Redis para cache e sessão
O código já é compatível (não usa `Cache::flush` nem tags; as chaves de tenant e de permissões são prefixadas/versionadas).
Falta só a infraestrutura. Quando a BD mostrar carga por leituras de cache e sessão:

1. Instalar o servidor Redis e a extensão `phpredis` no PHP.
2. No `.env`: `CACHE_STORE=redis`, `SESSION_DRIVER=redis`, `REDIS_HOST`/`REDIS_PASSWORD` conforme o servidor.
3. Se o Redis for partilhado com outra aplicação, definir `CACHE_PREFIX` e `REDIS_PREFIX` próprios.
4. `php artisan optimize:clear` e reiniciar o worker da fila.

Efeitos: as sessões actuais (tabela `sessions`) deixam de valer — todos têm de iniciar sessão uma vez; a cache
começa vazia. Reverter é voltar as duas variáveis a `database`.
