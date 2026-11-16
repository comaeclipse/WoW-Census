//go:build windows

package companion

import (
	"fmt"
	"os"
	"os/exec"
	"syscall"
)

const runKey = `HKCU\Software\Microsoft\Windows\CurrentVersion\Run`
const runValue = "WoWCensusCompanion"

// SetStartAtLogin adds or removes a per-user Run entry (no admin rights).
func SetStartAtLogin(enable bool) error {
	var args []string
	if enable {
		exe, err := os.Executable()
		if err != nil {
			return err
		}
		args = []string{"add", runKey, "/v", runValue, "/t", "REG_SZ", "/d", fmt.Sprintf(`"%s" --minimized`, exe), "/f"}
	} else {
		if !StartAtLoginEnabled() {
			return nil
		}
		args = []string{"delete", runKey, "/v", runValue, "/f"}
	}
	cmd := exec.Command("reg.exe", args...)
	cmd.SysProcAttr = &syscall.SysProcAttr{HideWindow: true, CreationFlags: 0x08000000} // CREATE_NO_WINDOW
	if out, err := cmd.CombinedOutput(); err != nil {
		return fmt.Errorf("%v: %s", err, out)
	}
	return nil
}

func StartAtLoginEnabled() bool {
	cmd := exec.Command("reg.exe", "query", runKey, "/v", runValue)
	cmd.SysProcAttr = &syscall.SysProcAttr{HideWindow: true, CreationFlags: 0x08000000}
	return cmd.Run() == nil
}
