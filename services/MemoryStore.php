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

    public function getPoHint(string $vendorName): ?string
    {
        $vendorKey = $this->normalizeVendor($vendorName);
        if ($vendorKey === '') {
            return null;
        }

        $data = $this->read();
        return $data['vendors'][$vendorKey]['default_po_number'] ?? null;
    }

    public function saveConfirmedPo(string $vendorName, string $poNumber): void
    {
        $vendorKey = $this->normalizeVendor($vendorName);
        if ($vendorKey === '' || trim($poNumber) === '') {
            throw new InvalidArgumentException('Vendor dan PO number wajib diisi.');
        }

        $data = $this->read();
        $data['vendors'][$vendorKey] = [
            'vendor_name' => $vendorName,
            'default_po_number' => $poNumber,
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
