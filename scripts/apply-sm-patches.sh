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

# ── Chrome 117+ RegExp v-flag compat ──────────────────────────────
echo ""
echo "--- Browser compat patches ---"

# PopupsFolderCreate.html: invalid regex pattern with escaped backslash in character class
SM_ROOT="/opt/x2mail/app/snappymail/v/${SM_VERSION}"
FILE="${SM_ROOT}/app/templates/Views/User/PopupsFolderCreate.html"
if grep -q 'pattern="\^\\[\\^\\\\\\\\\/\\]\\+\$"' "$FILE" 2>/dev/null || grep -q 'pattern="\^\[^\\\\/\]+\$"' "$FILE" 2>/dev/null; then
    sed -i 's|pattern="\^[^\\/]*\$"|pattern="^[^/]+$"|' "$FILE"
    # Fallback: direct replacement
    sed -i 's|pattern="\^\[^\\\\/\]+\$"|pattern="^[^/]+$"|' "$FILE"
    ok "PopupsFolderCreate.html: regex pattern"
else
    skip "PopupsFolderCreate.html: regex pattern"
fi

# ── Security fixes (Audit 2026-03-18) ────────────────────────────
echo ""
echo "--- Security audit patches ---"

# S1. OAuth2/Client.php — enable SSL verification when no certificate_file
FILE="${LIB}/OAuth2/Client.php"
if grep -q 'CURLOPT_SSL_VERIFYPEER, false' "$FILE" 2>/dev/null; then
    sed -i 's/CURLOPT_SSL_VERIFYPEER, false/CURLOPT_SSL_VERIFYPEER, true/' "$FILE"
    sed -i 's/CURLOPT_SSL_VERIFYHOST, 0/CURLOPT_SSL_VERIFYHOST, 2/' "$FILE"
    ok "OAuth2/Client.php: SSL verification enabled"
else
    skip "OAuth2/Client.php: SSL verification already enabled"
fi

# S2. HTTP Socket — replace removed \split() with \explode()
FILE="${LIB}/snappymail/http/request/socket.php"
if grep -q '\\split(' "$FILE" 2>/dev/null; then
    sed -i "s/\\\\split('/\\\\explode('/g" "$FILE"
    ok "socket.php: replaced \\split() with \\explode()"
else
    skip "socket.php: \\split() already replaced"
fi

# S3. HTTP Socket — \random_int() needs arguments
if grep -q '\\random_int()' "$FILE" 2>/dev/null; then
    sed -i 's/\\random_int()/\\random_int(0, PHP_INT_MAX)/' "$FILE"
    ok "socket.php: fixed \\random_int() arguments"
else
    skip "socket.php: \\random_int() already fixed"
fi

# S4. ImapClient.php — remove OAUTHBEARER from PLAIN/SCRAM branch
FILE="${LIB}/MailSo/Imap/ImapClient.php"
if grep -q "'OAUTHBEARER' === \$type || \\\\str_starts_with" "$FILE" 2>/dev/null; then
    sed -i "s/'OAUTHBEARER' === \$type || \\\\str_starts_with/\\\\str_starts_with/" "$FILE"
    ok "ImapClient.php: removed OAUTHBEARER from PLAIN/SCRAM branch"
else
    skip "ImapClient.php: OAUTHBEARER already removed from PLAIN/SCRAM branch"
fi

# S5. HTTP Request — verify_peer default true
FILE="${LIB}/snappymail/http/request.php"
if grep -q 'verify_peer = false' "$FILE" 2>/dev/null; then
    sed -i 's/verify_peer = false/verify_peer = true/' "$FILE"
    ok "request.php: verify_peer default set to true"
else
    skip "request.php: verify_peer already true"
fi

# S6. CURL — uncomment CURLOPT_SSL_VERIFYHOST
FILE="${LIB}/snappymail/http/request/curl.php"
if grep -q '//.*CURLOPT_SSL_VERIFYHOST' "$FILE" 2>/dev/null; then
    sed -i 's|//\s*CURLOPT_SSL_VERIFYHOST => \$this->verify_peer ? 2 : 0,|CURLOPT_SSL_VERIFYHOST => $this->verify_peer ? 2 : 0,|' "$FILE"
    ok "curl.php: CURLOPT_SSL_VERIFYHOST uncommented"
else
    skip "curl.php: CURLOPT_SSL_VERIFYHOST already uncommented"
fi

# S7. SASL CRAM — add undeclared $algo property
FILE="${LIB}/snappymail/sasl/cram.php"
if grep -q 'protected string \$algo;' "$FILE" 2>/dev/null; then
    skip "cram.php: \$algo property already declared"
else
    sed -i '/^class Cram extends/,/^{/ { /^{/a\\tprotected string $algo;' "$FILE"
    ok "cram.php: added \$algo property declaration"
fi

# S8. Contacts.php — remove deprecated auto_detect_line_endings
FILE="${LIB}/RainLoop/Actions/Contacts.php"
if grep -q 'auto_detect_line_endings' "$FILE" 2>/dev/null; then
    sed -i "/auto_detect_line_endings/d" "$FILE"
    ok "Contacts.php: removed auto_detect_line_endings"
else
    skip "Contacts.php: auto_detect_line_endings already removed"
fi

# S9. AdditionalAccount.php — fix $aData→$aAccountHash in NewInstanceFromTokenArray
#     Only replace $aData['smtp']['pass'] where DecryptUrlSafe or empty string is assigned
#     (inside NewInstanceFromTokenArray), NOT in asTokenArray where $aData is correct
FILE="${LIB}/RainLoop/Model/AdditionalAccount.php"
if grep -q 'NewInstanceFromTokenArray' "$FILE" 2>/dev/null && grep -A30 'NewInstanceFromTokenArray' "$FILE" | grep -q "\$aData\['smtp'\]\['pass'\]" 2>/dev/null; then
    sed -i '/NewInstanceFromTokenArray/,/^[[:space:]]*}$/ s/\$aData\['"'"'smtp'"'"'\]\['"'"'pass'"'"'\]/\$aAccountHash['"'"'smtp'"'"']['"'"'pass'"'"']/g' "$FILE"
    ok "AdditionalAccount.php: fixed \$aData → \$aAccountHash in NewInstanceFromTokenArray"
else
    skip "AdditionalAccount.php: \$aData already fixed"
fi

# S10. Folders.php — fix undefined $iErrorCode
FILE="${LIB}/RainLoop/Actions/Folders.php"
if grep -q 'FalseResponse(\$iErrorCode' "$FILE" 2>/dev/null && ! grep -q '\$iErrorCode = \$_FILES' "$FILE" 2>/dev/null; then
    sed -i '/FalseResponse(\$iErrorCode/i\\t\t\t$iErrorCode = $_FILES['"'"'appendFile'"'"']['"'"'error'"'"'];' "$FILE"
    ok "Folders.php: defined \$iErrorCode from \$_FILES"
else
    skip "Folders.php: \$iErrorCode already defined"
fi

echo ""
echo "=== All patches applied ==="
