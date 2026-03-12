# Laravel SQS Telemetry SDK

Um pacote Laravel projetado para capturar exceções não tratadas e interceptar requisições HTTP, enviando-as de forma assíncrona (em lotes) para a AWS SQS sem bloquear o tempo de resposta da aplicação principal. Totalmente compatível com PHP 7.4+ e Laravel 8 até 11.

## Por que usar este SDK?

O PHP rodando no modelo tradicional FPM "morre" (é encerrado) ao final de cada requisição. Registros síncronos em serviços externos (como AWS) afetam diretamente o tempo de resposta para o usuário final, deixando a aplicação mais lenta.

Este SDK resolve esse problema usando um **Buffer (Singleton) em Memória**. As telemetrias capturadas (Requisições ou Exceções) são armazenadas temporariamente em um array na memória da aplicação durante o processamento da requisição.

Somente **depois** que o Servidor Web envia a resposta de volta ao navegador do cliente (via FastCGI), o Laravel dispara o gancho do ciclo de vida chamado `app()->terminating()`. Este SDK registra um listener (ouvinte) exatamente nesse ponto para:
1. Recolher o array de itens armazenados na memória.
2. Agrupar em lotes (uma vez que a API da AWS SQS possui um limite de 10 mensagens por lote).
3. Executar o envio (I/O de rede) em background, de modo **totalmente não bloqueante** para a experiência do usuário.

Para aplicações executadas via **Laravel Octane**, este SDK é totalmente seguro: O buffer é forçosamente limpo sempre que as mensagens são enviadas, prevenindo *memory leaks* (vazamento de memória) entre as requisições.

## Instalação

Como este pacote será usado internamente ou de forma local inicialmente, você pode adicioná-lo ao `composer.json` do seu projeto hospedeiro usando um *path repository*:

```json
    "repositories": [
        {
            "type": "path",
            "url": "../laravel-sqs-telemetry"
        }
    ],
```

Então, instale o pacote rodando:
```bash
composer require pablocarvalho/laravel-sqs-telemetry
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

Você tem dois componentes principais à sua disposição: O *Middleware* de rastreamento HTTP e o *Exception handler* para captar os erros não tratados.

### O que é capturado?

### Request (Middleware)
- `url`
- `method`
- `ip`
- `user_agent`
- `status_code`
- `execution_time` (em ms)
- `timestamp`
- `headers`
- `payload` (senhas e tokens são substituídos por `********`)

### Exceptions (Handler)
- `class`
- `message`
- `file`
- `line`
- `url` (se via HTTP)
- `method`
- `timestamp`
- `headers` e `payload`
- `stack_trace` (limitado a 10 linhas)
- `ai_resolution_report` (detalhes sobre a causa e resolução da falha caso o módulo de IA esteja ativado)

## Análise de Exceções por Inteligência Artificial

A aplicação integra a API da OpenAI para gerar resoluções detalhadas (Code Scan e context injection).
Se você habilitar, a varredura buscará a linha exata no seu código local (`app/`, etc.) de onde o stacktrace alertou o erro, obtendo linhas de antes e de depois, enviando as para a IA e gerando orientações em Markdown para facilitar a resolução dentro do seu Client / Relatórios.

**Aviso:** Processar via IA adicionará um tempo extra (~1-5 segundos) para a exceção ser consolidada e enviada via SQS, afetando a performance final da resposta no erro no ambiente em que estiver rodando on demand.

### 1. Rastreamento de Requisições HTTP (Middleware)

Para registrar os endpoints acessados, tempos de resposta e dados da requisição via memória, adicione o middleware no seu HTTP Kernel ou grupo de Rotas.

**No Laravel 10 ou inferior:**
No arquivo `app/Http/Kernel.php`
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

**No Laravel 11:**
No arquivo `bootstrap/app.php`:
```php
->withMiddleware(function (Middleware $middleware) {
    $middleware->append(\Pablocarvalho\SqsTelemetry\Middleware\SqsTelemetryBufferMiddleware::class);
})
```

### 2. Rastreamento de Exceções

Para capturar e encaminhar logs das exceções não tratadas da sua aplicação, registre no Handler principal para que as exceções sejam absorvidas pelo buffer.

**No Laravel 10 ou inferior:**
No arquivo `app/Exceptions/Handler.php`, dentro do método `register()`:
```php
public function register(): void
{
    $this->reportable(function (\Throwable $e) {
        app(\Pablocarvalho\SqsTelemetry\Handlers\SqsExceptionHandler::class)->report($e);
    });
}
```

**No Laravel 11:**
No arquivo `bootstrap/app.php`:
```php
->withExceptions(function (Exceptions $exceptions) {
    $exceptions->report(function (\Throwable $e) {
        app(\Pablocarvalho\SqsTelemetry\Handlers\SqsExceptionHandler::class)->report($e);
    });
})
```
