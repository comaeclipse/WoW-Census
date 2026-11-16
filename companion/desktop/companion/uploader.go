package companion

import (
	"bytes"
	"context"
	"encoding/json"
	"errors"
	"fmt"
	"io"
	"net/http"
	"net/url"
	"strings"
	"time"
)

// Client talks to the Next.js upload API. File bodies go straight to R2 via
// a short-lived presigned PUT; Vercel only ever sees small JSON requests.
type Client struct {
	ServerURL   string
	DeviceToken string
	HTTP        *http.Client // JSON calls
	PutHTTP     *http.Client // the R2 PUT (longer timeout)
}

func NewClient(serverURL, token string) *Client {
	return &Client{
		ServerURL:   strings.TrimRight(serverURL, "/"),
		DeviceToken: token,
		HTTP:        &http.Client{Timeout: 30 * time.Second},
		PutHTTP:     &http.Client{Timeout: 10 * time.Minute},
	}
}

type prepareRequest struct {
	SourceID   string `json:"sourceId"`
	SHA256     string `json:"sha256"`
	LuaBytes   int64  `json:"luaBytes"`
	ZipBytes   int64  `json:"zipBytes"`
	ZipSHA256  string `json:"zipSha256"`
	Edition    string `json:"edition"`
	AppVersion string `json:"appVersion"`
}

type prepareResponse struct {
	Status    string            `json:"status"` // "upload" | "duplicate"
	UploadID  string            `json:"uploadId"`
	UploadURL string            `json:"uploadUrl"`
	Headers   map[string]string `json:"headers"`
	ExpiresAt time.Time         `json:"expiresAt"`
	ReceiptID string            `json:"receiptId"`
}

type completeResponse struct {
	Status    string `json:"status"` // "queued"
	ReceiptID string `json:"receiptId"`
	JobID     string `json:"jobId"`
}

// APIError carries the HTTP status so callers can tell auth problems
// (don't retry until config changes) from transient ones.
type APIError struct {
	Status     int
	Message    string
	RetryAfter time.Duration
}

func (e *APIError) Error() string {
	return fmt.Sprintf("server returned %d: %s", e.Status, e.Message)
}

// Upload runs prepare -> PUT -> complete and returns a queued receipt.
func (c *Client) Upload(ctx context.Context, b *Bundle) (Receipt, error) {
	if c.ServerURL == "" || c.DeviceToken == "" {
		return Receipt{}, errors.New("server URL and device token are required")
	}
	m := b.Manifest
	var prep prepareResponse
	err := c.postJSON(ctx, "/api/v1/uploads/prepare", prepareRequest{
		SourceID: m.SourceID, SHA256: m.SHA256, LuaBytes: m.Bytes,
		ZipBytes: int64(len(b.Zip)), ZipSHA256: b.ZipSHA, Edition: m.Edition, AppVersion: m.AppVersion,
	}, &prep)
	if err != nil {
		return Receipt{}, fmt.Errorf("prepare: %w", err)
	}
	base := Receipt{SourceID: m.SourceID, SHA256: m.SHA256, Bytes: int64(len(b.Zip)), At: time.Now().UTC()}
	if prep.Status == "duplicate" {
		base.Status, base.ReceiptID = "duplicate", prep.ReceiptID
		return base, nil
	}
	if prep.Status != "upload" || prep.UploadID == "" || prep.UploadURL == "" {
		return Receipt{}, fmt.Errorf("prepare: unexpected response status %q", prep.Status)
	}
	if err := c.put(ctx, prep, b.Zip); err != nil {
		return Receipt{}, fmt.Errorf("upload: %w", err)
	}
	var done completeResponse
	if err := c.postJSON(ctx, "/api/v1/uploads/complete", map[string]string{"uploadId": prep.UploadID}, &done); err != nil {
		return Receipt{}, fmt.Errorf("complete: %w", err)
	}
	if done.Status != "queued" && done.Status != "duplicate" {
		return Receipt{}, fmt.Errorf("complete: unexpected status %q", done.Status)
	}
	base.Status, base.ReceiptID, base.JobID = done.Status, done.ReceiptID, done.JobID
	return base, nil
}

func (c *Client) put(ctx context.Context, prep prepareResponse, body []byte) error {
	u, err := url.Parse(prep.UploadURL)
	if err != nil || (u.Scheme != "https" && !isLocalHost(u.Hostname())) {
		return errors.New("refusing non-https upload URL")
	}
	req, err := http.NewRequestWithContext(ctx, http.MethodPut, prep.UploadURL, bytes.NewReader(body))
	if err != nil {
		return err
	}
	req.ContentLength = int64(len(body))
	for k, v := range prep.Headers {
		req.Header.Set(k, v)
	}
	if req.Header.Get("Content-Type") == "" {
		req.Header.Set("Content-Type", "application/zip")
	}
	resp, err := c.PutHTTP.Do(req)
	if err != nil {
		return err
	}
	defer resp.Body.Close()
	if resp.StatusCode/100 != 2 {
		msg, _ := io.ReadAll(io.LimitReader(resp.Body, 2048))
		return &APIError{Status: resp.StatusCode, Message: strings.TrimSpace(string(msg))}
	}
	_, _ = io.Copy(io.Discard, resp.Body)
	return nil
}

func (c *Client) postJSON(ctx context.Context, path string, in, out any) error {
	body, err := json.Marshal(in)
	if err != nil {
		return err
	}
	req, err := http.NewRequestWithContext(ctx, http.MethodPost, c.ServerURL+path, bytes.NewReader(body))
	if err != nil {
		return err
	}
	req.Header.Set("Content-Type", "application/json")
	req.Header.Set("Authorization", "Bearer "+c.DeviceToken)
	req.Header.Set("User-Agent", "WoWCensusCompanion/"+AppVersion)
	resp, err := c.HTTP.Do(req)
	if err != nil {
		return err
	}
	defer resp.Body.Close()
	data, err := io.ReadAll(io.LimitReader(resp.Body, 64<<10))
	if err != nil {
		return err
	}
	if resp.StatusCode/100 != 2 {
		var e struct {
			Error string `json:"error"`
		}
		_ = json.Unmarshal(data, &e)
		if e.Error == "" {
			e.Error = http.StatusText(resp.StatusCode)
		}
		apiErr := &APIError{Status: resp.StatusCode, Message: e.Error}
		if ra := resp.Header.Get("Retry-After"); ra != "" {
			if secs, err := time.ParseDuration(ra + "s"); err == nil {
				apiErr.RetryAfter = secs
			}
		}
		return apiErr
	}
	return json.Unmarshal(data, out)
}

func isLocalHost(h string) bool { return h == "localhost" || h == "127.0.0.1" || h == "::1" }
