<?php

declare(strict_types=1);

function jsonResponse(array $payload, int $status = 200): never
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    exit;
}

function uploadErrorMessage(int $code): string
{
    return match ($code) {
        UPLOAD_ERR_INI_SIZE => 'Ukuran file melebihi batas upload_max_filesize di php.ini.',
        UPLOAD_ERR_FORM_SIZE => 'Ukuran file melebihi batas MAX_FILE_SIZE form.',
        UPLOAD_ERR_PARTIAL => 'File hanya ter-upload sebagian. Coba ulangi.',
        UPLOAD_ERR_NO_FILE => 'Tidak ada file yang dikirim.',
        UPLOAD_ERR_NO_TMP_DIR => 'Folder temporary upload tidak ditemukan (upload_tmp_dir).',
        UPLOAD_ERR_CANT_WRITE => 'Gagal menulis file ke disk.',
        UPLOAD_ERR_EXTENSION => 'Upload dihentikan oleh extension PHP.',
        default => 'Terjadi error upload tidak diketahui.',
    };
}

function parseIniSizeToBytes(string $value): int
{
    $value = trim($value);
    if ($value === '') {
        return 0;
    }

    $unit = strtolower(substr($value, -1));
    $number = (float) $value;

    return match ($unit) {
        'g' => (int) ($number * 1024 * 1024 * 1024),
        'm' => (int) ($number * 1024 * 1024),
        'k' => (int) ($number * 1024),
        default => (int) $number,
    };
}

function loadEnv(string $path): void
{
    if (!file_exists($path)) {
        return;
    }

    $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    if ($lines === false) {
        return;
    }

    foreach ($lines as $line) {
        if (str_starts_with(trim($line), '#') || !str_contains($line, '=')) {
            continue;
        }

        [$key, $value] = explode('=', $line, 2);
        $key = trim($key);
        $value = trim($value);

        if ($key !== '' && getenv($key) === false) {
            putenv("{$key}={$value}");
            $_ENV[$key] = $value;
        }
    }
}

$rootPath = dirname(__DIR__);
loadEnv($rootPath . '/.env');
require_once $rootPath . '/services/MemoryStore.php';
require_once $rootPath . '/services/InvoiceProcessor.php';

$maxUploadMb = (int) (getenv('MAX_UPLOAD_MB') ?: 10);
$appMaxUploadBytes = $maxUploadMb * 1024 * 1024;
$phpUploadMaxBytes = parseIniSizeToBytes((string) ini_get('upload_max_filesize'));
$phpPostMaxBytes = parseIniSizeToBytes((string) ini_get('post_max_size'));
$effectiveMaxUploadBytes = min(array_filter([$appMaxUploadBytes, $phpUploadMaxBytes, $phpPostMaxBytes]));
$effectiveMaxUploadMb = (int) floor($effectiveMaxUploadBytes / (1024 * 1024));
$allowedExtensions = ['pdf', 'png', 'jpg', 'jpeg', 'txt'];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_GET['action'] ?? '') === 'confirm_memory') {
    $payload = json_decode((string) file_get_contents('php://input'), true);
    $vendor = trim((string) ($payload['vendor'] ?? ''));
    $poNumber = trim((string) ($payload['po_number'] ?? ''));
    $sourceVariable = trim((string) ($payload['source_variable'] ?? ''));

    try {
        $memoryStore = new MemoryStore($rootPath . '/storage/memory.json');
        $memoryStore->saveConfirmedPo($vendor, $poNumber, $sourceVariable !== '' ? $sourceVariable : null);
        jsonResponse([
            'message' => 'Memory PO berhasil disimpan.',
            'vendor' => $vendor,
            'po_number' => $poNumber,
            'source_variable' => $sourceVariable !== '' ? $sourceVariable : null,
        ]);
    } catch (Throwable $exception) {
        jsonResponse(['error' => $exception->getMessage()], 400);
    }
}


if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_GET['action'] ?? '') === 'confirm_mapping') {
    $payload = json_decode((string) file_get_contents('php://input'), true);
    $vendor = trim((string) ($payload['vendor'] ?? ''));
    $field = trim((string) ($payload['field'] ?? ''));
    $wrongValue = trim((string) ($payload['wrong_value'] ?? ''));
    $correctValue = trim((string) ($payload['correct_value'] ?? ''));

    try {
        $memoryStore = new MemoryStore($rootPath . '/storage/memory.json');
        $memoryStore->saveFieldCorrection($vendor, $field, $wrongValue, $correctValue);
        jsonResponse([
            'message' => 'Mapping koreksi berhasil disimpan.',
            'vendor' => $vendor,
            'field' => $field,
            'wrong_value' => $wrongValue,
            'correct_value' => $correctValue,
        ]);
    } catch (Throwable $exception) {
        jsonResponse(['error' => $exception->getMessage()], 400);
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_GET['action'] ?? '') === 'upload') {
    if (!isset($_FILES['invoice'])) {
        jsonResponse(['error' => 'File invoice tidak ditemukan pada request.'], 400);
    }

    $invoice = $_FILES['invoice'];

    $uploadError = (int) ($invoice['error'] ?? UPLOAD_ERR_NO_FILE);
    if ($uploadError !== UPLOAD_ERR_OK) {
        jsonResponse([
            'error' => uploadErrorMessage($uploadError),
            'upload_error_code' => $uploadError,
            'php_upload_max_filesize' => ini_get('upload_max_filesize'),
            'php_post_max_size' => ini_get('post_max_size'),
            'app_max_upload_mb' => $maxUploadMb,
        ], 400);
    }

    if (($invoice['size'] ?? 0) > $effectiveMaxUploadBytes) {
        jsonResponse(['error' => "Ukuran file melebihi batas efektif upload {$effectiveMaxUploadMb} MB."], 400);
    }

    $originalName = (string) ($invoice['name'] ?? '');
    $extension = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));

    if (!in_array($extension, $allowedExtensions, true)) {
        jsonResponse(['error' => 'Ekstensi file tidak didukung. Gunakan: pdf/png/jpg/jpeg/txt.'], 400);
    }

    $uploadsDir = $rootPath . '/uploads';
    if (!is_dir($uploadsDir) && !mkdir($uploadsDir, 0777, true) && !is_dir($uploadsDir)) {
        jsonResponse(['error' => 'Gagal membuat direktori upload.'], 500);
    }

    $safeName = preg_replace('/[^a-zA-Z0-9._-]/', '_', $originalName) ?: ('invoice.' . $extension);
    $targetPath = $uploadsDir . '/' . uniqid('invoice_', true) . '_' . $safeName;

    $tmpName = (string) ($invoice['tmp_name'] ?? '');
    $moved = $tmpName !== '' && move_uploaded_file($tmpName, $targetPath);
    if (!$moved && $tmpName !== '' && is_file($tmpName)) {
        $moved = rename($tmpName, $targetPath);
    }

    if (!$moved) {
        jsonResponse([
            'error' => 'Gagal menyimpan file upload.',
            'tmp_name' => $tmpName,
            'is_uploaded_file' => $tmpName !== '' ? is_uploaded_file($tmpName) : false,
            'upload_tmp_dir' => ini_get('upload_tmp_dir') ?: '(default sistem)',
        ], 500);
    }

    try {
        $memoryStore = new MemoryStore($rootPath . '/storage/memory.json');
        $processor = new InvoiceProcessor(
            getenv('OLLAMA_BASE_URL') ?: 'http://localhost:11434',
            getenv('OLLAMA_MODEL') ?: 'gemma4',
            $memoryStore
        );

        $result = $processor->process($targetPath);
        jsonResponse($result);
    } catch (Throwable $exception) {
        jsonResponse(['error' => $exception->getMessage()], 500);
    } finally {
        if (file_exists($targetPath)) {
            @unlink($targetPath);
        }
    }
}
?>
<!doctype html>
<html lang="id">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <title>Invoice Uploader + Ollama</title>
  <link rel="stylesheet" href="../static/styles.css" />
</head>
<body>
  <main class="container">
    <h1>Invoice Uploader (PHP)</h1>
    <p>Upload invoice (PDF/Gambar/TXT), lalu dokumen dibaca langsung oleh Ollama untuk membuat variable invoice otomatis.</p>
    <p><strong>Batas upload efektif:</strong> <?= htmlspecialchars((string)$effectiveMaxUploadMb) ?> MB (dibatasi oleh php.ini & app).</p>

    <form id="upload-form">
      <input type="file" id="invoice" name="invoice" accept=".pdf,.png,.jpg,.jpeg,.txt" required />
      <button type="submit">Upload & Analisa</button>
    </form>

    <section>
      <h2>Hasil</h2>
      <pre id="result">Belum ada data.</pre>
    </section>

    <section>
      <h2>Simpan Memory PO</h2>
      <p>Jika hasil sudah benar, simpan vendor + PO agar jadi memory.</p>
      <form id="memory-form">
        <input type="text" id="vendor" placeholder="Nama Vendor" required />
        <input type="text" id="po_number" placeholder="Nomor PO (AAAAA-999999-999999)" required />
        <select id="source_variable">
          <option value="analysis.po_number">Semua variable scan akan tampil</option>
        </select>
        <button type="submit">Simpan Memory</button>
      </form>
      <pre id="memory-result">Belum ada memory update.</pre>
    </section>

    <section>
      <h2>Simpan Mapping Koreksi</h2>
      <p>Jika hasil scan salah, simpan pasangan nilai salah -> benar per field.</p>
      <form id="mapping-form">
        <input type="text" id="map_vendor" placeholder="Nama Vendor" required />
        <input type="text" id="map_field" placeholder="Field (contoh: invoice_number)" required />
        <input type="text" id="map_wrong" placeholder="Nilai hasil scan (salah)" required />
        <input type="text" id="map_correct" placeholder="Nilai benar" required />
        <button type="submit">Simpan Mapping</button>
      </form>
      <pre id="mapping-result">Belum ada mapping update.</pre>
    </section>
  </main>

  <script>
    const form = document.getElementById('upload-form');
    const resultBox = document.getElementById('result');
    const memoryForm = document.getElementById('memory-form');
    const memoryResult = document.getElementById('memory-result');
    const mappingForm = document.getElementById('mapping-form');
    const mappingResult = document.getElementById('mapping-result');

    function shortValue(value) {
      const text = String(value ?? '').trim();
      if (text.length <= 40) return text;
      return text.slice(0, 40) + '...';
    }

    function flattenAnalysisVariables(input, prefix = 'analysis') {
      const items = [];

      if (Array.isArray(input)) {
        input.forEach((value, index) => {
          items.push(...flattenAnalysisVariables(value, `${prefix}[${index}]`));
        });
        return items;
      }

      if (input !== null && typeof input === 'object') {
        for (const [key, value] of Object.entries(input)) {
          items.push(...flattenAnalysisVariables(value, `${prefix}.${key}`));
        }
        return items;
      }

      items.push({ path: prefix, value: input });
      return items;
    }

    function buildAllVariableOptions(analysis) {
      if (!analysis || typeof analysis !== 'object') {
        return [{ value: 'analysis.po_number', label: 'analysis.po_number => (kosong)' }];
      }

      const flattened = flattenAnalysisVariables(analysis);
      return flattened.map((item) => ({
        value: item.path,
        label: `${item.path} => ${shortValue(item.value)}`,
      }));
    }

    function renderSourceVariableOptions(analysis) {
      const select = document.getElementById('source_variable');
      select.innerHTML = '';
      const built = buildAllVariableOptions(analysis);
      const seen = new Set();

      for (const option of built) {
        if (seen.has(option.value)) continue;
        seen.add(option.value);
        const el = document.createElement('option');
        el.value = option.value;
        el.textContent = option.label;
        select.appendChild(el);
      }
    }

    form.addEventListener('submit', async (e) => {
      e.preventDefault();
      resultBox.textContent = 'Memproses...';

      const formData = new FormData();
      const fileInput = document.getElementById('invoice');
      if (!fileInput.files.length) {
        resultBox.textContent = 'Pilih file terlebih dahulu.';
        return;
      }

      const selected = fileInput.files[0];
      const maxBytes = <?= (int)$effectiveMaxUploadBytes ?>;
      if (selected.size > maxBytes) {
        const maxMb = Math.floor(maxBytes / (1024 * 1024));
        resultBox.textContent = `File terlalu besar. Maksimal ${maxMb} MB.`;
        return;
      }

      formData.append('invoice', selected);

      try {
        const response = await fetch('?action=upload', { method: 'POST', body: formData });
        const data = await response.json();
        resultBox.textContent = JSON.stringify(data, null, 2);

        if (data.analysis) {
          document.getElementById('vendor').value = data.analysis.vendor || '';
          document.getElementById('po_number').value = data.analysis.po_number || '';
          document.getElementById('map_vendor').value = data.analysis.vendor || '';
          renderSourceVariableOptions(data.analysis);
        }
      } catch (err) {
        resultBox.textContent = `Gagal: ${err}`;
      }
    });

    memoryForm.addEventListener('submit', async (e) => {
      e.preventDefault();
      memoryResult.textContent = 'Menyimpan memory...';

      const poNumber = document.getElementById('po_number').value.trim().toUpperCase();
      const poRegex = /^[A-Z0-9]{5}-[0-9]{6}-[0-9]{6}$/;
      if (!poRegex.test(poNumber)) {
        memoryResult.textContent = 'Format PO harus AAAAA-999999-999999';
        return;
      }

      const payload = {
        vendor: document.getElementById('vendor').value,
        po_number: poNumber,
        source_variable: document.getElementById('source_variable').value,
      };

      try {
        const response = await fetch('?action=confirm_memory', {
          method: 'POST',
          headers: { 'Content-Type': 'application/json' },
          body: JSON.stringify(payload)
        });
        const data = await response.json();
        memoryResult.textContent = JSON.stringify(data, null, 2);
      } catch (err) {
        memoryResult.textContent = `Gagal simpan memory: ${err}`;
      }
    });


    mappingForm.addEventListener('submit', async (e) => {
      e.preventDefault();
      mappingResult.textContent = 'Menyimpan mapping...';

      const payload = {
        vendor: document.getElementById('map_vendor').value,
        field: document.getElementById('map_field').value,
        wrong_value: document.getElementById('map_wrong').value,
        correct_value: document.getElementById('map_correct').value,
      };

      try {
        const response = await fetch('?action=confirm_mapping', {
          method: 'POST',
          headers: { 'Content-Type': 'application/json' },
          body: JSON.stringify(payload)
        });
        const data = await response.json();
        mappingResult.textContent = JSON.stringify(data, null, 2);
      } catch (err) {
        mappingResult.textContent = `Gagal simpan mapping: ${err}`;
      }
    });

  </script>
</body>
</html>
