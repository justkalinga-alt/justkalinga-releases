#!/usr/bin/env python3
from pathlib import Path
import sys

if len(sys.argv) != 2:
    raise SystemExit('usage: build_jksh_084_ist.py <plugin-root>')

root = Path(sys.argv[1])
main = root / 'jk-social-hub.php'
pages = root / 'src/Admin/Pages.php'
abilities = root / 'src/Mcp/Abilities.php'
settings = root / 'src/Services/Settings.php'


def replace_exact(path: Path, old: str, new: str, count=None):
    text = path.read_text()
    found = text.count(old)
    if found == 0:
        raise SystemExit(f'Missing expected patch target in {path}: {old[:140]!r}')
    if count is not None and found != count:
        raise SystemExit(f'Unexpected target count in {path}: {found} != {count} for {old[:120]!r}')
    path.write_text(text.replace(old, new))


# Version and release description.
replace_exact(
    main,
    'v0.8.3 adds a read-only signed relay-worker handshake to existing live readiness diagnostics while preserving Dry Run and live-publish safety gates.',
    'v0.8.4 makes Asia/Kolkata (IST) the only human-facing scheduling and display timezone while preserving UTC internally for platform APIs, queue safety and token storage.',
    1,
)
replace_exact(main, ' * Version:     0.8.3', ' * Version:     0.8.4', 1)
replace_exact(main, "define( 'JKSH_VERSION', '0.8.3' );", "define( 'JKSH_VERSION', '0.8.4' );", 1)

# Freeze operational timezone to IST.
replace_exact(
    settings,
    "'timezone'        => sanitize_text_field( $input['timezone'] ?? 'Asia/Kolkata' ),",
    "'timezone'        => 'Asia/Kolkata',",
    1,
)

# Add central UTC -> IST renderer for admin human-readable timestamps.
marker = "\tprivate function footer(): void {\n\t\techo '</div>';\n\t}\n"
helper = marker + """

\tprivate function ist_time( mixed $utc_value ): string {
\t\t$value = trim( (string) $utc_value );
\t\tif ( '' === $value || '0000-00-00 00:00:00' === $value ) {
\t\t\treturn '';
\t\t}
\t\ttry {
\t\t\t$utc = new \\DateTimeImmutable( $value, new \\DateTimeZone( 'UTC' ) );
\t\t\treturn $utc->setTimezone( new \\DateTimeZone( 'Asia/Kolkata' ) )->format( 'Y-m-d H:i:s' );
\t\t} catch ( \\Throwable $e ) {
\t\t\treturn $value;
\t\t}
\t}
"""
replace_exact(pages, marker, helper, 1)

# Human-facing labels.
text = pages.read_text()
for old, new in {
    'Publish UTC': 'Publish IST',
    'Token expiry UTC': 'Token expiry IST',
    'Last checked UTC': 'Last checked IST',
    'Created UTC': 'Created IST',
    'Scheduled UTC': 'Scheduled IST',
    'Time UTC': 'Time IST',
    'Hub operational timezone': 'Human-facing timezone (fixed to IST)',
}.items():
    text = text.replace(old, new)
text = text.replace(
    "$this->stat_card( 'Timezone', (string) $settings->get( 'timezone' ), 'Human-facing timezone (fixed to IST)' );",
    "$this->stat_card( 'Timezone', 'Asia/Kolkata (IST)', 'Human-facing timezone (fixed to IST)' );",
)
pages.write_text(text)

# Render stored UTC timestamps as IST everywhere users see them.
text = pages.read_text()
for old, new in [
    ("esc_html( (string) $row->publish_at )", "esc_html( $this->ist_time( $row->publish_at ) )"),
    ("esc_html( $row->created_at )", "esc_html( $this->ist_time( $row->created_at ) )"),
    ("esc_html( (string) $row->scheduled_at )", "esc_html( $this->ist_time( $row->scheduled_at ) )"),
    ("esc_html( (string) $account->token_expires_at )", "esc_html( $this->ist_time( $account->token_expires_at ) )"),
    ("esc_html( (string) $account->last_checked_at )", "esc_html( $this->ist_time( $account->last_checked_at ) )"),
]:
    text = text.replace(old, new)

# Calendar groups by IST date rather than UTC date.
old_calendar = "$date = $row->publish_at ? substr( $row->publish_at, 0, 10 ) : 'Unscheduled';"
new_calendar = "$ist_publish_at = $row->publish_at ? $this->ist_time( $row->publish_at ) : '';\n\t\t\t$date = $ist_publish_at ? substr( $ist_publish_at, 0, 10 ) : 'Unscheduled';"
if old_calendar not in text:
    raise SystemExit('Calendar date grouping target not found')
text = text.replace(old_calendar, new_calendar, 1)

# Fix timezone setting in UI.
old_tz_field = '<label><span>Operational timezone</span><input name="jksh_settings[timezone]" value="<?php echo esc_attr( $settings[\'timezone\'] ); ?>"></label>'
new_tz_field = '<label><span>Operational timezone</span><input value="Asia/Kolkata (IST)" readonly><input type="hidden" name="jksh_settings[timezone]" value="Asia/Kolkata"></label>'
if old_tz_field not in text:
    raise SystemExit('Timezone settings field target not found')
text = text.replace(old_tz_field, new_tz_field, 1)
pages.write_text(text)

# MCP scheduling becomes IST-only at the human contract. UTC remains internal.
old_schema = "\t\t\t\t\t'scheduled_at_utc' => array( 'type' => 'string' ),"
new_schema = "\t\t\t\t\t'scheduled_at_ist' => array( 'type' => 'string', 'description' => 'Schedule time in Asia/Kolkata (IST). Accepted formats include YYYY-MM-DD HH:MM:SS and YYYY-MM-DDTHH:MM.' ),"
replace_exact(abilities, old_schema, new_schema, 1)

old_method = "\tpublic function create_dry_run_content( mixed $input ): array|\\WP_Error {\n\t\treturn ( new ContentOrchestrator() )->create_dry_run( is_array( $input ) ? $input : array() );\n\t}"
new_method = """\tpublic function create_dry_run_content( mixed $input ): array|\\WP_Error {
\t\t$input = is_array( $input ) ? $input : array();
\t\t$ist_value = trim( (string) ( $input['scheduled_at_ist'] ?? '' ) );
\t\tif ( '' !== $ist_value ) {
\t\t\t$ist = new \\DateTimeZone( 'Asia/Kolkata' );
\t\t\t$when = \\DateTimeImmutable::createFromFormat( 'Y-m-d H:i:s', $ist_value, $ist );
\t\t\tif ( ! $when ) {
\t\t\t\t$when = \\DateTimeImmutable::createFromFormat( 'Y-m-d\\TH:i', $ist_value, $ist );
\t\t\t}
\t\t\tif ( ! $when ) {
\t\t\t\ttry {
\t\t\t\t\t$when = new \\DateTimeImmutable( $ist_value, $ist );
\t\t\t\t} catch ( \\Throwable $e ) {
\t\t\t\t\t$when = false;
\t\t\t\t}
\t\t\t}
\t\t\tif ( ! $when ) {
\t\t\t\treturn new \\WP_Error( 'jksh_invalid_schedule_ist', 'scheduled_at_ist must be a valid Asia/Kolkata (IST) date/time.' );
\t\t\t}
\t\t\t$input['scheduled_at_utc'] = $when->setTimezone( new \\DateTimeZone( 'UTC' ) )->format( 'Y-m-d H:i:s' );
\t\t}
\t\tunset( $input['scheduled_at_ist'] );
\t\t$result = ( new ContentOrchestrator() )->create_dry_run( $input );
\t\tif ( is_array( $result ) && ! empty( $result['scheduled_at_utc'] ) ) {
\t\t\ttry {
\t\t\t\t$utc = new \\DateTimeImmutable( (string) $result['scheduled_at_utc'], new \\DateTimeZone( 'UTC' ) );
\t\t\t\t$result['scheduled_at_ist'] = $utc->setTimezone( new \\DateTimeZone( 'Asia/Kolkata' ) )->format( 'Y-m-d H:i:s' );
\t\t\t} catch ( \\Throwable $e ) {
\t\t\t\t$result['scheduled_at_ist'] = (string) $result['scheduled_at_utc'];
\t\t\t}
\t\t\tunset( $result['scheduled_at_utc'] );
\t\t}
\t\treturn $result;
\t}"""
replace_exact(abilities, old_method, new_method, 1)

# Release notes if bundled.
readme = root / 'README.md'
if readme.exists():
    rt = readme.read_text()
    rt += "\n\n## 0.8.4\n- Asia/Kolkata (IST) is the only human-facing timezone in Composer, Calendar, Variants, Queue, Logs, Accounts and MCP scheduling.\n- UTC remains internal for platform APIs, queue execution and stored token timestamps.\n"
    readme.write_text(rt)

print('JK Social Hub 0.8.4 IST patch applied')
