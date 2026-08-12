<?php

declare(strict_types=1);

namespace Pablocarvalho\SqsTelemetry\Services;

use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Rastro de diagnostico por etapa do pipeline de telemetria.
 *
 * O caminho request -> buffer -> spool -> drain -> SQS esta cheio de try/catch
 * que engolem falhas de proposito (a telemetria nunca pode derrubar o host).
 * O efeito colateral e que, quando uma mensagem para de chegar na fila, nao ha
 * onde olhar. Este helper marca cada transicao para que, com o debug ligado, o
 * log mostre exatamente ate onde a mensagem chegou.
 *
 * Escreve no canal de log padrao da aplicacao. Como o rastro sai em nivel
 * `debug`/`warning`, o LOG_LEVEL do ambiente precisa deixar esses niveis
 * passarem (staging/producao costumam ficar em `error`) — ajuste o LOG_LEVEL
 * enquanto estiver investigando.
 *
 * Desligado por padrao (verboso: emite por request/flush). Ligue com
 * SQS_TELEMETRY_DEBUG=true no ambiente investigado.
 *
 * O marcador `__sqs_telemetry` faz o listener de logs do pacote ignorar estas
 * linhas, senao cada log de diagnostico viraria, ele proprio, telemetria.
 */
final class TelemetryLog
{
    /**
     * Registra uma etapa do pipeline. `level` permite elevar o que importa
     * (uma mensagem descartada) acima do rastro de debug comum.
     *
     * @param array<string, mixed> $context
     */
    public static function step(string $stage, array $context = [], string $level = 'debug'): void
    {
        if (! config('sqs-telemetry.debug', false)) {
            return;
        }

        try {
            Log::log($level, "SqsTelemetry[{$stage}]", $context + ['__sqs_telemetry' => true]);
        } catch (Throwable $e) {
            // O diagnostico jamais pode ser o motivo de uma falha.
        }
    }
}
