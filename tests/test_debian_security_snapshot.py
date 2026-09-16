#!/usr/bin/env python3
"""验证正式 Dockerfile 的安全源修复，不联网且不修改本机 APT 配置。"""

from __future__ import annotations

import re
import shlex
import subprocess
import unittest
from pathlib import Path


DOCKERFILE = Path(__file__).resolve().parents[1] / "Dockerfile"

# 2026-09-17 从生产基础镜像读取的原始源配置，保留镜像自带的历史注释。
PRODUCTION_SOURCES = """# deb http://snapshot.debian.org/archive/debian/20221114T000000Z bullseye main
deb http://deb.debian.org/debian bullseye main
# deb http://snapshot.debian.org/archive/debian-security/20221114T000000Z bullseye-security main
deb http://deb.debian.org/debian-security bullseye-security main
# deb http://snapshot.debian.org/archive/debian/20221114T000000Z bullseye-updates main
deb http://deb.debian.org/debian bullseye-updates main
"""


class DebianSecuritySnapshotTests(unittest.TestCase):
    """以实际构建命令为被测对象，约束安全源替换的影响范围。"""

    @classmethod
    def setUpClass(cls) -> None:
        cls.dockerfile = DOCKERFILE.read_text(encoding="utf-8")
        commands = [
            shlex.split(line.removeprefix("RUN ").removesuffix(" \\"))
            for line in cls.dockerfile.splitlines()
            if line.startswith("RUN sed ")
        ]
        if len(commands) != 1:
            raise AssertionError("正式构建必须只维护一条安全源替换命令")
        command = commands[0]
        if command[:3] != ["sed", "-i", "-E"] or command[-1] != "/etc/apt/sources.list":
            raise AssertionError("安全源替换命令与回归执行合同不一致")
        if len(command) != 5:
            raise AssertionError("安全源替换须使用单一表达式")
        cls.expression = command[3]

    def rewrite_sources(self, sources: str) -> str:
        """执行构建中的真实表达式，仅去掉原地写入选项以隔离系统配置。"""
        return subprocess.run(
            ["sed", "-E", self.expression],
            input=sources,
            text=True,
            check=True,
            capture_output=True,
        ).stdout

    def test_real_source_fixture_rewrites_only_security_line(self) -> None:
        before = PRODUCTION_SOURCES.splitlines()
        after = self.rewrite_sources(PRODUCTION_SOURCES).splitlines()
        self.assertEqual(len(before), len(after))
        self.assertEqual([i for i, pair in enumerate(zip(before, after)) if pair[0] != pair[1]], [3])
        self.assertRegex(
            after[3],
            r"^deb \[check-valid-until=no\] https://snapshot\.debian\.org/"
            r"archive/debian-security/\d{8}T\d{6}Z/ bullseye-security main$",
        )

    def test_main_updates_and_historical_comments_remain_unchanged(self) -> None:
        before = PRODUCTION_SOURCES.splitlines()
        after = self.rewrite_sources(PRODUCTION_SOURCES).splitlines()
        self.assertEqual([after[i] for i in (0, 1, 2, 4, 5)], [before[i] for i in (0, 1, 2, 4, 5)])

    def test_https_security_source_is_also_rewritten(self) -> None:
        https_sources = PRODUCTION_SOURCES.replace(
            "deb http://deb.debian.org/debian-security ",
            "deb https://deb.debian.org/debian-security ",
        )
        self.assertEqual(self.rewrite_sources(https_sources), self.rewrite_sources(PRODUCTION_SOURCES))

    def test_repeated_execution_is_idempotent(self) -> None:
        rewritten = self.rewrite_sources(PRODUCTION_SOURCES)
        self.assertEqual(self.rewrite_sources(rewritten), rewritten)

    def test_snapshot_date_has_one_authoritative_definition(self) -> None:
        timestamps = re.findall(r"/archive/debian-security/(\d{8}T\d{6}Z)/", self.dockerfile)
        self.assertEqual(len(timestamps), 1)
        rewritten = self.rewrite_sources(PRODUCTION_SOURCES)
        active_security_sources = [
            line for line in rewritten.splitlines()
            if line.startswith("deb ") and " bullseye-security " in line
        ]
        self.assertEqual(len(active_security_sources), 1)
        self.assertIn("/" + timestamps[0] + "/", active_security_sources[0])

    def test_repository_signature_verification_remains_enabled(self) -> None:
        active_commands = "\n".join(
            line for line in self.dockerfile.splitlines() if not line.lstrip().startswith("#")
        )
        for forbidden in (
            r"trusted\s*=\s*yes",
            r"allow-insecure\s*=\s*yes",
            r"allow-weak\s*=\s*yes",
            r"allow-downgrade-to-insecure\s*=\s*yes",
            r"--allow-unauthenticated",
            r"AllowUnauthenticated\s*=\s*(?:true|yes|1)",
            r"AllowInsecureRepositories\s*=\s*(?:true|yes|1)",
            r"AllowWeakRepositories\s*=\s*(?:true|yes|1)",
            r"AllowDowngradeToInsecureRepositories\s*=\s*(?:true|yes|1)",
        ):
            self.assertIsNone(re.search(forbidden, active_commands, flags=re.IGNORECASE), forbidden)
        self.assertEqual(self.dockerfile.count("check-valid-until=no"), 1)

    def test_php_base_and_strict_index_update_remain_pinned(self) -> None:
        self.assertEqual(self.dockerfile.splitlines()[0], "FROM php:7.4-cli-bullseye")
        self.assertIn("APT::Update::Error-Mode=any update", self.dockerfile)
        self.assertNotIn("--fix-missing", self.dockerfile)
        self.assertIn("&& grep -Fq 'https://snapshot.debian.org/archive/debian-security/'", self.dockerfile)


if __name__ == "__main__":
    unittest.main()
