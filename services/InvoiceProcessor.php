<?php

declare(strict_types=1);

class InvoiceProcessor
{
    private string $ollamaBaseUrl;
    private string $modelName;

    public function __construct(string $ollamaBaseUrl, string $modelName)
    {
        $this->ollamaBaseUrl = rtrim($ollamaBaseUrl, '/');
        $this->modelName = $modelName;
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

        $prompt = $this->basePrompt() . "\n\nISI INVOICE (TEXT):\n" . $text;
        $content = $this->callOllama([
            ['role' => 'system', 'content' => 'Kamu asisten ekstraksi data invoice ke JSON.'],
            ['role' => 'user', 'content' => $prompt],
        ]);

        return $this->decodeModelJson($content);
    }

    private function analyzeImageInvoice(string $imagePath): array
    {
        $prompt = $this->basePrompt() . "\n\nBaca detail invoice dari gambar yang dikirim.";
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

        $prompt = $this->basePrompt() . "\n\nBaca isi invoice dari halaman PDF (dikirim sebagai gambar).";
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

        if (!is_array($analysis)) {
            throw new RuntimeException('Output model bukan JSON valid.');
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
            . "  \"raw_text_summary\": string|null\n"
            . "}\n"
            . "Balas JSON saja, tanpa markdown atau penjelasan. Jika ada teks seperti PO/No PO/Purchase Order, isi ke field po_number.";
    }
}
