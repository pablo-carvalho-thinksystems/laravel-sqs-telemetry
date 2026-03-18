# Laravel SQS Telemetry SDK

Um pacote Laravel projetado para capturar exceções (tratadas e não tratadas), interceptar requisições HTTP, monitorar queries de banco, comandos Artisan e operações de cache, enviando tudo de forma assíncrona (em lotes) para a AWS SQS sem bloquear o tempo de resposta da aplicação principal.

**Compatível com PHP 7.2+ e Laravel 6 até 11.**

## Compatibilidade

| Laravel | PHP     | Suporte |
|---------|---------|---------|
| 6.x     | 7.2+    | ✅ (sem HTTP Client timeline) |
| 7.x     | 7.2+    | ✅      |
| 8.x     | 7.3+    | ✅      |
| 9.x     | 8.0+    | ✅      |
| 10.x    | 8.1+    | ✅      |
| 11.x    | 8.2+    | ✅      |

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

## O que é capturado?

### Request (Middleware)
- `url`, `method`, `ip`, `user_agent`
- `status_code`, `execution_time` (em ms)
- `timestamp`, `headers`, `payload` (senhas e tokens são substituídos por `********`)
- `timeline` — eventos detalhados do ciclo de vida da request

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
    'db'          => env('SQS_TELEMETRY_TIMELINE_DB', true),
    'db_bindings' => true, // sempre ativo, com sanitização automática
    'http'        => env('SQS_TELEMETRY_TIMELINE_HTTP', true),
    'cache'       => env('SQS_TELEMETRY_TIMELINE_CACHE', true),
    'commands'    => env('SQS_TELEMETRY_TIMELINE_COMMANDS', true),
    'exceptions'  => env('SQS_TELEMETRY_TIMELINE_EXCEPTIONS', true),
],
```

