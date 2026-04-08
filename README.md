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
- Output JSON variabel invoice (vendor, nomor invoice, **nomor PO**, tanggal, total, item, dll).

## Prasyarat

1. PHP 8.1+ dengan ekstensi `curl`
2. Ollama aktif (`http://localhost:11434`)
3. Model vision yang mendukung baca gambar/PDF-as-image (contoh: `gemma4` sesuai setup Anda)
4. Untuk PDF: ekstensi PHP `imagick` terpasang

---

## Cara Instalasi (Step-by-step)

### 1) Clone project

```bash
git clone <url-repo-anda>
cd invoice_uploader
```

### Lokasi folder project (penting)

Boleh di mana saja, tergantung environment:

- **Local development**: bebas, contoh `~/projects/invoice_uploader`
- **Server Linux + Apache/Nginx (umum)**: biasanya di `/var/www/`, contoh:

```bash
cd /var/www
git clone <url-repo-anda> invoice_uploader
cd invoice_uploader
```

> Jika pakai Apache/Nginx, arahkan document root ke folder `public/`.

### 2) Install dependency sistem

#### Ubuntu / Debian

```bash
sudo apt update
sudo apt install -y php php-cli php-curl php-imagick imagemagick
```

#### macOS (Homebrew)

```bash
brew install php imagemagick
pecl install imagick
```

> Setelah install `imagick`, pastikan extension-nya aktif di `php.ini`.

### 3) Install dan jalankan Ollama

- Install Ollama dari: https://ollama.com/download
- Jalankan service Ollama, lalu pull model:

```bash
ollama pull gemma4
ollama run gemma4
```

### 4) Setup environment project

```bash
cp .env.example .env
```

Isi `.env`:

```env
OLLAMA_BASE_URL=http://localhost:11434
OLLAMA_MODEL=gemma4
MAX_UPLOAD_MB=10
```

### 5) Jalankan aplikasi

```bash
php -S 0.0.0.0:8000 -t public
```

Buka browser:

```text
http://localhost:8000
```

### 6) Cek cepat instalasi

```bash
php -m | grep -E "curl|imagick"
curl http://localhost:11434/api/tags
```

Kalau output `curl` menampilkan daftar model, berarti koneksi ke Ollama berhasil.

---

## Alur

1. User upload dokumen invoice.
2. Server kirim konten invoice ke Ollama (text/vision).
3. Ollama membaca isi invoice dan membuat field-variable JSON.
4. JSON hasil analisis ditampilkan di halaman.

## Catatan

- Jika model lokal Anda bukan `gemma4`, ganti `OLLAMA_MODEL`.
- Untuk akurasi terbaik pada scan, gunakan gambar/PDF yang tajam.
- Jika PDF gagal diproses, pastikan `imagick` aktif dan ImageMagick mendukung pembacaan PDF.


### Troubleshooting upload

Jika muncul error upload, cek nilai berikut pada response API:
- `upload_error_code`
- `php_upload_max_filesize`
- `php_post_max_size`

Jika file besar, naikkan limit di `php.ini`:

```ini
upload_max_filesize = 20M
post_max_size = 20M
```

Lalu restart PHP/web server.


Contoh kasus seperti ini:

```json
{
  "error": "Ukuran file melebihi batas upload_max_filesize di php.ini.",
  "upload_error_code": 1,
  "php_upload_max_filesize": "2M",
  "php_post_max_size": "8M"
}
```

Artinya limit PHP Anda masih kecil. Solusi cepat:

```bash
php -d upload_max_filesize=20M -d post_max_size=20M -S 0.0.0.0:8000 -t public
```

Atau ubah permanen di `php.ini`, lalu restart service PHP.



### Troubleshooting PDF policy ImageMagick

Jika muncul error:

```text
attempt to perform an operation not allowed by the security policy `PDF'
```

Itu berarti policy ImageMagick di server memblok pembacaan PDF.

Sekarang aplikasi sudah ada fallback ke `pdftoppm` (poppler). Pastikan tool ini terpasang:

```bash
# Ubuntu/Debian
sudo apt install -y poppler-utils

# macOS
brew install poppler
```

Setelah itu jalankan ulang aplikasi.

