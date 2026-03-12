<?php

declare(strict_types=1);

namespace Pablocarvalho\SqsTelemetry\Services;

use Throwable;

class CodeContextFetcher
{
    /**
     * @var int Number of lines before and after to extract.
     */
    protected $contextLines = 15;

    /**
     * Extracts code context from the exception.
     * Iterates through the stack trace to find the first application-level frame.
     *
     * @param Throwable $e
     * @return array|null Returns array with 'file', 'line', 'snippet' or null.
     */
    public function fetchContext(Throwable $e): ?array
    {
        $frames = $e->getTrace();
        
        // Add the exception origin itself as the first frame to check
        array_unshift($frames, [
            'file' => $e->getFile(),
            'line' => $e->getLine(),
        ]);

        foreach ($frames as $frame) {
            if (!isset($frame['file']) || !isset($frame['line'])) {
                continue;
            }

            if ($this->isAppFile($frame['file'])) {
                $snippet = $this->extractSnippet($frame['file'], $frame['line']);
                
                if ($snippet) {
                    return [
                        'file'    => $frame['file'],
                        'line'    => $frame['line'],
                        'snippet' => $snippet,
                    ];
                }
            }
        }

        return null;
    }

    /**
     * Determines if the file belongs to the application (not vendor).
     *
     * @param string $file
     * @return bool
     */
    protected function isAppFile(string $file): bool
    {
        // Must be an absolute path within base_path()
        return strpos($file, base_path()) === 0 && strpos($file, base_path('vendor')) === false;
    }

    /**
     * Extracts a snippet of code around the given line.
     *
     * @param string $file
     * @param int $line
     * @return string|null
     */
    protected function extractSnippet(string $file, int $line): ?string
    {
        if (!file_exists($file) || !is_readable($file)) {
            return null;
        }

        $lines = file($file);
        if ($lines === false) {
            return null;
        }

        $startLine = max(0, $line - $this->contextLines - 1);
        $endLine   = min(count($lines), $line + $this->contextLines);
        
        $snippet = [];
        
        // Add a header
        $snippet[] = "// File: " . str_replace(base_path() . '/', '', $file);
        $snippet[] = "// Error near line: $line\n";

        for ($i = $startLine; $i < $endLine; $i++) {
            $currentLineNum = $i + 1;
            $prefix = ($currentLineNum === $line) ? '>> ' : '   ';
            $snippet[] = $prefix . str_pad((string)$currentLineNum, 4, ' ', STR_PAD_LEFT) . '| ' . rtrim($lines[$i]);
        }

        return implode("\n", $snippet);
    }
}
