#!/usr/bin/env bash
# Génère un certificat auto-signé pour le profil Compose `app-tls`.
#
# Usage : ./infrastructure/nginx/generate-dev-cert.sh
# Résultat : infrastructure/nginx/certs/{fullchain,privkey}.pem
# Ces fichiers ne doivent jamais être commités.
set -euo pipefail

script_dir="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
cert_dir="${script_dir}/certs"
mkdir -p "${cert_dir}"

if [[ -f "${cert_dir}/fullchain.pem" && -f "${cert_dir}/privkey.pem" ]]; then
    echo "Certificat déjà présent dans ${cert_dir} (supprimer les fichiers pour régénérer)."
    exit 0
fi

openssl req -x509 -newkey rsa:2048 -sha256 -days 825 -nodes \
    -keyout "${cert_dir}/privkey.pem" \
    -out "${cert_dir}/fullchain.pem" \
    -subj "/CN=localhost" \
    -addext "subjectAltName=DNS:localhost,IP:127.0.0.1" >/dev/null 2>&1

chmod 600 "${cert_dir}/privkey.pem"
chmod 644 "${cert_dir}/fullchain.pem"

echo "Certificat auto-signé généré :"
echo "  ${cert_dir}/fullchain.pem"
echo "  ${cert_dir}/privkey.pem"
echo "Démarrer ensuite : docker compose --profile app --profile app-tls up -d"
