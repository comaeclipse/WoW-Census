// Package companion holds the platform-independent core of the WoWCensus
// Companion: configuration, install detection, stable snapshots, bundles,
// the R2 upload client, backups, maintenance reports and the watcher loop.
//
// It deliberately uses only Go's standard library so it can be tested and
// run headless (see cmd/companion-cli) without Wails.
package companion

import (
	"encoding/json"
	"errors"
	"fmt"
	"net/url"
	"os"
	"path/filepath"
	"strings"
)

// AppVersion is reported in every manifest. Override at build time with
// -ldflags "-X wowcensuscompanion/companion.AppVersion=1.2.3".
var AppVersion = "0.1.0-dev"

const (
	appDirName          = "WoWCensusCompanion"
	defaultPollSeconds  = 15
	minPollSeconds      = 5
	maxPollSeconds      = 600
	defaultThresholdMB  = 50
	defaultBackupKeep   = 5
	defaultBackupCapMB  = 500
	SavedVariablesName  = "WoWCensus.lua"
	maxLuaBytes         = 256 << 20 // refuse to snapshot anything larger
	stableQuietDuration = 2         // seconds a file must be untouched before reading
)

// Config is persisted as JSON in the OS user config directory with 0600
// permissions where the platform supports it.
type Config struct {
	// StartAtLogin registers the app with the OS login items.
	StartAtLogin bool `json:"startAtLogin"`
	// AutoUpload is the user's explicit consent to upload. OFF by default.
	AutoUpload bool `json:"autoUpload"`
	// IncludeSystemInfo adds OS family, architecture, logical CPU count and
	// Go runtime version to the manifest. Nothing else is ever collected.
	IncludeSystemInfo bool `json:"includeSystemInfo"`
	// AutoBackup writes a compressed local backup whenever a new version of
	// WoWCensus.lua is seen.
	AutoBackup bool `json:"autoBackup"`

	// WoWRoots are user-selected World of Warcraft install directories.
	// Typical install paths are always probed as well.
	WoWRoots []string `json:"wowRoots"`

	ServerURL   string `json:"serverUrl"`
	DeviceToken string `json:"deviceToken"`

	SizeThresholdMB int    `json:"sizeThresholdMB"`
	PollSeconds     int    `json:"pollSeconds"`
	BackupDir       string `json:"backupDir"`
	BackupKeep      int    `json:"backupKeep"`
	BackupCapMB     int    `json:"backupCapMB"`
}

// DefaultConfig returns safe defaults: uploads off, no system info.
func DefaultConfig() Config {
	return Config{
		ServerURL:       "https://upload.wowcensus.io",
		SizeThresholdMB: defaultThresholdMB,
		PollSeconds:     defaultPollSeconds,
		BackupKeep:      defaultBackupKeep,
		BackupCapMB:     defaultBackupCapMB,
	}
}

// Normalize fills defaults and clamps values. It never enables uploads.
func (c *Config) Normalize() {
	if c.PollSeconds == 0 {
		c.PollSeconds = defaultPollSeconds
	}
	c.PollSeconds = min(max(c.PollSeconds, minPollSeconds), maxPollSeconds)
	if c.SizeThresholdMB <= 0 {
		c.SizeThresholdMB = defaultThresholdMB
	}
	if c.BackupKeep <= 0 {
		c.BackupKeep = defaultBackupKeep
	}
	if c.BackupCapMB <= 0 {
		c.BackupCapMB = defaultBackupCapMB
	}
	c.ServerURL = strings.TrimRight(strings.TrimSpace(c.ServerURL), "/")
	c.DeviceToken = strings.TrimSpace(c.DeviceToken)
	roots := c.WoWRoots[:0]
	seen := map[string]bool{}
	for _, r := range c.WoWRoots {
		r = strings.TrimSpace(r)
		if r == "" {
			continue
		}
		r = filepath.Clean(r)
		if !seen[pathKey(r)] {
			seen[pathKey(r)] = true
			roots = append(roots, r)
		}
	}
	c.WoWRoots = roots
}

// Validate reports problems that should block saving.
func (c Config) Validate() error {
	if c.ServerURL != "" {
		u, err := url.Parse(c.ServerURL)
		if err != nil || u.Host == "" {
			return fmt.Errorf("server URL is not a valid URL")
		}
		local := u.Hostname() == "localhost" || u.Hostname() == "127.0.0.1" || u.Hostname() == "::1"
		if u.Scheme != "https" && !(u.Scheme == "http" && local) {
			return fmt.Errorf("server URL must use https (http is only allowed for localhost)")
		}
	}
	if c.AutoUpload && (c.ServerURL == "" || c.DeviceToken == "") {
		return errors.New("automatic uploads need a server URL and a device token")
	}
	return nil
}

// ResolvedBackupDir returns the configured backup dir or the default one.
func (c Config) ResolvedBackupDir(appDir string) string {
	if strings.TrimSpace(c.BackupDir) != "" {
		return filepath.Clean(c.BackupDir)
	}
	return filepath.Join(appDir, "backups")
}

// DefaultAppDir is <UserConfigDir>/WoWCensusCompanion.
func DefaultAppDir() (string, error) {
	base, err := os.UserConfigDir()
	if err != nil {
		return "", err
	}
	return filepath.Join(base, appDirName), nil
}

// LoadConfig reads config.json from appDir, returning defaults if absent.
func LoadConfig(appDir string) (Config, error) {
	cfg := DefaultConfig()
	b, err := os.ReadFile(filepath.Join(appDir, "config.json"))
	if errors.Is(err, os.ErrNotExist) {
		return cfg, nil
	}
	if err != nil {
		return cfg, err
	}
	if err := json.Unmarshal(b, &cfg); err != nil {
		return DefaultConfig(), fmt.Errorf("config.json is unreadable: %w", err)
	}
	cfg.Normalize()
	return cfg, nil
}

// SaveConfig writes config.json atomically with mode 0600.
func SaveConfig(appDir string, cfg Config) error {
	cfg.Normalize()
	if err := cfg.Validate(); err != nil {
		return err
	}
	b, err := json.MarshalIndent(cfg, "", "  ")
	if err != nil {
		return err
	}
	return writeFileAtomic(filepath.Join(appDir, "config.json"), b, 0o600)
}

// Redacted returns a copy safe to show in logs or the UI status payload.
func (c Config) Redacted() Config {
	if c.DeviceToken != "" {
		c.DeviceToken = "••••" + lastN(c.DeviceToken, 4)
	}
	return c
}

func lastN(s string, n int) string {
	if len(s) <= n {
		return ""
	}
	return s[len(s)-n:]
}
