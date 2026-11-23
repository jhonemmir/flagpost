#!/usr/bin/env python3
"""Validate the shared contract file without depending on PHP or .NET.

This is a third, independent implementation of the algorithm. It catches
corruption in the vectors and drift between the JSON and the documented rules.
"""

from __future__ import annotations

import hashlib
import json
import re
import struct
import sys
from pathlib import Path

ROOT = Path(__file__).resolve().parent.parent
CONTRACT = ROOT / "spec" / "vectors" / "evaluation.json"

KEY_PATTERN = re.compile(r"^[a-z0-9][a-z0-9._-]{0,63}$")
REASONS = {
    "NOT_FOUND",
    "DISABLED",
    "ALLOW_LIST",
    "FULL_ROLLOUT",
    "NO_ROLLOUT",
    "MISSING_USER_ID",
    "PERCENTAGE_IN",
    "PERCENTAGE_OUT",
}


def bucket(flag_key: str, user_id: str) -> tuple[str, int, int]:
    digest = hashlib.sha256(f"{flag_key}:{user_id}".encode("utf-8")).digest()
    first = digest[:4]
    value = struct.unpack(">I", first)[0]
    return first.hex(), value, value % 100


def evaluate(
    flag_key: str,
    flag: dict | None,
    user_id: str | None,
    default: bool,
) -> tuple[bool, str]:
    if flag is None:
        return default, "NOT_FOUND"
    if not flag["enabled"]:
        return False, "DISABLED"
    if user_id and user_id in flag["allowList"]:
        return True, "ALLOW_LIST"
    if flag["percentage"] == 100:
        return True, "FULL_ROLLOUT"
    if flag["percentage"] == 0:
        return False, "NO_ROLLOUT"
    if not user_id:
        return default, "MISSING_USER_ID"
    value = bucket(flag_key, user_id)[2] < flag["percentage"]
    return (True, "PERCENTAGE_IN") if value else (False, "PERCENTAGE_OUT")


def fail(message: str) -> None:
    print(f"error: {message}", file=sys.stderr)
    raise SystemExit(1)


def main() -> int:
    contract = json.loads(CONTRACT.read_text(encoding="utf-8"))

    if contract.get("vectorsVersion") != 1:
        fail("vectorsVersion must be 1")

    vectors = contract["bucketVectors"]
    cases = contract["evaluationCases"]
    ids = [item["id"] for item in vectors] + [item["id"] for item in cases]
    if len(ids) != len(set(ids)):
        fail("duplicate ids across bucketVectors and evaluationCases")

    for vector in vectors:
        hex_digest, value, expected = bucket(vector["flagKey"], vector["userId"])
        if hex_digest != vector["first4BytesHex"]:
            fail(f"{vector['id']}: first4BytesHex is {vector['first4BytesHex']}, expected {hex_digest}")
        if expected != vector["bucket"]:
            fail(f"{vector['id']}: bucket is {vector['bucket']}, expected {expected}")
        if value != int(hex_digest, 16):
            fail(f"{vector['id']}: hex and value disagree")

    for case in cases:
        if case["expected"]["reason"] not in REASONS:
            fail(f"{case['id']}: unknown reason {case['expected']['reason']}")
        if not KEY_PATTERN.fullmatch(case["flagKey"]):
            fail(f"{case['id']}: invalid flag key")

        flag = case["flag"]
        if flag is not None:
            percentage = flag["percentage"]
            if not isinstance(percentage, int) or isinstance(percentage, bool):
                fail(f"{case['id']}: percentage must be an integer")
            if not 0 <= percentage <= 100:
                fail(f"{case['id']}: percentage out of range")
            if not isinstance(flag["enabled"], bool):
                fail(f"{case['id']}: enabled must be a boolean")
            seen: set[str] = set()
            for entry in flag["allowList"]:
                if not isinstance(entry, str) or not entry:
                    fail(f"{case['id']}: allowList entries must be non-empty strings")
                if len(entry.encode("utf-8")) > 256:
                    fail(f"{case['id']}: allowList entry exceeds 256 bytes")
                if entry in seen:
                    fail(f"{case['id']}: duplicate allowList entry")
                seen.add(entry)
            if len(flag["allowList"]) > 1000:
                fail(f"{case['id']}: allowList exceeds 1000 entries")

        user_id = case["userId"]
        if user_id is not None and not isinstance(user_id, str):
            fail(f"{case['id']}: userId must be a string or null")

        actual_value, actual_reason = evaluate(
            case["flagKey"], flag, user_id, case["defaultValue"]
        )
        expected = case["expected"]
        if (actual_value, actual_reason) != (expected["value"], expected["reason"]):
            fail(
                f"{case['id']}: got {actual_value}/{actual_reason}, "
                f"expected {expected['value']}/{expected['reason']}"
            )

    print(
        f"ok: {len(vectors)} bucket vectors and {len(cases)} evaluation cases "
        f"match the reference implementation (vectorsVersion={contract['vectorsVersion']})"
    )
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
