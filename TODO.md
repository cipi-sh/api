Implementazione completa. Riassumo cosa ho fatto e cosa resta da chiedere al pacchetto Cipi principale.

---

## Cosa è stato implementato in `cipi/api` (release 1.7.0)

Tutti i file passano `php -l`, l'OpenAPI è valido, niente lint. Nessuna nuova dipendenza in `composer.json`.

### Nuove rotte

- **Pubblica**: `GET /api/ping` (no auth) — onboarding mobile.
- **Server**: `GET /api/server/status`, `GET /api/server/metrics?range=…`, `GET /api/server/ssl/expiring?days=…`.
- **SSL**: `GET /api/apps/{name}/ssl`, `POST /api/apps/{name}/ssl/renew`.
- **Deploy history**: `GET /api/apps/{name}/deploys`, `…/{job}`, `…/{job}/log`.
- **Job log tail**: `GET /api/jobs/{id}/log/tail?from_byte=…` (long-polling pronto per uso da Flutter).
- **Devices**: `GET/POST/PATCH/DELETE /api/devices` scoped per token Sanctum.
- **Activity**: `GET /api/activity?type=&app=&status=&cursor=`.
- **Search**: `GET /api/search?q=…`.

Throttle dedicati per ogni gruppo (status 120/min, metrics 60/min, ssl/expiring 30/min, log-tail 120/min, devices register 30/min, search 60/min, ping 60/min).

### Nuove abilities Sanctum

`server-view`, `ssl-view`, `deploy-view`, `activity-view` (oltre alle esistenti). Esempio token mobile:
`apps-view,deploy-manage,deploy-view,ssl-view,ssl-manage,server-view,activity-view,dbs-view,dbs-manage`.

### Migrazioni

- `cipi_jobs`: aggiunte `app`, `log_path`, `started_at`, `finished_at`, `duration_seconds`, `triggered_by`, `token_id` (additive, idempotent).
- `cipi_devices` con unique `(token_id, push_token)`.
- `cipi_server_metrics`.

### Servizi nuovi

- `CipiVersionService` (legge `/etc/cipi/version` o `cipi --version`, cached 10').
- `ServerStatusService` (`/proc/loadavg`, `/proc/meminfo`, `/proc/cpuinfo`, `/proc/stat`, `df -PBM`, `systemctl is-active`).
- `SslInspectorService` (handshake TLS via `stream_socket_client` con SNI, parse `openssl_x509_parse`, cache 5').
- `JobLogService` (file per-job in `storage/app/cipi-job-logs/{uuid}.log`, tail con `from_byte`, retention).

### Streaming live dei log

`RunCipiCommand` riscritto con `proc_open` + `stream_select` non-bloccante, scrive l'output in tempo reale sul log file e poi chiude pulito. Nessun cambiamento al contratto di `CipiCliService::commandIsPermitted()`. Compatibile retroattivamente con tutti i job esistenti.

### Push notifications

- Evento `CipiApi\Events\JobStateChanged` (started/completed/failed) emesso da `RunCipiCommand`.
- Listener `SendJobNotifications` mappa job→evento (`deploy.success`, `deploy.failed`, `ssl.installed`, `ssl.failed`, `db.backup_completed`, …) e fa fan-out al solo set di device del token che ha lanciato il job (sicurezza).
- Driver pluggable: `PushDriverContract` con default `LogPushDriver`. Per FCM/APNs si bind nel proprio `AppServiceProvider`. Niente dipendenze HTTP esterne aggiunte: il pacchetto resta zero-friction.

### Scheduler

`cipi:record-server-metrics --prune` ogni minuto + `cipi:prune-job-logs` daily 03:30. Si registrano automaticamente quando il package viene caricato in console — basta che `schedule:run` giri sull'host.

### OpenAPI 3 + Swagger

`info.version` → `1.7.0`. Aggiunti tutti i nuovi paths/schemas (`PingResponse`, `ServerStatusResponse`, `ServerMetricsResponse`, `SslCertificate`, `SslInfoResponse`, `SslExpiringResponse`, `DeployHistoryItem`/`List`/`Show`/`Log`, `JobLogTailResponse`, `Device`/`DeviceRegisterRequest`/`DeviceUpdateRequest`, `ActivityListResponse`, `SearchResponse`) e tag (`Server`, `Devices`, `Activity`, `Search`, `Health`).

---

## Cosa chiedere/implementare nel pacchetto Cipi principale (`cipi-sh/cipi`)

Tre tipi di richiesta: una **bloccante**, alcune **molto utili**, alcune **nice-to-have**.

### Bloccanti — senza queste alcune feature restano "best-effort"

1. **Versione esposta in maniera leggibile dal package API**
   - Opzione A (zero-friction): scrivere `/etc/cipi/version` durante l'install di `setup.sh` e `cipi self-update`, contenente la sola versione (es. `4.4.17\n`).
   - Opzione B: garantire che `/usr/local/bin/cipi --version` funzioni **senza sudo** (eseguibile da `www-data`) o aggiungerlo al `cipi-api` sudoers whitelist.
   - Senza una delle due, `GET /api/ping` ritorna `version: null` (non blocca l'app, ma è brutto).

2. **Path standard dei backup database**
   - Documentare ufficialmente la directory dove `cipi db backup` salva i `.sql.gz` (oggi sembra `/home/cipi/backups` ma non è in setup.sh esplicito).
   - In alternativa: aggiungere una sub-command `cipi db backups <name>` (lista) e `cipi db backups <name> latest` (path), così potremmo esporre `GET /api/dbs/{db}/backups` senza ambiguità.

### Molto utili — sbloccano feature complete senza workaround

3. **Hook deploy → file di log condiviso**
   I deploy via webhook GitHub o `cipi deploy <app>` dalla CLI **non passano dal nostro `cipi_jobs`**, quindi la deploy history mobile li perde. Tre opzioni in ordine di preferenza:
   - **a)** Cipi scrive in append a `/var/log/cipi/deploys.jsonl` un record per deploy (`{app, status, exit_code, commit, branch, started_at, finished_at, log_path}`). Il package API può leggerlo come fonte aggiuntiva.
   - **b)** Cipi espone `cipi deploy history <app> --json --limit=N` da CLI (consumabile via `sudo`).
   - **c)** Cipi scrive ogni log di deploy in `/var/log/cipi/deploys/<app>/<id>.log` con un manifest. Anche questo abbastanza pulito.

4. **Sudoers whitelist per metriche/info read-only**
   Il package legge tutto via `/proc` e `df` senza sudo, ma alcuni `systemctl is-active` su servizi privilegiati possono dare `unknown`. Aggiungere a `/etc/sudoers.d/cipi-api`:

   ```
   www-data ALL=(root) NOPASSWD: /usr/bin/systemctl is-active *
   ```

   (solo `is-active`, non lo `start/stop`). E lasciar passare `cipi --version` se si sceglie l'opzione B di sopra.

5. **`cipi ssl info <app>` CLI** _(opzionale ma elegante)_
   Oggi facciamo handshake TLS contro `127.0.0.1:443` con SNI. Funziona, ma non distingue tra "cert valido in `/etc/letsencrypt/live/…`" e "cert auto-firmato di Nginx default". Una sub-command `cipi ssl info <app> --json` che restituisca `issuer/valid_until/days_remaining/auto_renewal/last_renewal_at` chiuderebbe il cerchio (e ci permetterebbe di esporre `auto_renewal` e `last_renewal_at` nell'API).

6. **`cipi server status --json`** _(opzionale)_
   Comando ufficiale che esponga la stessa snapshot che già produciamo. Vantaggi: la verità è nel package Cipi (es. lista servizi corretta per la distro), e potremmo passarci sopra anche da CLI client.

### Nice to have

7. **Eventi sub-command in Cipi**: un hook `cipi-hooks/post-deploy.d/` standardizzato, così possiamo registrare uno script che fa POST locale al nostro `/api/internal/jobs/notify` per notificare deploy non originati dall'API. Più pulito di leggere un log file.

8. **Centralizzazione abilities**: definire nel main package una _baseline_ di abilities documentate (incluse le nuove `server-view`, `ssl-view`, `deploy-view`, `activity-view`) così che `cipi:token-create` possa offrire shortcut tipo `--profile=mobile`.

9. **Scrittura `apps.json` con timestamp `last_deployed_at` per app** — permetterebbe ordinamento e widget "deploy recenti" senza dipendere da `cipi_jobs`.

---

## Cosa **non** ho implementato (di proposito)

- **Server-Sent Events** sul tail dei log: ho preferito long-polling con `from_byte` perché è agnostico al web server (PHP-FPM + Nginx senza buffer disable funziona out of the box). Se davvero serve SSE, te lo aggiungo come endpoint affiancato — ma per Flutter `http` con poll a 1-2s va bene.
- **Driver FCM completo**: richiede `firebase/php-jwt` o `kreait/firebase-php` come dipendenze e config delle service account. Ti ho lasciato un contract pulito (`PushDriverContract`) e lo binding nel container, così il driver lo metti dove ha più senso (potrebbe anche stare nel main `cipi` package se diventa standard).
- **Rotazione SSL automatica via API**: il `POST /ssl/renew` chiama lo stesso job `ssl install` di sempre. Un `cipi ssl renew --force` separato avrebbe senso solo dopo aver implementato il punto 5 sopra.

Quando vuoi, posso aprire un issue/PR draft sul main `cipi` con i punti 1-6 strutturati come spec, oppure sviluppare un driver FCM concreto se hai già le credenziali Firebase.
