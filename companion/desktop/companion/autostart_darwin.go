//go:build darwin

package companion

import (
	"encoding/xml"
	"fmt"
	"os"
	"path/filepath"
	"strings"
)

const launchAgentLabel = "io.wowcensus.companion"

func launchAgentPath() (string, error) {
	home, err := os.UserHomeDir()
	if err != nil {
		return "", err
	}
	return filepath.Join(home, "Library", "LaunchAgents", launchAgentLabel+".plist"), nil
}

// SetStartAtLogin writes or removes a per-user LaunchAgent.
func SetStartAtLogin(enable bool) error {
	p, err := launchAgentPath()
	if err != nil {
		return err
	}
	if !enable {
		if err := os.Remove(p); err != nil && !os.IsNotExist(err) {
			return err
		}
		return nil
	}
	exe, err := os.Executable()
	if err != nil {
		return err
	}
	var esc strings.Builder
	_ = xml.EscapeText(&esc, []byte(exe))
	plist := fmt.Sprintf(`<?xml version="1.0" encoding="UTF-8"?>
<!DOCTYPE plist PUBLIC "-//Apple//DTD PLIST 1.0//EN" "http://www.apple.com/DTDs/PropertyList-1.0.dtd">
<plist version="1.0"><dict>
  <key>Label</key><string>%s</string>
  <key>ProgramArguments</key><array><string>%s</string><string>--minimized</string></array>
  <key>RunAtLoad</key><true/>
</dict></plist>
`, launchAgentLabel, esc.String())
	if err := os.MkdirAll(filepath.Dir(p), 0o755); err != nil {
		return err
	}
	return writeFileAtomic(p, []byte(plist), 0o644)
}

func StartAtLoginEnabled() bool {
	p, err := launchAgentPath()
	if err != nil {
		return false
	}
	_, err = os.Stat(p)
	return err == nil
}
