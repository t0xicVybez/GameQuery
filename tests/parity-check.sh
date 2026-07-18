#!/usr/bin/env bash
#
# Fails if the PHP and Node ports have drifted apart: mismatched protocol
# registry keys or mismatched protocol class sets. The whole promise of this
# project is that the two ports stay identical, so CI enforces it.
#
# Requires the Node port to be built first (node/dist present).
set -euo pipefail
cd "$(dirname "$0")/.."

php_keys=$(php -r '
    require "autoload.php";
    $r = new GameQuery\ProtocolRegistry();
    $ref = new ReflectionObject($r);
    foreach ($ref->getProperties() as $p) {
        $p->setAccessible(true);
        $v = $p->getValue($r);
        if (is_array($v)) { $k = array_keys($v); sort($k); echo implode("\n", $k); }
    }
')

ts_keys=$(node --input-type=module -e '
    import { ProtocolRegistry } from "./node/dist/ProtocolRegistry.js";
    const r = new ProtocolRegistry();
    console.log([...r.factories.keys()].sort().join("\n"));
')

if [ "$php_keys" != "$ts_keys" ]; then
    echo "PARITY FAIL: protocol registry keys differ between PHP and Node"
    diff <(echo "$php_keys") <(echo "$ts_keys") || true
    exit 1
fi

php_classes=$(cd src/Protocol && ls *.php | sed 's/\.php$//' | grep -vE '^(AbstractProtocol|ProtocolInterface)$' | sort)
ts_classes=$(cd node/src/protocol && ls *.ts | sed 's/\.ts$//' | grep -vE '^(AbstractProtocol|ProtocolInterface|http)$' | sort)

if [ "$php_classes" != "$ts_classes" ]; then
    echo "PARITY FAIL: protocol class sets differ between PHP and Node"
    diff <(echo "$php_classes") <(echo "$ts_classes") || true
    exit 1
fi

key_count=$(echo "$php_keys" | grep -c . || true)
class_count=$(echo "$php_classes" | grep -c . || true)
echo "PARITY OK: ${key_count} registry keys and ${class_count} protocol classes match across both ports"
