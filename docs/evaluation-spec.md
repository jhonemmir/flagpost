# Evaluation spec — flagpost 1.0

This document is the **executable specification** of the flag evaluation algorithm. It is implemented twice, in PHP (the API's `explain` endpoint) and in C# (the SDK), and both implementations must produce identical results.

The machine-readable contract is [`spec/vectors/evaluation.json`](../spec/vectors/evaluation.json). Every bucket vector and evaluation case in that file becomes a test in **both** languages. An implementation is not correct until all of them pass.

Related: [ADR 0001 — Design decisions](adr/0001-design-decisions.md), [API contract](api-contract.md).

## 1. Contract

```
Evaluate(flagKey, flag, userId, defaultValue) -> (value, reason)
```

| Input | Type | Meaning |
|---|---|---|
| `flagKey` | string | The flag key. Must match the key grammar (section 5). |
| `flag` | `FlagConfig` or null | The configuration of this flag **in one environment**; null if the flag is unknown. |
| `userId` | string or null | An opaque identifier of the subject. Null or empty means "no user". |
| `defaultValue` | bool | Returned when the flag cannot be evaluated for lack of information. |

`FlagConfig`:

| Field | Type | Rules |
|---|---|---|
| `enabled` | bool | Master switch. `false` always evaluates to `false`. |
| `percentage` | integer 0 to 100 | Share of users that receive `true`. |
| `allowList` | array of strings | User ids that always receive `true` while the flag is enabled. |

Properties of the function:

- **Pure and deterministic:** no I/O, no clock, no randomness, no culture-sensitive operations.
- **Never throws:** every input combination produces a result.
- **Result:** a boolean `value` and a stable `reason` code.

## 2. Normative algorithm

Steps are evaluated **in order**; the first one that applies returns.

| Step | Condition | Result | Reason |
|---|---|---|---|
| 1 | `flag` is null | `defaultValue` | `NOT_FOUND` |
| 2 | `flag.enabled` is false | `false` | `DISABLED` |
| 3 | `userId` is non-empty and appears in `allowList` | `true` | `ALLOW_LIST` |
| 4 | `percentage` is 100 | `true` | `FULL_ROLLOUT` |
| 5 | `percentage` is 0 | `false` | `NO_ROLLOUT` |
| 6 | `userId` is null or empty | `defaultValue` | `MISSING_USER_ID` |
| 7 | `Bucket(flagKey, userId) < percentage` | `true` | `PERCENTAGE_IN` |
| 7 | otherwise | `false` | `PERCENTAGE_OUT` |

### 2.1 Bucket function

```
Bucket(flagKey, userId):
    bytes  = UTF-8 encoding of  flagKey + ":" + userId
    digest = SHA-256(bytes)
    n      = first 4 bytes of digest as a big-endian unsigned 32-bit integer
    return n mod 100            # range 0..99
```

- The separator `:` is unambiguous because the key grammar forbids `:` in flag keys.
- The `userId` is used **exactly as received**: no trimming, no case folding, no Unicode normalization.
- `2^32 mod 100 = 96`, so buckets 0 to 95 are marginally more likely than 96 to 99 (a bias of about 2 in 10 million). It is accepted and documented rather than corrected, to keep the algorithm trivial to port.

**Implementation note.** Use an unsigned 32-bit type: `uint` in C#, and `unpack('N', ...)` in PHP, which yields a non-negative integer on 64-bit builds. Do not use signed 32-bit integers, because a negative `n` changes the modulo result.

## 3. Invariants

1. Same inputs produce the same output in every implementation and on every platform.
2. No input throws; every problem maps to a result.
3. **Monotonic rollout:** for a fixed user and flag, if the result is `PERCENTAGE_IN` at `p1`, it is also `true` at any `p2 >= p1`. Raising a percentage never removes a user.
4. **Independence:** the bucket depends on the flag key, so a user's position in one rollout says nothing about another flag.
5. `userId` is opaque. Matching against `allowList` is ordinal, case-sensitive and exact.
6. An empty `userId` is equivalent to a missing one.
7. `DISABLED` and `NOT_FOUND` are distinct reasons; only `NOT_FOUND` and `MISSING_USER_ID` return `defaultValue`.
8. Reason codes are stable and machine-readable. They never change within a major version.

## 4. Reason catalog

| Code | Returns | Meaning |
|---|---|---|
| `NOT_FOUND` | `defaultValue` | The flag does not exist (or is not in the snapshot) |
| `DISABLED` | `false` | The flag is switched off in this environment |
| `ALLOW_LIST` | `true` | The user is in the allow list |
| `FULL_ROLLOUT` | `true` | Percentage is 100 |
| `NO_ROLLOUT` | `false` | Percentage is 0 |
| `MISSING_USER_ID` | `defaultValue` | A partial rollout needs a user id and none was given |
| `PERCENTAGE_IN` | `true` | The user's bucket is below the percentage |
| `PERCENTAGE_OUT` | `false` | The user's bucket is at or above the percentage |

**SDK-only reason.** The C# SDK additionally returns `NOT_READY` (with `defaultValue`) before its first successful fetch. It is not part of the shared vectors because it describes the client's state, not the algorithm.

## 5. Validation rules (API side)

The evaluation function trusts its input. The API validates configuration before it is stored:

| Field | Rule |
|---|---|
| Flag key | `^[a-z0-9][a-z0-9._-]{0,63}$` |
| `percentage` | A JSON number token with no decimal point and no exponent, from 0 to 100. `50.5`, `50.0`, `1e2` and `"50"` are all rejected, even when the value is integral. |
| `allowList` | At most 1000 entries; each entry a non-empty string of at most 256 **bytes** in UTF-8 (byte length, not character count). Entries must be valid UTF-8: unpaired surrogates are rejected. Duplicates are removed, preserving order. |
| `enabled` | JSON boolean. No coercion: `"true"` and `1` are rejected. |

**Allow-list budget.** The API also enforces a per-environment budget on allow-list data (1 MiB, see the API contract, section 7). The budget exists so the SDK payload stays small; it is not part of the evaluation algorithm.

**Why `50.0` is rejected.** JSON does not distinguish `50` from `50.0`, but PHP does (`int` vs `float`). Requiring an `int` after decoding keeps the stored value, the `explain` response and the SDK payload unambiguous in every language.

**Why byte length matters.** `strlen()` in PHP counts bytes; `mb_strlen()` counts characters. A single emoji is 4 bytes, so a 256-character limit enforced with `mb_strlen()` would accept ids that break the contract.

## 6. Cases

The 30 ids below match `evaluationCases[].id` in the JSON file. Users such as `user-466` were found by search so that their bucket sits exactly on a boundary for `new-checkout`.

Some cases exist to pin the **order of the steps**: E26 (disabled before missing user), E27 (allow list before full rollout) and E28 (no rollout before missing user). An implementation with two steps swapped can return the same boolean but a different reason, and the reason is part of the contract.

### A. Missing and disabled flags

| ID | Scenario | Config | User | Default | Expected |
|---|---|---|---|---|---|
| E01 | Flag missing | null | `alice` | false | `false` `NOT_FOUND` |
| E02 | Flag missing, default true | null | `alice` | true | `true` `NOT_FOUND` |
| E03 | Disabled beats allow list | off, 100%, allow `alice` | `alice` | false | `false` `DISABLED` |
| E04 | Disabled at 100% ignores default | off, 100% | `alice` | true | `false` `DISABLED` |
| E26 | Disabled beats missing user id | off, 50% | null | true | `false` `DISABLED` |

### B. Allow list

| ID | Scenario | Config | User | Default | Expected |
|---|---|---|---|---|---|
| E05 | Match at 0% | on, 0%, allow `alice` | `alice` | false | `true` `ALLOW_LIST` |
| E06 | Overrides an outside bucket | on, 50%, allow `user-241` | `user-241` | false | `true` `ALLOW_LIST` |
| E07 | Case-sensitive at 0% | on, 0%, allow `Alice` | `alice` | false | `false` `NO_ROLLOUT` |
| E08 | No user falls through | on, 100%, allow `alice` | null | false | `true` `FULL_ROLLOUT` |
| E27 | Allow list beats full rollout | on, 100%, allow `alice` | `alice` | false | `true` `ALLOW_LIST` |
| E29 | Case-sensitive with the bucket outside | on, 50%, allow `Alice` | `alice` | true | `false` `PERCENTAGE_OUT` |
| E30 | 256-byte id in the allow list | on, 50%, allow 256×`u` | 256×`u` | false | `true` `ALLOW_LIST` |

### C. Percentage extremes and missing users

| ID | Scenario | Config | User | Default | Expected |
|---|---|---|---|---|---|
| E09 | 100% needs no user | on, 100% | null | false | `true` `FULL_ROLLOUT` |
| E10 | 0% ignores default | on, 0% | `alice` | true | `false` `NO_ROLLOUT` |
| E11 | Partial, no user | on, 50% | null | false | `false` `MISSING_USER_ID` |
| E12 | Partial, no user, default true | on, 50% | null | true | `true` `MISSING_USER_ID` |
| E13 | Empty id is "missing" | on, 50% | `""` | true | `true` `MISSING_USER_ID` |
| E28 | 0% beats a missing user id | on, 0% | null | true | `false` `NO_ROLLOUT` |

### D. Bucket boundaries

| ID | Scenario | Config | User (bucket) | Default | Expected |
|---|---|---|---|---|---|
| E14 | bucket = p - 1 | on, 50% | `user-466` (49) | false | `true` `PERCENTAGE_IN` |
| E15 | bucket = p | on, 50% | `user-116` (50) | true | `false` `PERCENTAGE_OUT` |
| E16 | 1%, bucket 0 | on, 1% | `user-42` (0) | false | `true` `PERCENTAGE_IN` |
| E17 | 99%, bucket 99 | on, 99% | `user-17` (99) | true | `false` `PERCENTAGE_OUT` |
| E18 | 99%, bucket 98 | on, 99% | `user-171` (98) | false | `true` `PERCENTAGE_IN` |

### E. Opaque user ids

All use `on, 50%`. Expected values are in the JSON file.

| ID | Scenario | User |
|---|---|---|
| E19 | Latin with diacritics | `ñandú` |
| E20 | Emoji | `🚀` |
| E21 | CJK | `日本語` |
| E22 | 256-character id | 256 times `u` |
| E23 | Contains the separator | `a:b` |
| E24 | Leading space is significant | ` alice` |
| E25 | Different flag key (`dark-mode`) | `alice` |

### F. Bucket vectors

`bucketVectors` (B01 to B20) check the hash step in isolation, including the first four digest bytes in hex. When an evaluation case fails, check the bucket vectors first: a wrong byte order or a signed integer shows up there.

## 7. Test skeletons

### C# (xUnit)

```csharp
public class EvaluationContractTests
{
    private static readonly ContractFile Contract = ContractFile.Load();

    public static IEnumerable<object[]> BucketVectors() =>
        Contract.BucketVectors.Select(v => new object[] { v.Id, v.FlagKey, v.UserId, v.First4BytesHex, v.Bucket });

    [Theory]
    [MemberData(nameof(BucketVectors))]
    public void Bucket_MatchesSharedVector(string id, string flagKey, string userId, string first4BytesHex, int expected)
    {
        var (hex, value, bucket) = Bucketing.BucketDetailed(flagKey, userId);
        var expectedValue = Convert.ToUInt32(first4BytesHex, 16);

        Assert.Equal(first4BytesHex, hex);      // digest input and byte offset
        Assert.Equal(expectedValue, value);     // unsigned, big-endian decoding
        Assert.Equal(expected, bucket);         // bucket derived from the value
    }
}
```

### PHP (Pest)

```php
dataset('bucket_vectors', fn () => ContractFile::load()->bucketVectors());

it('matches the shared bucket vector', function (array $v) {
    $result = Bucketing::bucketDetailed($v['flagKey'], $v['userId']);
    $expectedValue = hexdec($v['first4BytesHex']);

    expect($result['hex'])->toBe($v['first4BytesHex'])
        ->and($result['value'])->toBe($expectedValue)
        ->and($result['bucket'])->toBe($v['bucket']);
})->with('bucket_vectors');
```

`Bucket()` is a thin wrapper over `BucketDetailed()`, which returns the first four digest bytes as hex, the decoded unsigned 32-bit value and the bucket. Asserting only the bucket leaves a hole, because different decodings can land on the same bucket by chance: measured on the current vectors, a little-endian decoding is invisible in 2 of 20 buckets and a signed-integer decoding in 5 of 20. Asserting the digest bytes and the unsigned value makes each class of error deterministic.

Both test projects read the **same file** from the repository (`spec/vectors/evaluation.json`); nobody keeps a copy.

### Property test (both languages)

The property test uses an **independent oracle**, not a restatement of the same arithmetic. For 1,000 generated user ids and random configurations:

1. **Precedence:** the result matches a small reference implementation written directly from the table in section 2, kept separate from the production evaluator and sharing no helper with it.
2. **Determinism:** evaluating the same inputs twice returns the same value and reason.
3. **Monotonic rollout (invariant 3):** if the result is `PERCENTAGE_IN` at `p`, the value is `true` at `p + 1`.
4. **Totality:** no generated input throws, including null, empty, 256-byte, emoji, colon and leading-space ids.

A property test that only asserts "`bucket < p` implies `bucket < p + 1`" is arithmetic, not a test of the implementation: it passes even when the evaluator is wrong.

## 8. Rules for changing this spec

1. Add a case by appending a new ID to the JSON file **and** the table above. Never renumber.
2. Never add a case that only one implementation can pass.
3. Changing the hash, the separator or the modulo reassigns every user in every rollout. It is a breaking change: bump `vectorsVersion` in the JSON file, bump `schemaVersion` in the SDK payload, and update ADR 0001 first.
4. New reason codes are additive; removing or renaming one is breaking.
5. `vectorsVersion` describes the format of `evaluation.json`; `schemaVersion` describes the wire format of `GET /v1/sdk/flags`. They are separate counters and change independently.

## 9. Out of scope for v1.0

- Targeting by user attributes (country, plan, etc.) and rule combinations.
- Multivariate flags (strings, numbers, JSON values). Flags are boolean.
- Sticky assignments stored on the server.
- Scheduled rollouts.
- Unicode normalization of user ids.

---

## Resumen en español

Esta es la especificación ejecutable de cómo se decide si un usuario recibe una función: **30 casos de evaluación y 20 vectores del hash**, definidos una sola vez en `spec/vectors/evaluation.json` y probados tanto en PHP como en C#.

- **Orden fijo:** flag inexistente, desactivado, lista de permitidos, 100%, 0%, usuario ausente y, por último, el porcentaje.
- **Hash determinista:** SHA-256 de `flagKey:userId`, primeros 4 bytes como entero sin signo, módulo 100. Un usuario siempre cae en el mismo grupo, y subir el porcentaje nunca le quita la función a nadie.
- **`userId` opaco:** sin recortar espacios, sin cambiar mayúsculas y sin normalizar Unicode; vacío equivale a ausente.
- **Validación:** el porcentaje debe ser un entero sin punto decimal (`50.0` y `1e2` se rechazan), las entradas de la lista se miden en bytes UTF-8 y el total de listas por entorno está acotado a 1 MiB.
- **Mantenimiento:** cada caso nuevo exige una fila en la tabla y una entrada en el JSON; cambiar el hash es un cambio incompatible. `vectorsVersion` (formato del JSON) y `schemaVersion` (protocolo HTTP) son contadores distintos.
