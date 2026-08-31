#!/usr/bin/env python3
"""扫描最终 APK 中的全部 DEX，阻断已知高危确定性引用类型合同破坏。

这个门禁检查的是已完成 R8 优化、混淆与发布处理的最终字节码，
而非 Java 源码。它专门阻断项目已发生过的 Object/Enum 高危类型破坏，
不充当通用 DEX verifier；候选产物仍必须通过 Android 16 ART 真机硬门禁。
"""

from __future__ import annotations

import argparse
import gc
import hashlib
import json
import os
import re
import struct
import sys
import zlib
import zipfile
from dataclasses import asdict, dataclass, field
from pathlib import Path
from typing import Any, Dict, Iterable, List, Mapping, Optional, Sequence, Tuple


DEX_ENTRY_RE = re.compile(r"^classes(?:[0-9]+)?\.dex$")
REFERENCE_DESCRIPTOR_RE = re.compile(r"^(?:L[^;]+;|\[.+)$")
# Androguard 4.1.4 完整支持传统单 DEX 头。Android 16 的 041
# 是实验性 container DEX，header_size 和多逻辑 DEX 语义都不同；
# 在依赖具备完整 container 解析前明确阻断，不把半解析当成通过。
DEX_MAGIC_RE = re.compile(br"^dex\n(?:035|036|037|038|039|040)\x00$")

JAVA_OBJECT = "Ljava/lang/Object;"
JAVA_ENUM = "Ljava/lang/Enum;"
ACC_INTERFACE = 0x0200

REGISTER_OPERAND = 0

CONFIG_CLASS = "Lcn/shell/Config;"
ARTIFACT_KIND_TEMPLATE = "template"
ARTIFACT_KIND_INJECTED = "injected"
SUPPORTED_ARTIFACT_KINDS = (ARTIFACT_KIND_TEMPLATE, ARTIFACT_KIND_INJECTED)
CONTRACT_MANIFEST_SHELL_VERSION = "manifest_shell_version"
CONTRACT_TEMPLATE_CONFIG_PLACEHOLDERS = "cn.shell.Config_template_placeholders"
CONTRACT_TEMPLATE_PRIMARY_DEX = "template_primary_dex_namespaces"
SHELL_PROTOCOL_MARKER_FIELD = "SHELL_PROTOCOL_MARKER"
SHELL_PROTOCOL_MARKER_PREFIX = "YUNZHURU_SHELL_PROTOCOL_V1:"
PRIMARY_DEX_ENTRY = "classes.dex"
PRIMARY_DEX_REQUIRED_CLASSES = (
    "Lcn/shell/App;",
    "Lcn/shell/MainActivity;",
    "Lcn/shell/ShellAppComponentFactory;",
    "Lcn/shell/Config;",
)
PRIMARY_DEX_GUARDED_PREFIXES = (
    "Lcn/shell/",
    "Lcom/example/shell/",
    "Lorg/lsposed/hiddenapibypass/",
    "Lnatives/cn/shell/",
)
# 这些静态字段是注入器与壳模板之间的完整替换合同。任意一项在
# R8/混淆后丢失或改值，都会导致后续注入生成表面成功、实际配置未落地。
CONFIG_PLACEHOLDER_CONTRACT: Mapping[str, str] = {
    "APP_ID": "[#APP_ID#]",
    "APP_KEY": "[#USER_ID#]",
    "KEY": "[#KEY#]",
    "DOMAINS": "[#DOMAINS#]",
    "BUCKETS": "[#BUCKETS#]",
    "DNS_POOL": "#NO#[#DNS#]",
    "SIGN": "[#SIGN#]",
    "PACKAGE": "[#PACKAGE#]",
    "NETWORK": "[#NETWORK#]",
    "VPNCHECK": "[#VPNCHECK#]",
    "ISMMAINPROCESS": "[#ISMMAINPROCESS#]",
    "APPLICATION": "[#APPLICATION#]",
    "LAUNCHER": "[#LAUNCHER#]",
    "DEVICES": "[#DEVICES#]",
    "TV": "[#TV#]",
    "originFactoryClassName": "android.app.AppComponentFactory",
}


class GateExecutionError(RuntimeError):
    """表示门禁本身没有完整执行，这种情况不得当作检查通过。"""


@dataclass(frozen=True)
class ClassInfo:
    """记录跨 DEX 的最小类型关系，用于识别已知接口和字段类型。"""

    descriptor: str
    super_descriptor: Optional[str]
    interfaces: Tuple[str, ...]
    access_flags: int

    @property
    def is_interface(self) -> bool:
        return bool(self.access_flags & ACC_INTERFACE)


@dataclass(frozen=True)
class Allocation:
    """跟踪某寄存器中的确切 new-instance 来源。"""

    descriptor: str
    origin_offset: int
    origin_register: int


@dataclass(frozen=True)
class Finding:
    """一条可直接定位到 DEX 指令的阻断项。"""

    code: str
    dex_entry: str
    class_descriptor: str
    method_name: str
    method_descriptor: str
    offset_code_units: int
    message: str
    actual_type: Optional[str] = None
    expected_type: Optional[str] = None
    allocation_offset_code_units: Optional[int] = None
    register: Optional[int] = None


@dataclass(frozen=True)
class ContractViolation:
    """模板版本或注入占位符合同的阻断项。"""

    code: str
    location: str
    message: str
    expected: Optional[str] = None
    actual: Optional[str] = None


@dataclass
class DexStats:
    """记录单个 DEX 的扫描范围，便于证明没有只检查 classes.dex。"""

    entry: str
    sha256: str
    byte_size: int
    class_count: int = 0
    method_count: int = 0
    instruction_count: int = 0


@dataclass
class ArtifactEvidence:
    """两遍顺序扫描之间保留的紧凑跨 DEX 合同证据。"""

    protocol_marker_candidates: Dict[str, int] = field(default_factory=dict)
    config_class_count: int = 0
    config_field_values: Dict[str, Optional[str]] = field(default_factory=dict)
    primary_dex_definition_locations: Dict[str, List[str]] = field(
        default_factory=dict
    )


@dataclass
class DexFirstPassSnapshot:
    """单个 DEX 解析完后留下的紧凑快照，不持有 Androguard 对象。"""

    class_count: int
    classes: Dict[str, ClassInfo]
    protocol_marker_candidates: Dict[str, int]
    config_class_count: int = 0
    config_field_values: Dict[str, Optional[str]] = field(default_factory=dict)
    primary_dex_definition_locations: Dict[str, List[str]] = field(
        default_factory=dict
    )


@dataclass
class ScanReport:
    """整个 APK 的可机器读取扫描结果。"""

    apk: str
    apk_sha256: str
    artifact_kind: str = ARTIFACT_KIND_TEMPLATE
    expected_shell_version: Optional[int] = None
    manifest_version_code: Optional[str] = None
    manifest_version_name: Optional[str] = None
    shell_protocol_marker_expected: Optional[str] = None
    shell_protocol_marker_hit_count: int = 0
    shell_protocol_marker_candidates: Dict[str, int] = field(default_factory=dict)
    primary_dex_definition_locations: Dict[str, List[str]] = field(
        default_factory=dict
    )
    config_placeholder_count: int = 0
    config_placeholder_expected_count: int = len(CONFIG_PLACEHOLDER_CONTRACT)
    skipped_contracts: List[str] = field(default_factory=list)
    dex_files: List[DexStats] = field(default_factory=list)
    contract_violations: List[ContractViolation] = field(default_factory=list)
    findings: List[Finding] = field(default_factory=list)

    @property
    def passed(self) -> bool:
        return not self.contract_violations and not self.findings

    def to_json_dict(self) -> Dict[str, Any]:
        return {
            "schema_version": 1,
            "apk": self.apk,
            "apk_sha256": self.apk_sha256,
            "passed": self.passed,
            "artifact_kind": self.artifact_kind,
            "expected_shell_version": self.expected_shell_version,
            "manifest_version_code": self.manifest_version_code,
            "manifest_version_name": self.manifest_version_name,
            "shell_protocol_marker_expected": self.shell_protocol_marker_expected,
            "shell_protocol_marker_hit_count": self.shell_protocol_marker_hit_count,
            "shell_protocol_marker_candidates": dict(
                self.shell_protocol_marker_candidates
            ),
            "primary_dex_definition_locations": {
                descriptor: list(entries)
                for descriptor, entries in self.primary_dex_definition_locations.items()
            },
            "config_placeholder_count": self.config_placeholder_count,
            "config_placeholder_expected_count": self.config_placeholder_expected_count,
            "skipped_contracts": list(self.skipped_contracts),
            "dex_files": [asdict(item) for item in self.dex_files],
            "contract_violations": [
                asdict(item) for item in self.contract_violations
            ],
            "findings": [asdict(item) for item in self.findings],
        }


def _load_androguard() -> Tuple[Any, Any]:
    """延迟导入依赖，让缺少依赖时仍能输出简洁的中文处理命令。"""

    try:
        # Androguard 默认会输出大量 DEBUG 日志，发布门禁只保留本脚本的诊断。
        from loguru import logger

        logger.remove()
        from androguard.core.apk import APK
        from androguard.core.dex import DEX
    except ModuleNotFoundError as exc:
        raise GateExecutionError(
            "缺少 Python 依赖 androguard。请先运行："
            "python3 -m pip install -r "
            "clients/shell/scripts/requirements-dex-gate.txt"
        ) from exc
    return APK, DEX


def sha256_file(path: Path) -> str:
    """以流式读取计算大 APK 哈希，避免为哈希再复制一份内存。"""

    digest = hashlib.sha256()
    with path.open("rb") as stream:
        for chunk in iter(lambda: stream.read(1024 * 1024), b""):
            digest.update(chunk)
    return digest.hexdigest()


def discover_dex_entries(archive: zipfile.ZipFile) -> List[str]:
    """返回 APK 根目录下全部 classes*.dex，并按实际 DEX 序号排序。"""

    entries = [
        info.filename
        for info in archive.infolist()
        if not info.is_dir() and DEX_ENTRY_RE.fullmatch(info.filename)
    ]
    if len(entries) != len(set(entries)):
        raise GateExecutionError("APK 内存在重复的 classes*.dex 条目。")

    def sort_key(name: str) -> Tuple[int, str]:
        if name == "classes.dex":
            return (1, name)
        return (int(name[len("classes") : -len(".dex")]), name)

    return sorted(entries, key=sort_key)


def validate_dex_container(data: bytes, entry: str) -> None:
    """检查 DEX 头、长度、SHA-1 和 Adler32，损坏制品直接阻断。"""

    if len(data) < 0x70:
        raise GateExecutionError(f"{entry} 长度小于标准 DEX 头。")
    if not DEX_MAGIC_RE.fullmatch(data[:8]):
        raise GateExecutionError(f"{entry} 的 DEX magic/version 不在已支持范围。")

    declared_size = struct.unpack_from("<I", data, 0x20)[0]
    header_size = struct.unpack_from("<I", data, 0x24)[0]
    if declared_size != len(data):
        raise GateExecutionError(
            f"{entry} 声明长度 {declared_size} 与实际长度 {len(data)} 不一致。"
        )
    if header_size != 0x70:
        raise GateExecutionError(f"{entry} 的 header_size={header_size:#x}，期望值为 0x70。")

    expected_signature = hashlib.sha1(data[0x20:]).digest()
    if data[0x0C:0x20] != expected_signature:
        raise GateExecutionError(f"{entry} 的 DEX SHA-1 签名校验失败。")
    expected_checksum = zlib.adler32(data[0x0C:]) & 0xFFFFFFFF
    actual_checksum = struct.unpack_from("<I", data, 0x08)[0]
    if actual_checksum != expected_checksum:
        raise GateExecutionError(f"{entry} 的 DEX Adler32 校验失败。")


def _operand_registers(instruction: Any) -> List[int]:
    registers: List[int] = []
    for operand in instruction.get_operands():
        try:
            kind = int(operand[0])
        except (TypeError, ValueError):
            continue
        if kind == REGISTER_OPERAND:
            registers.append(int(operand[1]))
    return registers


def _referenced_texts(instruction: Any) -> List[str]:
    return [
        str(operand[2])
        for operand in instruction.get_operands()
        if len(operand) >= 3 and isinstance(operand[2], str)
    ]


def _first_type_reference(instruction: Any) -> Optional[str]:
    for text in reversed(_referenced_texts(instruction)):
        value = text.strip()
        if REFERENCE_DESCRIPTOR_RE.fullmatch(value):
            return value
    return None


def parse_field_reference(text: str) -> Optional[Tuple[str, str, str]]:
    """解析 Androguard 的 `Lowner;->name Ltype;` 字段输出。"""

    if "->" not in text:
        return None
    owner, remainder = text.split("->", 1)
    pieces = remainder.rsplit(None, 1)
    if len(pieces) != 2:
        return None
    name, descriptor = pieces
    if not owner.startswith("L") or not owner.endswith(";"):
        return None
    return owner, name, descriptor.replace(" ", "")


def parse_method_reference(text: str) -> Optional[Tuple[str, str, str]]:
    """解析 Androguard 的 `Lowner;->name(params)return` 方法输出。"""

    if "->" not in text:
        return None
    owner, remainder = text.split("->", 1)
    descriptor_start = remainder.find("(")
    if descriptor_start < 0 or not owner.startswith("L") or not owner.endswith(";"):
        return None
    name = remainder[:descriptor_start]
    descriptor = remainder[descriptor_start:].replace(" ", "")
    return owner, name, descriptor


def parse_method_descriptor(descriptor: str) -> Tuple[List[str], str]:
    """将 DEX 方法描述符分解成参数列表和返回类型。"""

    descriptor = descriptor.replace(" ", "")
    if not descriptor.startswith("(") or ")" not in descriptor:
        raise ValueError(f"非法方法描述符：{descriptor}")
    end = descriptor.index(")")
    parameter_blob = descriptor[1:end]
    return_blob = descriptor[end + 1 :]
    if not return_blob:
        raise ValueError(f"方法描述符缺少返回类型：{descriptor}")

    parameters: List[str] = []
    cursor = 0
    while cursor < len(parameter_blob):
        start = cursor
        while cursor < len(parameter_blob) and parameter_blob[cursor] == "[":
            cursor += 1
        if cursor >= len(parameter_blob):
            raise ValueError(f"非法数组描述符：{descriptor}")
        if parameter_blob[cursor] == "L":
            terminator = parameter_blob.find(";", cursor)
            if terminator < 0:
                raise ValueError(f"非法引用描述符：{descriptor}")
            cursor = terminator + 1
        elif parameter_blob[cursor] in "ZBSCIJFD":
            cursor += 1
        else:
            raise ValueError(f"未识别的参数描述符：{descriptor}")
        parameters.append(parameter_blob[start:cursor])
    return parameters, return_blob


def _is_reference(descriptor: Optional[str]) -> bool:
    return bool(descriptor and REFERENCE_DESCRIPTOR_RE.fullmatch(descriptor))


def _is_assignable_exact_base(actual: str, expected: str) -> bool:
    """
    判断门禁跟踪的“确切基类实例”能否赋给目标引用。

    本门禁只跟踪直接 new 出的 java.lang.Object/java.lang.Enum，因此
    判断是确定性的：Object 不是其他类或接口的实例，Enum 也不是某个
    具体枚举子类的实例。
    """

    if actual == expected or expected == JAVA_OBJECT:
        return True
    if actual == JAVA_ENUM and expected == JAVA_ENUM:
        return True
    return False


def _target_role(descriptor: str, classes: Mapping[str, ClassInfo]) -> str:
    info = classes.get(descriptor)
    if info and info.is_interface:
        return "已知接口"
    if info:
        return "已知类"
    return "引用类型"


def _instruction_has_destination(name: str) -> bool:
    """判断第一个寄存器是否被当前指令改写。"""

    no_destination_prefixes = (
        "nop",
        "return",
        "monitor-",
        "check-cast",
        "fill-array-data",
        "filled-new-array",
        "throw",
        "goto",
        "packed-switch",
        "sparse-switch",
        "if-",
        "aput",
        "iput",
        "sput",
        "invoke-",
    )
    return not name.startswith(no_destination_prefixes)


def _make_type_finding(
    *,
    dex_entry: str,
    class_descriptor: str,
    method_name: str,
    method_descriptor: str,
    instruction_offset: int,
    allocation: Allocation,
    register: int,
    expected: str,
    usage: str,
    classes: Mapping[str, ClassInfo],
) -> Finding:
    code = (
        "OBJECT_AS_TYPED_REFERENCE"
        if allocation.descriptor == JAVA_OBJECT
        else "ENUM_AS_CONCRETE_ENUM"
    )
    role = _target_role(expected, classes)
    return Finding(
        code=code,
        dex_entry=dex_entry,
        class_descriptor=class_descriptor,
        method_name=method_name,
        method_descriptor=method_descriptor,
        offset_code_units=instruction_offset // 2,
        message=(
            f"v{register} 由 new-instance {allocation.descriptor} 产生，"
            f"却被用作{usage}{role} {expected}；ART 引用类型不相容。"
        ),
        actual_type=allocation.descriptor,
        expected_type=expected,
        allocation_offset_code_units=allocation.origin_offset // 2,
        register=register,
    )


def _check_register_use(
    *,
    findings: List[Finding],
    register_state: Mapping[int, Allocation],
    register: int,
    expected: Optional[str],
    usage: str,
    dex_entry: str,
    class_descriptor: str,
    method_name: str,
    method_descriptor: str,
    instruction_offset: int,
    classes: Mapping[str, ClassInfo],
) -> None:
    allocation = register_state.get(register)
    if allocation is None or not _is_reference(expected):
        return
    assert expected is not None
    if _is_assignable_exact_base(allocation.descriptor, expected):
        return
    findings.append(
        _make_type_finding(
            dex_entry=dex_entry,
            class_descriptor=class_descriptor,
            method_name=method_name,
            method_descriptor=method_descriptor,
            instruction_offset=instruction_offset,
            allocation=allocation,
            register=register,
            expected=expected,
            usage=usage,
            classes=classes,
        )
    )


def _expanded_invoke_types(
    owner: str, parameters: Sequence[str], is_static: bool
) -> List[Optional[str]]:
    """按 DEX 寄存器个数展开调用参数，long/double 各占两个寄存器。"""

    result: List[Optional[str]] = []
    if not is_static:
        result.append(owner)
    for parameter in parameters:
        result.append(parameter)
        if parameter in ("J", "D"):
            result.append(None)
    return result


def scan_method(
    *,
    dex_entry: str,
    class_info: ClassInfo,
    method: Any,
    classes: Mapping[str, ClassInfo],
) -> Tuple[List[Finding], int]:
    """
    在一个方法内执行轻量寄存器来源跟踪。

    只跟踪直接构造的 Object/Enum 基类实例，并在普通指令改写寄存器时
    立即清除来源。这使报告保持“确定性错误”，而不是对任意对象做猜测。
    """

    method_name = str(method.get_name())
    method_descriptor = str(method.get_descriptor()).replace(" ", "")
    code = method.get_code()
    if code is None:
        return [], 0

    try:
        instructions = list(code.get_bc().get_instructions())
    except Exception as exc:  # pragma: no cover - 由真实损坏 DEX 集成路径覆盖
        raise GateExecutionError(
            f"{dex_entry} {class_info.descriptor}->{method_name}{method_descriptor} "
            f"指令解码失败：{exc}"
        ) from exc

    findings: List[Finding] = []
    register_state: Dict[int, Allocation] = {}
    offset = 0

    try:
        _, current_return_type = parse_method_descriptor(method_descriptor)
    except ValueError as exc:
        raise GateExecutionError(
            f"{dex_entry} {class_info.descriptor}->{method_name} 描述符解析失败：{exc}"
        ) from exc

    for instruction in instructions:
        name = str(instruction.get_name())
        registers = _operand_registers(instruction)
        referenced_texts = _referenced_texts(instruction)

        if name == "new-instance" and registers:
            allocation_type = _first_type_reference(instruction)
            destination = registers[0]
            register_state.pop(destination, None)
            if allocation_type in (JAVA_OBJECT, JAVA_ENUM):
                allocation = Allocation(allocation_type, offset, destination)
                register_state[destination] = allocation
                if allocation_type == JAVA_ENUM:
                    enum_context = (
                        class_info.super_descriptor == JAVA_ENUM
                        and method_name == "<clinit>"
                    )
                    findings.append(
                        Finding(
                            code=(
                                "ENUM_CLINIT_INSTANTIATES_BASE_ENUM"
                                if enum_context
                                else "DIRECT_ENUM_BASE_INSTANTIATION"
                            ),
                            dex_entry=dex_entry,
                            class_descriptor=class_info.descriptor,
                            method_name=method_name,
                            method_descriptor=method_descriptor,
                            offset_code_units=offset // 2,
                            message=(
                                "枚举子类的 <clinit> 直接 new java.lang.Enum，"
                                "枚举字段将获得错误的父类寄存器类型。"
                                if enum_context
                                else "DEX 直接 new java.lang.Enum，这不是合法的具体实例。"
                            ),
                            actual_type=JAVA_ENUM,
                            allocation_offset_code_units=offset // 2,
                            register=destination,
                        )
                    )

        elif name.startswith("move-object") and not name.startswith("move-object-result"):
            if len(registers) >= 2:
                destination, source = registers[0], registers[1]
                allocation = register_state.get(source)
                if allocation is None:
                    register_state.pop(destination, None)
                else:
                    register_state[destination] = allocation

        elif name == "check-cast" and registers:
            # check-cast 是显式类型边界；后续验证由 ART 的 cast 语义负责，
            # 不再把 cast 前的确切基类来源延伸到后续字段赋值。
            register_state.pop(registers[0], None)

        elif name.startswith(("sput-object", "iput-object")):
            field_ref = next(
                (parse_field_reference(text) for text in referenced_texts if "->" in text),
                None,
            )
            if field_ref and registers:
                owner, _, field_type = field_ref
                _check_register_use(
                    findings=findings,
                    register_state=register_state,
                    register=registers[0],
                    expected=field_type,
                    usage="字段值的",
                    dex_entry=dex_entry,
                    class_descriptor=class_info.descriptor,
                    method_name=method_name,
                    method_descriptor=method_descriptor,
                    instruction_offset=offset,
                    classes=classes,
                )
                if name.startswith("iput-object") and len(registers) >= 2:
                    _check_register_use(
                        findings=findings,
                        register_state=register_state,
                        register=registers[1],
                        expected=owner,
                        usage="实例字段持有者的",
                        dex_entry=dex_entry,
                        class_descriptor=class_info.descriptor,
                        method_name=method_name,
                        method_descriptor=method_descriptor,
                        instruction_offset=offset,
                        classes=classes,
                    )

        elif name.startswith("invoke-"):
            method_ref = next(
                (parse_method_reference(text) for text in referenced_texts if "->" in text),
                None,
            )
            if method_ref:
                owner, _, called_descriptor = method_ref
                try:
                    parameters, _ = parse_method_descriptor(called_descriptor)
                except ValueError as exc:
                    raise GateExecutionError(
                        f"{dex_entry} {class_info.descriptor}->{method_name}{method_descriptor} "
                        f"的调用描述符解析失败：{exc}"
                    ) from exc
                expected_types = _expanded_invoke_types(
                    owner,
                    parameters,
                    is_static=name.startswith("invoke-static"),
                )
                # invoke-custom 的引用不是普通 owner->method，上面会自然跳过。
                if len(expected_types) == len(registers):
                    for register, expected in zip(registers, expected_types):
                        _check_register_use(
                            findings=findings,
                            register_state=register_state,
                            register=register,
                            expected=expected,
                            usage="方法调用参数的",
                            dex_entry=dex_entry,
                            class_descriptor=class_info.descriptor,
                            method_name=method_name,
                            method_descriptor=method_descriptor,
                            instruction_offset=offset,
                            classes=classes,
                        )

        elif name == "return-object" and registers:
            _check_register_use(
                findings=findings,
                register_state=register_state,
                register=registers[0],
                expected=current_return_type,
                usage="方法返回值的",
                dex_entry=dex_entry,
                class_descriptor=class_info.descriptor,
                method_name=method_name,
                method_descriptor=method_descriptor,
                instruction_offset=offset,
                classes=classes,
            )

        elif registers and _instruction_has_destination(name):
            # 任何其他写寄存器的指令都终止旧 new-instance 来源，避免误报。
            register_state.pop(registers[0], None)

        offset += int(instruction.get_length())

    return findings, len(instructions)


def _class_info_from_definition(dex_class: Any) -> ClassInfo:
    """把 Androguard class_def 压缩为跨遍扫描需要的最小类型关系。"""

    descriptor = str(dex_class.get_name())
    superclass = dex_class.get_superclassname()
    return ClassInfo(
        descriptor=descriptor,
        super_descriptor=str(superclass) if superclass else None,
        interfaces=tuple(str(item) for item in dex_class.get_interfaces()),
        access_flags=int(dex_class.get_access_flags()),
    )


def _read_config_fields_from_class(
    dex_class: Any,
) -> Dict[str, Optional[str]]:
    """从单个 Config class_def 读取静态初始值。"""

    values: Dict[str, Optional[str]] = {}
    for dex_field in dex_class.get_fields():
        name = str(dex_field.get_name())
        encoded_value = dex_field.get_init_value()
        if encoded_value is None:
            values[name] = None
            continue
        try:
            value = encoded_value.get_value()
        except Exception as exc:
            raise GateExecutionError(
                f"{CONFIG_CLASS}->{name} 静态初始值解码失败：{exc}"
            ) from exc
        values[name] = value if isinstance(value, str) else str(value)
    return values


def collect_dex_first_pass_snapshot(
    dex_entry: str,
    dex: Any,
    include_template_contracts: bool,
) -> DexFirstPassSnapshot:
    """
    对单个 DEX 执行第一遍收集。

    返回值只包含字符串、整数、小型 dataclass 和容器，调用方随后可以
    释放当前 Androguard DEX，不让 multidex 解析树随文件数累积。
    """

    classes: Dict[str, ClassInfo] = {}
    config_class_count = 0
    config_field_values: Dict[str, Optional[str]] = {}
    primary_locations: Dict[str, List[str]] = {}
    try:
        dex_classes = dex.get_classes()
    except Exception as exc:
        raise GateExecutionError(f"{dex_entry} 的 class_defs 读取失败：{exc}") from exc

    class_count = 0
    for dex_class in dex_classes:
        class_count += 1
        info = _class_info_from_definition(dex_class)
        previous = classes.get(info.descriptor)
        if previous is not None and previous != info:
            raise GateExecutionError(
                f"{dex_entry} 内定义了冲突类 {info.descriptor}。"
            )
        classes[info.descriptor] = info

        if not include_template_contracts:
            continue
        if info.descriptor == CONFIG_CLASS:
            config_class_count += 1
            config_field_values.update(_read_config_fields_from_class(dex_class))
        if info.descriptor in PRIMARY_DEX_REQUIRED_CLASSES or info.descriptor.startswith(
            PRIMARY_DEX_GUARDED_PREFIXES
        ):
            primary_locations.setdefault(info.descriptor, []).append(dex_entry)

    marker_candidates = _collect_protocol_marker_candidates([dex])
    return DexFirstPassSnapshot(
        class_count=class_count,
        classes=classes,
        protocol_marker_candidates=marker_candidates,
        config_class_count=config_class_count,
        config_field_values=config_field_values,
        primary_dex_definition_locations=primary_locations,
    )


def merge_first_pass_snapshot(
    classes: Dict[str, ClassInfo],
    evidence: ArtifactEvidence,
    snapshot: DexFirstPassSnapshot,
) -> None:
    """把单 DEX 紧凑快照合并进全 APK 证据，不保留解析器对象。"""

    for descriptor, info in snapshot.classes.items():
        previous = classes.get(descriptor)
        if previous is not None and previous != info:
            raise GateExecutionError(f"多个 DEX 定义了冲突类 {descriptor}。")
        classes[descriptor] = info
    for marker, count in snapshot.protocol_marker_candidates.items():
        evidence.protocol_marker_candidates[marker] = (
            evidence.protocol_marker_candidates.get(marker, 0) + count
        )
    evidence.config_class_count += snapshot.config_class_count
    evidence.config_field_values.update(snapshot.config_field_values)
    for descriptor, entries in snapshot.primary_dex_definition_locations.items():
        evidence.primary_dex_definition_locations.setdefault(descriptor, []).extend(
            entries
        )


def _collect_class_info(dex_objects: Iterable[Any]) -> Dict[str, ClassInfo]:
    """批量兼容辅助函数，仅用于单元测试对照；生产扫描使用顺序快照。"""

    classes: Dict[str, ClassInfo] = {}
    evidence = ArtifactEvidence()
    for index, dex in enumerate(dex_objects, start=1):
        snapshot = collect_dex_first_pass_snapshot(
            PRIMARY_DEX_ENTRY if index == 1 else f"classes{index}.dex",
            dex,
            include_template_contracts=False,
        )
        merge_first_pass_snapshot(classes, evidence, snapshot)
    return classes


def validate_shell_version_contract(
    *,
    expected_version: Optional[int],
    manifest_version_code: Optional[str],
    manifest_version_name: Optional[str],
) -> List[ContractViolation]:
    """检查 Gradle 最终写入 Manifest 的双版本号。"""

    if expected_version is None:
        return []
    expected = str(expected_version)
    violations: List[ContractViolation] = []
    if manifest_version_code != expected:
        violations.append(
            ContractViolation(
                code="SHELL_VERSION_CODE_MISMATCH",
                location="AndroidManifest.xml@android:versionCode",
                message=(
                    f"最终 Manifest versionCode={manifest_version_code!r}，"
                    f"发布壳版本要求为 {expected}。"
                ),
                expected=expected,
                actual=manifest_version_code,
            )
        )
    if manifest_version_name != expected:
        violations.append(
            ContractViolation(
                code="SHELL_VERSION_NAME_MISMATCH",
                location="AndroidManifest.xml@android:versionName",
                message=(
                    f"最终 Manifest versionName={manifest_version_name!r}，"
                    f"发布壳版本要求为 {expected!r}。"
                ),
                expected=expected,
                actual=manifest_version_name,
            )
        )
    return violations


def build_shell_protocol_marker(expected_version: int) -> str:
    """从发布壳版本生成模板和注入成品共用的明文自证标记。"""

    return f"{SHELL_PROTOCOL_MARKER_PREFIX}{expected_version}"


def _collect_protocol_marker_candidates(
    dex_objects: Iterable[Any],
) -> Dict[str, int]:
    """跨全部 DEX 字符串池计数协议 marker，不依赖注入后的类名。"""

    candidates: Dict[str, int] = {}
    for dex in dex_objects:
        try:
            strings = dex.get_strings()
        except Exception as exc:
            raise GateExecutionError(f"DEX 字符串池读取失败：{exc}") from exc
        for raw_value in strings:
            value = str(raw_value)
            if not value.startswith(SHELL_PROTOCOL_MARKER_PREFIX):
                continue
            candidates[value] = candidates.get(value, 0) + 1
    return dict(sorted(candidates.items()))


def validate_protocol_marker_candidate_counts(
    report: ScanReport, candidates: Mapping[str, int]
) -> List[ContractViolation]:
    """验证顺序第一遍收集的 marker 在全 APK 中恰好出现一次。"""

    if report.expected_shell_version is None:
        raise GateExecutionError("shell protocol marker 校验缺少预期壳版本。")
    expected = build_shell_protocol_marker(report.expected_shell_version)
    candidates = dict(sorted(candidates.items()))
    expected_hits = candidates.get(expected, 0)
    report.shell_protocol_marker_expected = expected
    report.shell_protocol_marker_hit_count = expected_hits
    report.shell_protocol_marker_candidates = candidates

    violations: List[ContractViolation] = []
    if expected_hits == 0:
        if candidates:
            actual = ", ".join(
                f"{marker} x{count}" for marker, count in candidates.items()
            )
            violations.append(
                ContractViolation(
                    code="SHELL_PROTOCOL_MARKER_VERSION_MISMATCH",
                    location="classes*.dex string pools",
                    message=(
                        f"未命中预期 marker {expected!r}，"
                        f"但发现其他协议 marker：{actual}。"
                    ),
                    expected=expected,
                    actual=actual,
                )
            )
        else:
            violations.append(
                ContractViolation(
                    code="SHELL_PROTOCOL_MARKER_MISSING",
                    location="classes*.dex string pools",
                    message=f"全部 DEX 字符串池中未命中 marker {expected!r}。",
                    expected=expected,
                    actual="0",
                )
            )
    elif expected_hits > 1:
        violations.append(
            ContractViolation(
                code="SHELL_PROTOCOL_MARKER_DUPLICATED",
                location="classes*.dex string pools",
                message=(
                    f"预期 marker {expected!r} 在全部 DEX 字符串池中"
                    f"出现 {expected_hits} 次，合同要求恰好一次。"
                ),
                expected="1",
                actual=str(expected_hits),
            )
        )

    unexpected = {
        marker: count for marker, count in candidates.items() if marker != expected
    }
    if expected_hits == 1 and unexpected:
        actual = ", ".join(
            f"{marker} x{count}" for marker, count in unexpected.items()
        )
        violations.append(
            ContractViolation(
                code="SHELL_PROTOCOL_MARKER_UNEXPECTED_EXTRA",
                location="classes*.dex string pools",
                message=f"预期 marker 已命中，但还存在额外协议 marker：{actual}。",
                expected=expected,
                actual=actual,
            )
        )
    return violations


def validate_protocol_marker_contract(
    report: ScanReport, dex_objects: Iterable[Any]
) -> List[ContractViolation]:
    """批量兼容辅助函数，供单元测试对照使用。"""

    return validate_protocol_marker_candidate_counts(
        report,
        _collect_protocol_marker_candidates(dex_objects),
    )


def validate_template_config_marker(
    field_values: Mapping[str, Optional[str]], expected_marker: str
) -> List[ContractViolation]:
    """模板阶段还要确认 marker 属于明文 `cn.shell.Config` 静态字段。"""

    actual = field_values.get(SHELL_PROTOCOL_MARKER_FIELD)
    if actual == expected_marker:
        return []
    return [
        ContractViolation(
            code="CONFIG_PROTOCOL_MARKER_MISMATCH",
            location=f"{CONFIG_CLASS}->{SHELL_PROTOCOL_MARKER_FIELD}",
            message=(
                f"模板 Config 协议 marker 字段为 {actual!r}，"
                f"合同值为 {expected_marker!r}。"
            ),
            expected=expected_marker,
            actual=actual,
        )
    ]


def validate_config_placeholder_contract(
    class_count: int,
    field_values: Mapping[str, Optional[str]],
) -> Tuple[List[ContractViolation], int]:
    """
    检查 `cn.shell.Config` 是否仅定义一次，以及注入器需要的占位符是否
    仍保存在字段静态初始值中。
    """

    violations: List[ContractViolation] = []
    if class_count != 1:
        violations.append(
            ContractViolation(
                code="CONFIG_CLASS_COUNT_MISMATCH",
                location=CONFIG_CLASS,
                message=(
                    f"最终 APK 中 {CONFIG_CLASS} 定义数为 {class_count}，"
                    "注入模板要求恰好一个。"
                ),
                expected="1",
                actual=str(class_count),
            )
        )

    matched = 0
    for field_name, expected_value in CONFIG_PLACEHOLDER_CONTRACT.items():
        actual_value = field_values.get(field_name)
        if actual_value == expected_value:
            matched += 1
            continue
        violations.append(
            ContractViolation(
                code="CONFIG_PLACEHOLDER_MISMATCH",
                location=f"{CONFIG_CLASS}->{field_name}",
                message=(
                    f"注入字段 {field_name} 静态值为 {actual_value!r}，"
                    f"合同值为 {expected_value!r}。"
                ),
                expected=expected_value,
                actual=actual_value,
            )
        )
    return violations, matched


def _collect_config_field_values(
    dex_objects: Iterable[Any],
) -> Tuple[int, Dict[str, Optional[str]]]:
    """从最终 DEX 静态初始值中读取注入合同，不从源码推断。"""

    class_count = 0
    values: Dict[str, Optional[str]] = {}
    for dex in dex_objects:
        for dex_class in dex.get_classes():
            if str(dex_class.get_name()) != CONFIG_CLASS:
                continue
            class_count += 1
            for dex_field in dex_class.get_fields():
                name = str(dex_field.get_name())
                encoded_value = dex_field.get_init_value()
                if encoded_value is None:
                    values[name] = None
                    continue
                try:
                    value = encoded_value.get_value()
                except Exception as exc:
                    raise GateExecutionError(
                        f"{CONFIG_CLASS}->{name} 静态初始值解码失败：{exc}"
                    ) from exc
                values[name] = value if isinstance(value, str) else str(value)
    return class_count, values


def validate_primary_dex_contract(
    dex_entries: Sequence[str], dex_objects: Sequence[Any]
) -> Tuple[Dict[str, List[str]], List[ContractViolation]]:
    """
    验证注入前固定入口和壳命名空间全部位于 `classes.dex`。

    注入器会在模板阶段根据这些未改名定义执行替换，因此分流到
    `classesN.dex` 不是普通 multidex 布局变化，而是注入合同破坏。
    """

    if len(dex_entries) != len(dex_objects):
        raise GateExecutionError("primary-dex 合同的 DEX 名称与对象数量不一致。")

    locations: Dict[str, List[str]] = {}
    for dex_entry, dex in zip(dex_entries, dex_objects):
        try:
            classes = dex.get_classes()
        except Exception as exc:
            raise GateExecutionError(
                f"{dex_entry} 的 class_defs 读取失败：{exc}"
            ) from exc
        for dex_class in classes:
            descriptor = str(dex_class.get_name())
            if descriptor in PRIMARY_DEX_REQUIRED_CLASSES or descriptor.startswith(
                PRIMARY_DEX_GUARDED_PREFIXES
            ):
                locations.setdefault(descriptor, []).append(dex_entry)

    locations = {
        descriptor: sorted(entries)
        for descriptor, entries in sorted(locations.items())
    }
    return locations, validate_primary_dex_location_contract(locations)


def validate_primary_dex_location_contract(
    locations: Mapping[str, Sequence[str]],
) -> List[ContractViolation]:
    """验证顺序第一遍收集的模板 class_def 位置。"""

    violations: List[ContractViolation] = []
    for descriptor in PRIMARY_DEX_REQUIRED_CLASSES:
        actual_locations = list(locations.get(descriptor, []))
        if actual_locations == [PRIMARY_DEX_ENTRY]:
            continue
        actual = ", ".join(actual_locations) if actual_locations else "缺失"
        violations.append(
            ContractViolation(
                code="PRIMARY_DEX_REQUIRED_CLASS_MISMATCH",
                location=descriptor,
                message=(
                    f"关键注入定义 {descriptor} 位于 {actual}，"
                    f"合同要求在 {PRIMARY_DEX_ENTRY} 恰好定义一次。"
                ),
                expected=PRIMARY_DEX_ENTRY,
                actual=actual,
            )
        )

    for descriptor, raw_locations in sorted(locations.items()):
        actual_locations = list(raw_locations)
        secondary_entries = [
            entry for entry in actual_locations if entry != PRIMARY_DEX_ENTRY
        ]
        if not secondary_entries:
            continue
        actual = ", ".join(secondary_entries)
        violations.append(
            ContractViolation(
                code="PRIMARY_DEX_NAMESPACE_LEAK",
                location=descriptor,
                message=(
                    f"受保护壳命名空间 {descriptor} 出现在次级 DEX：{actual}；"
                    f"这些 class_defs 只允许出现在 {PRIMARY_DEX_ENTRY}。"
                ),
                expected=PRIMARY_DEX_ENTRY,
                actual=actual,
            )
        )
    return violations


def apply_artifact_contracts(report: ScanReport, evidence: ArtifactEvidence) -> None:
    """
    根据制品阶段应用合同。

    壳模板的 Manifest 版本、`cn.shell.Config` 占位符和未改名命名空间
    属于注入前合同；注入成品跳过这三项，但仍要求跨全部 DEX 的
    版本 marker、APK/DEX 完整性和已知高危引用类型合同。
    """

    # 协议 marker 是模板和注入成品的共同自证合同，必须先执行。
    report.contract_violations.extend(
        validate_protocol_marker_candidate_counts(
            report,
            evidence.protocol_marker_candidates,
        )
    )

    if report.artifact_kind == ARTIFACT_KIND_INJECTED:
        report.skipped_contracts.extend(
            [
                CONTRACT_MANIFEST_SHELL_VERSION,
                CONTRACT_TEMPLATE_CONFIG_PLACEHOLDERS,
                CONTRACT_TEMPLATE_PRIMARY_DEX,
            ]
        )
        return
    if report.artifact_kind != ARTIFACT_KIND_TEMPLATE:
        raise GateExecutionError(f"未识别的制品类型：{report.artifact_kind}")

    report.contract_violations.extend(
        validate_shell_version_contract(
            expected_version=report.expected_shell_version,
            manifest_version_code=report.manifest_version_code,
            manifest_version_name=report.manifest_version_name,
        )
    )
    definition_locations = {
        descriptor: sorted(entries)
        for descriptor, entries in sorted(
            evidence.primary_dex_definition_locations.items()
        )
    }
    report.primary_dex_definition_locations = definition_locations
    report.contract_violations.extend(
        validate_primary_dex_location_contract(definition_locations)
    )
    placeholder_violations, matched_placeholders = validate_config_placeholder_contract(
        evidence.config_class_count,
        evidence.config_field_values,
    )
    report.config_placeholder_count = matched_placeholders
    report.contract_violations.extend(placeholder_violations)
    assert report.shell_protocol_marker_expected is not None
    report.contract_violations.extend(
        validate_template_config_marker(
            evidence.config_field_values,
            report.shell_protocol_marker_expected,
        )
    )


def validate_scan_options(
    artifact_kind: str, expected_shell_version: Optional[int]
) -> None:
    """在读取 APK 前验证制品模式参数合同。"""

    if artifact_kind not in SUPPORTED_ARTIFACT_KINDS:
        raise GateExecutionError(f"未识别的制品类型：{artifact_kind}")
    if expected_shell_version is None:
        raise GateExecutionError(
            f"{artifact_kind} 模式必须显式提供 --expected-shell-version。"
        )


def _parse_first_pass_dex(
    DEX: Any,
    data: bytes,
    dex_entry: str,
    include_template_contracts: bool,
) -> DexFirstPassSnapshot:
    """解析一个 DEX 并立即压缩成第一遍快照。"""

    try:
        dex = DEX(data)
    except Exception as exc:
        raise GateExecutionError(f"{dex_entry} 的 DEX 解析失败：{exc}") from exc
    return collect_dex_first_pass_snapshot(
        dex_entry,
        dex,
        include_template_contracts=include_template_contracts,
    )


def _scan_second_pass_dex(
    DEX: Any,
    data: bytes,
    stats: DexStats,
    classes: Mapping[str, ClassInfo],
) -> List[Finding]:
    """重新解析一个 DEX 并扫描方法，返回值不持有 Androguard 对象。"""

    try:
        dex = DEX(data)
    except Exception as exc:
        raise GateExecutionError(
            f"{stats.entry} 的 DEX 第二遍解析失败：{exc}"
        ) from exc

    findings: List[Finding] = []
    second_pass_class_count = 0
    for dex_class in dex.get_classes():
        second_pass_class_count += 1
        descriptor = str(dex_class.get_name())
        class_info = classes.get(descriptor)
        if class_info is None:
            raise GateExecutionError(
                f"{stats.entry} 第二遍出现第一遍未记录的类 {descriptor}。"
            )
        for method in dex_class.get_methods():
            stats.method_count += 1
            method_findings, instruction_count = scan_method(
                dex_entry=stats.entry,
                class_info=class_info,
                method=method,
                classes=classes,
            )
            stats.instruction_count += instruction_count
            findings.extend(method_findings)
    if second_pass_class_count != stats.class_count:
        raise GateExecutionError(
            f"{stats.entry} 两遍 class_defs 计数不一致："
            f"{stats.class_count} != {second_pass_class_count}。"
        )
    return findings


def scan_apk(
    apk_path: Path,
    expected_shell_version: Optional[int] = None,
    artifact_kind: str = ARTIFACT_KIND_TEMPLATE,
) -> ScanReport:
    """扫描 APK 全部 classes*.dex；任何 DEX 解析错误都使门禁中止。"""

    # 先验证发布参数，避免未声明壳版本时读取甚至扫描整个 APK。
    validate_scan_options(artifact_kind, expected_shell_version)
    apk_path = apk_path.expanduser().resolve()
    if not apk_path.is_file():
        raise GateExecutionError(f"APK 文件不存在：{apk_path}")

    APK, DEX = _load_androguard()
    report = ScanReport(
        apk=str(apk_path),
        apk_sha256=sha256_file(apk_path),
        artifact_kind=artifact_kind,
        expected_shell_version=expected_shell_version,
    )
    classes: Dict[str, ClassInfo] = {}
    evidence = ArtifactEvidence()

    try:
        manifest_apk = APK(str(apk_path))
        if not manifest_apk.is_valid_APK():
            raise GateExecutionError("APK AndroidManifest.xml 解析结果无效。")
        version_code = manifest_apk.get_androidversion_code()
        version_name = manifest_apk.get_androidversion_name()
        report.manifest_version_code = (
            str(version_code) if version_code is not None else None
        )
        report.manifest_version_name = (
            str(version_name) if version_name is not None else None
        )
    except GateExecutionError:
        raise
    except Exception as exc:
        raise GateExecutionError(f"APK Manifest 解析失败：{exc}") from exc
    finally:
        # Androguard APK 对象只用于 Manifest，在 DEX 遍历前即释放。
        if "manifest_apk" in locals():
            del manifest_apk
        gc.collect()

    # 第一遍：每次只解析一个 DEX，收集紧凑类型关系与制品合同后释放。
    try:
        with zipfile.ZipFile(apk_path, "r") as archive:
            entries = discover_dex_entries(archive)
            if not entries:
                raise GateExecutionError("APK 根目录下没有 classes*.dex。")
            for entry in entries:
                try:
                    data = archive.read(entry)
                except (KeyError, OSError, RuntimeError) as exc:
                    raise GateExecutionError(f"读取 {entry} 失败：{exc}") from exc
                validate_dex_container(data, entry)
                stats = DexStats(
                    entry=entry,
                    sha256=hashlib.sha256(data).hexdigest(),
                    byte_size=len(data),
                )
                snapshot = _parse_first_pass_dex(
                    DEX,
                    data,
                    entry,
                    include_template_contracts=(
                        artifact_kind == ARTIFACT_KIND_TEMPLATE
                    ),
                )
                stats.class_count = snapshot.class_count
                merge_first_pass_snapshot(classes, evidence, snapshot)
                report.dex_files.append(stats)
                # 显式终止当前 DEX 的 bytes/快照引用，循环中立即回收解析树。
                del snapshot
                del data
                gc.collect()
    except zipfile.BadZipFile as exc:
        raise GateExecutionError(f"APK ZIP 结构校验失败：{exc}") from exc

    apply_artifact_contracts(report, evidence)

    # 第二遍：按相同顺序重新解析单个 DEX 扫描方法，然后再释放。
    try:
        with zipfile.ZipFile(apk_path, "r") as archive:
            for stats in report.dex_files:
                try:
                    data = archive.read(stats.entry)
                except (KeyError, OSError, RuntimeError) as exc:
                    raise GateExecutionError(
                        f"第二遍读取 {stats.entry} 失败：{exc}"
                    ) from exc
                second_hash = hashlib.sha256(data).hexdigest()
                if len(data) != stats.byte_size or second_hash != stats.sha256:
                    raise GateExecutionError(
                        f"{stats.entry} 在两遍扫描之间发生变化。"
                    )
                report.findings.extend(
                    _scan_second_pass_dex(DEX, data, stats, classes)
                )
                del data
                gc.collect()
    except zipfile.BadZipFile as exc:
        raise GateExecutionError(f"APK ZIP 第二遍结构校验失败：{exc}") from exc

    report.findings.sort(
        key=lambda item: (
            item.dex_entry,
            item.class_descriptor,
            item.method_name,
            item.offset_code_units,
            item.code,
        )
    )
    return report


def print_report(report: ScanReport, max_findings: int) -> None:
    """输出面向发布人员的简洁中文诊断。"""

    print(f"[DEX门禁] APK：{report.apk}")
    print(f"[DEX门禁] APK SHA-256：{report.apk_sha256}")
    print(f"[DEX门禁] 制品类型：{report.artifact_kind}")
    expected_text = (
        str(report.expected_shell_version)
        if report.expected_shell_version is not None
        else "未指定"
    )
    if CONTRACT_MANIFEST_SHELL_VERSION in report.skipped_contracts:
        print(
            "[DEX门禁] Manifest 壳版本合同：SKIPPED"
            "（注入成品保留目标应用版本）"
        )
        print(
            "[DEX门禁] 目标 Manifest 版本："
            f"versionCode={report.manifest_version_code!r}，"
            f"versionName={report.manifest_version_name!r}"
        )
    else:
        print(
            "[DEX门禁] Manifest 版本："
            f"versionCode={report.manifest_version_code!r}，"
            f"versionName={report.manifest_version_name!r}，"
            f"期望壳版本={expected_text}"
        )
    if CONTRACT_TEMPLATE_CONFIG_PLACEHOLDERS in report.skipped_contracts:
        print(
            "[DEX门禁] cn.shell.Config 注入合同：SKIPPED"
            "（注入成品已改名并替换真实值）"
        )
    else:
        print(
            "[DEX门禁] cn.shell.Config 注入合同："
            f"{report.config_placeholder_count}/"
            f"{report.config_placeholder_expected_count} 项匹配"
        )
    marker_candidates = (
        ", ".join(
            f"{marker} x{count}"
            for marker, count in report.shell_protocol_marker_candidates.items()
        )
        or "无"
    )
    print(
        "[DEX门禁] 壳协议 marker："
        f"expected={report.shell_protocol_marker_expected!r}，"
        f"hits={report.shell_protocol_marker_hit_count}，"
        f"candidates={marker_candidates}"
    )
    if CONTRACT_TEMPLATE_PRIMARY_DEX in report.skipped_contracts:
        print(
            "[DEX门禁] 模板 primary-dex 命名空间合同：SKIPPED"
            "（注入成品已完成类名与包名替换）"
        )
    else:
        required_primary_count = sum(
            report.primary_dex_definition_locations.get(descriptor)
            == [PRIMARY_DEX_ENTRY]
            for descriptor in PRIMARY_DEX_REQUIRED_CLASSES
        )
        print(
            "[DEX门禁] 模板 primary-dex 命名空间合同："
            f"关键定义 {required_primary_count}/"
            f"{len(PRIMARY_DEX_REQUIRED_CLASSES)}，"
            f"受保护 class_defs="
            f"{len(report.primary_dex_definition_locations)}"
        )
    for stats in report.dex_files:
        print(
            f"[DEX门禁] {stats.entry}：{stats.byte_size} 字节，"
            f"{stats.class_count} 个类，{stats.method_count} 个方法，"
            f"{stats.instruction_count} 条指令，SHA-256={stats.sha256}"
        )

    if report.passed:
        print(
            f"[DEX门禁] PASS：已扫描 {len(report.dex_files)} 个 DEX，"
            "未命中已知高危确定性引用类型合同。"
        )
        return

    total_violations = len(report.contract_violations) + len(report.findings)
    print(f"[DEX门禁] FAIL：发现 {total_violations} 个阻断项。")
    for index, violation in enumerate(report.contract_violations, start=1):
        print(f"  合同 {index}. [{violation.code}] {violation.location}")
        print(f"     {violation.message}")
    for index, finding in enumerate(report.findings[:max_findings], start=1):
        location = (
            f"{finding.dex_entry}!{finding.class_descriptor}->"
            f"{finding.method_name}{finding.method_descriptor}"
            f" @0x{finding.offset_code_units:x}"
        )
        print(f"  {index}. [{finding.code}] {location}")
        print(f"     {finding.message}")
        if finding.allocation_offset_code_units is not None:
            print(
                "     new-instance 来源："
                f"@0x{finding.allocation_offset_code_units:x}"
            )
    omitted = len(report.findings) - max_findings
    if omitted > 0:
        print(f"  ... 其余 {omitted} 个阻断项请查看 JSON 报告。")


def write_json_report(report: ScanReport, destination: Path) -> None:
    """原子替换 JSON 报告，避免 CI 中留下半截文件。"""

    destination = destination.expanduser().resolve()
    destination.parent.mkdir(parents=True, exist_ok=True)
    temporary = destination.with_name(f".{destination.name}.{os.getpid()}.tmp")
    try:
        temporary.write_text(
            json.dumps(report.to_json_dict(), ensure_ascii=False, indent=2) + "\n",
            encoding="utf-8",
        )
        os.replace(temporary, destination)
    finally:
        if temporary.exists():
            temporary.unlink()


def build_argument_parser() -> argparse.ArgumentParser:
    parser = argparse.ArgumentParser(
        description=(
            "扫描最终 APK 全部 classes*.dex 的已知高危"
            "确定性引用类型合同破坏。"
        )
    )
    parser.add_argument("apk", type=Path, help="待检查的最终 APK 路径")
    parser.add_argument(
        "--artifact-kind",
        choices=SUPPORTED_ARTIFACT_KINDS,
        default=ARTIFACT_KIND_TEMPLATE,
        help=(
            "制品阶段：template 校验全部模板合同；"
            "injected 保留 marker、APK/DEX 完整性和高危类型扫描"
        ),
    )
    parser.add_argument(
        "--json-report",
        type=Path,
        help="可选：写入完整 JSON 报告，便于 CI 归档",
    )
    parser.add_argument(
        "--expected-shell-version",
        type=int,
        help=(
            "必填的发布壳版本；生成跨 DEX 协议 marker，"
            "template 模式还会严格校验 Manifest 版本"
        ),
    )
    parser.add_argument(
        "--max-findings",
        type=int,
        default=50,
        help="终端最多输出的阻断项数（默认 50）",
    )
    return parser


def main(argv: Optional[Sequence[str]] = None) -> int:
    args = build_argument_parser().parse_args(argv)
    if args.max_findings < 1:
        print("[DEX门禁] 参数错误：--max-findings 必须大于 0。", file=sys.stderr)
        return 2
    if args.expected_shell_version is not None and args.expected_shell_version < 1:
        print(
            "[DEX门禁] 参数错误：--expected-shell-version 必须大于 0。",
            file=sys.stderr,
        )
        return 2
    try:
        validate_scan_options(args.artifact_kind, args.expected_shell_version)
        report = scan_apk(
            args.apk,
            expected_shell_version=args.expected_shell_version,
            artifact_kind=args.artifact_kind,
        )
        print_report(report, args.max_findings)
        if args.json_report:
            write_json_report(report, args.json_report)
            print(f"[DEX门禁] JSON 报告：{args.json_report.expanduser().resolve()}")
        return 0 if report.passed else 1
    except GateExecutionError as exc:
        print(f"[DEX门禁] 执行错误：{exc}", file=sys.stderr)
        return 2
    except OSError as exc:
        print(f"[DEX门禁] 文件系统错误：{exc}", file=sys.stderr)
        return 2


if __name__ == "__main__":
    raise SystemExit(main())
