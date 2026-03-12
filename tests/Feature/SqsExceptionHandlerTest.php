<?php

namespace Pablocarvalho\SqsTelemetry\Tests\Feature;

use Exception;
use Mockery;
use Pablocarvalho\SqsTelemetry\Handlers\SqsExceptionHandler;
use Pablocarvalho\SqsTelemetry\Services\AiExceptionAnalyzer;
use Pablocarvalho\SqsTelemetry\Services\CodeContextFetcher;
use Pablocarvalho\SqsTelemetry\Services\SqsBuffer;
use Pablocarvalho\SqsTelemetry\Tests\TestCase;

class SqsExceptionHandlerTest extends TestCase
{
    public function test_handler_adds_exception_to_buffer()
    {
        config(['sqs-telemetry.enabled' => true]);
        
        $bufferMock = Mockery::mock(SqsBuffer::class);
        $fetcherMock = Mockery::mock(CodeContextFetcher::class);
        $aiMock = Mockery::mock(AiExceptionAnalyzer::class);

        $fetcherMock->shouldReceive('fetchContext')->andReturn(null);
        $aiMock->shouldReceive('isEnabled')->andReturn(false);

        $bufferMock->shouldReceive('addException')->once()->with(Mockery::on(function ($data) {
            return $data['class'] === Exception::class
                && $data['message'] === 'Test Exception'
                && isset($data['stack_trace'])
                && array_key_exists('ai_resolution_report', $data)
                && $data['ai_resolution_report'] === null;
        }));

        $handler = new SqsExceptionHandler($bufferMock, $fetcherMock, $aiMock);
        $handler->report(new Exception('Test Exception'));
    }

    public function test_handler_does_not_add_when_disabled()
    {
        config(['sqs-telemetry.enabled' => false]);
        
        $bufferMock = Mockery::mock(SqsBuffer::class);
        $fetcherMock = Mockery::mock(CodeContextFetcher::class);
        $aiMock = Mockery::mock(AiExceptionAnalyzer::class);

        $bufferMock->shouldReceive('addException')->never();
        
        $handler = new SqsExceptionHandler($bufferMock, $fetcherMock, $aiMock);
        $handler->report(new Exception('Test Exception'));
    }

    public function test_handler_includes_ai_report_when_enabled()
    {
        config(['sqs-telemetry.enabled' => true]);
        
        $bufferMock = Mockery::mock(SqsBuffer::class);
        $fetcherMock = Mockery::mock(CodeContextFetcher::class);
        $aiMock = Mockery::mock(AiExceptionAnalyzer::class);

        $e = new Exception('AI Test Exception');
        $context = ['file' => 'test.php', 'line' => 10, 'snippet' => 'echo "bug";'];

        $fetcherMock->shouldReceive('fetchContext')->with($e)->andReturn($context);
        $aiMock->shouldReceive('isEnabled')->andReturn(true);
        $aiMock->shouldReceive('generateReport')->with($e, $context)->andReturn('Mocked AI Report Markdown');

        $bufferMock->shouldReceive('addException')->once()->with(Mockery::on(function ($data) {
            return $data['class'] === Exception::class
                && $data['message'] === 'AI Test Exception'
                && $data['ai_resolution_report'] === 'Mocked AI Report Markdown';
        }));

        $handler = new SqsExceptionHandler($bufferMock, $fetcherMock, $aiMock);
        $handler->report($e);
    }
}
