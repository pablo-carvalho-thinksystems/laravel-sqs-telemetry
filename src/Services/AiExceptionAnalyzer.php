<?php

declare(strict_types=1);

namespace Pablocarvalho\SqsTelemetry\Services;

use Illuminate\Support\Facades\Http;
use Throwable;

class AiExceptionAnalyzer
{
    /**
     * @var string
     */
    protected $provider;

    /**
     * @var string
     */
    protected $apiUrl;

    /**
     * @var string
     */
    protected $apiKey;

    /**
     * @var string
     */
    protected $model;

    public function __construct()
    {
        $this->provider = config('sqs-telemetry.ai.provider', 'openai');
        $this->apiUrl   = config('sqs-telemetry.ai.api_url', 'https://api.openai.com/v1/chat/completions');
        $this->apiKey   = config('sqs-telemetry.ai.api_key');
        $this->model    = config('sqs-telemetry.ai.model', 'gpt-4o-mini');
    }

    /**
     * Checks if AI analysis is configured and enabled.
     *
     * @return bool
     */
    public function isEnabled(): bool
    {
        return config('sqs-telemetry.ai.enabled', false) && !empty($this->apiKey);
    }

    /**
     * Generates a resolution report for a given exception and its context.
     *
     * @param Throwable $e
     * @param array|null $context Code context fetched from CodeContextFetcher
     * @return string|null Markdown report, or null if failed or disabled.
     */
    public function generateReport(Throwable $e, ?array $context): ?string
    {
        if (!$this->isEnabled()) {
            return null;
        }

        try {
            $prompt = $this->buildPrompt($e, $context);
            
            // For now, only OpenAI structure is officially supported, but can easily be extended later
            if ($this->provider === 'openai') {
                return $this->callOpenAI($prompt);
            }
        } catch (Throwable $e) {
            // Silently fail AI analysis so it doesn't crash the application
            return "Failed to generate AI report: " . $e->getMessage();
        }

        return null;
    }

    /**
     * Builds the prompt to send to the AI model.
     *
     * @param Throwable $e
     * @param array|null $context
     * @return string
     */
    protected function buildPrompt(Throwable $e, ?array $context): string
    {
        $prompt = "Você é um engenheiro de software especialista em PHP e Laravel.\n\n";
        $prompt .= "Uma exceção ocorreu na aplicação Laravel:\n\n";
        $prompt .= "Exception Class: " . get_class($e) . "\n";
        $prompt .= "Error Message: " . $e->getMessage() . "\n";
        $prompt .= "File: " . $e->getFile() . " on line " . $e->getLine() . "\n\n";

        if ($context && isset($context['snippet'])) {
            $prompt .= "Abaixo está o contexto do arquivo `{$context['file']}` próximo à linha `{$context['line']}` onde o erro parece ter começado na aplicação:\n\n";
            $prompt .= "```php\n" . $context['snippet'] . "\n```\n\n";
        }

        $prompt .= "Abaixo estão as primeiras 10 linhas da Stack Trace:\n\n";
        $prompt .= "```\n";
        $prompt .= implode("\n", array_slice(explode("\n", $e->getTraceAsString()), 0, 10));
        $prompt .= "\n```\n\n";

        $prompt .= "Por favor, atue como um especialista e forneça um RELATÓRIO DE RESOLUÇÃO COMPLETO em Markdown. Seu relatório deve:\n";
        $prompt .= "1. Explicar brevemente o provável motivo da exceção (causa raiz).\n";
        $prompt .= "2. Fornecer uma DICA DE RESOLUÇÃO PRÁTICA ou trecho sugerido de código para corrigir o problema, baseado no snippet fornecido.\n";
        $prompt .= "3. Informar quais variáveis, envs ou dependências verificar, se for o caso.\n";
        $prompt .= "Saturate isso de detalhes técnicos de nível sênior adequados ao framework Laravel.\n";
        $prompt .= "Seja direto, formatado em bom Markdown e fale em Português do Brasil.\n";

        return $prompt;
    }

    /**
     * Sends the prompt to OpenAI Chat Completions API.
     *
     * @param string $prompt
     * @return string|null
     */
    protected function callOpenAI(string $prompt): ?string
    {
        $response = Http::withToken($this->apiKey)
            ->timeout(10) // Small timeout to not freeze the app forever
            ->post($this->apiUrl, [
                'model' => $this->model,
                'messages' => [
                    [
                        'role' => 'system',
                        'content' => 'Você é um assistente sênior focado em resolver bugs PHP/Laravel.',
                    ],
                    [
                        'role' => 'user',
                        'content' => $prompt,
                    ],
                ],
                'temperature' => 0.2, // Low temperature for more deterministic/factual output
                'max_tokens' => 800,
            ]);

        if ($response->successful()) {
            return $response->json('choices.0.message.content');
        }

        return "AI Error: [" . $response->status() . "] " . $response->body();
    }
}
