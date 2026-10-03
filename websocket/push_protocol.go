package main

import (
	"bytes"
	"encoding/json"
	"fmt"
	"strconv"
	"strings"
)

// pushTarget 表示一次推送请求中的应用目标。
// 设备列表为空时沿用协议约定，向该应用的全部在线设备推送。
type pushTarget struct {
	AppID   string
	Devices []string
}

// parsePushRequest 解析并校验管理员推送消息。
//
// 旧实现直接对 map[string]interface{} 做多次类型断言，畸形消息会触发
// panic 并终止当前 WebSocket 处理协程。这里把协议边界集中到一个纯函数，
// 让调用方只处理已经校验过的字符串和切片，同时兼容历史数字型 appid。
func parsePushRequest(payload []byte) (string, []pushTarget, error) {
	var envelope struct {
		Action  string          `json:"action"`
		Message *string         `json:"message"`
		Data    json.RawMessage `json:"data"`
	}
	decoder := json.NewDecoder(bytes.NewReader(payload))
	if err := decoder.Decode(&envelope); err != nil {
		return "", nil, fmt.Errorf("消息格式错误: %w", err)
	}
	if envelope.Action != "push" {
		return "", nil, fmt.Errorf("非法操作")
	}
	if envelope.Message == nil {
		return "", nil, fmt.Errorf("缺少推送内容")
	}
	if len(envelope.Data) == 0 || bytes.Equal(bytes.TrimSpace(envelope.Data), []byte("null")) {
		return "", nil, fmt.Errorf("数据格式错误")
	}

	var rawTargets []struct {
		AppID   json.RawMessage `json:"appid"`
		Devices json.RawMessage `json:"devices"`
	}
	if err := json.Unmarshal(envelope.Data, &rawTargets); err != nil {
		return "", nil, fmt.Errorf("数据格式错误: %w", err)
	}
	if rawTargets == nil {
		return "", nil, fmt.Errorf("数据格式错误")
	}

	targets := make([]pushTarget, 0, len(rawTargets))
	for index, raw := range rawTargets {
		appid, err := parseAppID(raw.AppID)
		if err != nil {
			return "", nil, fmt.Errorf("第 %d 个目标的 appid 无效: %w", index+1, err)
		}
		devices, err := parseDeviceIDs(raw.Devices)
		if err != nil {
			return "", nil, fmt.Errorf("第 %d 个目标的 devices 无效: %w", index+1, err)
		}
		targets = append(targets, pushTarget{AppID: appid, Devices: devices})
	}
	return *envelope.Message, targets, nil
}

// parseAppID 兼容历史客户端发送的数字 appid 和新客户端发送的字符串 appid。
func parseAppID(raw json.RawMessage) (string, error) {
	if len(raw) == 0 || bytes.Equal(bytes.TrimSpace(raw), []byte("null")) {
		return "", fmt.Errorf("缺少 appid")
	}
	var text string
	if err := json.Unmarshal(raw, &text); err == nil {
		text = strings.TrimSpace(text)
		if text != "" {
			return text, nil
		}
	}

	var number json.Number
	decoder := json.NewDecoder(bytes.NewReader(raw))
	decoder.UseNumber()
	if err := decoder.Decode(&number); err != nil {
		return "", fmt.Errorf("必须是字符串或数字")
	}
	value, err := strconv.ParseFloat(number.String(), 64)
	if err != nil {
		return "", fmt.Errorf("必须是字符串或数字")
	}
	return fmt.Sprintf("%.0f", value), nil
}

// parseDeviceIDs 将设备标识限制为非空字符串，避免后续推送时发生类型断言 panic。
func parseDeviceIDs(raw json.RawMessage) ([]string, error) {
	if len(raw) == 0 || bytes.Equal(bytes.TrimSpace(raw), []byte("null")) {
		return nil, nil
	}
	var values []json.RawMessage
	if err := json.Unmarshal(raw, &values); err != nil {
		return nil, fmt.Errorf("必须是字符串数组")
	}
	devices := make([]string, 0, len(values))
	for _, value := range values {
		var device string
		if err := json.Unmarshal(value, &device); err != nil || strings.TrimSpace(device) == "" {
			return nil, fmt.Errorf("设备标识必须是非空字符串")
		}
		devices = append(devices, device)
	}
	return devices, nil
}
