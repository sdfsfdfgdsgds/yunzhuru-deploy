package main

import (
	"crypto/aes"
	"crypto/cipher"
	"crypto/md5"
	"crypto/rand"
	"database/sql"
	"encoding/base64"
	"encoding/hex"
	"encoding/json"
	"fmt"
	"io"
	"io/ioutil"
	"log"
	"net"
	"net/http"
	"os"
	"os/signal"
	"regexp"
	"strings"
	"sync"
	"syscall"
	"time"

	_ "github.com/go-sql-driver/mysql"
	"github.com/gorilla/websocket"
)

// ======================== 配置与全局变量 ========================

// 数据库配置结构体
type DBConfig struct {
	Host     string
	DBName   string
	Username string
	Password string
	Charset  string
}

// AES密钥
const aesKey = "1234567890abcdef"

// 运行目录下的日志与PID文件名
const (
	LogFile = ".ws.log"
	PidFile = ".ws.pid"
)

// 客户端连接信息
type ClientInfo struct {
	Conn      *websocket.Conn
	AppID     string
	DeviceID  string
	IsAdmin   bool
	AdminInfo *AdminInfo
	IP        string
}

// WebSocket 升级器
var upgrader = websocket.Upgrader{
	CheckOrigin: func(r *http.Request) bool { return true },
}

// 连接管理
var (
	//clients   = make(map[string]map[*websocket.Conn]*ClientInfo)
	clients   = make(map[string]map[string]*ClientInfo)
	clientsMu sync.Mutex
	db        *sql.DB
)

// ======================== 启动/关闭 辅助函数 ========================

// 初始化日志：清空/创建 ws.log，并配置同时输出到文件与控制台（C1方案）
func initLogger() (*os.File, error) {
	// 以截断模式打开或创建日志文件
	lf, err := os.OpenFile(LogFile, os.O_CREATE|os.O_WRONLY|os.O_TRUNC, 0644)
	if err != nil {
		return nil, err
	}
	// 同时输出到文件与控制台
	mw := io.MultiWriter(os.Stdout, lf)
	log.SetOutput(mw)
	// 展示日期、时间、短文件名
	log.SetFlags(log.LstdFlags | log.Lshortfile)
	return lf, nil
}

// 写入当前进程PID到 ws.pid（启动时调用）
func writePID() error {
	pid := os.Getpid()
	content := []byte(fmt.Sprintf("%d\n", pid))
	return os.WriteFile(PidFile, content, 0644)
}

// 清空 ws.pid 内容（关闭时调用，不删除文件）
func clearPID() {
	_ = os.WriteFile(PidFile, []byte(""), 0644)
}

// 启动或关闭时清空在线表
func clearOnlineTable() {
	if db == nil {
		return
	}
	_, err := db.Exec("TRUNCATE TABLE cainiao_ws")
	if err != nil {
		log.Println("清空在线表失败:", err)
	} else {
		log.Println("已清空在线表 cainiao_ws")
	}
}

// ======================== 配置与数据库 ========================

// 从 PHP 配置文件读取 MySQL 配置
func getConfigFromFile() (*DBConfig, error) {
	exePath, err := os.Getwd()
	if err != nil {
		return nil, err
	}
	configFilePath := exePath + "/../config/config.php"

	fileContent, err := ioutil.ReadFile(configFilePath)
	if err != nil {
		return nil, err
	}

	cfg := &DBConfig{}
	re := regexp.MustCompile(`'host'\s*=>\s*'([^']+)'`)
	if m := re.FindStringSubmatch(string(fileContent)); len(m) > 1 {
		cfg.Host = m[1]
	}
	re = regexp.MustCompile(`'dbname'\s*=>\s*'([^']+)'`)
	if m := re.FindStringSubmatch(string(fileContent)); len(m) > 1 {
		cfg.DBName = m[1]
	}
	re = regexp.MustCompile(`'username'\s*=>\s*'([^']+)'`)
	if m := re.FindStringSubmatch(string(fileContent)); len(m) > 1 {
		cfg.Username = m[1]
	}
	re = regexp.MustCompile(`'password'\s*=>\s*'([^']+)'`)
	if m := re.FindStringSubmatch(string(fileContent)); len(m) > 1 {
		cfg.Password = m[1]
	}
	re = regexp.MustCompile(`'charset'\s*=>\s*'([^']+)'`)
	if m := re.FindStringSubmatch(string(fileContent)); len(m) > 1 {
		cfg.Charset = m[1]
	}

	return cfg, nil
}

// 建立 MySQL 连接
func connectToDatabase(cfg *DBConfig) (*sql.DB, error) {
	dsn := fmt.Sprintf("%s:%s@tcp(%s:3306)/%s?charset=%s&parseTime=true",
		cfg.Username, cfg.Password, cfg.Host, cfg.DBName, cfg.Charset)
	db, err := sql.Open("mysql", dsn)
	if err != nil {
		return nil, err
	}
	if err := db.Ping(); err != nil {
		return nil, err
	}
	return db, nil
}

// ======================== 权限与校验 ========================

// 管理员信息
type AdminInfo struct {
	UserID        int
	Role          string
	VipExpireTime sql.NullTime
}

// admin_token 校验
func validateAdminToken(token string) (*AdminInfo, bool) {
	if db == nil {
		log.Println("数据库连接未初始化")
		return nil, false
	}
	if token == "" {
		return nil, false
	}

	var ai AdminInfo
	q := "SELECT id, role, vip_expire_time FROM cainiao_user WHERE apptoken = ?"
	err := db.QueryRow(q, token).Scan(&ai.UserID, &ai.Role, &ai.VipExpireTime)
	if err != nil {
		if err == sql.ErrNoRows {
			return nil, false
		}
		log.Println("数据库查询错误:", err)
		return nil, false
	}
	return &ai, true
}

// 检查系统是否要求VIP才能推送
func isPushVipOnly() bool {
	if db == nil {
		return false
	}
	var keyValue string
	err := db.QueryRow("SELECT key_value FROM cainiao_system_setting WHERE key_name = 'push'").Scan(&keyValue)
	if err != nil {
		if err != sql.ErrNoRows {
			log.Println("查询系统配置错误:", err)
		}
		return false
	}
	return keyValue == "1"
}

// 检查用户是否VIP
func isVipUser(ai *AdminInfo) bool {
	if !ai.VipExpireTime.Valid {
		return false
	}
	return ai.VipExpireTime.Time.After(time.Now())
}

// 检查推送权限
func validatePushPermission(ai *AdminInfo) (bool, string) {
	if isPushVipOnly() && !isVipUser(ai) {
		return false, "推送功能仅限VIP用户使用"
	}
	return true, ""
}

// 检查应用是否存在且归属
func validateAppPermission(appid string, ai *AdminInfo) (bool, string) {
	if db == nil {
		return false, "数据库连接未初始化"
	}
	var appUserID int
	err := db.QueryRow("SELECT user_id FROM cainiao_apk WHERE id = ?", appid).Scan(&appUserID)
	if err != nil {
		if err == sql.ErrNoRows {
			return false, fmt.Sprintf("应用ID %s 不存在", appid)
		}
		log.Println("数据库查询错误:", err)
		return false, "数据库查询错误"
	}
	if ai.Role == "admin" {
		return true, ""
	}
	if ai.Role == "user" {
		if appUserID == ai.UserID {
			return true, ""
		}
		return false, fmt.Sprintf("应用ID %s 不属于该管理员", appid)
	}
	return false, fmt.Sprintf("角色 %s 无权限进行推送操作", ai.Role)
}

// ======================== 数据库写入：在线表 ========================

// 写入或更新用户上线记录（appid+device唯一）
func saveOnlineRecord(appid, deviceID, ip string) {
	q := "REPLACE INTO cainiao_ws (apk_id, device_id, visit_time, ip_address) VALUES (?, ?, NOW(), ?)"
	if _, err := db.Exec(q, appid, deviceID, ip); err != nil {
		log.Println("写入上线记录失败:", err)
	} else {
		//log.Printf("上线记录成功: appid=%s, device=%s, ip=%s\n", appid, deviceID, ip)
	}
}

// 删除用户下线记录
func deleteOnlineRecord(appid, deviceID string) {
	q := "DELETE FROM cainiao_ws WHERE apk_id = ? AND device_id = ?"
	if _, err := db.Exec(q, appid, deviceID); err != nil {
		log.Println("删除下线记录失败:", err)
	} else {
		//log.Printf("下线记录删除成功: appid=%s, device=%s\n", appid, deviceID)
	}
}

// ======================== 加密与工具 ========================

// AES CBC/PKCS7 加密
func aesEncrypt(plaintext string) (string, error) {
	block, err := aes.NewCipher([]byte(aesKey))
	if err != nil {
	 return "", err
	}

	padding := aes.BlockSize - len(plaintext)%aes.BlockSize
	padtext := make([]byte, len(plaintext)+padding)
	copy(padtext, plaintext)
	for i := len(plaintext); i < len(padtext); i++ {
		padtext[i] = byte(padding)
	}

	iv := make([]byte, aes.BlockSize)
	if _, err := io.ReadFull(rand.Reader, iv); err != nil {
		return "", err
	}

	mode := cipher.NewCBCEncrypter(block, iv)
	ciphertext := make([]byte, len(padtext))
	mode.CryptBlocks(ciphertext, padtext)

	result := append(iv, ciphertext...)
	return base64.StdEncoding.EncodeToString(result), nil
}

// 仅提取IP（去掉端口），如失败则原样返回
func extractIP(remoteAddr string, r *http.Request) string {
	// 优先读取代理头（如存在）
	xff := r.Header.Get("X-Forwarded-For")
	if xff != "" {
		// 多个IP用逗号分隔，取第一个有效的
		parts := strings.Split(xff, ",")
		first := strings.TrimSpace(parts[0])
		if first != "" {
			return first
		}
	}
	// 兼容X-Real-IP
	xrip := r.Header.Get("X-Real-IP")
	if xrip != "" {
		return xrip
	}
	// 使用 RemoteAddr 去掉端口
	host, _, err := net.SplitHostPort(remoteAddr)
	if err == nil && host != "" {
		return host
	}
	return remoteAddr
}

// ======================== WebSocket 处理 ========================

func handleConnections(w http.ResponseWriter, r *http.Request) {
	// 读取URL参数
	q := r.URL.Query()
	appid := q.Get("appid")
	devices := q.Get("devices")
	key := q.Get("key")
	adminToken := q.Get("admin_token")

	// 提取纯IP（不带端口）
	ip := extractIP(r.RemoteAddr, r)

	var adminInfo *AdminInfo
	var isAdmin bool

	// ================= 管理员 / 用户鉴权 =================
	if adminToken != "" {
		var ok bool
		adminInfo, ok = validateAdminToken(adminToken)
		if !ok {
			http.Error(w, "管理员鉴权失败", http.StatusForbidden)
			return
		}
		if pass, msg := validatePushPermission(adminInfo); !pass {
			http.Error(w, msg, http.StatusForbidden)
			return
		}
		isAdmin = true
		log.Printf("管理员鉴权成功,userID=%d, role=%s\n", adminInfo.UserID, adminInfo.Role)
	} else {
		if appid == "" || devices == "" || key == "" {
			http.Error(w, "缺少鉴权参数", http.StatusUnauthorized)
			return
		}
		md5hash := md5.Sum([]byte(appid + devices))
		if key != hex.EncodeToString(md5hash[:]) {
			http.Error(w, "鉴权失败", http.StatusUnauthorized)
			return
		}
		isAdmin = false
	}
	// ================= 应用重定向检查 =================2025 12 23 新增
    if !isAdmin {
        var redirectApkID string
        err := db.QueryRow(
            "SELECT apk_id2 FROM cainiao_redirect WHERE apk_id1 = ? LIMIT 1",
            appid,
        ).Scan(&redirectApkID)
    
        if err == nil && redirectApkID != "" {
            log.Printf("检测到应用重定向: %s -> %s\n", appid, redirectApkID)
            appid = redirectApkID
        } else if err != nil && err != sql.ErrNoRows {
            log.Println("查询应用重定向失败:", err)
        }
    }


	// ================= 升级 WebSocket =================
	conn, err := upgrader.Upgrade(w, r, nil)
	if err != nil {
		log.Println("连接升级失败:", err)
		return
	}
	defer conn.Close()

	// 分组Key
	groupKey := "admin"
	if !isAdmin {
		groupKey = appid
	}

	clientInfo := &ClientInfo{
		Conn:      conn,
		AppID:     appid,
		DeviceID:  devices,
		IsAdmin:   isAdmin,
		AdminInfo: adminInfo,
		IP:        ip,
	}

	// ================= 入组（按 appid + device 唯一） =================
	clientsMu.Lock()
	if clients[groupKey] == nil {
		clients[groupKey] = make(map[string]*ClientInfo)
	}

	// 非管理员：同一设备新连接踢旧连接
	if !isAdmin {
		if old, ok := clients[groupKey][devices]; ok {
			log.Printf("设备重复上线，踢掉旧连接: appid=%s deviceID=%s\n", appid, devices)
			_ = old.Conn.Close()
		}
		clients[groupKey][devices] = clientInfo
	} else {
		// 管理员仍按连接唯一
		clients[groupKey][fmt.Sprintf("%p", conn)] = clientInfo
	}
	clientsMu.Unlock()

	// ================= 写入在线表 & 日志 =================
	if !isAdmin {
		saveOnlineRecord(appid, devices, ip)
		log.Printf("用户鉴权成功,appid=%s, 在线设备数=%d\n", appid, countOnline(appid))
	} else {
		log.Printf("管理员连接成功, 当前管理员在线数=%d\n", countOnline("admin"))
	}

	// ================= 消息循环 =================
	for {
		_, msg, err := conn.ReadMessage()
		if err != nil {
			clientsMu.Lock()
			if !isAdmin {
				delete(clients[groupKey], devices)
			} else {
				delete(clients[groupKey], fmt.Sprintf("%p", conn))
			}
			clientsMu.Unlock()

			if !isAdmin {
				deleteOnlineRecord(appid, devices)
			}
			break
		}

		if string(msg) == "ping" {
			_ = conn.WriteMessage(websocket.TextMessage, []byte("pong"))
			continue
		}

		log.Printf("接收到消息: %s\n", string(msg))

		if !isAdmin {
			log.Println("非管理员尝试推送消息,已忽略")
			continue
		}

		// ===== 管理员推送解析 =====
		var data map[string]interface{}
		if err := json.Unmarshal(msg, &data); err != nil {
			sendErrorResponse(conn, "", "消息格式错误")
			continue
		}

		if data["action"] != "push" {
			sendErrorResponse(conn, "", "非法操作")
			continue
		}

		message, ok := data["message"].(string)
		if !ok {
			sendErrorResponse(conn, "", "缺少推送内容")
			continue
		}

		devicesArray, ok := data["data"].([]interface{})
		if !ok {
			sendErrorResponse(conn, "", "数据格式错误")
			continue
		}

		var results []map[string]interface{}
		success, fail := 0, 0

		for _, d := range devicesArray {
			item := d.(map[string]interface{})
			appidPush := fmt.Sprintf("%.0f", item["appid"].(float64))

			if pass, msg := validateAppPermission(appidPush, clientInfo.AdminInfo); !pass {
				results = append(results, map[string]interface{}{
					"appid":   appidPush,
					"status":  "failed",
					"message": msg,
				})
				fail++
				continue
			}

			targetDevices, _ := item["devices"].([]interface{})

			encrypted, err := aesEncrypt(message)
			if err != nil {
				fail++
				continue
			}

			count := pushToClients(appidPush, targetDevices, encrypted)
			results = append(results, map[string]interface{}{
				"appid":        appidPush,
				"status":       "success",
				"pushed_count": count,
				"target_count": len(targetDevices),
			})
			success++
		}

		sendPushResponse(conn, success, fail, results)
	}
}


// 向指定设备或所有设备推送消息
func pushToClients(appid string, devices []interface{}, message string) int {
	clientsMu.Lock()
	defer clientsMu.Unlock()

	if clients[appid] == nil || len(clients[appid]) == 0 {
		log.Printf("appid=%s 没有在线客户端\n", appid)
		return 0
	}

	pushCount := 0

	// ===== 推送给 appid 下的所有设备 =====
	if len(devices) == 0 {
		for deviceID, ci := range clients[appid] {
			if err := ci.Conn.WriteMessage(websocket.TextMessage, []byte(message)); err != nil {
				log.Printf("推送失败(设备:%s),关闭连接: %v\n", deviceID, err)
				ci.Conn.Close()
				delete(clients[appid], deviceID)
			} else {
				pushCount++
				log.Printf("推送成功: appid=%s, deviceID=%s\n", appid, deviceID)
			}
		}
		log.Printf("推送完成: appid=%s, 成功推送 %d 个设备\n", appid, pushCount)
		return pushCount
	}

	// ===== 推送给指定设备 =====
	target := make(map[string]bool)
	for _, d := range devices {
		if id, ok := d.(string); ok && id != "" {
			target[id] = true
		}
	}

	for deviceID, ci := range clients[appid] {
		if !target[deviceID] {
			continue
		}
		if err := ci.Conn.WriteMessage(websocket.TextMessage, []byte(message)); err != nil {
			log.Printf("推送失败(设备:%s),关闭连接: %v\n", deviceID, err)
			ci.Conn.Close()
			delete(clients[appid], deviceID)
		} else {
			pushCount++
			log.Printf("推送成功: appid=%s, deviceID=%s\n", appid, deviceID)
		}
	}

	log.Printf(
		"推送完成: appid=%s, 目标设备数=%d, 成功推送 %d 个设备\n",
		appid,
		len(target),
		pushCount,
	)

	return pushCount
}


// 发送错误响应
func sendErrorResponse(conn *websocket.Conn, appid string, errMsg string) {
	resp := map[string]interface{}{
		"status":  "error",
		"message": errMsg,
	}
	if appid != "" {
		resp["appid"] = appid
	}
	if b, err := json.Marshal(resp); err == nil {
		_ = conn.WriteMessage(websocket.TextMessage, b)
	}
}

// 发送推送结果
func sendPushResponse(conn *websocket.Conn, successCount, failCount int, results []map[string]interface{}) {
	resp := map[string]interface{}{
		"status":        "success",
		"success_count": successCount,
		"fail_count":    failCount,
		"results":       results,
	}
	if b, err := json.Marshal(resp); err == nil {
		_ = conn.WriteMessage(websocket.TextMessage, b)
		log.Printf("推送结果已返回: 成功=%d, 失败=%d\n", successCount, failCount)
	}
}

// 返回 appid 在线人数
func countOnline(appid string) int {
	clientsMu.Lock()
	defer clientsMu.Unlock()
	return len(clients[appid])
}

// ======================== 主函数 ========================

func main() {
	// 初始化日志：清空或创建 ws.log，并将日志输出到文件+控制台
	logFile, err := initLogger()
	if err != nil {
		// 如果日志初始化失败，仍尝试输出错误并退出
		fmt.Println("初始化日志失败:", err)
		os.Exit(1)
	}
	defer logFile.Close()

	// 捕获退出信号，用于优雅清理
	quit := make(chan os.Signal, 1)
	signal.Notify(quit, syscall.SIGINT, syscall.SIGTERM)

	// 读取配置并连接数据库
	cfg, err := getConfigFromFile()
	if err != nil {
		log.Fatal("读取配置文件失败:", err)
	}
	db, err = connectToDatabase(cfg)
	if err != nil {
		log.Fatal("数据库连接失败:", err)
	}
	defer db.Close()

	// 启动时：清空在线表、写入PID（覆盖）
	clearOnlineTable()
	if err := writePID(); err != nil {
		log.Println("写入 ws.pid 失败:", err)
	} else {
		log.Println("已写入 ws.pid")
	}

	// 启动HTTP服务
	http.HandleFunc("/ws", handleConnections)
	addr := ":1888"
	log.Println("数据库连接成功:", cfg.DBName)
	log.Println("WebSocket 服务已启动, 监听端口", addr)

	// 关闭处理：收到信号后清理并退出
	go func() {
		<-quit
		log.Println("接收到退出信号，开始清理...")

		// 清空在线表
		clearOnlineTable()

		// 清空PID文件内容
		clearPID()

		log.Println("清理完成，即将退出")
		os.Exit(0) // 直接退出（注意：defer不再执行）
	}()

	// 阻塞监听
	if err := http.ListenAndServe(addr, nil); err != nil {
		log.Fatal("HTTP服务启动失败:", err)
	}
}
