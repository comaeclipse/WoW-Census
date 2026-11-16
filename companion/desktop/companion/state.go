package companion

import (
	"crypto/rand"
	"crypto/sha256"
	"encoding/hex"
	"encoding/json"
	"errors"
	"os"
	"path/filepath"
	"sync"
	"time"
)

// Receipt records a server acknowledgement. Status is "queued": the file is
// stored and an ingest job exists. It is NOT proof of database import and
// must never authorise pruning of the original data.
type Receipt struct {
	SourceID  string    `json:"sourceId"`
	SHA256    string    `json:"sha256"`
	Status    string    `json:"status"` // "queued" or "duplicate"
	ReceiptID string    `json:"receiptId,omitempty"`
	JobID     string    `json:"jobId,omitempty"`
	Bytes     int64     `json:"bytes"`
	At        time.Time `json:"at"`
}

// Failure tracks retry backoff for one (source, hash).
type Failure struct {
	Attempts int       `json:"attempts"`
	LastErr  string    `json:"lastError"`
	NextTry  time.Time `json:"nextTry"`
}

// State is local JSON state: the per-install salt, receipts (for dedup
// across restarts), last seen signatures and retry backoff.
type State struct {
	mu   sync.Mutex
	path string

	InstallSalt string              `json:"installSalt"`
	Receipts    map[string]Receipt  `json:"receipts"`
	Seen        map[string]seenFile `json:"seen"`
	Failures    map[string]Failure  `json:"failures"`
}

type seenFile struct {
	Sig    FileSig `json:"sig"`
	SHA256 string  `json:"sha256"`
}

const maxReceipts = 2000

func LoadState(appDir string) (*State, error) {
	s := &State{path: filepath.Join(appDir, "state.json")}
	b, err := os.ReadFile(s.path)
	switch {
	case errors.Is(err, os.ErrNotExist):
	case err != nil:
		return nil, err
	default:
		if err := json.Unmarshal(b, s); err != nil {
			// Keep the corrupt file for inspection and start fresh rather
			// than refusing to run. Receipts are an optimisation; the
			// server deduplicates too.
			_ = os.Rename(s.path, s.path+".corrupt-"+time.Now().Format("20060102-150405"))
			s = &State{path: s.path}
		}
	}
	if s.InstallSalt == "" {
		buf := make([]byte, 32)
		if _, err := rand.Read(buf); err != nil {
			return nil, err
		}
		s.InstallSalt = hex.EncodeToString(buf)
	}
	if s.Receipts == nil {
		s.Receipts = map[string]Receipt{}
	}
	if s.Seen == nil {
		s.Seen = map[string]seenFile{}
	}
	if s.Failures == nil {
		s.Failures = map[string]Failure{}
	}
	return s, s.Save()
}

// SourceID is a salted SHA-256 of the local path. The path itself is never
// transmitted, and the salt never leaves this machine.
func (s *State) SourceID(path string) string {
	sum := sha256.Sum256([]byte(s.InstallSalt + "\x00" + pathKey(path)))
	return hex.EncodeToString(sum[:])
}

func receiptKey(sourceID, sha string) string { return sourceID + ":" + sha }

func (s *State) Receipt(sourceID, sha string) (Receipt, bool) {
	s.mu.Lock()
	defer s.mu.Unlock()
	r, ok := s.Receipts[receiptKey(sourceID, sha)]
	return r, ok
}

func (s *State) LatestReceipt(sourceID string) (Receipt, bool) {
	s.mu.Lock()
	defer s.mu.Unlock()
	var best Receipt
	ok := false
	for _, r := range s.Receipts {
		if r.SourceID == sourceID && (!ok || r.At.After(best.At)) {
			best, ok = r, true
		}
	}
	return best, ok
}

func (s *State) PutReceipt(r Receipt) error {
	s.mu.Lock()
	s.Receipts[receiptKey(r.SourceID, r.SHA256)] = r
	delete(s.Failures, receiptKey(r.SourceID, r.SHA256))
	if len(s.Receipts) > maxReceipts {
		s.pruneReceiptsLocked()
	}
	s.mu.Unlock()
	return s.Save()
}

func (s *State) pruneReceiptsLocked() {
	var oldestK string
	var oldest time.Time
	for len(s.Receipts) > maxReceipts {
		oldestK = ""
		for k, r := range s.Receipts {
			if oldestK == "" || r.At.Before(oldest) {
				oldestK, oldest = k, r.At
			}
		}
		delete(s.Receipts, oldestK)
	}
}

// Backoff returns whether an upload of (sourceID, sha) may be attempted now.
func (s *State) Backoff(sourceID, sha string, now time.Time) (Failure, bool) {
	s.mu.Lock()
	defer s.mu.Unlock()
	f, ok := s.Failures[receiptKey(sourceID, sha)]
	return f, ok && now.Before(f.NextTry)
}

// RecordFailure applies exponential backoff: 30s, 1m, 2m ... capped at 1h.
func (s *State) RecordFailure(sourceID, sha string, err error, now time.Time) error {
	s.mu.Lock()
	k := receiptKey(sourceID, sha)
	f := s.Failures[k]
	f.Attempts++
	f.LastErr = err.Error()
	delay := min(30*time.Second<<min(f.Attempts-1, 7), time.Hour)
	var apiErr *APIError
	if errors.As(err, &apiErr) && apiErr.RetryAfter > delay {
		delay = min(apiErr.RetryAfter, 24*time.Hour)
	}
	f.NextTry = now.Add(delay)
	s.Failures[k] = f
	s.mu.Unlock()
	return s.Save()
}

// ClearFailures resets backoff, e.g. after the user changes the token.
func (s *State) ClearFailures() error {
	s.mu.Lock()
	s.Failures = map[string]Failure{}
	s.mu.Unlock()
	return s.Save()
}

func (s *State) SeenSig(path string) (seenFile, bool) {
	s.mu.Lock()
	defer s.mu.Unlock()
	v, ok := s.Seen[pathKey(path)]
	return v, ok
}

func (s *State) SetSeen(path string, sig FileSig, sha string) error {
	s.mu.Lock()
	s.Seen[pathKey(path)] = seenFile{Sig: sig, SHA256: sha}
	s.mu.Unlock()
	return s.Save()
}

func (s *State) Save() error {
	s.mu.Lock()
	b, err := json.MarshalIndent(s, "", "  ")
	s.mu.Unlock()
	if err != nil {
		return err
	}
	return writeFileAtomic(s.path, b, 0o600)
}
