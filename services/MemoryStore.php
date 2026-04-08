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
        if ($vendorKey === '' || trim($poNumber) === '') {
            throw new InvalidArgumentException('Vendor dan PO number wajib diisi.');
        }

        $data = $this->read();
        $data['vendors'][$vendorKey] = [
            'vendor_name' => $vendorName,
            'default_po_number' => $poNumber,
            'source_variable' => $sourceVariable,
            'updated_at' => date('c'),
        ];

        $this->write($data);
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
