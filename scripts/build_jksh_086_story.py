#!/usr/bin/env python3
from pathlib import Path
import sys

if len(sys.argv) != 2:
    raise SystemExit("usage: build_jksh_086_story.py <plugin-root>")

root = Path(sys.argv[1])
main = root / "jk-social-hub.php"
meta = root / "src/Services/MetaPublisher.php"
schema = root / "src/Services/PlatformSchema.php"

def replace_exact(path, old, new, count=1):
    text = path.read_text()
    found = text.count(old)
    if found != count:
        raise SystemExit(f"{path}: target count {found} != {count}: {old[:140]!r}")
    path.write_text(text.replace(old, new, count))

replace_exact(main, " * Version:     0.8.5", " * Version:     0.8.6")
replace_exact(main, "define( 'JKSH_VERSION', '0.8.5' );", "define( 'JKSH_VERSION', '0.8.6' );")

replace_exact(schema, "public const VERSION = 'omnichannel_v5_2026-09-18';", "public const VERSION = 'omnichannel_v6_2026-09-18';")
replace_exact(
    schema,
    "'music_suggestion' => $this->field( false, 'stored_manual', 'Devotional audio search suggestion only. Instagram Graph publishing does not expose licensed music-library selection.' ),",
    "'music_suggestion' => $this->field( false, 'stored_manual', 'Single-image and carousel posts only. Saved as a devotional audio search suggestion because Instagram Graph publishing does not expose licensed music-library selection.' ),",
)
replace_exact(
    schema,
    "'story' => $this->field( true, 'planned_adapter', 'Story publishing is reserved but not live in this release.' ),",
    "'story' => $this->field( true, 'implemented', 'Companion Story publishing is implemented after a successful Instagram image, carousel or Reel post. Carousels use the first image for the Story. Highlight placement remains native-app only.' ),",
)

m = meta.read_text()

needle = """		if ( $poll_count > 20 ) {
			return $this->failure( 'Instagram media processing did not finish within the JK Social Hub wait window.', false );
		}

		if ( 'reel' === $type ) {"""
replacement = """		if ( $poll_count > 20 ) {
			if ( 'story_wait' === (string) ( $state['phase'] ?? '' ) && ! empty( $state['feed_external_id'] ) ) {
				return $this->instagram_story_finish( $payload, $state, false, '', 'Story processing exceeded the JK Social Hub wait window.' );
			}
			return $this->failure( 'Instagram media processing did not finish within the JK Social Hub wait window.', false );
		}

		if ( 'story_wait' === (string) ( $state['phase'] ?? '' ) ) {
			return $this->instagram_story_when_ready( $client, $ig_id, $token, $payload, $state );
		}

		if ( 'reel' === $type ) {"""
if m.count(needle) != 1:
    raise SystemExit("publish_instagram story-state insertion target missing")
m = m.replace(needle, replacement, 1)

calls = {
    "return $this->instagram_publish_when_ready( $client, $ig_id, $token, $payload, $state, 'Instagram Reel published.' );":
    "return $this->instagram_publish_when_ready( $client, $ig_id, $token, $payload, $state, 'Instagram Reel published.', $fields, $media );",
    "return $this->instagram_publish_when_ready( $client, $ig_id, $token, $payload, $state, 'Instagram image published.' );":
    "return $this->instagram_publish_when_ready( $client, $ig_id, $token, $payload, $state, 'Instagram image published.', $fields, $media );",
    "return $this->instagram_publish_when_ready( $client, $ig_id, $token, $payload, $state, 'Instagram carousel published.' );":
    "return $this->instagram_publish_when_ready( $client, $ig_id, $token, $payload, $state, 'Instagram carousel published.', $fields, $media );",
}
for old,new in calls.items():
    if m.count(old) != 1:
        raise SystemExit(f"publish call target missing: {old}")
    m = m.replace(old,new,1)

old_method = """	private function instagram_publish_when_ready( MetaClient $client, string $ig_id, string $token, array $payload, array $state, string $message ): array {
		$container_id = sanitize_text_field( (string) ( $state['container_id'] ?? '' ) );
		if ( '' === $container_id ) {
			return $this->failure( 'Instagram publish state is missing the media container ID.', false );
		}
		$status = $this->container_status( $client, $container_id, $token );
		if ( ! $status['ok'] ) {
			return $status;
		}
		if ( ! $status['ready'] ) {
			$state['poll_count'] = (int) ( $state['poll_count'] ?? 0 ) + 1;
			return $this->pending( $payload, $state, 20, 'Instagram media is still processing.' );
		}
		$publish = $client->post( $ig_id . '/media_publish', $token, array( 'creation_id' => $container_id ) );
		if ( ! $publish['ok'] || empty( $publish['data']['id'] ) ) {
			return $this->from_api_error( $publish, 'Instagram media publish step failed.' );
		}
		$media_id = sanitize_text_field( (string) $publish['data']['id'] );
		$permalink = $this->permalink( $client, $media_id, $token );
		return $this->success( $media_id, $permalink, $message, $publish['data'], $payload );
	}
"""
new_method = """	private function instagram_publish_when_ready( MetaClient $client, string $ig_id, string $token, array $payload, array $state, string $message, array $fields = array(), array $media = array() ): array {
		$container_id = sanitize_text_field( (string) ( $state['container_id'] ?? '' ) );
		if ( '' === $container_id ) {
			return $this->failure( 'Instagram publish state is missing the media container ID.', false );
		}
		$status = $this->container_status( $client, $container_id, $token );
		if ( ! $status['ok'] ) {
			return $status;
		}
		if ( ! $status['ready'] ) {
			$state['poll_count'] = (int) ( $state['poll_count'] ?? 0 ) + 1;
			return $this->pending( $payload, $state, 20, 'Instagram media is still processing.' );
		}
		$publish = $client->post( $ig_id . '/media_publish', $token, array( 'creation_id' => $container_id ) );
		if ( ! $publish['ok'] || empty( $publish['data']['id'] ) ) {
			return $this->from_api_error( $publish, 'Instagram media publish step failed.' );
		}
		$media_id = sanitize_text_field( (string) $publish['data']['id'] );
		$permalink = $this->permalink( $client, $media_id, $token );
		if ( empty( $fields['story_after_publish'] ) ) {
			return $this->success( $media_id, $permalink, $message, $publish['data'], $payload );
		}

		$story_source = $this->instagram_story_source( $media );
		if ( empty( $story_source['url'] ) ) {
			return $this->success(
				$media_id,
				$permalink,
				$message . ' Companion Story was skipped because no compatible source media was available.',
				array( 'feed' => $publish['data'], 'story' => array( 'ok' => false, 'reason' => 'no_compatible_source' ) ),
				$payload
			);
		}

		$params = array( 'media_type' => 'STORIES' );
		if ( 'video' === $story_source['type'] ) {
			$params['video_url'] = $story_source['url'];
		} else {
			$params['image_url'] = $story_source['url'];
		}
		$create = $client->post( $ig_id . '/media', $token, $params );
		if ( ! $create['ok'] || empty( $create['data']['id'] ) ) {
			$reason = sanitize_text_field( (string) ( $create['message'] ?? 'Meta rejected the Story container.' ) );
			return $this->success(
				$media_id,
				$permalink,
				$message . ' Feed post is live; companion Story could not be prepared: ' . $reason,
				array(
					'feed' => $publish['data'],
					'story' => array(
						'ok' => false,
						'api_code' => (int) ( $create['code'] ?? 0 ),
						'error_subcode' => (int) ( $create['error_subcode'] ?? 0 ),
						'message' => $reason,
					),
				),
				$payload
			);
		}

		$story_state = array(
			'phase' => 'story_wait',
			'container_id' => sanitize_text_field( (string) $create['data']['id'] ),
			'poll_count' => 0,
			'feed_external_id' => $media_id,
			'feed_external_url' => $permalink,
			'feed_message' => $message,
			'story_source_type' => sanitize_key( (string) $story_source['type'] ),
		);
		return $this->pending( $payload, $story_state, 15, 'Instagram feed post published. Companion Story is processing.' );
	}

	private function instagram_story_source( array $media ): array {
		foreach ( $media as $item ) {
			if ( ! is_array( $item ) || empty( $item['url'] ) ) {
				continue;
			}
			$type = sanitize_key( (string) ( $item['type'] ?? '' ) );
			if ( in_array( $type, array( 'image', 'video' ), true ) ) {
				return array( 'type' => $type, 'url' => esc_url_raw( (string) $item['url'] ) );
			}
		}
		return array();
	}

	private function instagram_story_when_ready( MetaClient $client, string $ig_id, string $token, array $payload, array $state ): array {
		$container_id = sanitize_text_field( (string) ( $state['container_id'] ?? '' ) );
		if ( '' === $container_id || empty( $state['feed_external_id'] ) ) {
			return $this->failure( 'Instagram Story state is incomplete.', false );
		}
		$status = $this->container_status( $client, $container_id, $token );
		if ( ! $status['ok'] ) {
			return $this->instagram_story_finish( $payload, $state, false, '', sanitize_text_field( (string) ( $status['message'] ?? 'Story processing failed.' ) ) );
		}
		if ( ! $status['ready'] ) {
			$state['poll_count'] = (int) ( $state['poll_count'] ?? 0 ) + 1;
			return $this->pending( $payload, $state, 15, 'Instagram companion Story is still processing.' );
		}
		$publish = $client->post( $ig_id . '/media_publish', $token, array( 'creation_id' => $container_id ) );
		if ( ! $publish['ok'] || empty( $publish['data']['id'] ) ) {
			$reason = sanitize_text_field( (string) ( $publish['message'] ?? 'Meta rejected the Story publish step.' ) );
			return $this->instagram_story_finish( $payload, $state, false, '', $reason );
		}
		$story_id = sanitize_text_field( (string) $publish['data']['id'] );
		return $this->instagram_story_finish( $payload, $state, true, $story_id, 'Companion Story published.' );
	}

	private function instagram_story_finish( array $payload, array $state, bool $story_ok, string $story_id, string $story_message ): array {
		$feed_id = sanitize_text_field( (string) ( $state['feed_external_id'] ?? '' ) );
		$feed_url = esc_url_raw( (string) ( $state['feed_external_url'] ?? '' ) );
		$feed_message = sanitize_text_field( (string) ( $state['feed_message'] ?? 'Instagram feed post published.' ) );
		$response = array(
			'feed' => array( 'id' => $feed_id ),
			'story' => array(
				'ok' => $story_ok,
				'id' => sanitize_text_field( $story_id ),
				'message' => sanitize_text_field( $story_message ),
				'source_type' => sanitize_key( (string) ( $state['story_source_type'] ?? '' ) ),
			),
		);
		$message = $story_ok
			? $feed_message . ' Companion Story published.'
			: $feed_message . ' Feed post is live; companion Story was not published: ' . sanitize_text_field( $story_message );
		return $this->success( $feed_id, $feed_url, $message, $response, $payload );
	}
"""
if m.count(old_method) != 1:
    raise SystemExit("instagram_publish_when_ready method target missing")
m = m.replace(old_method, new_method, 1)
meta.write_text(m)

print("JK Social Hub 0.8.6 Story companion patch applied")
