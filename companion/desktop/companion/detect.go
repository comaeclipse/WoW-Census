package companion

import (
	"os"
	"path/filepath"
	"regexp"
	"runtime"
	"sort"
	"strings"
)

// SavedVarFile is one WoWCensus.lua found under a WoW install.
type SavedVarFile struct {
	Path    string `json:"path"`
	Root    string `json:"root"`    // the edition directory, e.g. ...\_retail_
	Edition string `json:"edition"` // "retail", "classic_era", "anniversary", ...
	Account string `json:"account"` // WTF account folder name (shown locally only)
}

// Search bounds keep detection cheap even if a user points at a whole drive.
const (
	maxSearchDepth   = 4
	maxDirsPerRoot   = 4000
	maxEntriesPerDir = 2000
)

var editionDirRe = regexp.MustCompile(`^_[A-Za-z0-9]+(?:_[A-Za-z0-9]+)*_$`)

// TypicalInstallRoots lists the usual World of Warcraft locations per OS.
func TypicalInstallRoots() []string {
	var roots []string
	home, _ := os.UserHomeDir()
	switch runtime.GOOS {
	case "windows":
		for _, pf := range []string{os.Getenv("ProgramFiles(x86)"), os.Getenv("ProgramFiles")} {
			if pf != "" {
				roots = append(roots, filepath.Join(pf, "World of Warcraft"))
			}
		}
		for d := 'C'; d <= 'Z'; d++ {
			drive := string(d) + `:\`
			if _, err := os.Stat(drive); err != nil {
				continue
			}
			for _, sub := range []string{"World of Warcraft", `Games\World of Warcraft`, `Blizzard\World of Warcraft`,
				`Program Files (x86)\World of Warcraft`, `Program Files\World of Warcraft`, `Battle.net\World of Warcraft`} {
				roots = append(roots, filepath.Join(drive, sub))
			}
		}
	case "darwin":
		roots = append(roots, "/Applications/World of Warcraft")
		if home != "" {
			roots = append(roots, filepath.Join(home, "Applications", "World of Warcraft"))
		}
	default:
		if home != "" {
			roots = append(roots,
				filepath.Join(home, "Games", "world-of-warcraft", "drive_c", "Program Files (x86)", "World of Warcraft"),
				filepath.Join(home, ".wine", "drive_c", "Program Files (x86)", "World of Warcraft"),
				filepath.Join(home, ".local", "share", "lutris", "prefixes"),
				filepath.Join(home, "Games"),
			)
		}
	}
	return roots
}

// DiscoverWithDefaults scans the user's roots plus typical install paths.
func DiscoverWithDefaults(userRoots []string) []SavedVarFile {
	return Discover(append(append([]string{}, userRoots...), TypicalInstallRoots()...))
}

// Discover finds every WoWCensus.lua under the given roots. Each root may be
// a WoW install ("World of Warcraft"), an edition directory ("_retail_"), or
// a parent folder; the search is bounded by depth and directory count.
func Discover(all []string) []SavedVarFile {
	found := map[string]SavedVarFile{}
	visitedEditions := map[string]bool{}
	for _, root := range all {
		if fi, err := os.Stat(root); err != nil || !fi.IsDir() {
			continue
		}
		for _, ed := range findEditionDirs(root) {
			k := pathKey(ed)
			if visitedEditions[k] {
				continue
			}
			visitedEditions[k] = true
			for _, f := range savedVarsInEdition(ed) {
				found[pathKey(f.Path)] = f
			}
		}
	}
	out := make([]SavedVarFile, 0, len(found))
	for _, f := range found {
		out = append(out, f)
	}
	sort.Slice(out, func(i, j int) bool { return out[i].Path < out[j].Path })
	return out
}

// findEditionDirs returns directories that contain WTF/Account. It walks at
// most maxSearchDepth levels and maxDirsPerRoot directories, skipping
// symlinks so loops are impossible.
func findEditionDirs(root string) []string {
	type item struct {
		path  string
		depth int
	}
	var out []string
	queue := []item{{root, 0}}
	visited := 0
	for len(queue) > 0 && visited < maxDirsPerRoot {
		cur := queue[0]
		queue = queue[1:]
		visited++
		if isDir(filepath.Join(cur.path, "WTF", "Account")) {
			out = append(out, cur.path)
			continue // an edition dir never nests another edition
		}
		if cur.depth >= maxSearchDepth {
			continue
		}
		entries, err := os.ReadDir(cur.path)
		if err != nil {
			continue
		}
		if len(entries) > maxEntriesPerDir {
			entries = entries[:maxEntriesPerDir]
		}
		// Prefer obvious candidates first so the bound rarely matters.
		sort.SliceStable(entries, func(i, j int) bool {
			return score(entries[i].Name()) > score(entries[j].Name())
		})
		for _, e := range entries {
			if !e.IsDir() || e.Type()&os.ModeSymlink != 0 {
				continue
			}
			name := e.Name()
			if skipDir(name) {
				continue
			}
			queue = append(queue, item{filepath.Join(cur.path, name), cur.depth + 1})
		}
	}
	return out
}

func score(name string) int {
	switch {
	case editionDirRe.MatchString(name):
		return 3
	case strings.EqualFold(name, "World of Warcraft"):
		return 2
	case strings.Contains(strings.ToLower(name), "warcraft"), strings.Contains(strings.ToLower(name), "wow"):
		return 1
	}
	return 0
}

func skipDir(name string) bool {
	lower := strings.ToLower(name)
	switch lower {
	case "windows", "$recycle.bin", "system volume information", "node_modules", ".git",
		"interface", "cache", "logs", "errors", "data", "screenshots", "fonts", "utils":
		return true
	}
	return strings.HasPrefix(name, ".") && lower != ".wine"
}

func savedVarsInEdition(edDir string) []SavedVarFile {
	accountsDir := filepath.Join(edDir, "WTF", "Account")
	entries, err := os.ReadDir(accountsDir)
	if err != nil {
		return nil
	}
	edition := editionName(edDir)
	var out []SavedVarFile
	for _, e := range entries {
		if !e.IsDir() || strings.EqualFold(e.Name(), "SavedVariables") {
			continue
		}
		p := filepath.Join(accountsDir, e.Name(), "SavedVariables", SavedVariablesName)
		if fi, err := os.Stat(p); err == nil && fi.Mode().IsRegular() {
			out = append(out, SavedVarFile{Path: p, Root: edDir, Edition: edition, Account: e.Name()})
		}
	}
	return out
}

func editionName(edDir string) string {
	base := filepath.Base(edDir)
	if editionDirRe.MatchString(base) {
		return strings.ToLower(strings.Trim(base, "_"))
	}
	return "unknown"
}

func isDir(p string) bool {
	fi, err := os.Stat(p)
	return err == nil && fi.IsDir()
}
