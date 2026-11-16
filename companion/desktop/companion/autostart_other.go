//go:build !windows && !darwin

package companion

import (
	"fmt"
	"os"
	"path/filepath"
	"strings"
)

func autostartPath() (string, error) {
	base, err := os.UserConfigDir() // $XDG_CONFIG_HOME or ~/.config
	if err != nil {
		return "", err
	}
	return filepath.Join(base, "autostart", "wowcensus-companion.desktop"), nil
}

// SetStartAtLogin writes or removes an XDG autostart entry.
func SetStartAtLogin(enable bool) error {
	p, err := autostartPath()
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
	quoted := `"` + strings.NewReplacer(`\`, `\\`, `"`, `\"`, "`", "\\`", "$", `\$`).Replace(exe) + `"`
	entry := fmt.Sprintf("[Desktop Entry]\nType=Application\nName=WoWCensus Companion\nExec=%s --minimized\nX-GNOME-Autostart-enabled=true\n", quoted)
	return writeFileAtomic(p, []byte(entry), 0o644)
}

func StartAtLoginEnabled() bool {
	p, err := autostartPath()
	if err != nil {
		return false
	}
	_, err = os.Stat(p)
	return err == nil
}
