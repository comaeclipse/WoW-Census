package main

import (
	"context"

	"github.com/wailsapp/wails/v3/pkg/application"

	"wowcensuscompanion/companion"
)

// CompanionService is bound to the frontend. It never returns the device
// token: Settings shows a redacted hint and an empty field keeps the stored
// token.
type CompanionService struct {
	engine *companion.Engine
	cancel context.CancelFunc
}

// SettingsView is what the Settings form edits.
type SettingsView struct {
	companion.Config
	HasToken  bool   `json:"hasToken"`
	TokenHint string `json:"tokenHint"`
	AppDir    string `json:"appDir"`
}

func (s *CompanionService) ServiceStartup(ctx context.Context, _ application.ServiceOptions) error {
	s.engine.OnChange(func(st companion.Status) {
		if app := application.Get(); app != nil {
			app.Event.Emit("status", st)
		}
	})
	runCtx, cancel := context.WithCancel(context.Background())
	s.cancel = cancel
	go s.engine.Run(runCtx)
	return nil
}

func (s *CompanionService) ServiceShutdown() error {
	if s.cancel != nil {
		s.cancel()
	}
	return nil
}

func (s *CompanionService) GetSettings() SettingsView {
	cfg := s.engine.Config()
	hint := cfg.Redacted().DeviceToken
	view := SettingsView{Config: cfg, HasToken: cfg.DeviceToken != "", TokenHint: hint, AppDir: s.engine.AppDir()}
	view.DeviceToken = ""
	view.StartAtLogin = cfg.StartAtLogin && companion.StartAtLoginEnabled()
	return view
}

// SaveSettings persists settings. An empty token keeps the existing one;
// ClearToken removes it.
func (s *CompanionService) SaveSettings(cfg companion.Config, clearToken bool) (SettingsView, error) {
	if clearToken {
		err := s.engine.ClearToken()
		return s.GetSettings(), err
	}
	if err := s.engine.UpdateConfig(cfg); err != nil {
		return s.GetSettings(), err
	}
	return s.GetSettings(), nil
}

func (s *CompanionService) GetStatus() companion.Status { return s.engine.Status() }

func (s *CompanionService) DetectInstalls(extraRoots []string) []companion.SavedVarFile {
	return companion.DiscoverWithDefaults(extraRoots)
}

func (s *CompanionService) ScanNow()   { s.engine.ScanNow() }
func (s *CompanionService) UploadNow() { s.engine.UploadNow() }
func (s *CompanionService) BackupNow() { s.engine.BackupNow() }

func (s *CompanionService) MaintenanceReport() companion.MaintenanceReport { return s.engine.Report() }
