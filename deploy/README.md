# Running a staging server

One Ubuntu server runs everything in Docker: the web app with automatic HTTPS
(FrankenPHP/Caddy), a queue worker, the scheduler, Postgres with pgvector and
Redis. Good for the live WhatsApp test and a small pilot; split it up later.

## 1. Create the server (AWS Lightsail, Mumbai)

1. AWS console → **Lightsail** → **Create instance**.
2. Region **Mumbai (ap-south-1)**. Platform **Linux/Unix**, blueprint **OS Only → Ubuntu 24.04 LTS**.
3. Plan with at least **2 GB RAM** (building the images needs it). Create.
4. Instance → **Networking**: create and attach a **static IP**, and add a
   firewall rule for **HTTPS (443)**. HTTP (80) is open by default; keep it,
   the certificate check needs it.

## 2. Point a domain at it

At your DNS provider, add an **A record**, e.g. `wa.yourdomain.com` → the static IP.
Wait until `ping wa.yourdomain.com` shows that IP.

## 3. Install the app

Connect with the **Connect using SSH** button in Lightsail, then:

```bash
sudo apt-get update && sudo apt-get install -y git
git clone https://github.com/Mithilaj2/whatsapp-bot.git
cd whatsapp-bot
sudo bash deploy/setup.sh
```

The repository is private, so `git clone` asks for your GitHub username and a
password: use a **fine-grained personal access token** (GitHub → Settings →
Developer settings) with read access to this repository's contents.

`setup.sh` asks for the domain, the Meta App ID and the App Secret, writes
`deploy/.env` with generated database passwords, builds and starts everything,
and prints the webhook URL and verify token.

## 4. Connect your WhatsApp test number

1. Open `https://wa.yourdomain.com`, create your account and business, and
   copy the **Business ID** shown under the business name.
2. In the Meta app → WhatsApp → API Setup, copy an access token. The temporary
   one lasts 24 hours; for longer, create a System User token in Business
   Settings with `whatsapp_business_messaging` and `whatsapp_business_management`.
3. On the server:

   ```bash
   sudo bash deploy/artisan.sh whatsapp:connect <business-id> \
       --waba=<whatsapp-business-account-id> --phone-number-id=<phone-number-id>
   ```

   It asks for the token (hidden), checks it with Meta and stores it encrypted.

## 5. Point Meta's webhook at the server

Meta app → WhatsApp → **Configuration** → Webhook → **Edit**:

- Callback URL: `https://wa.yourdomain.com/api/webhooks/whatsapp`
- Verify token: printed by `setup.sh` (also `META_WEBHOOK_VERIFY_TOKEN` in `deploy/.env`)

Save, then **subscribe** to the `messages` field. Now send "hi" from your phone
to the test number; it shows up in the inbox.

## Day to day

- Update to the latest code: `sudo bash deploy/update.sh`
- Logs: `sudo docker compose -f deploy/docker-compose.yml --env-file deploy/.env logs -f web worker`
- Any artisan command: `sudo bash deploy/artisan.sh <command>`

`deploy/.env` holds every secret. Keep it on the server only, and back up the
`pgdata` volume before anyone relies on the data.
