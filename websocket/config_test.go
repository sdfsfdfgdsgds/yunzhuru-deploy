package main

import (
	"os"
	"path/filepath"
	"testing"
)

// unsetWebSocketDBEnvironmentForTest 为每个配置测试清理 DB_*，避免宿主环境影响结果。
func unsetWebSocketDBEnvironmentForTest(t *testing.T) {
	t.Helper()
	for _, name := range dbEnvironmentKeys {
		t.Setenv(name, "")
		if err := os.Unsetenv(name); err != nil {
			t.Fatalf("清理环境变量 %s 失败: %v", name, err)
		}
	}
}

func TestWebSocketConfigEnvironmentTakesPriority(t *testing.T) {
	unsetWebSocketDBEnvironmentForTest(t)
	t.Setenv("DB_HOST", "env-db.example")
	t.Setenv("DB_PORT", "33061")
	t.Setenv("DB_NAME", "env_database")
	t.Setenv("DB_USER", "env_user")
	t.Setenv("DB_PASS", "env_password")
	t.Setenv("DB_CHARSET", "utf8mb4")

	cfg, err := getConfigFromFile()
	if err != nil {
		t.Fatalf("读取环境变量配置失败: %v", err)
	}
	want := &DBConfig{
		Host:     "env-db.example",
		Port:     "33061",
		DBName:   "env_database",
		Username: "env_user",
		Password: "env_password",
		Charset:  "utf8mb4",
	}
	if *cfg != *want {
		t.Fatalf("环境变量配置不符合预期: got=%+v want=%+v", *cfg, *want)
	}
}

func TestWebSocketConfigFallsBackToLiteralFile(t *testing.T) {
	unsetWebSocketDBEnvironmentForTest(t)
	root := t.TempDir()
	configDir := filepath.Join(root, "config")
	websocketDir := filepath.Join(root, "websocket")
	if err := os.MkdirAll(configDir, 0o755); err != nil {
		t.Fatalf("创建配置目录失败: %v", err)
	}
	if err := os.MkdirAll(websocketDir, 0o755); err != nil {
		t.Fatalf("创建工作目录失败: %v", err)
	}
	configContent := `<?php
return [
    'host' => 'legacy-db.example',
    'port' => 33061,
    'dbname' => 'legacy_database',
    'username' => 'legacy_user',
    'password' => 'legacy_password',
    'charset' => 'utf8mb4',
];
`
	if err := os.WriteFile(filepath.Join(configDir, "config.php"), []byte(configContent), 0o600); err != nil {
		t.Fatalf("写入测试配置失败: %v", err)
	}

	oldWorkingDir, err := os.Getwd()
	if err != nil {
		t.Fatalf("获取当前工作目录失败: %v", err)
	}
	if err := os.Chdir(websocketDir); err != nil {
		t.Fatalf("切换测试工作目录失败: %v", err)
	}
	t.Cleanup(func() { _ = os.Chdir(oldWorkingDir) })

	cfg, err := getConfigFromFile()
	if err != nil {
		t.Fatalf("读取旧版字面量配置失败: %v", err)
	}
	if cfg.Host != "legacy-db.example" || cfg.Port != "33061" || cfg.DBName != "legacy_database" || cfg.Username != "legacy_user" || cfg.Password != "legacy_password" || cfg.Charset != "utf8mb4" {
		t.Fatalf("旧版配置解析结果不符合预期: %+v", *cfg)
	}
}

func TestWebSocketConfigFindsConfigFromProjectRoot(t *testing.T) {
	unsetWebSocketDBEnvironmentForTest(t)
	root := t.TempDir()
	configDir := filepath.Join(root, "config")
	if err := os.MkdirAll(configDir, 0o755); err != nil {
		t.Fatalf("创建配置目录失败: %v", err)
	}
	configContent := `<?php
return [
    'host' => 'root-db.example',
    'port' => 3306,
    'dbname' => 'root_database',
    'username' => 'root_user',
    'password' => 'root_password',
    'charset' => 'utf8mb4',
];
`
	if err := os.WriteFile(filepath.Join(configDir, "config.php"), []byte(configContent), 0o600); err != nil {
		t.Fatalf("写入测试配置失败: %v", err)
	}

	oldWorkingDir, err := os.Getwd()
	if err != nil {
		t.Fatalf("获取当前工作目录失败: %v", err)
	}
	if err := os.Chdir(root); err != nil {
		t.Fatalf("切换项目根目录失败: %v", err)
	}
	t.Cleanup(func() { _ = os.Chdir(oldWorkingDir) })

	cfg, err := getConfigFromFile()
	if err != nil {
		t.Fatalf("从项目根目录读取配置失败: %v", err)
	}
	if cfg.Host != "root-db.example" || cfg.DBName != "root_database" || cfg.Username != "root_user" {
		t.Fatalf("项目根目录配置解析结果不符合预期: %+v", *cfg)
	}
}

func TestWebSocketConfigMergesEnvironmentWithLiteralFallback(t *testing.T) {
	unsetWebSocketDBEnvironmentForTest(t)
	root := t.TempDir()
	configDir := filepath.Join(root, "config")
	websocketDir := filepath.Join(root, "websocket")
	if err := os.MkdirAll(configDir, 0o755); err != nil {
		t.Fatalf("创建配置目录失败: %v", err)
	}
	if err := os.MkdirAll(websocketDir, 0o755); err != nil {
		t.Fatalf("创建工作目录失败: %v", err)
	}
	configContent := `<?php
return [
    'host' => 'legacy-db.example',
    'port' => 3306,
    'dbname' => 'legacy_database',
    'username' => 'legacy_user',
    'password' => 'legacy_password',
    'charset' => 'latin1',
];
`
	if err := os.WriteFile(filepath.Join(configDir, "config.php"), []byte(configContent), 0o600); err != nil {
		t.Fatalf("写入测试配置失败: %v", err)
	}
	t.Setenv("DB_HOST", "env-db.example")
	t.Setenv("DB_PORT", "33062")

	oldWorkingDir, err := os.Getwd()
	if err != nil {
		t.Fatalf("获取当前工作目录失败: %v", err)
	}
	if err := os.Chdir(websocketDir); err != nil {
		t.Fatalf("切换测试工作目录失败: %v", err)
	}
	t.Cleanup(func() { _ = os.Chdir(oldWorkingDir) })

	cfg, err := getConfigFromFile()
	if err != nil {
		t.Fatalf("合并环境变量与旧版配置失败: %v", err)
	}
	if cfg.Host != "env-db.example" || cfg.Port != "33062" || cfg.DBName != "legacy_database" || cfg.Username != "legacy_user" || cfg.Password != "legacy_password" || cfg.Charset != "latin1" {
		t.Fatalf("环境变量优先级结果不符合预期: %+v", *cfg)
	}
}

func TestWebSocketDSNUsesConfiguredPort(t *testing.T) {
	cfg := &DBConfig{
		Host:     "db.example",
		Port:     "33061",
		DBName:   "yunzhuru",
		Username: "user",
		Password: "pass",
		Charset:  "utf8mb4",
	}
	want := "user:pass@tcp(db.example:33061)/yunzhuru?charset=utf8mb4&parseTime=true"
	if got := buildMySQLDSN(cfg); got != want {
		t.Fatalf("DSN 端口未使用 DB_PORT: got=%q want=%q", got, want)
	}
}
