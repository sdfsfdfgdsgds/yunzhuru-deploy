package main

import "testing"

func TestParsePushRequestSupportsStringAndNumericAppIDs(t *testing.T) {
	message, targets, err := parsePushRequest([]byte(`{"action":"push","message":"公告","data":[{"appid":123,"devices":["a","b"]},{"appid":"456","devices":[]}]}`))
	if err != nil {
		t.Fatalf("解析推送消息失败: %v", err)
	}
	if message != "公告" || len(targets) != 2 {
		t.Fatalf("解析结果不符合预期: message=%q targets=%+v", message, targets)
	}
	if targets[0].AppID != "123" || len(targets[0].Devices) != 2 || targets[1].AppID != "456" || len(targets[1].Devices) != 0 {
		t.Fatalf("目标转换不符合预期: %+v", targets)
	}
}

func TestParsePushRequestRejectsMalformedTargets(t *testing.T) {
	cases := []string{
		`{"action":"push","message":"公告","data":[{"appid":true}]}`,
		`{"action":"push","message":"公告","data":[{"appid":1,"devices":[2]}]}`,
		`{"action":"push","message":"公告","data":{}}`,
	}
	for _, payload := range cases {
		if _, _, err := parsePushRequest([]byte(payload)); err == nil {
			t.Fatalf("畸形目标未被拒绝: %s", payload)
		}
	}
}

func TestParsePushRequestRejectsMissingRequiredFields(t *testing.T) {
	cases := []string{
		`{"message":"公告","data":[]}`,
		`{"action":"ping","message":"公告","data":[]}`,
		`{"action":"push","data":[]}`,
		`{"action":"push","message":"公告"}`,
	}
	for _, payload := range cases {
		if _, _, err := parsePushRequest([]byte(payload)); err == nil {
			t.Fatalf("缺少字段的消息未被拒绝: %s", payload)
		}
	}
}

func TestParsePushRequestAllowsEmptyMessageForProtocolCompatibility(t *testing.T) {
	message, targets, err := parsePushRequest([]byte(`{"action":"push","message":"","data":[{"appid":1}]}`))
	if err != nil {
		t.Fatalf("空消息属于合法协议值，不应被拒绝: %v", err)
	}
	if message != "" || len(targets) != 1 {
		t.Fatalf("空消息解析结果不符合预期: message=%q targets=%+v", message, targets)
	}
}
