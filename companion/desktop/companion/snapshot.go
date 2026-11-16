package companion

import (
	"crypto/sha256"
	"encoding/hex"
	"errors"
	"fmt"
	"io"
	"os"
	"time"
)

// ErrUnstable means the file is being written (or was just written) and
// should be retried on the next reconciliation pass.
var ErrUnstable = errors.New("file is still changing")

// FileSig is a cheap change signature used between polls.
type FileSig struct {
	Size    int64     `json:"size"`
	ModTime time.Time `json:"modTime"`
}

func (s FileSig) Equal(o FileSig) bool { return s.Size == o.Size && s.ModTime.Equal(o.ModTime) }

// Snapshot is an in-memory, verified-stable copy of WoWCensus.lua.
type Snapshot struct {
	File    SavedVarFile
	Data    []byte
	SHA256  string
	Sig     FileSig
	TakenAt time.Time
}

func statSig(path string) (FileSig, error) {
	fi, err := os.Stat(path)
	if err != nil {
		return FileSig{}, err
	}
	if !fi.Mode().IsRegular() {
		return FileSig{}, fmt.Errorf("%s is not a regular file", path)
	}
	return FileSig{Size: fi.Size(), ModTime: fi.ModTime()}, nil
}

// TakeSnapshot reads the file only if it has been quiet for a short period
// and its size/mtime do not change across the read. WoW writes
// SavedVariables on /reload and logout; this avoids catching a half-written
// file. The original file is never modified.
func TakeSnapshot(f SavedVarFile, now time.Time) (*Snapshot, error) {
	before, err := statSig(f.Path)
	if err != nil {
		return nil, err
	}
	if before.Size == 0 {
		return nil, ErrUnstable
	}
	if before.Size > maxLuaBytes {
		return nil, fmt.Errorf("%s is %d MB, above the %d MB safety limit", f.Path, before.Size>>20, maxLuaBytes>>20)
	}
	if now.Sub(before.ModTime) < stableQuietDuration*time.Second {
		return nil, ErrUnstable
	}
	fh, err := os.Open(f.Path)
	if err != nil {
		return nil, err
	}
	defer fh.Close()
	data, err := io.ReadAll(io.LimitReader(fh, maxLuaBytes+1))
	if err != nil {
		return nil, err
	}
	after, err := statSig(f.Path)
	if err != nil {
		return nil, err
	}
	if !after.Equal(before) || int64(len(data)) != before.Size {
		return nil, ErrUnstable
	}
	sum := sha256.Sum256(data)
	return &Snapshot{File: f, Data: data, SHA256: hex.EncodeToString(sum[:]), Sig: after, TakenAt: now.UTC()}, nil
}
