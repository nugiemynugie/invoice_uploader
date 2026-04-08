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
require_once $rootPath . '/services/InvoiceProcessor.php';

$maxUploadMb = (int) (getenv('MAX_UPLOAD_MB') ?: 10);
$maxUploadBytes = $maxUploadMb * 1024 * 1024;
$allowedExtensions = ['pdf', 'png', 'jpg', 'jpeg', 'txt'];

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

    if (($invoice['size'] ?? 0) > $maxUploadBytes) {
        jsonResponse(['error' => "Ukuran file melebihi {$maxUploadMb} MB."], 400);
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
        $processor = new InvoiceProcessor(
            getenv('OLLAMA_BASE_URL') ?: 'http://localhost:11434',
            getenv('OLLAMA_MODEL') ?: 'gemma4'
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

    <form id="upload-form">
      <input type="file" id="invoice" name="invoice" accept=".pdf,.png,.jpg,.jpeg,.txt" required />
      <button type="submit">Upload & Analisa</button>
    </form>

    <section>
      <h2>Hasil</h2>
      <pre id="result">Belum ada data.</pre>
    </section>
  </main>

  <script>
    const form = document.getElementById('upload-form');
    const resultBox = document.getElementById('result');

    form.addEventListener('submit', async (e) => {
      e.preventDefault();
      resultBox.textContent = 'Memproses...';

      const formData = new FormData();
      const fileInput = document.getElementById('invoice');
      if (!fileInput.files.length) {
        resultBox.textContent = 'Pilih file terlebih dahulu.';
        return;
      }

      formData.append('invoice', fileInput.files[0]);

      try {
        const response = await fetch('?action=upload', { method: 'POST', body: formData });
        const data = await response.json();
        resultBox.textContent = JSON.stringify(data, null, 2);
      } catch (err) {
        resultBox.textContent = `Gagal: ${err}`;
      }
    });
  </script>
</body>
</html>
