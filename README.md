# Composer Lock Diff CLI

[![CircleCI](https://circleci.com/gh/Sweetchuck/composer-lock-diff-cli/tree/1.x.svg?style=svg)](https://circleci.com/gh/Sweetchuck/composer-lock-diff-cli/?branch=1.x)
[![codecov](https://codecov.io/gh/Sweetchuck/composer-lock-diff-cli/branch/1.x/graph/badge.svg?token=HSF16OGPyr)](https://app.codecov.io/gh/Sweetchuck/composer-lock-diff-cli/branch/1.x)


## Example usage 01

**Command:**
```shell
    commit_hash='abc1234'
    composer-lock-diff report \
        <(git show "${commit_hash}^:composer.lock" 2>/dev/null) \
        <(git show "${commit_hash}:composer.lock") \
        <(git show "${commit_hash}^:composer.json" 2>/dev/null) \
        <(git show "${commit_hash}:composer.json")
```

**Output:**
```text
+------------------+---------+---------+-------------+-----------------+
| Name             | Before  | After   | Required    | Direct          |
+------------------+---------+---------+-------------+-----------------+
| vendor1/project1 | v4.8.0  | v4.9.0  | dev  : dev  | child  : child  |
| vendor2/project2 | 4.1.22  | 4.2.2   | dev  : dev  | direct : direct |
+------------------+---------+---------+-------------+-----------------+
```
