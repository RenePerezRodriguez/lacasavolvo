#!/usr/bin/env bash
# =============================================================================
# Deploy incremental a PRODUCCIÓN por la API de cPanel (UAPI). **NO FTP.**
# =============================================================================
# Este hosting no tiene SSH: todo se hace por el token de cPanel (UAPI :2083),
# subiendo archivos con Fileman/save_file_content y corriendo PHP temporales
# (protegidos por ?key= y autoeliminados). Validado en producción 2026-06-19.
#
# Requisitos (Git Bash en Windows): curl, python, openssl, base64, npm.
# Lee credenciales de api/scripts/deploy/.deploy.env  → CPANEL_USER + CPANEL_API_TOKEN.
#
# USO:
#   bash api/scripts/deploy/deploy-cpanel-api.sh --api app/Http/Controllers/CajaController.php routes/api.php
#   bash api/scripts/deploy/deploy-cpanel-api.sh --spa              # build + sube el SPA a lacasavolvo.com
#   bash api/scripts/deploy/deploy-cpanel-api.sh --api routes/api.php --spa
#   (--no-build para reusar front/dist tal cual; por defecto el SPA se rebuildea)
#
# Pasos: sube .php de la API → limpia cachés (route/config/view + opcache) →
#        (opcional) build + zip→b64→extract del SPA → smoke test. Idempotente.
# =============================================================================
set -euo pipefail
export MSYS_NO_PATHCONV=1 MSYS2_ARG_CONV_EXCL="*"   # Git Bash mutila rutas /home/... sin esto

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
ROOT="$(cd "$SCRIPT_DIR/../../.." && pwd)"
cd "$ROOT"   # curl --data-urlencode content@<ruta> usa rutas RELATIVAS (evita líos de path en Windows)

ENVF="api/scripts/deploy/.deploy.env"
[ -f "$ENVF" ] || { echo "Falta $ENVF (copiá .deploy.env.example y completá CPANEL_*)"; exit 1; }
set -a; source <(grep -E '^(CPANEL_USER|CPANEL_API_TOKEN)=' "$ENVF" | sed 's/\r$//'); set +a
: "${CPANEL_USER:?CPANEL_USER vacío en .deploy.env}"; : "${CPANEL_API_TOKEN:?CPANEL_API_TOKEN vacío}"

H="lacasavolvo.com:2083"
AUTH="Authorization: cpanel ${CPANEL_USER}:${CPANEL_API_TOKEN}"
HOME_DIR="/home/${CPANEL_USER}"
API_DIR="$HOME_DIR/public_html/api.lacasavolvo.com"   # app Laravel (web docroot = su /public)
DOC="$HOME_DIR/public_html"                            # raíz del SPA (lacasavolvo.com)

qenc(){ python -c "import urllib.parse,sys;print(urllib.parse.quote(sys.argv[1]))" "$1"; }
# uapi_save <dir-absoluto-en-server> <nombre-archivo> <ruta-local-relativa>
uapi_save(){
  curl -sk -m 180 -H "$AUTH" "https://$H/execute/Fileman/save_file_content" \
    --data-urlencode "dir=$1" --data-urlencode "file=$2" --data-urlencode "content@$3" \
    | python -c "import sys,json;d=json.load(sys.stdin);assert d.get('status')==1,d.get('errors');print('  OK',sys.argv[1])" "$2"
}

API_FILES=(); DO_SPA=0; DO_BUILD=1
while [ $# -gt 0 ]; do
  case "$1" in
    --api) shift; while [ $# -gt 0 ] && [[ "$1" != --* ]]; do API_FILES+=("$1"); shift; done;;
    --spa) DO_SPA=1; shift;;
    --no-build) DO_BUILD=0; shift;;
    *) echo "Arg desconocido: $1"; exit 1;;
  esac
done
[ ${#API_FILES[@]} -eq 0 ] && [ $DO_SPA -eq 0 ] && { echo "Nada que hacer. Usá --api <archivos> y/o --spa."; exit 1; }

# ── 1) Archivos .php de la API ───────────────────────────────────────────────
if [ ${#API_FILES[@]} -gt 0 ]; then
  echo "== API (api.lacasavolvo.com) =="
  for rel in "${API_FILES[@]}"; do
    [ -f "api/$rel" ] || { echo "  NO existe api/$rel"; exit 1; }
    uapi_save "$API_DIR/$(dirname "$rel")" "$(basename "$rel")" "api/$rel"
  done

  # ── 2) Limpiar cachés (sin esto, las rutas nuevas dan 404) ──────────────────
  echo "== Limpiando cachés de la API =="
  S=$(openssl rand -hex 16); TMP=".scratch/_clearcache.php"; mkdir -p .scratch
  cat > "$TMP" <<PHP
<?php header('Content-Type: text/plain');
\$S='$S'; if((\$_GET['key']??'')!==\$S){http_response_code(403);exit("forbidden\n");}
\$B='$API_DIR'; require \$B.'/vendor/autoload.php'; \$a=require \$B.'/bootstrap/app.php';
\$a->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
foreach(['route:clear','config:clear','view:clear'] as \$c){ try{ \Illuminate\Support\Facades\Artisan::call(\$c); echo "  ok \$c\n"; }catch(Throwable \$e){ echo "  ! \$c: ".\$e->getMessage()."\n"; } }
if(function_exists('opcache_reset')) @opcache_reset();
@unlink(__FILE__); echo "[cachés limpias]\n";
PHP
  uapi_save "$API_DIR/public" "clearcache.php" "$TMP"; rm -f "$TMP"
  curl -sk -m 90 "https://api.lacasavolvo.com/clearcache.php?key=$S" || echo "  (timeout transitorio — API fría; verificá el smoke)"
fi

# ── 3) Frontend SPA (lacasavolvo.com) ────────────────────────────────────────
if [ $DO_SPA -eq 1 ]; then
  echo "== SPA (lacasavolvo.com) =="
  [ $DO_BUILD -eq 1 ] && ( cd front && npm run build )
  mkdir -p .scratch; ZIP=".scratch/spa.zip"; B64="$ZIP.b64"; rm -f "$ZIP" "$B64"
  # OJO: zip con python (separador '/'). NO Compress-Archive (mete '\' → rompe el SPA en Linux).
  python - "front/dist" "$ZIP" <<'PY'
import zipfile,os,sys
root,out=sys.argv[1],sys.argv[2]
with zipfile.ZipFile(out,'w',zipfile.ZIP_DEFLATED) as z:
    for dp,_,fs in os.walk(root):
        for f in fs:
            full=os.path.join(dp,f); z.write(full, os.path.relpath(full,root).replace(os.sep,'/'))
PY
  base64 -w0 "$ZIP" > "$B64"
  # backup del index.html vivo (rollback): guarda el hash del bundle anterior
  curl -sk -m 30 -H "$AUTH" "https://$H/execute/Fileman/get_file_content?dir=$(qenc "$DOC")&file=index.html" \
    | python -c "import sys,json,re;c=(json.load(sys.stdin).get('data') or {}).get('content','');open('.scratch/prod-index-before.html','w',encoding='utf-8').write(c);print('  bundle previo:',re.findall(r'assets/index-[^\"]+',c))"
  uapi_save "$HOME_DIR" "spa.zip.b64" "$B64"
  S=$(openssl rand -hex 16); TMP=".scratch/_spadeploy.php"
  cat > "$TMP" <<PHP
<?php header('Content-Type: text/plain');
\$S='$S'; if((\$_GET['key']??'')!==\$S){http_response_code(403);exit("forbidden\n");}
\$DOC='$DOC'; \$Z='$HOME_DIR/spa.zip'; \$Bb='$HOME_DIR/spa.zip.b64';
if(!is_file(\$Z)&&is_file(\$Bb)){ file_put_contents(\$Z, base64_decode(file_get_contents(\$Bb))); }
if(!is_file(\$Z)||filesize(\$Z)<1000) exit("[X] no zip\n");
\$z=new ZipArchive(); if(\$z->open(\$Z)!==true) exit("[X] no abro zip\n");
\$ok=false; for(\$i=0;\$i<\$z->numFiles;\$i++) if(\$z->getNameIndex(\$i)==='index.html'){\$ok=true;break;}
if(!\$ok){ \$z->close(); exit("[X] el zip no tiene index.html\n"); }
\$n=\$z->numFiles; \$z->extractTo(\$DOC); \$z->close(); @unlink(\$Z); @unlink(\$Bb);   // extrae ENCIMA, no mueve nada (sin downtime; subdominios intactos)
echo (is_file("\$DOC/index.html")&&is_dir("\$DOC/assets")) ? "[OK] \$n archivos extraídos\n" : "[!] postcheck falló\n";
@unlink(__FILE__); echo "[done]\n";
PHP
  uapi_save "$API_DIR/public" "spadeploy.php" "$TMP"; rm -f "$TMP"
  curl -sk -m 120 "https://api.lacasavolvo.com/spadeploy.php?key=$S"
  rm -f "$ZIP" "$B64"
fi

# ── 4) Smoke test ────────────────────────────────────────────────────────────
echo "== Smoke =="
curl -sk -m 30 -o /dev/null -w "  /api/login (espera 405): %{http_code}\n" "https://api.lacasavolvo.com/api/login" || true
[ $DO_SPA -eq 1 ] && curl -sk -m 30 "https://lacasavolvo.com/?cb=$RANDOM" \
  | python -c "import sys,re;print('  SPA bundle:',re.findall(r'assets/index-[^\"]+\.js',sys.stdin.read()))" || true
echo "== Deploy OK =="
