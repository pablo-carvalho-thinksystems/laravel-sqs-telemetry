# Laravel SQS Telemetry SDK

Um pacote Laravel projetado para capturar exceções (tratadas e não tratadas), interceptar requisições HTTP, monitorar queries de banco, comandos Artisan e operações de cache, enviando tudo de forma assíncrona (em lotes) para a AWS SQS sem bloquear o tempo de resposta da aplicação principal.

**Compatível com PHP 7.2+ e Laravel 6 até 13.**

## Compatibilidade

| Laravel | PHP     | Suporte |
|---------|---------|---------|
| 6.x     | 7.2+    | ✅ (sem HTTP Client timeline) |
| 7.x     | 7.2+    | ✅      |
| 8.x     | 7.3+    | ✅      |
| 9.x     | 8.0+    | ✅      |
| 10.x    | 8.1+    | ✅      |
| 11.x    | 8.2+    | ✅      |
| 12.x    | 8.2+    | ✅      |
| 13.x    | 8.3+    | ✅      |

Também roda em **Acorn (WordPress)**, que exige configuração específica — veja
[Hosts que não roteiam pelo kernel](#hosts-que-não-roteiam-pelo-kernel-acorn--wordpress).

> **Nota:** No Laravel 6, os listeners de HTTP Client (`ResponseReceived`, `ConnectionFailed`) não serão registrados, pois o HTTP Client foi introduzido no Laravel 7. Todas as demais funcionalidades (queries, cache, commands, exceptions) funcionam normalmente.

## Por que usar este SDK?

O PHP rodando no modelo tradicional FPM "morre" (é encerrado) ao final de cada requisição. Registros síncronos em serviços externos (como AWS) afetam diretamente o tempo de resposta para o usuário final, deixando a aplicação mais lenta.

Este SDK resolve esse problema usando um **Buffer (Singleton) em Memória**. As telemetrias capturadas (Requisições, Exceções, Queries, Commands, Cache) são armazenadas temporariamente em um array na memória da aplicação durante o processamento da requisição.

Somente **depois** que o Servidor Web envia a resposta de volta ao navegador do cliente (via FastCGI), o Laravel dispara o gancho do ciclo de vida chamado `app()->terminating()`. Este SDK registra um listener (ouvinte) exatamente nesse ponto para:
1. Recolher o array de itens armazenados na memória.
2. Agrupar em lotes (uma vez que a API da AWS SQS possui um limite de 10 mensagens por lote).
3. Executar o envio (I/O de rede) em background, de modo **totalmente não bloqueante** para a experiência do usuário.

Para aplicações executadas via **Laravel Octane**, este SDK é totalmente seguro: O buffer é forçosamente limpo sempre que as mensagens são enviadas, prevenindo *memory leaks* (vazamento de memória) entre as requisições.

## Instalação

```bash
composer require pablocarvalho/laravel-sqs-telemetry
```

Ou, para usar via *path repository* localmente:

```json
"repositories": [
    {
        "type": "path",
        "url": "../laravel-sqs-telemetry"
    }
],
```

## Configuração

Publique o arquivo de configuração e prepare suas variáveis de ambiente:

```bash
php artisan vendor:publish --tag=sqs-telemetry-config
```

Em seguida, adicione as variáveis necessárias ao seu arquivo `.env`:
```env
SQS_TELEMETRY_ENABLED=true
SQS_TELEMETRY_QUEUE_URL="https://sqs.us-east-1.amazonaws.com/123456789012/my-telemetry-queue"
AWS_ACCESS_KEY_ID="your-key"
AWS_SECRET_ACCESS_KEY="your-secret"
AWS_DEFAULT_REGION="us-east-1"
SQS_TELEMETRY_BATCH_SIZE=10

# AI Configs (Opcional - Requer OpenAI Key)
SQS_TELEMETRY_AI_ENABLED=true
SQS_TELEMETRY_AI_API_KEY="sk-..."
```

## Custo por request

Cada request registrada termina com uma chamada de rede ao SQS. Em hosts que
mantêm a thread ocupada até o script acabar (FPM, FrankenPHP em modo clássico),
esse custo é de **thread**, não só de latência percebida — a resposta já saiu,
mas o worker continua preso. Três mecanismos existem para controlar isso.

### Sampling

A fração de requests que registram telemetria. O sorteio acontece **uma vez por
request, antes de qualquer listener trabalhar**, então uma request não sorteada
não paga quase nada: sem timeline, sem buffer, sem chamada ao SQS.

```env
SQS_TELEMETRY_SAMPLING_RATE=0.05          # 5% das requests
SQS_TELEMETRY_ALWAYS_RECORD_EXCEPTIONS=true
```

Exceções ignoram o sorteio por padrão — um erro nunca vale a pena descartar, por
mais agressivo que seja o sampling. Requests de console são sempre registradas
(são poucas e cada uma interessa individualmente).

### Limites de payload

O SQS limita uma chamada `SendMessageBatch` a **256 KB somando todas as
entradas**, então uma timeline gorda não só incha o payload: ela derruba o batch
inteiro, levando junto as outras mensagens. Os limites impedem que uma request
patológica custe as demais.

```env
SQS_TELEMETRY_MAX_TIMELINE_EVENTS=200     # importa em stacks com muitas queries
SQS_TELEMETRY_MAX_LOGS_PER_REQUEST=50
SQS_TELEMETRY_MAX_MESSAGE_BYTES=240000
```

Quando a timeline é cortada, um evento `timeline_truncated` entra no lugar com a
contagem do que foi descartado — uma timeline truncada que não avisa se lê como
uma timeline completa. Se ainda assim a mensagem não couber, os campos mais
pesados (`timeline`, `payload`, `headers`, `stack_trace`) são descartados em
ordem e a mensagem sai com `truncated_fields` preenchido.

### Backtrace por query

`db_source_location` percorre 50 frames de backtrace **a cada query executada**.
É barato depurando um punhado de queries e ruinoso em stacks que disparam
centenas por request, por isso vem **desligado**.

```env
SQS_TELEMETRY_TIMELINE_DB_SOURCE=true     # ligue só quando for investigar
```

## Uso

### 1. Rastreamento de Requisições HTTP (Middleware)

Adicione o middleware no seu HTTP Kernel ou grupo de Rotas.

**Laravel 6 até 10** — No arquivo `app/Http/Kernel.php`:
```php
protected $middlewareGroups = [
    'web' => [
        // ...
        \Pablocarvalho\SqsTelemetry\Middleware\SqsTelemetryBufferMiddleware::class,
    ],
    'api' => [
        // ...
        \Pablocarvalho\SqsTelemetry\Middleware\SqsTelemetryBufferMiddleware::class,
    ],
];
```

**Laravel 11** — No arquivo `bootstrap/app.php`:
```php
->withMiddleware(function (Middleware $middleware) {
    $middleware->append(\Pablocarvalho\SqsTelemetry\Middleware\SqsTelemetryBufferMiddleware::class);
})
```

### 2. Rastreamento de Exceções

Registre no Handler principal para que as exceções não tratadas sejam absorvidas pelo buffer.

**Laravel 6 e 7** — No arquivo `app/Exceptions/Handler.php`, método `report()`:
```php
public function report(Throwable $exception)
{
    app(\Pablocarvalho\SqsTelemetry\Handlers\SqsExceptionHandler::class)->report($exception);
    parent::report($exception);
}
```

**Laravel 8 até 10** — No arquivo `app/Exceptions/Handler.php`, método `register()`:
```php
public function register(): void
{
    $this->reportable(function (\Throwable $e) {
        app(\Pablocarvalho\SqsTelemetry\Handlers\SqsExceptionHandler::class)->report($e);
    });
}
```

**Laravel 11** — No arquivo `bootstrap/app.php`:
```php
->withExceptions(function (Exceptions $exceptions) {
    $exceptions->report(function (\Throwable $e) {
        app(\Pablocarvalho\SqsTelemetry\Handlers\SqsExceptionHandler::class)->report($e);
    });
})
```

> **Exceptions tratadas (catch):** A partir da v1.0.7, exceptions logadas via `report($e)` ou `Log::error('msg', ['exception' => $e])` são capturadas automaticamente pelo listener `MessageLogged`, sem necessidade de configuração adicional.

> **Sem duplicação:** uma exception não tratada chega ao buffer por dois caminhos
> — o hook de report do host e o listener `MessageLogged`, quando o handler
> padrão a escreve no log. Os dois compartilham um registro e a mensagem sai uma
> vez só.

## Hosts que não roteiam pelo kernel (Acorn / WordPress)

Alguns hosts **bootam o framework mas não entregam a request ao HTTP kernel**.
O caso canônico é o Acorn dentro do WordPress. Duas coisas quebram ali, e cada
uma tem sua chave em `capture`.

### 1. A resposta é produzida depois do middleware

Na rota catch-all do WordPress o kernel roda em `after_setup_theme` e o
WordPress renderiza **depois**. Um middleware medindo na saída cronometra a
passagem do kernel, reporta timeline vazia e lê o status errado.

```env
SQS_TELEMETRY_DEFER_REQUEST=true
```

Com isso, duração, status e timeline são lidos quando o buffer drena no
`terminating()` — depois de a resposta real existir.

### 2. Paths que nunca chegam ao middleware

O Acorn chama `$kernel->bootstrap()` em toda request e **só depois** devolve
`/wp-admin`, `/wp-json`, `wp-login.php` e qualquer path `.php` para o WordPress.
Nesses paths os listeners coletam normalmente, mas nenhum middleware roda — e a
request sumiria da fila. Em site movimentado isso é boa parte do tráfego, e
`admin-ajax.php` costuma ser onde a carga aparece primeiro.

```env
SQS_TELEMETRY_FALLBACK_TO_SHUTDOWN=true
SQS_TELEMETRY_FINISH_REQUEST=true
```

Com o fallback ligado, a request é reconstruída das superglobais do PHP no
shutdown e sai marcada com `capture_source: shutdown` (as capturadas pelo
middleware saem como `middleware`). Elas não carregam informação de roteamento e
a timeline delas começa no boot, não no middleware.

`SQS_TELEMETRY_FINISH_REQUEST` chama `fastcgi_finish_request()` antes do envio,
liberando a resposta ao cliente. Vale **só** para esse caminho de fallback — no
caminho roteado o host já liberou a resposta e chamar de novo seria erro.
Qualquer saída escrita depois desse ponto é descartada, por isso é opt-in.

> **Depois de instalar em ambiente com cache quente**, rode
> `php artisan package:discover`. Se o manifesto de auto-discovery estiver
> desatualizado, o provider não é registrado e nada acontece — sem erro nenhum.

## Contexto adicional nas mensagens

Para carimbar toda mensagem com informação do host (tenant, release, pod), passe
o nome de uma classe que implemente `Contracts\ContextResolver`. É um nome de
classe, e não um closure, para o config continuar cacheável.

```php
// config/sqs-telemetry.php
'context' => [
    'resolver' => \App\Services\Telemetry\TenantContextResolver::class,
],
```

```php
use Pablocarvalho\SqsTelemetry\Contracts\ContextResolver;

class TenantContextResolver implements ContextResolver
{
    public function resolve(): array
    {
        return ['tenant' => DB_NAME, 'site_host' => parse_url(WP_HOME, PHP_URL_HOST)];
    }
}
```

O resolver roda **uma vez por flush**, não por mensagem, durante o teardown da
request — não deve lançar exceção nem fazer I/O. As chaves da própria mensagem
vencem as do resolver, para que ele não consiga sobrescrever, por exemplo, a
classe de uma exception.

## O que é capturado?

### Request (Middleware ou fallback de shutdown)
- `url`, `method`, `ip`, `user_agent`
- `status_code`, `execution_time` (em ms)
- `timestamp`, `headers`, `payload` (senhas e tokens são substituídos por `********`)
- `timeline` — eventos detalhados do ciclo de vida da request
- `capture_source` — `middleware` ou `shutdown`

### Exceptions (Handler + MessageLogged)
- `class`, `message`, `file`, `line`
- `url` (se via HTTP), `method`
- `timestamp`, `headers`, `payload`
- `stack_trace` (limitado a 10 linhas)
- `handled` — `true` se a exception foi tratada em um catch
- `log_level` — nível do log (error, warning, etc.)
- `ai_resolution_report` (se o módulo de IA estiver ativado)

### Commands (Artisan)
- `command`, `exit_code`
- `execution_time` (em ms)
- `timestamp`, `timeline`

### Timeline (automático)

Cada request/command captura um timeline detalhado com:

| Evento | Descrição |
|--------|-----------|
| `db_query` | Queries SQL com tempo de execução, connection, database e **bindings** (com sanitização automática de dados sensíveis) |
| `http_request` | Chamadas HTTP externas (Laravel 7+) |
| `cache_hit` / `cache_miss` / `cache_write` / `cache_forget` | Operações de cache |
| `exception` | Exceptions capturadas durante a execução |
| `command_start` / `command_finished` | Início e fim de comandos Artisan |

#### Sanitização de Bindings

Campos sensíveis são automaticamente substituídos por `[REDACTED]`:
- `password`, `secret`, `token`, `api_key`, `cpf`, `cnpj`

Exemplo de evento `db_query` no timeline:
```json
{
    "type": "db_query",
    "description": "insert into \"users\" (\"name\", \"email\", \"password\") values (?, ?, ?)",
    "duration_ms": 2.0,
    "context": {
        "connection": "pgsql",
        "database": "meu_banco",
        "bindings": ["John", "john@example.com", "[REDACTED]"]
    }
}
```

## Análise de Exceções por Inteligência Artificial

A aplicação integra a API da OpenAI para gerar resoluções detalhadas (Code Scan e context injection).
Se você habilitar, a varredura buscará a linha exata no seu código local (`app/`, etc.) de onde o stacktrace alertou o erro, obtendo linhas de antes e de depois, enviando as para a IA e gerando orientações em Markdown para facilitar a resolução dentro do seu Client / Relatórios.

**Aviso:** Processar via IA adicionará um tempo extra (~1-5 segundos) para a exceção ser consolidada e enviada via SQS.

## Configurações do Timeline

Todas as opções de timeline podem ser configuradas no arquivo `config/sqs-telemetry.php`:

```php
'timeline' => [
    'db'                 => env('SQS_TELEMETRY_TIMELINE_DB', true),
    'db_bindings'        => true, // sempre ativo, com sanitização automática
    'db_source_location' => env('SQS_TELEMETRY_TIMELINE_DB_SOURCE', false), // caro: backtrace por query
    'http'               => env('SQS_TELEMETRY_TIMELINE_HTTP', true),
    'cache'              => env('SQS_TELEMETRY_TIMELINE_CACHE', true),
    'commands'           => env('SQS_TELEMETRY_TIMELINE_COMMANDS', true),
    'exceptions'         => env('SQS_TELEMETRY_TIMELINE_EXCEPTIONS', true),
    'logs'               => env('SQS_TELEMETRY_TIMELINE_LOGS', true),
],
```

Um evento `timeline_truncated` é acrescentado quando o limite de eventos é
atingido, com a contagem do que ficou de fora.

