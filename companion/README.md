# WoWCensus Companion

A Go/Wails v3 desktop app that watches `WoWCensus.lua` SavedVariables and, with the player's consent, uploads them through a Next.js/Vercel API that hands out short-lived presigned Cloudflare R2 PUT URLs. See [ARCHITECTURE.md](ARCHITECTURE.md) for the design and wire contract.

```
companion/
  desktop/                 overlay for the official Wails v3 vanilla-js template
    main.go                window, tray menu, hide-on-close
    companionservice.go    methods bound to the frontend
    companion/             stdlib-only core (watcher, detection, snapshots, bundles,
                           upload client, backups, report, start-at-login) + tests
    cmd/companion-cli/     headless runner for the same engine
    frontend/              index.html, src/main.js, public/style.css
  api/                     Next.js 16 app for Vercel (prepare / complete / health)
  scripts/                 bootstrap-desktop.ps1 / .sh
```

## Desktop

Needs **Go 1.26+** (Wails v3.0.0 requires it) and the Wails CLI.

```powershell
go install github.com/wailsapp/wails/v3/cmd/wails3@v3.0.0
wails3 doctor
.\scripts\bootstrap-desktop.ps1          # creates .\wowcensus-companion and applies the overlay
cd wowcensus-companion
wails3 dev                               # or: wails3 build / wails3 package
```

The bootstrap runs `wails3 init -t vanilla-js -mod wowcensuscompanion`, deletes the template's `greetservice.go`, copies `desktop/` over it, runs `go mod tidy` and the core tests. The template's `build/`, `Taskfile.yml` and `go.mod` are kept. The frontend imports generated bindings from `frontend/bindings/wowcensuscompanion`, which `wails3 dev`/`build` regenerate.

Command-line flag: `--minimized` starts hidden in the tray (start-at-login uses it).

### Headless CLI

Same engine, no UI. Useful before Wails is set up:

```powershell
cd wowcensus-companion   # or any module that contains desktop/
go run ./cmd/companion-cli -scan          # detected files (JSON)
go run ./cmd/companion-cli -report        # maintenance report
go run ./cmd/companion-cli -backup-now
go run ./cmd/companion-cli -upload-now    # needs serverUrl + deviceToken in config.json
go run ./cmd/companion-cli                # watch until Ctrl+C
```

`-app-dir <dir>` points it at a different config/state folder.

### Where things are stored

`<UserConfigDir>/WoWCensusCompanion/` (`%AppData%\WoWCensusCompanion` on Windows):

- `config.json` (0600): settings, including the device token.
- `state.json` (0600): per-install salt, queued receipts (dedup across restarts), last seen file signatures, retry backoff.
- `backups/<sourceId prefix>/<UTC time>-<sha prefix>.lua.gz`: optional compressed backups, rotated by generations per file and a total size cap.

## What the desktop app does

- **Consent first.** Automatic uploads are OFF by default and turning them on asks for confirmation. System info (OS, arch, CPU count, Go version) is a separate opt-in. Without a token nothing is ever sent.
- **Detection**: user-chosen folders plus typical install paths per OS. A bounded search (depth 4, 4,000 dirs per root, no symlinks) finds every `_edition_/WTF/Account/<ACCOUNT>/SavedVariables/WoWCensus.lua`.
- **Watching**: reconciliation polling every 15 s (5–600 s configurable). Changes made while asleep or closed are still found on the next pass. It does not use fsnotify yet.
- **Stable snapshots**: a file is read only after 2 s without writes, and only if its size and mtime are the same before and after the read. Files over 256 MB are refused.
- **Bundle**: ZIP with `manifest.json` and `WoWCensus.lua`. The manifest contains a salted source ID, edition, SHA-256, size, mtime, time and app version. It never contains the local path or the account folder name.
- **Upload**: `prepare` → direct PUT to R2 → `complete`. Receipts are keyed by (sourceId, sha256), so unchanged content is never re-sent. Failures back off exponentially (30 s to 1 h, longer if the server sends `Retry-After`). **Upload now** skips the backoff.
- **Tray**: closing the window hides it. The tray menu has Open, Upload now, Back up now and Quit.
- **Start at login**: a per-user Run key on Windows, a LaunchAgent on macOS, an XDG autostart entry on Linux.
- **Maintenance**: a read-only size report plus backup rotation. **The live WoW Lua files are never edited** (see ARCHITECTURE.md for why).

## API (Vercel)

```powershell
cd api
npm install
copy .env.example .env.local    # fill in R2, Upstash and DEMO_DEVICE_TOKEN_SHA256
npm run dev
npm test                        # auth + schema unit tests
npm run build
```

Deploy `api/` as its own Vercel project (for example on `upload.wowcensus.io`). Set the same variables in the Vercel project. The R2 bucket must be **private**.

- `POST /api/v1/uploads/prepare`: per-IP limit (60 per 10 min), then bearer auth, then a per-device limit (30/h). It validates the request with zod, enforces size limits and returns `duplicate` if this content was already queued. Otherwise it presigns a 120 s PUT with Content-Type, Content-Length and (optionally) the SHA-256 checksum signed in.
- `POST /api/v1/uploads/complete`: checks the object with HeadObject (size + type). A mismatching object is deleted. A valid one gets an `XADD wowcensus:ingest` entry and a receipt saved for 90 days. Completion is idempotent and guarded by a lock.
- `GET /api/health`.

### Configure a pilot client

1. Make a long random token: `node -e "console.log(require('crypto').randomBytes(32).toString('base64url'))"`
2. Hash it: `node -e "console.log(require('crypto').createHash('sha256').update(process.argv[1]).digest('hex'))" <token>` and set the hash as `DEMO_DEVICE_TOKEN_SHA256`.
3. In the companion's Settings, paste the token, set the server URL, check the detected folders, and turn on automatic uploads.
4. `/reload` or log out in WoW, or press **Upload now**.

The single shared token is **only for private pilots**. Before a public launch you need per-device enrollment, hashed and scoped tokens with revocation, and an OS credential store (the token is currently in `config.json`).

## Verified so far

- Go core: unit tests cover detection, snapshot stability, bundle privacy, salted IDs, backup rotation, and an end-to-end upload against a fake prepare/PUT/complete server (dedup across restarts, consent, backoff, upload-now). `go vet` passes for windows, darwin and linux.
- The desktop app compiles and vets against `github.com/wailsapp/wails/v3 v3.0.0` with Go 1.26.0.
- API: `tsc`, unit tests and `next build` pass.
- **Not yet tested** against a real R2 bucket and Upstash instance. In particular, check that R2 accepts the signed `x-amz-checksum-sha256` header; set `R2_SIGN_CHECKSUM=false` if it does not. The UI has not been clicked through in a real Wails window yet.

## Still to build before public launch

- **Ingest worker** (not included): consumes `wowcensus:ingest` with a consumer group and is idempotent on `ingestKey`. It must re-verify the zip SHA-256, cap uncompressed size and zip inflation, and parse only allowlisted Lua constructs with a non-executing parser (never run uploaded Lua). It batches Postgres writes under a budget and applies retention to R2 objects.
- **Import acknowledgements** before any compaction. "Queued" is not "imported".
- fsnotify triggers, an auto-updater, signed installers, notifications, a secure token store, single-instance, a tray icon asset, per-device enrollment and revocation, and a privacy disclosure review.
