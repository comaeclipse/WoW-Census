package companion

import (
	"archive/zip"
	"bytes"
	"context"
	"encoding/json"
	"errors"
	"io"
	"net/http"
	"net/http/httptest"
	"os"
	"path/filepath"
	"strings"
	"sync/atomic"
	"testing"
	"time"
)

func makeInstall(t *testing.T, root, edition, account, content string) string {
	t.Helper()
	dir := filepath.Join(root, "World of Warcraft", edition, "WTF", "Account", account, "SavedVariables")
	if err := os.MkdirAll(dir, 0o755); err != nil {
		t.Fatal(err)
	}
	p := filepath.Join(dir, SavedVariablesName)
	writeOld(t, p, content)
	return p
}

// writeOld writes a file and backdates its mtime so it counts as stable.
func writeOld(t *testing.T, p, content string) {
	t.Helper()
	if err := os.WriteFile(p, []byte(content), 0o644); err != nil {
		t.Fatal(err)
	}
	old := time.Now().Add(-time.Minute)
	if err := os.Chtimes(p, old, old); err != nil {
		t.Fatal(err)
	}
}

func TestDiscoverFindsEditionsAndAccounts(t *testing.T) {
	root := t.TempDir()
	a := makeInstall(t, root, "_retail_", "ACCOUNT1", "WoWCensusDB = {}")
	b := makeInstall(t, root, "_anniversary_", "ACCOUNT2", "WoWCensusDB = {}")
	// A stray account-level SavedVariables folder must be ignored.
	_ = os.MkdirAll(filepath.Join(root, "World of Warcraft", "_retail_", "WTF", "Account", "SavedVariables"), 0o755)

	files := Discover([]string{root})
	got := map[string]SavedVarFile{}
	for _, f := range files {
		got[pathKey(f.Path)] = f
	}
	if f, ok := got[pathKey(a)]; !ok || f.Edition != "retail" || f.Account != "ACCOUNT1" {
		t.Fatalf("retail file not found correctly: %+v", files)
	}
	if f, ok := got[pathKey(b)]; !ok || f.Edition != "anniversary" {
		t.Fatalf("anniversary file not found: %+v", files)
	}
	// Pointing directly at an edition directory works too.
	files = Discover([]string{filepath.Join(root, "World of Warcraft", "_retail_")})
	found := false
	for _, f := range files {
		found = found || pathKey(f.Path) == pathKey(a)
	}
	if !found {
		t.Fatal("edition-dir root not detected")
	}
}

func TestSnapshotRejectsFreshAndChangingFiles(t *testing.T) {
	root := t.TempDir()
	p := makeInstall(t, root, "_retail_", "A", "WoWCensusDB = { x = 1 }")
	f := SavedVarFile{Path: p, Edition: "retail"}
	snap, err := TakeSnapshot(f, time.Now())
	if err != nil {
		t.Fatal(err)
	}
	if len(snap.SHA256) != 64 || string(snap.Data) != "WoWCensusDB = { x = 1 }" {
		t.Fatalf("bad snapshot %+v", snap)
	}
	// Just written => unstable.
	if err := os.WriteFile(p, []byte("WoWCensusDB = { x = 2 }"), 0o644); err != nil {
		t.Fatal(err)
	}
	if _, err := TakeSnapshot(f, time.Now()); !errors.Is(err, ErrUnstable) {
		t.Fatalf("want ErrUnstable, got %v", err)
	}
}

func TestBundleContainsManifestAndLua(t *testing.T) {
	snap := &Snapshot{File: SavedVarFile{Edition: "retail", Path: "/secret/path"}, Data: []byte("WoWCensusDB = {}"), SHA256: strings.Repeat("a", 64), TakenAt: time.Now()}
	m := NewManifest(snap, strings.Repeat("b", 64), false)
	b, err := BuildBundle(snap, m)
	if err != nil {
		t.Fatal(err)
	}
	zr, err := zip.NewReader(bytes.NewReader(b.Zip), int64(len(b.Zip)))
	if err != nil {
		t.Fatal(err)
	}
	names := map[string][]byte{}
	for _, f := range zr.File {
		rc, _ := f.Open()
		names[f.Name], _ = io.ReadAll(rc)
		rc.Close()
	}
	if string(names[SavedVariablesName]) != "WoWCensusDB = {}" {
		t.Fatal("lua missing from bundle")
	}
	if bytes.Contains(names["manifest.json"], []byte("secret")) {
		t.Fatal("manifest leaks the local path")
	}
	if bytes.Contains(names["manifest.json"], []byte(`"system"`)) {
		t.Fatal("system info included without opt-in")
	}
}

func TestStateSourceIDIsSaltedAndPersistent(t *testing.T) {
	dir := t.TempDir()
	s1, err := LoadState(dir)
	if err != nil {
		t.Fatal(err)
	}
	id := s1.SourceID("C:/WoW/x.lua")
	if err := s1.PutReceipt(Receipt{SourceID: id, SHA256: "h", Status: "queued", At: time.Now()}); err != nil {
		t.Fatal(err)
	}
	s2, _ := LoadState(dir)
	if s2.SourceID("C:/WoW/x.lua") != id {
		t.Fatal("salt not persisted")
	}
	if _, ok := s2.Receipt(id, "h"); !ok {
		t.Fatal("receipt not persisted")
	}
	other, _ := LoadState(t.TempDir())
	if other.SourceID("C:/WoW/x.lua") == id {
		t.Fatal("source ID is not salted per install")
	}
}

func TestBackupRotation(t *testing.T) {
	dir := t.TempDir()
	src := strings.Repeat("c", 64)
	base := time.Now().Add(-time.Hour)
	for i := 0; i < 7; i++ {
		data := []byte("WoWCensusDB = {" + strings.Repeat("x", i) + "}")
		snap := &Snapshot{File: SavedVarFile{Edition: "retail"}, Data: data, SHA256: strings.Repeat(string(rune('0'+i)), 64), TakenAt: base.Add(time.Duration(i) * time.Minute)}
		if _, err := WriteBackup(dir, snap, src); err != nil {
			t.Fatal(err)
		}
	}
	n, err := RotateBackups(dir, 3, 1<<30)
	if err != nil || n != 4 {
		t.Fatalf("removed %d, err %v", n, err)
	}
	left, _ := ListBackups(dir)
	if len(left) != 3 {
		t.Fatalf("want 3 left, got %d", len(left))
	}
	// A tiny cap keeps only the newest generation.
	if _, err := RotateBackups(dir, 3, 1); err != nil {
		t.Fatal(err)
	}
	left, _ = ListBackups(dir)
	if len(left) != 1 || !strings.Contains(left[0].Path, strings.Repeat("6", 12)) {
		t.Fatalf("cap rotation kept %+v", left)
	}
}

type fakeServer struct {
	prepares, puts, completes atomic.Int32
	token                     string
	failPut                   atomic.Bool
}

func (fs *fakeServer) handler(t *testing.T, srvURL func() string) http.Handler {
	mux := http.NewServeMux()
	auth := func(w http.ResponseWriter, r *http.Request) bool {
		if r.Header.Get("Authorization") != "Bearer "+fs.token {
			w.WriteHeader(401)
			_, _ = w.Write([]byte(`{"error":"bad token"}`))
			return false
		}
		return true
	}
	mux.HandleFunc("/api/v1/uploads/prepare", func(w http.ResponseWriter, r *http.Request) {
		if !auth(w, r) {
			return
		}
		fs.prepares.Add(1)
		var req prepareRequest
		_ = json.NewDecoder(r.Body).Decode(&req)
		if len(req.SourceID) != 64 || len(req.SHA256) != 64 || req.ZipBytes <= 0 {
			t.Errorf("bad prepare request %+v", req)
		}
		_ = json.NewEncoder(w).Encode(prepareResponse{Status: "upload", UploadID: "u1", UploadURL: srvURL() + "/put/u1",
			Headers: map[string]string{"Content-Type": "application/zip"}, ExpiresAt: time.Now().Add(2 * time.Minute)})
	})
	mux.HandleFunc("/put/u1", func(w http.ResponseWriter, r *http.Request) {
		fs.puts.Add(1)
		if fs.failPut.Load() {
			w.WriteHeader(500)
			return
		}
		body, _ := io.ReadAll(r.Body)
		if r.Method != http.MethodPut || r.Header.Get("Authorization") != "" || len(body) == 0 {
			t.Errorf("bad PUT: method %s, auth %q, %d bytes", r.Method, r.Header.Get("Authorization"), len(body))
		}
		w.WriteHeader(200)
	})
	mux.HandleFunc("/api/v1/uploads/complete", func(w http.ResponseWriter, r *http.Request) {
		if !auth(w, r) {
			return
		}
		fs.completes.Add(1)
		_ = json.NewEncoder(w).Encode(completeResponse{Status: "queued", ReceiptID: "r1", JobID: "1-0"})
	})
	return mux
}

func TestEngineUploadsOnceThenOnChange(t *testing.T) {
	root := t.TempDir()
	p := makeInstall(t, root, "_retail_", "A", "WoWCensusDB = { v = 1 }")
	fs := &fakeServer{token: "test-token"}
	var srv *httptest.Server
	srv = httptest.NewServer(fs.handler(t, func() string { return srv.URL }))
	defer srv.Close()

	appDir := t.TempDir()
	e, err := NewEngine(appDir)
	if err != nil {
		t.Fatal(err)
	}
	e.discover = func([]string) []SavedVarFile { return Discover([]string{root}) }
	cfg := e.Config()
	cfg.ServerURL, cfg.DeviceToken, cfg.AutoUpload, cfg.AutoBackup = srv.URL, "test-token", true, true
	if err := e.UpdateConfig(cfg); err != nil {
		t.Fatal(err)
	}
	ctx := context.Background()
	e.RunOnce(ctx, false, false)
	if fs.completes.Load() != 1 {
		t.Fatalf("want 1 upload, got %d (log %+v)", fs.completes.Load(), e.Status().Log)
	}
	st := e.Status()
	if len(st.Files) != 1 || !st.Files[0].Uploaded || st.Files[0].LastReceipt.Status != "queued" {
		t.Fatalf("status %+v", st.Files)
	}

	// Unchanged file: no new upload, even after a restart (receipts persist).
	e.RunOnce(ctx, false, false)
	e2, _ := NewEngine(appDir)
	e2.discover = e.discover
	e2.RunOnce(ctx, false, false)
	if fs.prepares.Load() != 1 {
		t.Fatalf("dedup failed: %d prepares", fs.prepares.Load())
	}

	// Changed file: uploaded again and backed up.
	writeOld(t, p, "WoWCensusDB = { v = 2 }")
	e2.RunOnce(ctx, false, false)
	if fs.completes.Load() != 2 {
		t.Fatalf("change not uploaded: %d", fs.completes.Load())
	}
	if bs, _ := ListBackups(e.Config().ResolvedBackupDir(appDir)); len(bs) != 2 {
		t.Fatalf("want 2 backups, got %d", len(bs))
	}
	rep := e2.Report()
	if rep.BackupCount != 2 || len(rep.Files) != 1 {
		t.Fatalf("report %+v", rep)
	}
}

func TestEngineRespectsConsentAndBacksOff(t *testing.T) {
	root := t.TempDir()
	makeInstall(t, root, "_classic_era_", "A", "WoWCensusDB = {}")
	fs := &fakeServer{token: "tok"}
	var srv *httptest.Server
	srv = httptest.NewServer(fs.handler(t, func() string { return srv.URL }))
	defer srv.Close()

	e, _ := NewEngine(t.TempDir())
	e.discover = func([]string) []SavedVarFile { return Discover([]string{root}) }
	cfg := e.Config()
	cfg.ServerURL, cfg.DeviceToken = srv.URL, "tok" // AutoUpload stays OFF
	if err := e.UpdateConfig(cfg); err != nil {
		t.Fatal(err)
	}
	e.RunOnce(context.Background(), false, false)
	if fs.prepares.Load() != 0 {
		t.Fatal("uploaded without consent")
	}
	// A failing PUT records backoff; the next automatic pass does not retry.
	fs.failPut.Store(true)
	cfg.AutoUpload = true
	_ = e.UpdateConfig(cfg)
	e.RunOnce(context.Background(), false, false)
	e.RunOnce(context.Background(), false, false)
	if fs.puts.Load() != 1 {
		t.Fatalf("want 1 PUT attempt during backoff, got %d", fs.puts.Load())
	}
	if f := e.Status().Files[0]; f.NextRetry == nil || f.LastError == "" {
		t.Fatalf("backoff not surfaced: %+v", f)
	}
	// Upload now ignores backoff.
	fs.failPut.Store(false)
	e.RunOnce(context.Background(), true, false)
	if fs.completes.Load() != 1 {
		t.Fatal("upload now did not upload")
	}
}

func TestConfigValidation(t *testing.T) {
	c := DefaultConfig()
	if c.AutoUpload {
		t.Fatal("uploads must default to off")
	}
	c.ServerURL = "http://example.com"
	if c.Validate() == nil {
		t.Fatal("plain http to a remote host must be rejected")
	}
	c.ServerURL = "http://localhost:3000"
	if c.Validate() != nil {
		t.Fatal("localhost http should be allowed")
	}
	c.AutoUpload = true
	if c.Validate() == nil {
		t.Fatal("auto upload without token must be rejected")
	}
	if got := (Config{DeviceToken: "abcdefgh"}).Redacted().DeviceToken; strings.Contains(got, "abcd") {
		t.Fatalf("token not redacted: %s", got)
	}
}
