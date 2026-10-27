#!/usr/bin/env python3
"""把 APK Manifest 的 versionName 规范化为 AXML STRING 类型。"""

from __future__ import annotations

import copy
import math
import os
import struct
import sys
import tempfile
import zipfile
from pathlib import Path


RES_XML_TYPE = 0x0003
RES_STRING_POOL_TYPE = 0x0001
RES_XML_START_ELEMENT_TYPE = 0x0102
TYPE_REFERENCE = 0x01
TYPE_STRING = 0x03
TYPE_FLOAT = 0x04
TYPE_INT_DEC = 0x10
TYPE_INT_HEX = 0x11


def u16(data: bytes, offset: int) -> int:
    return struct.unpack_from("<H", data, offset)[0]


def u32(data: bytes, offset: int) -> int:
    return struct.unpack_from("<I", data, offset)[0]


def encode_length(length: int) -> bytes:
    if length < 0x80:
        return bytes([length])
    if length > 0x7FFF:
        raise ValueError("字符串长度超过 AXML 单字节扩展长度范围")
    return bytes([0x80 | (length >> 8), length & 0xFF])


def decode_length(data: bytes, offset: int) -> tuple[int, int]:
    first = data[offset]
    offset += 1
    if first & 0x80:
        first = ((first & 0x7F) << 8) | data[offset]
        offset += 1
    return first, offset


def decode_pool(data: bytes) -> tuple[list[str], int, int, int, bytes]:
    pool = u16(data, 2)
    if u16(data, pool) != RES_STRING_POOL_TYPE:
        raise ValueError("缺少 AXML 字符串池")
    header_size = u16(data, pool + 2)
    pool_size = u32(data, pool + 4)
    count = u32(data, pool + 8)
    style_count = u32(data, pool + 12)
    flags = u32(data, pool + 16)
    strings_start = u32(data, pool + 20)
    styles_start = u32(data, pool + 24)
    offsets = pool + header_size
    strings = pool + strings_start
    values: list[str] = []
    utf8 = bool(flags & 0x100)
    for index in range(count):
        offset = strings + u32(data, offsets + index * 4)
        if utf8:
            _utf16_length, offset = decode_length(data, offset)
            byte_length, offset = decode_length(data, offset)
            values.append(data[offset : offset + byte_length].decode("utf-8"))
        else:
            utf16_length = u16(data, offset)
            offset += 2
            values.append(data[offset : offset + utf16_length * 2].decode("utf-16le", "surrogatepass"))
    styles = data[pool + styles_start : pool + pool_size] if styles_start else b""
    return values, flags, style_count, styles_start, styles


def encode_pool(values: list[str], flags: int, style_count: int, styles: bytes) -> bytes:
    utf8 = bool(flags & 0x100)
    string_data = bytearray()
    offsets: list[int] = []
    for value in values:
        offsets.append(len(string_data))
        if utf8:
            encoded = value.encode("utf-8")
            string_data.extend(encode_length(len(value)))
            string_data.extend(encode_length(len(encoded)))
            string_data.extend(encoded)
            string_data.append(0)
        else:
            encoded = value.encode("utf-16le", "surrogatepass")
            string_data.extend(struct.pack("<H", len(encoded) // 2))
            string_data.extend(encoded)
            string_data.extend(b"\0\0")
    while len(string_data) % 4:
        string_data.append(0)

    header_size = 28
    strings_start = header_size + len(offsets) * 4
    styles_start = strings_start + len(string_data) if styles else 0
    pool_size = (styles_start + len(styles)) if styles else (strings_start + len(string_data))
    header = struct.pack(
        "<HHIIIIII",
        RES_STRING_POOL_TYPE,
        header_size,
        pool_size,
        len(values),
        style_count if styles else 0,
        flags,
        strings_start,
        styles_start,
    )
    return header + b"".join(struct.pack("<I", offset) for offset in offsets) + bytes(string_data) + styles


def find_version_name(data: bytes) -> tuple[int, int, int, int, int] | None:
    if u16(data, 0) != RES_XML_TYPE or u32(data, 4) != len(data):
        raise ValueError("AXML 头无效")
    values, _flags, _style_count, _styles_start, _styles = decode_pool(data)
    version_index = values.index("versionName")
    pool = u16(data, 2)
    cursor = pool + u32(data, pool + 4)
    while cursor < len(data):
        chunk_type = u16(data, cursor)
        chunk_header = u16(data, cursor + 2)
        chunk_size = u32(data, cursor + 4)
        if chunk_size < chunk_header or cursor + chunk_size > len(data):
            raise ValueError("AXML chunk 越界")
        if chunk_type == RES_XML_START_ELEMENT_TYPE and chunk_size >= 36:
            name_index = u32(data, cursor + 20)
            attr_start = u16(data, cursor + 24)
            attr_size = u16(data, cursor + 26)
            attr_count = u16(data, cursor + 28)
            if name_index < len(values) and values[name_index] == "manifest":
                for index in range(attr_count):
                    attr = cursor + 16 + attr_start + index * attr_size
                    if u32(data, attr + 4) == version_index:
                        return attr, data[attr + 15], u32(data, attr + 16), index, cursor
                return None
        cursor += chunk_size
    raise ValueError("未找到 manifest 根节点")


def float_text(value: float) -> str:
    if not math.isfinite(value):
        raise ValueError("versionName FLOAT 不是有限数")
    if abs(value - round(value)) < 1e-7:
        return f"{value:.1f}"
    return format(value, ".9g")


def normalize_manifest(data: bytes) -> bytes:
    found = find_version_name(data)
    if found is None:
        return data
    attr, value_type, value_data, _index, _cursor = found
    if value_type == TYPE_REFERENCE:
        return data
    values, flags, style_count, _styles_start, styles = decode_pool(data)
    if value_type == TYPE_STRING:
        if value_data >= len(values):
            raise ValueError("versionName STRING 索引越界")
        text = values[value_data]
    elif value_type == TYPE_FLOAT:
        text = float_text(struct.unpack("<f", struct.pack("<I", value_data))[0])
    elif value_type in (TYPE_INT_DEC, TYPE_INT_HEX):
        signed = struct.unpack("<i", struct.pack("<I", value_data))[0]
        text = str(signed if value_type == TYPE_INT_DEC else value_data)
    else:
        raise ValueError(f"不支持的 versionName AXML 类型 0x{value_type:02x}")

    if text in values:
        string_index = values.index(text)
        rebuilt = data
    else:
        string_index = len(values)
        rebuilt_pool = encode_pool(values + [text], flags, style_count, styles)
        pool = u16(data, 2)
        old_pool_size = u32(data, pool + 4)
        rebuilt = data[:pool] + rebuilt_pool + data[pool + old_pool_size :]
        rebuilt = struct.pack("<HHI", RES_XML_TYPE, u16(rebuilt, 2), len(rebuilt)) + rebuilt[8:]

    found = find_version_name(rebuilt)
    if found is None:
        raise ValueError("重建后未找到 versionName")
    attr = found[0]
    rebuilt = bytearray(rebuilt)
    struct.pack_into("<I", rebuilt, attr + 8, string_index)
    rebuilt[attr + 15] = TYPE_STRING
    struct.pack_into("<I", rebuilt, attr + 16, string_index)
    return bytes(rebuilt)


def normalize_apk(path: Path) -> bool:
    with zipfile.ZipFile(path, "r") as source:
        infos = source.infolist()
        manifest = source.read("AndroidManifest.xml")
        normalized = normalize_manifest(manifest)
        if normalized == manifest:
            return False
        fd, temp_name = tempfile.mkstemp(prefix=path.name + ".", suffix=".tmp", dir=path.parent)
        os.close(fd)
        temp_path = Path(temp_name)
        try:
            with zipfile.ZipFile(temp_path, "w") as target:
                for info in infos:
                    clone = copy.copy(info)
                    target.writestr(clone, normalized if info.filename == "AndroidManifest.xml" else source.read(info.filename))
            os.replace(temp_path, path)
        finally:
            temp_path.unlink(missing_ok=True)
    return True


def main() -> int:
    if len(sys.argv) != 2:
        print("用法：normalize_manifest_version.py <APK>", file=sys.stderr)
        return 2
    try:
        changed = normalize_apk(Path(sys.argv[1]).expanduser())
    except Exception as error:  # pragma: no cover - CLI error is reported to injector logs
        print(f"Manifest versionName 修复失败：{error}", file=sys.stderr)
        return 1
    print("Manifest versionName 已规范化为 STRING" if changed else "Manifest versionName 已是 STRING/REFERENCE")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
