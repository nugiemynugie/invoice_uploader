# Invoice Uploader (PHP Native + Ollama Gemma)

Project web berbasis **PHP murni** untuk upload invoice lalu **dibaca langsung oleh Ollama** (model default `gemma4`) untuk menghasilkan variable/field invoice dalam format JSON.

## Cocok untuk integrasi Laravel

Struktur dipisah agar mudah dipindah ke Laravel:
- `services/InvoiceProcessor.php` berisi logika inti pemrosesan invoice + panggilan Ollama.
- `public/index.php` berisi endpoint upload dan UI demo sederhana.

## Fitur

- Upload invoice: `PDF`, `PNG`, `JPG`, `JPEG`, `TXT`.
- Parsing data invoice langsung oleh Ollama:
  - TXT: isi teks dikirim ke model.
  - Gambar: dikirim sebagai input vision ke model.
  - PDF: dikonversi ke gambar (Imagick), lalu dibaca model vision.
- Output JSON variabel invoice (vendor, nomor invoice, tanggal, total, item, dll).

## Prasyarat

1. PHP 8.1+ dengan ekstensi `curl`
2. Ollama aktif (`http://localhost:11434`)
3. Model vision yang mendukung baca gambar/PDF-as-image (contoh: `gemma4` sesuai setup Anda)
4. Untuk PDF: ekstensi PHP `imagick` terpasang

## Setup

```bash
cp .env.example .env
```

Isi `.env`:

```env
OLLAMA_BASE_URL=http://localhost:11434
OLLAMA_MODEL=gemma4
MAX_UPLOAD_MB=10
```

## Jalankan

```bash
php -S 0.0.0.0:8000 -t public
```

Buka: `http://localhost:8000`

## Alur

1. User upload dokumen invoice.
2. Server kirim konten invoice ke Ollama (text/vision).
3. Ollama membaca isi invoice dan membuat field-variable JSON.
4. JSON hasil analisis ditampilkan di halaman.

## Catatan

- Jika model lokal Anda bukan `gemma4`, ganti `OLLAMA_MODEL`.
- Untuk akurasi terbaik pada scan, gunakan gambar/PDF yang tajam.
