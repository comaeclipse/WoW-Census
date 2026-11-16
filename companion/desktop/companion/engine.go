package companion

import (
	"context"
	"errors"
	"fmt"
	"sync"
	"time"
)

// LogEntry is a user-visible activity line.
type LogEntry struct {
	Seq     int64     `json:"seq"`
	At      time.Time `json:"at"`
	Level   string    `json:"level"` // info | warn | error
	Message string    `json:"message"`
}

// FileStatus is what the UI shows per discovered WoWCensus.lua.
type FileStatus struct {
	SavedVarFile
	SourceID      string     `json:"sourceId"`
	Bytes         int64      `json:"bytes"`
	ModTime       time.Time  `json:"modTime"`
	SHA256        string     `json:"sha256"`
	OverThreshold bool       `json:"overThreshold"`
	Uploaded      bool       `json:"uploaded"` // queued receipt exists for current hash
	LastReceipt   *Receipt   `json:"lastReceipt,omitempty"`
	NextRetry     *time.Time `json:"nextRetry,omitempty"`
	LastError     string     `json:"lastError,omitempty"`
}

// Status is the full UI status payload.
type Status struct {
	Running    bool         `json:"running"`
	Busy       string       `json:"busy,omitempty"`
	LastScan   time.Time    `json:"lastScan"`
	AutoUpload bool         `json:"autoUpload"`
	Files      []FileStatus `json:"files"`
	Log        []LogEntry   `json:"log"`
}

// MaintenanceReport summarises file sizes and companion backup usage. It is
// read-only: the companion never edits WoW's live SavedVariables.
type MaintenanceReport struct {
	GeneratedAt time.Time    `json:"generatedAt"`
	ThresholdMB int          `json:"thresholdMB"`
	Files       []FileStatus `json:"files"`
	BackupDir   string       `json:"backupDir"`
	BackupCount int          `json:"backupCount"`
	BackupBytes int64        `json:"backupBytes"`
	BackupCapMB int          `json:"backupCapMB"`
	BackupKeep  int          `json:"backupKeep"`
	Notes       []string     `json:"notes"`
}

type action int

const (
	actScan action = iota
	actUploadNow
	actBackupNow
)

// Engine runs the reconciliation watcher and owns config + state.
type Engine struct {
	appDir string
	state  *State

	mu       sync.Mutex
	cfg      Config
	status   Status
	onChange func(Status)
	logSeq   int64

	actions chan action

	// test hooks
	now       func() time.Time
	newClient func(serverURL, token string) *Client
	discover  func(roots []string) []SavedVarFile
}

const maxLog = 200

// NewEngine loads config and state from appDir ("" = OS default).
func NewEngine(appDir string) (*Engine, error) {
	if appDir == "" {
		d, err := DefaultAppDir()
		if err != nil {
			return nil, err
		}
		appDir = d
	}
	cfg, cfgErr := LoadConfig(appDir)
	st, err := LoadState(appDir)
	if err != nil {
		return nil, err
	}
	e := &Engine{
		appDir: appDir, state: st, cfg: cfg,
		actions:   make(chan action, 8),
		now:       time.Now,
		newClient: NewClient,
		discover:  DiscoverWithDefaults,
	}
	e.status.Files = []FileStatus{}
	e.status.Log = []LogEntry{}
	if cfgErr != nil {
		e.logf("error", "%v (defaults loaded)", cfgErr)
	}
	return e, nil
}

func (e *Engine) AppDir() string { return e.appDir }

// OnChange registers a callback fired (outside the lock) on status updates.
func (e *Engine) OnChange(fn func(Status)) {
	e.mu.Lock()
	e.onChange = fn
	e.mu.Unlock()
}

func (e *Engine) Config() Config {
	e.mu.Lock()
	defer e.mu.Unlock()
	c := e.cfg
	c.WoWRoots = append([]string{}, e.cfg.WoWRoots...)
	return c
}

// UpdateConfig validates, persists and applies a new config, then rescans.
// An empty DeviceToken keeps the stored one (the UI never receives it).
func (e *Engine) UpdateConfig(c Config) error {
	old := e.Config()
	if c.DeviceToken == "" {
		c.DeviceToken = old.DeviceToken
	}
	c.Normalize()
	if err := c.Validate(); err != nil {
		return err
	}
	if c.StartAtLogin != old.StartAtLogin {
		if err := SetStartAtLogin(c.StartAtLogin); err != nil {
			return fmt.Errorf("start at login: %w", err)
		}
	}
	if err := SaveConfig(e.appDir, c); err != nil {
		return err
	}
	if c.DeviceToken != old.DeviceToken || c.ServerURL != old.ServerURL {
		_ = e.state.ClearFailures()
	}
	e.mu.Lock()
	e.cfg = c
	e.status.AutoUpload = c.AutoUpload
	e.mu.Unlock()
	e.logf("info", "Settings saved (automatic uploads %s)", onOff(c.AutoUpload))
	e.Trigger(actScan)
	return nil
}

// ClearToken removes the stored device token and turns uploads off.
func (e *Engine) ClearToken() error {
	c := e.Config()
	c.DeviceToken, c.AutoUpload = "", false
	if err := SaveConfig(e.appDir, c); err != nil {
		return err
	}
	e.mu.Lock()
	e.cfg = c
	e.status.AutoUpload = false
	e.mu.Unlock()
	e.logf("info", "Device token removed; automatic uploads turned off")
	return nil
}

func onOff(b bool) string {
	if b {
		return "on"
	}
	return "off"
}

func (e *Engine) Status() Status {
	e.mu.Lock()
	defer e.mu.Unlock()
	s := e.status
	s.Files = append([]FileStatus{}, e.status.Files...)
	s.Log = append([]LogEntry{}, e.status.Log...)
	return s
}

// Trigger queues an action for the run loop without blocking.
func (e *Engine) Trigger(a action) {
	select {
	case e.actions <- a:
	default:
	}
}

func (e *Engine) ScanNow()   { e.Trigger(actScan) }
func (e *Engine) UploadNow() { e.Trigger(actUploadNow) }
func (e *Engine) BackupNow() { e.Trigger(actBackupNow) }

// Run polls every PollSeconds until ctx is cancelled. Reconciliation
// polling (rather than relying solely on OS events) means a change made
// while the machine slept, or while the app was closed, is still found.
func (e *Engine) Run(ctx context.Context) {
	e.setRunning(true)
	defer e.setRunning(false)
	e.logf("info", "Watcher started (polling every %ds)", e.Config().PollSeconds)
	e.reconcile(ctx, actScan)
	for {
		interval := time.Duration(e.Config().PollSeconds) * time.Second
		timer := time.NewTimer(interval)
		select {
		case <-ctx.Done():
			timer.Stop()
			return
		case a := <-e.actions:
			timer.Stop()
			e.reconcile(ctx, a)
		case <-timer.C:
			e.reconcile(ctx, actScan)
		}
	}
}

// RunOnce performs a single pass (used by the CLI and tests).
func (e *Engine) RunOnce(ctx context.Context, upload, backup bool) {
	switch {
	case upload:
		e.reconcile(ctx, actUploadNow)
	case backup:
		e.reconcile(ctx, actBackupNow)
	default:
		e.reconcile(ctx, actScan)
	}
}

func (e *Engine) reconcile(ctx context.Context, a action) {
	cfg := e.Config()
	forceUpload := a == actUploadNow
	forceBackup := a == actBackupNow
	switch a {
	case actUploadNow:
		e.setBusy("Uploading")
		if cfg.ServerURL == "" || cfg.DeviceToken == "" {
			e.logf("error", "Upload now needs a server URL and device token in Settings")
			forceUpload = false
		}
	case actBackupNow:
		e.setBusy("Backing up")
	default:
		e.setBusy("Scanning")
	}
	defer e.setBusy("")

	files := e.discover(cfg.WoWRoots)
	now := e.now()
	statuses := make([]FileStatus, 0, len(files))
	backupDir := cfg.ResolvedBackupDir(e.appDir)
	backedUp := false

	for _, f := range files {
		if ctx.Err() != nil {
			return
		}
		fs := FileStatus{SavedVarFile: f, SourceID: e.state.SourceID(f.Path)}
		sig, err := statSig(f.Path)
		if err != nil {
			fs.LastError = err.Error()
			statuses = append(statuses, fs)
			continue
		}
		fs.Bytes, fs.ModTime = sig.Size, sig.ModTime
		fs.OverThreshold = sig.Size > int64(cfg.SizeThresholdMB)<<20
		seen, known := e.state.SeenSig(f.Path)
		changed := !known || !seen.Sig.Equal(sig)
		if !changed {
			fs.SHA256 = seen.SHA256
		}
		_, hasReceipt := e.state.Receipt(fs.SourceID, fs.SHA256)
		fail, inBackoff := e.state.Backoff(fs.SourceID, fs.SHA256, now)
		wantUpload := (cfg.AutoUpload || forceUpload) && cfg.DeviceToken != "" && cfg.ServerURL != "" &&
			(changed || !hasReceipt) && (forceUpload || !inBackoff)
		wantBackup := forceBackup || (cfg.AutoBackup && changed)

		if changed || wantUpload || wantBackup {
			snap, err := TakeSnapshot(f, now)
			switch {
			case errors.Is(err, ErrUnstable):
				if forceUpload || forceBackup {
					e.logf("warn", "%s (%s) is still being written; will retry", f.Edition, f.Account)
				}
				fs.LastError = "waiting for the file to settle"
				statuses = append(statuses, fs)
				continue
			case err != nil:
				fs.LastError = err.Error()
				e.logf("error", "Read %s: %v", f.Path, err)
				statuses = append(statuses, fs)
				continue
			}
			fs.SHA256 = snap.SHA256
			if changed {
				_ = e.state.SetSeen(f.Path, snap.Sig, snap.SHA256)
				if known {
					e.logf("info", "New save detected: %s / %s (%s)", f.Edition, f.Account, humanBytes(sig.Size))
				}
			}
			if wantBackup {
				if p, err := WriteBackup(backupDir, snap, fs.SourceID); err != nil {
					e.logf("error", "Backup failed: %v", err)
				} else {
					backedUp = true
					e.logf("info", "Backup written: %s", p)
				}
			}
			_, hasReceipt = e.state.Receipt(fs.SourceID, snap.SHA256)
			fail, inBackoff = e.state.Backoff(fs.SourceID, snap.SHA256, now)
			if wantUpload && !hasReceipt && (forceUpload || !inBackoff) {
				e.upload(ctx, cfg, snap, fs.SourceID)
			} else if forceUpload && hasReceipt {
				e.logf("info", "%s / %s already uploaded (same content)", f.Edition, f.Account)
			}
		}

		if r, ok := e.state.Receipt(fs.SourceID, fs.SHA256); ok {
			fs.Uploaded = true
			fs.LastReceipt = &r
		} else if r, ok := e.state.LatestReceipt(fs.SourceID); ok {
			fs.LastReceipt = &r
		}
		if fail, inBackoff = e.state.Backoff(fs.SourceID, fs.SHA256, e.now()); inBackoff {
			t := fail.NextTry
			fs.NextRetry = &t
			fs.LastError = fail.LastErr
		}
		statuses = append(statuses, fs)
	}

	if backedUp || forceBackup {
		if n, err := RotateBackups(backupDir, cfg.BackupKeep, int64(cfg.BackupCapMB)<<20); err != nil {
			e.logf("error", "Backup rotation: %v", err)
		} else if n > 0 {
			e.logf("info", "Removed %d old backup(s)", n)
		}
	}
	if forceBackup && len(files) == 0 {
		e.logf("warn", "No WoWCensus.lua found to back up")
	}

	e.mu.Lock()
	e.status.Files = statuses
	e.status.LastScan = e.now().UTC()
	e.status.AutoUpload = cfg.AutoUpload
	e.mu.Unlock()
	e.notify()
}

func (e *Engine) upload(ctx context.Context, cfg Config, snap *Snapshot, sourceID string) {
	m := NewManifest(snap, sourceID, cfg.IncludeSystemInfo)
	b, err := BuildBundle(snap, m)
	if err != nil {
		e.logf("error", "Bundle failed: %v", err)
		return
	}
	label := snap.File.Edition + " / " + snap.File.Account
	e.logf("info", "Uploading %s (%s zipped)", label, humanBytes(int64(len(b.Zip))))
	r, err := e.newClient(cfg.ServerURL, cfg.DeviceToken).Upload(ctx, b)
	if err != nil {
		_ = e.state.RecordFailure(sourceID, snap.SHA256, err, e.now())
		e.logf("error", "Upload of %s failed: %v", label, err)
		return
	}
	_ = e.state.PutReceipt(r)
	if r.Status == "duplicate" {
		e.logf("info", "%s: server already has this version", label)
	} else {
		e.logf("info", "%s queued for import (receipt %s). Queued is not yet imported.", label, r.ReceiptID)
	}
}

// Report builds the read-only maintenance report from the latest scan.
func (e *Engine) Report() MaintenanceReport {
	cfg := e.Config()
	st := e.Status()
	dir := cfg.ResolvedBackupDir(e.appDir)
	rep := MaintenanceReport{
		GeneratedAt: e.now().UTC(), ThresholdMB: cfg.SizeThresholdMB, Files: st.Files,
		BackupDir: dir, BackupCapMB: cfg.BackupCapMB, BackupKeep: cfg.BackupKeep,
	}
	if bs, err := ListBackups(dir); err == nil {
		rep.BackupCount = len(bs)
		for _, b := range bs {
			rep.BackupBytes += b.Bytes
		}
	}
	over := 0
	for _, f := range st.Files {
		if f.OverThreshold {
			over++
		}
	}
	if over > 0 {
		rep.Notes = append(rep.Notes, fmt.Sprintf(
			"%d file(s) exceed %d MB. Large SavedVariables slow WoW's /reload and logout. "+
				"The companion does not trim them: safe compaction needs import acknowledgements from the server and must run while WoW is closed.",
			over, cfg.SizeThresholdMB))
	}
	rep.Notes = append(rep.Notes,
		"Upload receipts say \"queued\": stored and waiting for the ingest worker, not yet imported into the database.",
		"Backups are companion-made gzip copies, rotated by generation count and total size. Your live WoW files are never modified.")
	return rep
}

func (e *Engine) setRunning(b bool) {
	e.mu.Lock()
	e.status.Running = b
	e.mu.Unlock()
	e.notify()
}

func (e *Engine) setBusy(s string) {
	e.mu.Lock()
	e.status.Busy = s
	e.mu.Unlock()
	e.notify()
}

func (e *Engine) logf(level, format string, args ...any) {
	e.mu.Lock()
	e.logSeq++
	e.status.Log = append(e.status.Log, LogEntry{Seq: e.logSeq, At: e.now().UTC(), Level: level, Message: fmt.Sprintf(format, args...)})
	if len(e.status.Log) > maxLog {
		e.status.Log = e.status.Log[len(e.status.Log)-maxLog:]
	}
	e.mu.Unlock()
	e.notify()
}

func (e *Engine) notify() {
	e.mu.Lock()
	fn := e.onChange
	e.mu.Unlock()
	if fn != nil {
		fn(e.Status())
	}
}

func humanBytes(n int64) string {
	switch {
	case n >= 1<<20:
		return fmt.Sprintf("%.1f MB", float64(n)/(1<<20))
	case n >= 1<<10:
		return fmt.Sprintf("%.1f KB", float64(n)/(1<<10))
	}
	return fmt.Sprintf("%d B", n)
}
