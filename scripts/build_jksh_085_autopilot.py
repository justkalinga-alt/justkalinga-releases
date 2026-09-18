#!/usr/bin/env python3
from pathlib import Path
import sys

if len(sys.argv) != 2:
    raise SystemExit("usage: build_jksh_085_autopilot.py <plugin-root>")

root = Path(sys.argv[1])
main = root / "jk-social-hub.php"
meta = root / "src/Services/MetaPublisher.php"
yt = root / "src/Services/YouTubePublisher.php"
schema = root / "src/Services/PlatformSchema.php"

def replace_exact(path, old, new, count=1):
    text = path.read_text()
    found = text.count(old)
    if found != count:
        raise SystemExit(f"{path}: target count {found} != {count}: {old[:120]!r}")
    path.write_text(text.replace(old, new, count))

# Release version only. 0.8.4 already contains the verified IST patch.
replace_exact(main, " * Version:     0.8.4", " * Version:     0.8.5")
replace_exact(main, "define( 'JKSH_VERSION', '0.8.4' );", "define( 'JKSH_VERSION', '0.8.5' );")

# Native-field schema v5.
replace_exact(schema, "public const VERSION = 'omnichannel_v4_2026-09-17';", "public const VERSION = 'omnichannel_v5_2026-09-18';")
replace_exact(
    schema,
    "\t\t\t\t\t'location_id' => $this->field( true, 'implemented', 'Requires a valid Meta location/Page ID. The Hub never invents one.' ),",
    "\t\t\t\t\t'location_id' => $this->field( true, 'implemented', 'Requires a valid Meta location/Page ID. The Hub never invents one.' ),\n"
    "\t\t\t\t\t'location_query' => $this->field( false, 'implemented', 'Resolved at publish time through Meta Places search. The first valid result is used; failure does not fabricate a location.' ),\n"
    "\t\t\t\t\t'product_url' => $this->field( false, 'implemented', 'A JustKalinga product or product-search URL appended to public caption copy when absent.' ),\n"
    "\t\t\t\t\t'music_suggestion' => $this->field( false, 'stored_manual', 'Devotional audio search suggestion only. Instagram Graph publishing does not expose licensed music-library selection.' ),"
)
replace_exact(
    schema,
    "\t\t\t\t\t'place_id' => $this->field( true, 'conditional_stored', 'Stored only when a valid place identifier is known; not fabricated.' ),",
    "\t\t\t\t\t'place_id' => $this->field( true, 'conditional_stored', 'Stored only when a valid place identifier is known; not fabricated.' ),\n"
    "\t\t\t\t\t'place_query' => $this->field( false, 'conditional_stored', 'Human location query retained for future Facebook place mapping.' ),\n"
    "\t\t\t\t\t'product_url' => $this->field( false, 'implemented', 'Appended to public copy when absent.' ),"
)
replace_exact(
    schema,
    "\t\t\t\t\t'location' => $this->field( false, 'deprecated_platform_field', 'YouTube recording-location fields are deprecated. The Hub may retain source location internally but will not rely on deprecated API writes.' ),",
    "\t\t\t\t\t'location' => $this->field( false, 'deprecated_platform_field', 'YouTube recording-location fields are deprecated. The Hub may retain source location internally but will not rely on deprecated API writes.' ),\n"
    "\t\t\t\t\t'location_text' => $this->field( false, 'implemented', 'Retained in public description copy because YouTube recording-location API writes are deprecated.' ),\n"
    "\t\t\t\t\t'product_url' => $this->field( false, 'implemented', 'A JustKalinga product or product-search URL appended to the description when absent.' ),"
)

# Instagram location autopilot: resolve text query to the first valid Meta place result.
replace_exact(
    meta,
    "\t\t$fields = $this->platform_fields( $variant );\n\t\t$state = isset( $payload['meta_state'] ) && is_array( $payload['meta_state'] ) ? $payload['meta_state'] : array();",
    "\t\t$fields = $this->platform_fields( $variant );\n"
    "\t\t$fields = $this->resolve_instagram_location( $client, $token, $fields );\n"
    "\t\t$state = isset( $payload['meta_state'] ) && is_array( $payload['meta_state'] ) ? $payload['meta_state'] : array();"
)

marker = "\tprivate function instagram_common_params( array $fields, bool $allow_alt, ?array $media = null, int $index = 0, bool $allow_parent_fields = true ): array {"
helper = r'''\tprivate function resolve_instagram_location( MetaClient $client, string $token, array $fields ): array {
\t\tif ( ! empty( $fields['location_id'] ) ) {
\t\t\treturn $fields;
\t\t}
\t\t$query = trim( sanitize_text_field( (string) ( $fields['location_query'] ?? '' ) ) );
\t\tif ( '' === $query ) {
\t\t\treturn $fields;
\t\t}
\t\t$result = $client->get(
\t\t\t'search',
\t\t\t$token,
\t\t\tarray(
\t\t\t\t'type'   => 'place',
\t\t\t\t'q'      => $query,
\t\t\t\t'limit'  => 5,
\t\t\t\t'fields' => 'id,name,location',
\t\t\t)
\t\t);
\t\tif ( empty( $result['ok'] ) || empty( $result['data']['data'] ) || ! is_array( $result['data']['data'] ) ) {
\t\t\t$fields['location_resolution'] = array( 'query' => $query, 'resolved' => false );
\t\t\treturn $fields;
\t\t}
\t\tforeach ( $result['data']['data'] as $row ) {
\t\t\t$id = sanitize_text_field( (string) ( $row['id'] ?? '' ) );
\t\t\tif ( '' === $id ) {
\t\t\t\tcontinue;
\t\t\t}
\t\t\t$fields['location_id'] = $id;
\t\t\t$fields['location_resolution'] = array(
\t\t\t\t'query'    => $query,
\t\t\t\t'resolved' => true,
\t\t\t\t'id'       => $id,
\t\t\t\t'name'     => sanitize_text_field( (string) ( $row['name'] ?? '' ) ),
\t\t\t);
\t\t\tbreak;
\t\t}
\t\treturn $fields;
\t}

'''
mtext = meta.read_text()
if marker not in mtext:
    raise SystemExit("MetaPublisher location helper marker missing")
mtext = mtext.replace(marker, helper + marker, 1)
meta.write_text(mtext)

# Product URL is a supported public fallback on Meta posts. Music stays suggestion-only.
old_compose = r'''\tprivate function compose_caption( object $variant ): string {
\t\t$parts = array_filter(
\t\t\tarray(
\t\t\t\ttrim( (string) $variant->caption ),
\t\t\t\ttrim( (string) $variant->hashtags ),
\t\t\t\ttrim( (string) $variant->cta ),
\t\t\t),
\t\t\tstatic fn( string $value ): bool => '' !== $value
\t\t);
\t\treturn sanitize_textarea_field( implode( "\n\n", $parts ) );
\t}'''
new_compose = r'''\tprivate function compose_caption( object $variant ): string {
\t\t$fields = $this->platform_fields( $variant );
\t\t$parts = array_filter(
\t\t\tarray(
\t\t\t\ttrim( (string) $variant->caption ),
\t\t\t\ttrim( (string) $variant->hashtags ),
\t\t\t\ttrim( (string) $variant->cta ),
\t\t\t),
\t\t\tstatic fn( string $value ): bool => '' !== $value
\t\t);
\t\t$caption = implode( "\n\n", $parts );
\t\t$product_url = esc_url_raw( (string) ( $fields['product_url'] ?? $fields['link'] ?? '' ) );
\t\tif ( $product_url && false === strpos( $caption, $product_url ) ) {
\t\t\t$caption .= ( '' !== $caption ? "\n\n" : '' ) . '🔗 ' . $product_url;
\t\t}
\t\treturn sanitize_textarea_field( $caption );
\t}'''
replace_exact(meta, old_compose, new_compose)

# YouTube product + location autopilot stays inside description because native recording-location writes are deprecated.
old_desc = r'''\tprivate function description( object $variant, array $fields ): string {
\t\treturn sanitize_textarea_field( (string) ( $fields['description'] ?? $variant->description ?? '' ) );
\t}'''
new_desc = r'''\tprivate function description( object $variant, array $fields ): string {
\t\t$description = sanitize_textarea_field( (string) ( $fields['description'] ?? $variant->description ?? '' ) );
\t\t$location = trim( sanitize_text_field( (string) ( $fields['location_text'] ?? '' ) ) );
\t\t$product_url = esc_url_raw( (string) ( $fields['product_url'] ?? '' ) );
\t\tif ( $location && false === stripos( $description, $location ) ) {
\t\t\t$description .= ( '' !== $description ? "\n\n" : '' ) . '📍 ' . $location;
\t\t}
\t\tif ( $product_url && false === strpos( $description, $product_url ) ) {
\t\t\t$description .= ( '' !== $description ? "\n" : '' ) . '🔗 ' . $product_url;
\t\t}
\t\treturn sanitize_textarea_field( $description );
\t}'''
replace_exact(yt, old_desc, new_desc)

# Better thumbnail receipts for Shorts and videos.
replace_exact(
    yt,
    "\t\t\t$secondary['thumbnail'] = array( 'ok' => ! empty( $result['ok'] ), 'status' => (int) ( $result['status'] ?? 0 ) );",
    "\t\t\t$secondary['thumbnail'] = array(\n"
    "\t\t\t\t'ok' => ! empty( $result['ok'] ),\n"
    "\t\t\t\t'status' => (int) ( $result['status'] ?? 0 ),\n"
    "\t\t\t\t'attachment_id' => $thumb_id,\n"
    "\t\t\t\t'api' => 'thumbnails.set',\n"
    "\t\t\t\t'note' => 'YouTube accepted the custom thumbnail request when ok=true. Some vertical-video browse surfaces may still render an automatically cropped preview.',\n"
    "\t\t\t);"
)

# Keep a conservative local memory guard but make the diagnostic explicit.
replace_exact(
    yt,
    "\t\t\treturn $this->failure( 'JK Social Hub limits custom thumbnails to 10 MB for server memory safety.', false );",
    "\t\t\treturn $this->failure( 'JK Social Hub locally limits custom thumbnails to 10 MB for server memory safety before calling YouTube thumbnails.set.', false );"
)

print("JK Social Hub 0.8.5 posting autopilot patch applied")
