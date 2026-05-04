# Poppy Storage

A simple, secure PHP-based file storage API with bucket support for Next.js apps. No S3 compatibility needed - just plain PHP, cPanel ready.

## Features

- Multi-bucket support with unique API keys
- File upload (images: JPG, PNG, WebP + PDFs only)
- File retrieval with Cloudflare-optimized caching headers
- File deletion with JSON metadata updates
- Folder-based storage with sharding for performance
- MIME validation using finfo
- 10MB file size limit
- Admin dashboard (create/delete buckets, view sizes)
- Basic auth protected admin panel
- Bucket size tracking via per-bucket JSON files
- Laravel-style security (storage outside web root)
- API keys hashed with BCRYPT (plaintext shown only once)

## Directory Structure

```
poppy-storage/
├── app/                    # Application code (outside web root)
│   ├── config.php          # Configuration + helpers
│   ├── Api/                # API endpoints
│   ├── Admin/              # Admin handlers
│   └── Helpers/            # Security utilities
├── public/                 # Web root (cPanel points here)
│   ├── index.php           # Front controller
│   └── .htaccess
├── storage/                # Runtime data (outside web root)
│   ├── buckets.json        # Global bucket metadata
│   └── buckets/            # Per-bucket file storage
├── .env.example            # Configuration template
└── instruction.md          # Full project context
```

## Installation

1. Upload to cPanel home directory
2. Point your domain (e.g., `localhost:3060`) to `poppy-storage/public/`
3. Copy `.env.example` to `.env` and fill in:
   ```env
    URL=http://localhost:3060
    ADMIN_USER=admin
    ADMIN_PASS=your_strong_password
    MAX_SIZE=10485760
   ```
4. Set permissions: `storage/` to 0750, JSON files to 0640

## Usage

### Upload (Next.js)
```js
const formData = new FormData();
formData.append("file", file);

const res = await fetch(
  `http://localhost:3060/api/upload?bucket=mybucket&key=API_KEY`,
  { method: "POST", body: formData }
);
const data = await res.json();
// data.url contains file URL
```

### Display
```jsx
<img src="http://localhost:3060/api/file?bucket=mybucket&f=a1/abc123.jpg" />
```

### Delete
```js
await fetch(
  `http://localhost:3060/api/delete?bucket=mybucket&f=a1/abc123.jpg&key=API_KEY`
);
```

## Admin Panel

Access at `http://localhost:3060/admin` - protected with basic auth.

- Create buckets (API key shown once after creation)
- Delete buckets (recursive file cleanup)
- View bucket sizes (calculated from files.json)

## Security

- All sensitive files (`storage/`, `.env`) outside web root
- API keys hashed with BCRYPT, never stored in plaintext
- Path traversal prevention
- PHP execution disabled in upload folders
- Whitelisted routes only (no arbitrary file inclusion)
- Basic auth for admin panel

## Requirements

- PHP 7.4+ (for `random_bytes`, `finfo`, `password_hash`)
- Apache with `.htaccess` support
- No Composer dependencies
