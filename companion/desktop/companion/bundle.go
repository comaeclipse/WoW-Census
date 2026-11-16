package companion

import (
	"archive/zip"
	"bytes"
	"crypto/sha256"
	"encoding/hex"
	"encoding/json"
	"runtime"
	"time"
)

// Manifest describes a bundle. It never contains the local path, the
// account folder name or anything not listed here.
type Manifest struct {
	SchemaVersion int         `json:"schemaVersion"`
	SourceID      string      `json:"sourceId"`
	Edition       string      `json:"edition"`
	FileName      string      `json:"fileName"`
	SHA256        string      `json:"sha256"`
	Bytes         int64       `json:"bytes"`
	FileModTime   time.Time   `json:"fileModTime"`
	CreatedAt     time.Time   `json:"createdAt"`
	AppVersion    string      `json:"appVersion"`
	System        *SystemInfo `json:"system,omitempty"`
}

// SystemInfo is only included when the user opts in.
type SystemInfo struct {
	OS        string `json:"os"`
	Arch      string `json:"arch"`
	NumCPU    int    `json:"numCpu"`
	GoVersion string `json:"goVersion"`
}

// Bundle is a ready-to-upload ZIP.
type Bundle struct {
	Manifest Manifest
	Zip      []byte
	ZipSHA   string
}

func NewManifest(snap *Snapshot, sourceID string, includeSystem bool) Manifest {
	m := Manifest{
		SchemaVersion: 1,
		SourceID:      sourceID,
		Edition:       snap.File.Edition,
		FileName:      SavedVariablesName,
		SHA256:        snap.SHA256,
		Bytes:         int64(len(snap.Data)),
		FileModTime:   snap.Sig.ModTime.UTC(),
		CreatedAt:     snap.TakenAt.UTC(),
		AppVersion:    AppVersion,
	}
	if includeSystem {
		m.System = &SystemInfo{OS: runtime.GOOS, Arch: runtime.GOARCH, NumCPU: runtime.NumCPU(), GoVersion: runtime.Version()}
	}
	return m
}

// BuildBundle zips manifest.json and the snapshot bytes.
func BuildBundle(snap *Snapshot, m Manifest) (*Bundle, error) {
	var buf bytes.Buffer
	zw := zip.NewWriter(&buf)
	mj, err := json.MarshalIndent(m, "", "  ")
	if err != nil {
		return nil, err
	}
	stamp := m.CreatedAt
	for _, f := range []struct {
		name string
		data []byte
	}{{"manifest.json", mj}, {SavedVariablesName, snap.Data}} {
		w, err := zw.CreateHeader(&zip.FileHeader{Name: f.name, Method: zip.Deflate, Modified: stamp})
		if err != nil {
			return nil, err
		}
		if _, err := w.Write(f.data); err != nil {
			return nil, err
		}
	}
	if err := zw.Close(); err != nil {
		return nil, err
	}
	sum := sha256.Sum256(buf.Bytes())
	return &Bundle{Manifest: m, Zip: buf.Bytes(), ZipSHA: hex.EncodeToString(sum[:])}, nil
}
