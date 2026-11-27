"""
Build deploy artifacts into deploy/out/:
  app.zip         Laravel core (no dev deps, no tests, no node_modules) -> extracted to ~/<app_dir> (default app/mescits)
  public/         contents of Laravel public/ with index.php pointing to ../../<app_dir> -> ~/public_html/<sub>
                  (the public dir must be exactly two levels below the home dir, e.g. ~/public_html/mescits)
  x_<rand>.php    one-shot self-deleting extractor (token protected) uploaded to public dir
  env.production  .env template (DB_PASSWORD left for the user); extractor installs it only if no .env exists
  --drawio        also build drawio.zip + xd_<rand>.php (self-hosted draw.io, ~3000 files, pinned version in
                  cits/public/drawio/CITS_VERSION.txt). public/ never contains drawio/: deploy it only when it changes.
Usage: python deploy/build.py [--no-npm] [--drawio]
"""
import base64, os, secrets, shutil, subprocess, sys, zipfile
from pathlib import Path

ROOT = Path(__file__).resolve().parent.parent
SRC = ROOT / "cits"
OUT = ROOT / "deploy" / "out"
STAGE = OUT / "stage"
# Machine / site specific values live in deploy/deploy.local.json (git-ignored) or env vars:
#   {"php": "...php.exe", "composer": "...composer.phar", "app_url": "https://example.com/mescits",
#    "db_database": "cpuser_mescits", "db_username": "cpuser_mescits", "app_dir": "app/mescits"}
import json as _json
_LOCAL = Path(__file__).resolve().parent / "deploy.local.json"
_CFG = _json.loads(_LOCAL.read_text(encoding="utf-8")) if _LOCAL.exists() else {}
def _cfg(key, env, default):
    return os.environ.get(env) or _CFG.get(key) or default
PHP = _cfg("php", "CITS_PHP", shutil.which("php") or "php")
COMPOSER = _cfg("composer", "CITS_COMPOSER", "composer.phar")
APP_URL = _cfg("app_url", "CITS_APP_URL", "https://example.com/mescits")
DB_DATABASE = _cfg("db_database", "CITS_DB_DATABASE", "cpuser_mescits")
DB_USERNAME = _cfg("db_username", "CITS_DB_USERNAME", "cpuser_mescits")
APP_DIR = _cfg("app_dir", "CITS_APP_DIR", "app/mescits").strip("/")  # core dir relative to the home dir
assert all(part not in ("", ".", "..") for part in APP_DIR.split("/")), "app_dir must be a plain relative path"
_URL_PATH = (APP_URL.split("://", 1)[-1].split("/", 1) + [""])[1].strip("/")
ASSET_URL = "/" + _URL_PATH if _URL_PATH else ""  # Vite base path = sub directory of APP_URL

EXCLUDE_TOP = {"node_modules", "vendor", "tests", ".git", "public", ".env", ".env.example", "phpunit.xml",
               "CLAUDE.md", "AGENTS.md", ".phpunit.result.cache", "package.json", "package-lock.json",
               "vite.config.js", ".editorconfig", ".gitattributes", "docs"}


def run(args, cwd, env=None):
    print("+", " ".join(map(str, args)))
    subprocess.run(args, cwd=cwd, check=True, env=env)


def ignore_build_files(directory, names):
    """Skip local SQLite files everywhere, and Laravel's generated caches only inside bootstrap/cache
    (a bare "services.php" pattern also dropped config/services.php)."""
    skip = [n for n in names if n.endswith(".sqlite")]
    if Path(directory).resolve() == (SRC / "bootstrap" / "cache").resolve():
        skip += [n for n in names if n in ("packages.php", "services.php", "config.php") or (n.startswith("routes-") and n.endswith(".php"))]
    return skip


def copy_app():
    assert STAGE.parent == OUT and OUT.parent.name == "deploy"
    if STAGE.exists():
        shutil.rmtree(STAGE)
    STAGE.mkdir(parents=True)
    for item in SRC.iterdir():
        if item.name in EXCLUDE_TOP:
            continue
        if item.name == "storage":
            continue
        dst = STAGE / item.name
        if item.is_dir():
            shutil.copytree(item, dst, ignore=ignore_build_files)
        else:
            shutil.copy2(item, dst)
    # online user manual (served behind login by ManualController)
    manual = ROOT / "docs" / "manual"
    shutil.copytree(manual, STAGE / "resources" / "manual", ignore=shutil.ignore_patterns("dist", "build.php", "build-pdf.mjs"))
    # empty storage skeleton
    for d in ["app/private", "app/public", "framework/cache/data", "framework/sessions", "framework/views", "logs"]:
        p = STAGE / "storage" / d
        p.mkdir(parents=True, exist_ok=True)
        (p / ".gitignore").write_text("*\n!.gitignore\n", encoding="utf-8")
    run([PHP, COMPOSER, "install", "--no-dev", "--optimize-autoloader", "--no-interaction", "--no-scripts"], STAGE)
    (STAGE / ".env").write_text("APP_KEY=base64:" + base64.b64encode(os.urandom(32)).decode() + "\n", encoding="utf-8")
    run([PHP, "artisan", "package:discover"], STAGE)
    (STAGE / ".env").unlink()


def env_template():
    key = "base64:" + base64.b64encode(os.urandom(32)).decode()
    return f"""APP_NAME=CITS
APP_ENV=production
APP_KEY={key}
APP_DEBUG=false
APP_URL={APP_URL}
APP_LOCALE=zh_TW
APP_FALLBACK_LOCALE=en

LOG_CHANNEL=daily
LOG_LEVEL=warning

DB_CONNECTION=mariadb
DB_HOST=localhost
DB_PORT=3306
DB_DATABASE={DB_DATABASE}
DB_USERNAME={DB_USERNAME}
DB_PASSWORD=__FILL_IN_DB_PASSWORD__
DB_CHARSET=utf8mb4
DB_COLLATION=utf8mb4_uca1400_ai_ci

SESSION_DRIVER=file
SESSION_LIFETIME=120
SESSION_SECURE_COOKIE=true
CACHE_STORE=file
QUEUE_CONNECTION=database
FILESYSTEM_DISK=local
MAIL_MAILER=log
"""


def build_zip():
    z = OUT / "app.zip"
    if z.exists():
        z.unlink()
    with zipfile.ZipFile(z, "w", zipfile.ZIP_DEFLATED) as zf:
        for p in STAGE.rglob("*"):
            if p.is_file():
                zf.write(p, p.relative_to(STAGE).as_posix())
        zf.writestr("env.production", env_template())
    print("app.zip", round(z.stat().st_size / 1048576, 1), "MB")


def build_public():
    pub = OUT / "public"
    if pub.exists():
        shutil.rmtree(pub)
    shutil.copytree(SRC / "public", pub, ignore=lambda d, names: [n for n in names if n in ("hot", "storage") or (n == "drawio" and Path(d) == SRC / "public")])
    idx = (pub / "index.php").read_text(encoding="utf-8")
    idx = idx.replace("__DIR__.'/../storage/framework/maintenance.php'", f"__DIR__.'/../../{APP_DIR}/storage/framework/maintenance.php'")
    idx = idx.replace("__DIR__.'/../vendor/autoload.php'", f"__DIR__.'/../../{APP_DIR}/vendor/autoload.php'")
    idx = idx.replace("__DIR__.'/../bootstrap/app.php'", f"__DIR__.'/../../{APP_DIR}/bootstrap/app.php'")
    assert idx.count(f"../../{APP_DIR}/") == 3, "index.php patch failed"
    idx = idx.replace("$app->handleRequest(Request::capture());", "$app->usePublicPath(__DIR__);\n\n$app->handleRequest(Request::capture());")
    assert "usePublicPath" in idx
    (pub / "index.php").write_text(idx, encoding="utf-8", newline="\n")
    ht = pub / ".htaccess"
    s = ht.read_text(encoding="utf-8")
    if "HTTPS-FORCE" not in s:
        s = s.replace("RewriteEngine On", "RewriteEngine On\n\n    # HTTPS-FORCE\n    RewriteCond %{HTTPS} !=on\n    RewriteCond %{HTTP:X-Forwarded-Proto} !https\n    RewriteRule ^ https://%{HTTP_HOST}%{REQUEST_URI} [L,R=301]", 1)
    ht.write_text(s, encoding="utf-8", newline="\n")


def build_extractor():
    for old in OUT.glob("x_*.php"):
        old.unlink()
    token = secrets.token_hex(16)
    name = f"x_{secrets.token_hex(8)}.php"
    php = r"""<?php
declare(strict_types=1);
header('Content-Type: text/plain; charset=utf-8');
// POST + header only: a token in the query string would end up in access logs.
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST' || !hash_equals('__TOKEN__', (string)($_SERVER['HTTP_X_DEPLOY_TOKEN'] ?? ''))) { http_response_code(404); exit; }
// one run at a time; whatever happens next (error, timeout, fatal), the script removes itself
$lock = fopen(__FILE__, 'r');
if (!$lock || !flock($lock, LOCK_EX | LOCK_NB)) { http_response_code(409); echo "already running\n"; exit; }
register_shutdown_function(function () { if (is_file(__FILE__)) { @unlink(__FILE__); } });
@set_time_limit(300);
$home = dirname(__DIR__, 2);
$app = "$home/__APP_DIR__";
$zipPath = "$app/app.zip";
$out = [];
if (!is_file($zipPath)) { echo "app.zip not found\n"; exit; }
$zip = new ZipArchive();
if ($zip->open($zipPath) !== true) { echo "zip open failed\n"; exit; }
$keepEnv = is_file("$app/.env");
$n = 0;
$inZip = [];
for ($i = 0; $i < $zip->numFiles; $i++) {
  $name = $zip->getNameIndex($i);
  if (str_contains($name, '..')) continue;
  $inZip[$name] = true;
  if (str_starts_with($name, 'storage/') && !str_ends_with($name, '.gitignore')) continue;
  if ($name === 'env.production') {
    if (!$keepEnv) { file_put_contents("$app/.env", $zip->getFromIndex($i)); $out[] = '.env created from template'; }
    continue;
  }
  $dest = "$app/$name";
  if (str_ends_with($name, '/')) { @mkdir($dest, 0755, true); continue; }
  if (str_starts_with($name, 'storage/') && is_file($dest)) continue;
  @mkdir(dirname($dest), 0755, true);
  file_put_contents($dest, $zip->getFromIndex($i));
  $n++;
}
$zip->close();
// routes are loaded by glob(): a route file removed from the code must not stay active on the server
$stale = 0;
if (is_dir("$app/routes") && isset($inZip['routes/web.php'])) {  // never on a zip without routes (broken build)
  foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator("$app/routes", FilesystemIterator::SKIP_DOTS)) as $f) {
    $rel = 'routes/' . str_replace('\\', '/', substr($f->getPathname(), strlen("$app/routes/")));
    if ($f->isFile() && !isset($inZip[$rel]) && @unlink($f->getPathname())) { $out[] = "stale removed: $rel"; $stale++; }
  }
}
foreach (['bootstrap/cache'] as $d) { @mkdir("$app/$d", 0755, true); foreach (glob("$app/$d/*.php") as $f) { if (!in_array(basename($f), ['packages.php','services.php'], true)) @unlink($f); } }
$out[] = "files written = $n";
$out[] = 'env kept = ' . ($keepEnv ? 'yes' : 'no');
$out[] = 'zip deleted = ' . (@unlink($zipPath) ? 'yes' : 'NO');
$out[] = "stale route files removed = $stale";
$out[] = 'self deleted = ' . (@unlink(__FILE__) ? 'yes' : 'NO');
echo implode("\n", $out), "\n";
""".replace("__TOKEN__", token).replace("__APP_DIR__", APP_DIR)
    (OUT / name).write_text(php, encoding="utf-8", newline="\n")
    # line 1 = file name, line 2 = token; call it with POST and the header X-Deploy-Token (docs/DEPLOYMENT_GUIDE.md)
    (OUT / "extractor.txt").write_text(f"{name}\n{token}\n", encoding="utf-8")
    print("extractor", name)


# draw.io entry pages for cloud storage back-ends (they load Microsoft scripts); CITS only embeds index.html.
# Not shipped, and removed from the server by the drawio extractor.
DRAWIO_DROP = ["onedrive3.html", "teams.html"]


def build_drawio():
    """drawio.zip (uploaded to the private app folder, like app.zip) + one-shot extractor xd_*.php into <public>/drawio."""
    src = SRC / "public" / "drawio"
    assert (src / "CITS_VERSION.txt").is_file(), "public/drawio missing"
    z = OUT / "drawio.zip"
    if z.exists():
        z.unlink()
    n = 0
    with zipfile.ZipFile(z, "w", zipfile.ZIP_DEFLATED) as zf:
        for p in src.rglob("*"):
            if p.is_file() and p.relative_to(src).as_posix() not in DRAWIO_DROP:
                zf.write(p, p.relative_to(src).as_posix())
                n += 1
    for old in OUT.glob("xd_*.php"):
        old.unlink()
    token = secrets.token_hex(16)
    name = f"xd_{secrets.token_hex(8)}.php"
    php = r"""<?php
declare(strict_types=1);
header('Content-Type: text/plain; charset=utf-8');
// POST + header only: a token in the query string would end up in access logs.
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST' || !hash_equals('__TOKEN__', (string)($_SERVER['HTTP_X_DEPLOY_TOKEN'] ?? ''))) { http_response_code(404); exit; }
// one run at a time; whatever happens next (error, timeout, fatal), the script removes itself
$lock = fopen(__FILE__, 'r');
if (!$lock || !flock($lock, LOCK_EX | LOCK_NB)) { http_response_code(409); echo "already running\n"; exit; }
register_shutdown_function(function () { if (is_file(__FILE__)) { @unlink(__FILE__); } });
@set_time_limit(600);
$zipPath = dirname(__DIR__, 2) . '/__APP_DIR__/drawio.zip';
$dest = __DIR__ . '/drawio';
if (!is_file($zipPath)) { echo "drawio.zip not found\n"; exit; }
$zip = new ZipArchive();
if ($zip->open($zipPath) !== true) { echo "zip open failed\n"; exit; }
$n = 0;
for ($i = 0; $i < $zip->numFiles; $i++) {
  $name = $zip->getNameIndex($i);
  if (str_contains($name, '..') || str_starts_with($name, '/')) continue;
  if (str_ends_with($name, '/')) { @mkdir("$dest/$name", 0755, true); continue; }
  @mkdir(dirname("$dest/$name"), 0755, true);
  file_put_contents("$dest/$name", $zip->getFromIndex($i));
  $n++;
}
$zip->close();
echo "files written = $n\n";
foreach (__DROP__ as $f) { if (is_file("$dest/$f") && @unlink("$dest/$f")) echo "removed $f\n"; }
echo 'version = ' . trim((string) @file_get_contents("$dest/CITS_VERSION.txt")) . "\n";
echo 'zip deleted = ' . (@unlink($zipPath) ? 'yes' : 'NO') . "\n";
echo 'self deleted = ' . (@unlink(__FILE__) ? 'yes' : 'NO') . "\n";
""".replace("__TOKEN__", token).replace("__APP_DIR__", APP_DIR).replace("__DROP__", "[" + ", ".join(f"'{f}'" for f in DRAWIO_DROP) + "]")
    (OUT / name).write_text(php, encoding="utf-8", newline="\n")
    (OUT / "extractor_drawio.txt").write_text(f"{name}\n{token}\n", encoding="utf-8")
    print("drawio.zip", n, "files", round(z.stat().st_size / 1048576, 1), "MB; extractor", name)


if __name__ == "__main__":
    OUT.mkdir(parents=True, exist_ok=True)
    # ASSET_URL: vite base must include the sub-path, otherwise dynamic chunks (mermaid) load from /build and 404
    run(["npm.cmd" if os.name == "nt" else "npm", "run", "build"], SRC, env={**os.environ, "ASSET_URL": ASSET_URL}) if "--no-npm" not in sys.argv else None
    copy_app()
    build_zip()
    build_public()
    build_extractor()
    if "--drawio" in sys.argv:
        build_drawio()
    print("done")
