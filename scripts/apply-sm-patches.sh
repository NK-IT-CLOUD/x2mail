#!/usr/bin/env bash
# ------------------------------------------------------------------
# apply-sm-patches.sh — PHP 8.4 compatibility patches for SnappyMail
# ------------------------------------------------------------------
# Re-applies all patches after update-core.sh replaces libraries.
# Safe to run multiple times (sed guards with grep checks).
# ------------------------------------------------------------------
set -euo pipefail

SM_VERSION=$(cat /opt/x2mail/SM_VERSION 2>/dev/null || echo "2.38.2")
LIB="/opt/x2mail/app/snappymail/v/${SM_VERSION}/app/libraries"

ok()   { echo "  [OK] $1"; }
skip() { echo "  [SKIP] $1 (already patched)"; }
fail() { echo "  [FAIL] $1"; exit 1; }

echo "=== Applying PHP 8.4 patches to SnappyMail ${SM_VERSION} ==="

# ------------------------------------------------------------------
# 1. Logger.php — remove E_STRICT, guard array lookups
# ------------------------------------------------------------------
FILE="${LIB}/MailSo/Log/Logger.php"
echo "--- Logger.php ---"

# Remove E_STRICT from PHP_TYPES
if grep -q 'E_STRICT.*LOG_CRIT' "$FILE" 2>/dev/null; then
    sed -i '/\\E_STRICT => \\LOG_CRIT,/d' "$FILE"
    ok "Removed E_STRICT from PHP_TYPES"
else
    skip "E_STRICT already removed from PHP_TYPES"
fi

# Remove E_STRICT from PHP_TYPE_POSTFIX
if grep -q "E_STRICT => '-STRICT'" "$FILE" 2>/dev/null; then
    sed -i "/\\\\E_STRICT => '-STRICT',/d" "$FILE"
    ok "Removed E_STRICT from PHP_TYPE_POSTFIX"
else
    skip "E_STRICT already removed from PHP_TYPE_POSTFIX"
fi

# Guard array lookups in __phpErrorHandler with isset()
if grep -q 'static::PHP_TYPES\[$iErrNo\],$' "$FILE" 2>/dev/null; then
    sed -i 's/static::PHP_TYPES\[\$iErrNo\],$/isset(static::PHP_TYPES[$iErrNo]) ? static::PHP_TYPES[$iErrNo] : \\LOG_ERR,/' "$FILE"
    ok "Guarded PHP_TYPES lookup"
else
    skip "PHP_TYPES lookup already guarded"
fi

if grep -q "'PHP' \. static::PHP_TYPE_POSTFIX\[\$iErrNo\]" "$FILE" 2>/dev/null; then
    sed -i "s/'PHP' \. static::PHP_TYPE_POSTFIX\[\\\$iErrNo\]/'PHP' . (isset(static::PHP_TYPE_POSTFIX[\$iErrNo]) ? static::PHP_TYPE_POSTFIX[\$iErrNo] : '')/" "$FILE"
    ok "Guarded PHP_TYPE_POSTFIX lookup"
else
    skip "PHP_TYPE_POSTFIX lookup already guarded"
fi

# ------------------------------------------------------------------
# 2. ConnectSettings.php — guard 'secure' key access
# ------------------------------------------------------------------
FILE="${LIB}/MailSo/Net/ConnectSettings.php"
echo "--- ConnectSettings.php ---"

if grep -q "isset(\$aSettings\['type'\]) ? \$aSettings\['type'\] : \$aSettings\['secure'\]" "$FILE" 2>/dev/null; then
    sed -i "s|isset(\$aSettings\['type'\]) ? \$aSettings\['type'\] : \$aSettings\['secure'\]|\$aSettings['type'] ?? \$aSettings['secure'] ?? \\\\MailSo\\\\Net\\\\Enumerations\\\\ConnectionSecurityType::AUTO_DETECT|" "$FILE"
    ok "Guarded 'secure' key access"
else
    skip "'secure' key already guarded"
fi

# ------------------------------------------------------------------
# 3. PGP/GPG — implicit nullable &$plaintext parameters
# ------------------------------------------------------------------
echo "--- PGP/GPG implicit nullable ---"

PGP_FILES=(
    "${LIB}/snappymail/pgp/pgpinterface.php"
    "${LIB}/snappymail/pgp/pecl.php"
    "${LIB}/snappymail/gpg/base.php"
    "${LIB}/snappymail/gpg/smime.php"
    "${LIB}/snappymail/gpg/pgp.php"
)

for FILE in "${PGP_FILES[@]}"; do
    BASENAME=$(basename "$FILE")
    # Match only unpatched: "string &$plaintext = null" NOT preceded by "?"
    if grep -qP '(?<!\?)string &\$plaintext = null' "$FILE" 2>/dev/null; then
        sed -i -E 's/([^?])string &\$plaintext = null/\1?string \&$plaintext = null/g; s/^string &\$plaintext = null/?string \&$plaintext = null/g' "$FILE"
        ok "${BASENAME}: fixed implicit nullable \$plaintext"
    else
        skip "${BASENAME}: already patched"
    fi
done

# ------------------------------------------------------------------
# 4. Sabre VObject — implicit nullable typed parameters
# ------------------------------------------------------------------
echo "--- Sabre VObject implicit nullable ---"

# Document.php: createProperty parameters
FILE="${LIB}/Sabre/VObject/Document.php"
if grep -q 'array \$parameters = null, string \$valueType = null' "$FILE" 2>/dev/null; then
    sed -i 's/array \$parameters = null, string \$valueType = null/?array $parameters = null, ?string $valueType = null/' "$FILE"
    ok "Document.php: createProperty"
else
    skip "Document.php: createProperty"
fi

# Property.php: __construct string $group
FILE="${LIB}/Sabre/VObject/Property.php"
if grep -q 'array \$parameters = \[\], string \$group = null)' "$FILE" 2>/dev/null; then
    sed -i 's/array \$parameters = \[\], string \$group = null)/array $parameters = [], ?string $group = null)/' "$FILE"
    ok "Property.php: __construct"
else
    skip "Property.php: __construct"
fi

# Property/Text.php: __construct string $group
FILE="${LIB}/Sabre/VObject/Property/Text.php"
if grep -q 'array \$parameters = \[\], string \$group = null)' "$FILE" 2>/dev/null; then
    sed -i 's/array \$parameters = \[\], string \$group = null)/array $parameters = [], ?string $group = null)/' "$FILE"
    ok "Property/Text.php: __construct"
else
    skip "Property/Text.php: __construct"
fi

# FreeBusyGenerator.php: __construct and setTimeRange
FILE="${LIB}/Sabre/VObject/FreeBusyGenerator.php"
if grep -q '\\DateTimeInterface \$start = null, \\DateTimeInterface \$end = null, \$objects = null, \\DateTimeZone \$timeZone = null' "$FILE" 2>/dev/null; then
    sed -i 's/\\DateTimeInterface \$start = null, \\DateTimeInterface \$end = null, \$objects = null, \\DateTimeZone \$timeZone = null/?\\DateTimeInterface $start = null, ?\\DateTimeInterface $end = null, $objects = null, ?\\DateTimeZone $timeZone = null/' "$FILE"
    ok "FreeBusyGenerator.php: __construct"
else
    skip "FreeBusyGenerator.php: __construct"
fi
if grep -q 'setTimeRange(\\DateTimeInterface \$start = null, \\DateTimeInterface \$end = null)' "$FILE" 2>/dev/null; then
    sed -i 's/setTimeRange(\\DateTimeInterface \$start = null, \\DateTimeInterface \$end = null)/setTimeRange(?\\DateTimeInterface $start = null, ?\\DateTimeInterface $end = null)/' "$FILE"
    ok "FreeBusyGenerator.php: setTimeRange"
else
    skip "FreeBusyGenerator.php: setTimeRange"
fi

# ITip/Broker.php: processMessage* methods
FILE="${LIB}/Sabre/VObject/ITip/Broker.php"
# Match only unpatched: "VCalendar $existingObject = null" NOT preceded by "?"
if grep -qP '(?<!\?)VCalendar \$existingObject = null' "$FILE" 2>/dev/null; then
    sed -i -E 's/([^?])VCalendar \$existingObject = null/\1?VCalendar $existingObject = null/g' "$FILE"
    ok "ITip/Broker.php: processMessage methods"
else
    skip "ITip/Broker.php: processMessage methods"
fi

# Recur/EventIterator.php: __construct
FILE="${LIB}/Sabre/VObject/Recur/EventIterator.php"
if grep -q '__construct(\$input, string \$uid = null, \\DateTimeZone \$timeZone = null)' "$FILE" 2>/dev/null; then
    sed -i 's/__construct(\$input, string \$uid = null, \\DateTimeZone \$timeZone = null)/__construct($input, ?string $uid = null, ?\\DateTimeZone $timeZone = null)/' "$FILE"
    ok "Recur/EventIterator.php: __construct"
else
    skip "Recur/EventIterator.php: __construct"
fi

# DateTimeParser.php: parseDateTime and parseDate
FILE="${LIB}/Sabre/VObject/DateTimeParser.php"
if grep -q 'parseDateTime(string \$dt, \\DateTimeZone \$tz = null)' "$FILE" 2>/dev/null; then
    sed -i 's/parseDateTime(string \$dt, \\DateTimeZone \$tz = null)/parseDateTime(string $dt, ?\\DateTimeZone $tz = null)/' "$FILE"
    ok "DateTimeParser.php: parseDateTime"
else
    skip "DateTimeParser.php: parseDateTime"
fi
if grep -q 'parseDate(string \$date, \\DateTimeZone \$tz = null)' "$FILE" 2>/dev/null; then
    sed -i 's/parseDate(string \$date, \\DateTimeZone \$tz = null)/parseDate(string $date, ?\\DateTimeZone $tz = null)/' "$FILE"
    ok "DateTimeParser.php: parseDate"
else
    skip "DateTimeParser.php: parseDate"
fi

# Component/VCalendar.php: getBaseComponents, getBaseComponent, expand
FILE="${LIB}/Sabre/VObject/Component/VCalendar.php"
if grep -q 'getBaseComponents(string \$componentName = null)' "$FILE" 2>/dev/null; then
    sed -i 's/getBaseComponents(string \$componentName = null)/getBaseComponents(?string $componentName = null)/' "$FILE"
    ok "VCalendar.php: getBaseComponents"
else
    skip "VCalendar.php: getBaseComponents"
fi
if grep -q 'getBaseComponent(string \$componentName = null)' "$FILE" 2>/dev/null; then
    sed -i 's/getBaseComponent(string \$componentName = null)/getBaseComponent(?string $componentName = null)/' "$FILE"
    ok "VCalendar.php: getBaseComponent"
else
    skip "VCalendar.php: getBaseComponent"
fi
if grep -q 'expand(\\DateTimeInterface \$start, \\DateTimeInterface \$end, \\DateTimeZone \$timeZone = null)' "$FILE" 2>/dev/null; then
    sed -i 's/expand(\\DateTimeInterface \$start, \\DateTimeInterface \$end, \\DateTimeZone \$timeZone = null)/expand(\\DateTimeInterface $start, \\DateTimeInterface $end, ?\\DateTimeZone $timeZone = null)/' "$FILE"
    ok "VCalendar.php: expand"
else
    skip "VCalendar.php: expand"
fi

# Property/ICalendar/DateTime.php: getDateTime and getDateTimes
FILE="${LIB}/Sabre/VObject/Property/ICalendar/DateTime.php"
if grep -q 'getDateTime(\\DateTimeZone \$timeZone = null)' "$FILE" 2>/dev/null; then
    sed -i 's/getDateTime(\\DateTimeZone \$timeZone = null)/getDateTime(?\\DateTimeZone $timeZone = null)/' "$FILE"
    ok "ICalendar/DateTime.php: getDateTime"
else
    skip "ICalendar/DateTime.php: getDateTime"
fi
if grep -q 'getDateTimes(\\DateTimeZone \$timeZone = null)' "$FILE" 2>/dev/null; then
    sed -i 's/getDateTimes(\\DateTimeZone \$timeZone = null)/getDateTimes(?\\DateTimeZone $timeZone = null)/' "$FILE"
    ok "ICalendar/DateTime.php: getDateTimes"
else
    skip "ICalendar/DateTime.php: getDateTimes"
fi

echo ""
echo "=== All PHP 8.4 patches applied ==="
