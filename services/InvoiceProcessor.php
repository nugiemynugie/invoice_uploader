<?php

declare(strict_types=1);

class InvoiceProcessor
{
    private string $ollamaBaseUrl;
    private string $modelName;
    private ?MemoryStore $memoryStore;

    public function __construct(string $ollamaBaseUrl, string $modelName, ?MemoryStore $memoryStore = null)
    {
        $this->ollamaBaseUrl = rtrim($ollamaBaseUrl, '/');
        $this->modelName = $modelName;
        $this->memoryStore = $memoryStore;
    }

    public function process(string $filePath): array
    {
        $extension = strtolower(pathinfo($filePath, PATHINFO_EXTENSION));

        $result = match ($extension) {
            'txt' => $this->analyzeTextInvoice((string) file_get_contents($filePath)),
            'png', 'jpg', 'jpeg' => $this->analyzeImageInvoice($filePath),
            'pdf' => $this->analyzePdfInvoice($filePath),
            default => throw new InvalidArgumentException("Format file tidak didukung: {$extension}"),
        };

        $result = $this->normalizePoFromAnalysis($result);
        $result = $this->applyMemoryFallback($result);
        $result = $this->applyFieldCorrections($result);

        return [
            'filename' => basename($filePath),
            'model' => $this->modelName,
            'analysis' => $result,
        ];
    }

    private function analyzeTextInvoice(string $text): array
    {
        if (trim($text) === '') {
            throw new RuntimeException('Isi file TXT kosong.');
        }

        $prompt = $this->basePromptWithMemoryHint() . "\n\nISI INVOICE (TEXT):\n" . $text;
        $content = $this->callOllama([
            ['role' => 'system', 'content' => 'Kamu asisten ekstraksi data invoice ke JSON.'],
            ['role' => 'user', 'content' => $prompt],
        ]);

        return $this->decodeModelJson($content);
    }

    private function analyzeImageInvoice(string $imagePath): array
    {
        $prompt = $this->basePromptWithMemoryHint() . "\n\nBaca detail invoice dari gambar yang dikirim.";
        $imageBase64 = base64_encode((string) file_get_contents($imagePath));

        $content = $this->callOllama([
            ['role' => 'system', 'content' => 'Kamu asisten vision untuk membaca invoice dan mengubahnya ke JSON.'],
            ['role' => 'user', 'content' => $prompt, 'images' => [$imageBase64]],
        ]);

        return $this->decodeModelJson($content);
    }

    private function analyzePdfInvoice(string $pdfPath): array
    {
        $images = $this->pdfToBase64Images($pdfPath, 3);
        if ($images === []) {
            throw new RuntimeException('Gagal mengonversi PDF ke gambar untuk dibaca Ollama.');
        }

        $prompt = $this->basePromptWithMemoryHint() . "\n\nBaca isi invoice dari halaman PDF (dikirim sebagai gambar).";
        $content = $this->callOllama([
            ['role' => 'system', 'content' => 'Kamu asisten vision untuk membaca invoice PDF dan mengubahnya ke JSON.'],
            ['role' => 'user', 'content' => $prompt, 'images' => $images],
        ]);

        return $this->decodeModelJson($content);
    }

    private function pdfToBase64Images(string $pdfPath, int $maxPages = 3): array
    {
        // Prioritas: Imagick (jika policy PDF diizinkan), fallback ke pdftoppm.
        if (extension_loaded('imagick') && class_exists('Imagick')) {
            try {
                $imagick = new Imagick();
                $imagick->setResolution(200, 200);
                $imagick->readImage($pdfPath);

                $images = [];
                $page = 0;

                foreach ($imagick as $frame) {
                    if ($page >= $maxPages) {
                        break;
                    }

                    $frame->setImageFormat('png');
                    $blob = $frame->getImageBlob();
                    $images[] = base64_encode($blob);
                    $page++;
                }

                $imagick->clear();
                $imagick->destroy();

                if ($images !== []) {
                    return $images;
                }
            } catch (Throwable $error) {
                // Lanjut ke fallback pdftoppm (umumnya aman dari policy ImageMagick PDF).
            }
        }

        return $this->pdfToBase64ImagesWithPdftoppm($pdfPath, $maxPages);
    }

    private function pdfToBase64ImagesWithPdftoppm(string $pdfPath, int $maxPages): array
    {
        $binary = trim((string) shell_exec('command -v pdftoppm'));
        if ($binary === '') {
            throw new RuntimeException(
                'PDF tidak bisa diproses. Imagick diblok policy PDF dan pdftoppm tidak ditemukan. Install poppler-utils.'
            );
        }

        $tempDir = rtrim(sys_get_temp_dir(), DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'pdfimg_' . bin2hex(random_bytes(6));
        if (!mkdir($tempDir, 0777, true) && !is_dir($tempDir)) {
            throw new RuntimeException('Gagal membuat direktori sementara untuk konversi PDF.');
        }

        $outputPrefix = $tempDir . DIRECTORY_SEPARATOR . 'page';
        $command = sprintf(
            '%s -f 1 -singlefile -png %s %s 2>&1',
            escapeshellcmd($binary),
            escapeshellarg($pdfPath),
            escapeshellarg($outputPrefix)
        );

        // Konversi page-by-page agar bisa batasi jumlah halaman.
        $images = [];
        for ($page = 1; $page <= $maxPages; $page++) {
            $pagePrefix = $tempDir . DIRECTORY_SEPARATOR . 'page_' . $page;
            $cmd = sprintf(
                '%s -f %d -l %d -singlefile -png %s %s 2>&1',
                escapeshellcmd($binary),
                $page,
                $page,
                escapeshellarg($pdfPath),
                escapeshellarg($pagePrefix)
            );
            exec($cmd, $output, $code);

            $pngPath = $pagePrefix . '.png';
            if ($code !== 0 || !file_exists($pngPath)) {
                break;
            }

            $images[] = base64_encode((string) file_get_contents($pngPath));
        }

        foreach (glob($tempDir . DIRECTORY_SEPARATOR . '*') ?: [] as $tmpFile) {
            @unlink($tmpFile);
        }
        @rmdir($tempDir);

        if ($images === []) {
            throw new RuntimeException('Gagal konversi PDF ke gambar dengan pdftoppm.');
        }

        return $images;
    }

    private function callOllama(array $messages): string
    {
        $payload = [
            'model' => $this->modelName,
            'messages' => $messages,
            'stream' => false,
            'format' => 'json',
            'options' => ['temperature' => 0],
        ];

        $ch = curl_init($this->ollamaBaseUrl . '/api/chat');
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
            CURLOPT_POSTFIELDS => json_encode($payload, JSON_UNESCAPED_UNICODE),
            CURLOPT_TIMEOUT => 180,
        ]);

        $responseBody = curl_exec($ch);
        $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        curl_close($ch);

        if ($responseBody === false || $curlError !== '') {
            throw new RuntimeException('Gagal menghubungi Ollama: ' . $curlError);
        }

        if ($httpCode >= 400) {
            throw new RuntimeException("Ollama error HTTP {$httpCode}: {$responseBody}");
        }

        $decoded = json_decode($responseBody, true);
        if (!is_array($decoded)) {
            throw new RuntimeException('Response Ollama tidak valid JSON.');
        }

        $content = trim((string)($decoded['message']['content'] ?? ''));
        if ($content === '') {
            throw new RuntimeException('Response Ollama kosong.');
        }

        return $content;
    }

    private function decodeModelJson(string $content): array
    {
        $clean = trim(str_replace(["```json", "```"], '', $content));
        $analysis = json_decode($clean, true);

        if (is_array($analysis)) {
            return $analysis;
        }

        $recovered = $this->recoverJsonFromText($clean);
        if ($recovered !== null) {
            return $recovered;
        }

        throw new RuntimeException('Output model bukan JSON valid.');
    }

    private function recoverJsonFromText(string $text): ?array
    {
        // Coba ambil blok objek JSON terbesar.
        if (preg_match('/\{(?:[^{}]|(?R))*\}/s', $text, $match)) {
            $decoded = json_decode($match[0], true);
            if (is_array($decoded)) {
                return $decoded;
            }
        }

        // Fallback: ambil dari kurung kurawal pertama sampai terakhir.
        $start = strpos($text, '{');
        $end = strrpos($text, '}');
        if ($start !== false && $end !== false && $end > $start) {
            $candidate = substr($text, $start, $end - $start + 1);
            $decoded = json_decode($candidate, true);
            if (is_array($decoded)) {
                return $decoded;
            }
        }

        return null;
    }



    private function normalizePoFromAnalysis(array $analysis): array
    {
        $candidate = '';

        $stringValues = $this->collectStringValues($analysis);

        if (!empty($analysis['po_number']) && is_string($analysis['po_number'])) {
            $candidate = trim($analysis['po_number']);
        }

        if (!$this->isValidPoFormat($candidate)) {
            foreach ($stringValues as $value) {
                $found = $this->extractPoFromText($value);
                if ($found !== null) {
                    $candidate = $found;
                    break;
                }
            }

            if ($candidate === '' && $this->memoryStore !== null) {
                $vendor = (string) ($analysis['vendor'] ?? '');
                $memory = $this->memoryStore->getPoMemory($vendor);
                if (is_array($memory) && !empty($memory['po_prefix'])) {
                    foreach ($stringValues as $v) {
                        if (preg_match('/\b([0-9]{6})-([0-9]{6})\b/', $v, $m)) {
                            $candidate = strtoupper($memory['po_prefix']) . '-' . $m[1] . '-' . $m[2];
                            break;
                        }
                    }
                }
            }
        }

        $analysis['po_number'] = $this->isValidPoFormat($candidate) ? strtoupper($candidate) : null;
        $analysis['po_format'] = 'AAAAA-999999-999999';
        $analysis['po_format_valid'] = $analysis['po_number'] !== null;

        return $analysis;
    }

    private function collectStringValues(array $data): array
    {
        $values = [];

        $walker = function ($node) use (&$values, &$walker): void {
            if (is_string($node) && trim($node) !== '') {
                $values[] = $node;
                return;
            }

            if (is_array($node)) {
                foreach ($node as $child) {
                    $walker($child);
                }
            }
        };

        $walker($data);

        return $values;
    }

    private function extractPoFromText(string $text): ?string
    {
        $patterns = [
            '/\b([A-Za-z0-9]{5}-[0-9]{6}-[0-9]{6})\b/',
            '/\b([A-Za-z0-9]{5})[- ]?([0-9]{6})[- ]([0-9]{6})\b/',
            '/\b([A-Za-z0-9]{5})([0-9]{6})[- ]([0-9]{6})\b/',
        ];

        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $text, $matches)) {
                if (count($matches) >= 4) {
                    return strtoupper($matches[1] . '-' . $matches[2] . '-' . $matches[3]);
                }

                return strtoupper($matches[1]);
            }
        }

        return null;
    }

    private function isValidPoFormat(?string $poNumber): bool
    {
        if ($poNumber === null) {
            return false;
        }

        return (bool) preg_match('/^[A-Za-z0-9]{5}-[0-9]{6}-[0-9]{6}$/', trim($poNumber));
    }

    private function basePromptWithMemoryHint(): string
    {
        $basePrompt = $this->basePrompt();
        if ($this->memoryStore === null) {
            return $basePrompt;
        }

        return $basePrompt
            . "\nTambahan: jika vendor cocok dengan memory internal, prioritaskan PO berdasarkan konteks invoice.";
    }


    private function applyFieldCorrections(array $analysis): array
    {
        if ($this->memoryStore === null) {
            return $analysis;
        }

        $vendor = (string) ($analysis['vendor'] ?? '');
        if ($vendor === '') {
            return $analysis;
        }

        return $this->memoryStore->applyCorrections($vendor, $analysis);
    }

    private function applyMemoryFallback(array $analysis): array
    {
        if ($this->memoryStore === null) {
            return $analysis;
        }

        $vendor = (string) ($analysis['vendor'] ?? '');
        $poNumber = trim((string) ($analysis['po_number'] ?? ''));

        if ($vendor === '') {
            return $analysis;
        }

        $poMemory = $this->memoryStore->getPoMemory($vendor);
        if ($poMemory === null) {
            return $analysis;
        }

        $poHint = (string) ($poMemory['default_po_number'] ?? "");
        $sourceVariable = (string) ($poMemory['source_variable'] ?? "");
        if ($poHint === "") {
            return $analysis;
        }

        if ($poNumber === '') {
            $analysis['po_number'] = $poHint;
            $analysis['po_source'] = 'memory_fallback';
            $analysis['po_source_variable'] = $sourceVariable !== "" ? $sourceVariable : null;
        } else {
            $analysis['po_source'] = 'document';
        }

        return $analysis;
    }

    private function basePrompt(): string
    {
        return "Ekstrak data invoice dan hasilkan JSON valid dengan field ini:\n"
            . "{\n"
            . "  \"vendor\": string|null,\n"
            . "  \"invoice_number\": string|null,\n"
            . "  \"po_number\": string|null,\n"
            . "  \"invoice_date\": string|null,\n"
            . "  \"due_date\": string|null,\n"
            . "  \"currency\": string|null,\n"
            . "  \"subtotal\": number|null,\n"
            . "  \"tax\": number|null,\n"
            . "  \"total\": number|null,\n"
            . "  \"line_items\": [\n"
            . "    {\"description\": string|null, \"quantity\": number|null, \"unit_price\": number|null, \"amount\": number|null}\n"
            . "  ],\n"
            . "  \"notes\": string|null,\n"
            . "  \"raw_text_summary\": string|null,\n"
            . "  \"po_raw_candidates\": [string]\n"
            . "}\n"
            . "Balas JSON saja, tanpa markdown atau penjelasan. Jika ada teks seperti PO/No PO/Purchase Order, isi ke field po_number dengan format wajib AAAAA-999999-999999 (5 karakter alfanumerik, lalu 6 digit angka, lalu 6 digit angka). Selain itu, salin semua kandidat teks PO apa adanya ke po_raw_candidates.";
    }
}
