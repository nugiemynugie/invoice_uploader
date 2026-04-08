<?php

declare(strict_types=1);

class MemoryStore
{
    private string $filePath;

    public function __construct(string $filePath)
    {
        $this->filePath = $filePath;
        $dir = dirname($filePath);
        if (!is_dir($dir)) {
            mkdir($dir, 0777, true);
        }

        if (!file_exists($this->filePath)) {
            file_put_contents($this->filePath, json_encode(['vendors' => []], JSON_PRETTY_PRINT));
        }
    }

    public function getPoMemory(string $vendorName): ?array
    {
        $vendorKey = $this->normalizeVendor($vendorName);
        if ($vendorKey === '') {
            return null;
        }

        $data = $this->read();
        $memory = $data['vendors'][$vendorKey] ?? null;

        if (!is_array($memory) || empty($memory['default_po_number'])) {
            return null;
        }

        return $memory;
    }

    public function saveConfirmedPo(string $vendorName, string $poNumber, ?string $sourceVariable = null): void
    {
        $vendorKey = $this->normalizeVendor($vendorName);
        $poNumber = $this->normalizePoNumber($poNumber);

        if ($vendorKey === '' || $poNumber === '') {
            throw new InvalidArgumentException('Vendor dan PO number wajib diisi.');
        }

        if (!$this->isValidPoFormat($poNumber)) {
            throw new InvalidArgumentException('Format PO harus 5 alfanumerik-6 angka-6 angka (contoh: ABC12-123456-789012).');
        }

        $data = $this->read();
        $existing = $data['vendors'][$vendorKey] ?? [];
        $data['vendors'][$vendorKey] = array_merge($existing, [
            'vendor_name' => $vendorName,
            'default_po_number' => $poNumber,
            'po_prefix' => substr($poNumber, 0, 5),
            'source_variable' => $sourceVariable,
            'updated_at' => date('c'),
        ]);

        $this->write($data);
    }

    public function saveFieldCorrection(string $vendorName, string $field, string $wrongValue, string $correctValue): void
    {
        $vendorKey = $this->normalizeVendor($vendorName);
        $field = trim($field);
        $wrongValue = trim($wrongValue);
        $correctValue = trim($correctValue);

        if ($vendorKey === '' || $field === '' || $wrongValue === '' || $correctValue === '') {
            throw new InvalidArgumentException('Vendor, field, wrong_value, dan correct_value wajib diisi.');
        }

        $data = $this->read();
        $existing = $data['vendors'][$vendorKey] ?? ['vendor_name' => $vendorName];
        $existing['corrections'] = $existing['corrections'] ?? [];
        $existing['corrections'][$field] = $existing['corrections'][$field] ?? [];
        $existing['corrections'][$field][$wrongValue] = $correctValue;
        $existing['updated_at'] = date('c');

        $data['vendors'][$vendorKey] = $existing;
        $this->write($data);
    }

    public function applyCorrections(string $vendorName, array $analysis): array
    {
        $vendorKey = $this->normalizeVendor($vendorName);
        if ($vendorKey === '') {
            return $analysis;
        }

        $data = $this->read();
        $corrections = $data['vendors'][$vendorKey]['corrections'] ?? null;
        if (!is_array($corrections)) {
            return $analysis;
        }

        foreach ($corrections as $field => $maps) {
            if (!array_key_exists($field, $analysis) || !is_array($maps)) {
                continue;
            }

            $current = (string) ($analysis[$field] ?? '');
            if ($current !== '' && isset($maps[$current])) {
                $analysis[$field] = $maps[$current];
                $analysis['correction_source'][$field] = 'memory_mapping';
            }
        }

        return $analysis;
    }

    private function normalizePoNumber(string $poNumber): string
    {
        return strtoupper(trim($poNumber));
    }

    private function isValidPoFormat(string $poNumber): bool
    {
        return (bool) preg_match('/^[A-Z0-9]{5}-[0-9]{6}-[0-9]{6}$/', strtoupper(trim($poNumber)));
    }

    private function normalizeVendor(string $vendorName): string
    {
        return strtolower(trim(preg_replace('/\s+/', ' ', $vendorName)));
    }

    private function read(): array
    {
        $content = (string) file_get_contents($this->filePath);
        $decoded = json_decode($content, true);

        return is_array($decoded) ? $decoded : ['vendors' => []];
    }

    private function write(array $data): void
    {
        file_put_contents($this->filePath, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
    }
}
