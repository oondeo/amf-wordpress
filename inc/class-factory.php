<?php
/**
 * AMF WordPress media item factory.
 */

declare( strict_types=1 );

namespace AMFWordPress;

use AssetManagerFramework\Audio;
use AssetManagerFramework\Document;
use AssetManagerFramework\Image;
use AssetManagerFramework\Media;
use AssetManagerFramework\Video;
use stdClass;

/**
 * AMF WordPress media item factory.
 *
 * @package AMFWordPress
 */
class Factory {

	/**
	 * Create an AMF media item according to the given data.
	 *
	 * @param stdClass $data Raw response data from the WordPress REST API.
	 *
	 * @return Media AMF media item.
	 */
	public function create( stdClass $data ): Media {

		// Ensure string ID.
		$data->id = (string) $data->id;

		$item = $this->create_item( $data );

		$item->set_url( $data->source_url );
		$item->set_title( $data->title->rendered );
		$item->set_filename( basename( $data->source_url ) );
		$item->set_link( $data->link );

		if ( $data->alt_text ) {
			$item->set_alt( $data->alt_text );
		}

		if ( $data->caption->rendered ) {
			$item->set_caption( $data->caption->rendered );
		}

		$item->set_name( $data->id );

		if ( $data->date ) {
			$item->set_date( strtotime( $data->date ) );
		}

		if ( $data->modified ) {
			$item->set_modified( strtotime( $data->modified ) );
		}

		$item->set_file_size( $this->get_file_size( $data->source_url ) );

		return $item;
	}

	/**
	 * Create an AMF media item with type-specific data only.
	 *
	 * @param stdClass $data Raw response data from the WordPress REST API.
	 *
	 * @return Media AMF media item.
	 */
	private function create_item( stdClass $data ): Media {

		// Use mime_type instead of media_type as WordPress sets non-images to just 'file'.
		$type = strtok( $data->mime_type, '/' );

		switch ( $type ) {
			case 'image':
				return $this->create_image( $data );

			case 'video':
				return $this->create_video( $data );

			case 'audio':
				return $this->create_audio( $data );

			case 'application':
				return $this->create_document( $data );

			default:
				return new Media( $data->id, $data->mime_type );
		}
	}

	/**
	 * Create an AMF audio item.
	 *
	 * @param stdClass $data Raw response data from the WordPress REST API.
	 *
	 * @return Audio AMF audio item.
	 */
	public function create_audio( stdClass $data ): Audio {

		$item = new Audio( $data->id, $data->mime_type );

		if ( $data->media_details->length_formatted ) {
			$item->set_length( $data->media_details->length_formatted );
		}

		if ( $data->meta ) {
			$item->set_meta( $data->meta );
		}

		$featured_media_url = $this->get_featured_media_url( $data );
		if ( $featured_media_url ) {
			$item->set_image( $featured_media_url );
			$item->set_thumb( $featured_media_url );
		}

		return $item;
	}

	/**
	 * Create an AMF document item.
	 *
	 * @param stdClass $data Raw response data from the WordPress REST API.
	 *
	 * @return Document AMF document item.
	 */
	public function create_document( stdClass $data ): Document {

		$item = new Document( $data->id, $data->mime_type );

		return $item;
	}

	/**
	 * Create an AMF image item.
	 *
	 * @param stdClass $data Raw response data from the WordPress REST API.
	 *
	 * @return Image AMF image item.
	 */
	public function create_image( stdClass $data ): Image {

		$item = new Image( $data->id, $data->mime_type );

		$sizes = $this->get_image_sizes( $data );
		if ( $sizes ) {
			$item->set_sizes( $sizes );
		}

		$item->set_width( $data->media_details->width );
		$item->set_height( $data->media_details->height );

		return $item;
	}

	/**
	 * Create an AMF video item.
	 *
	 * @param stdClass $data Raw response data from the WordPress REST API.
	 *
	 * @return Video AMF video item.
	 */
	public function create_video( stdClass $data ): Video {

		$item = new Video( $data->id, $data->mime_type );

		if ( $data->media_details->length_formatted ) {
			$item->set_length( $data->media_details->length_formatted );
		}

		if ( $data->meta ) {
			$item->set_meta( $data->meta );
		}

		$featured_media_url = $this->get_featured_media_url( $data );
		if ( $featured_media_url ) {
			$item->set_image( $featured_media_url );
			$item->set_thumb( $featured_media_url );
		}

		return $item;
	}

	/**
	 * Return the featured media URL included in the given response data.
	 *
	 * @param stdClass $data Raw response data from the WordPress REST API.
	 *
	 * @return string Featured media URL.
	 */
	private function get_featured_media_url( stdClass $data ): string {

		return (string) ( $data->_embedded->{'wp:featuredmedia'}[0]->source_url ?? '' );
	}

	/**
	 * Get the remote file's size without downloading the full file.
	 *
	 * @param string $url Target URL.
	 *
	 * @return int File size in bytes.
	 */
	private function get_file_size( string $url ): int {

		$headers = get_headers( $url, 1 ) ?: [];
		$headers = array_change_key_case( $headers );

		$file_size = (int) ( $headers['content-length'] ?? 0 );

		return $file_size;
	}

	/**
	 * Get image size mapping from the given response data.
	 *
	 * @param stdClass $data Raw response data from the WordPress REST API.
	 *
	 * @return array[] Image sizes.
	 */
	private function get_image_sizes( stdClass $data ): array {

		if ( empty( $data->media_details->sizes ) ) {
			return [];
		}

		$available_sizes = $data->media_details->sizes;

		$possible_sizes = [
			'thumbnail',
			'medium',
			'large',
		];

		$registered_sizes = wp_get_registered_image_subsizes();

		$sizes = [];

		foreach ( $possible_sizes as $size ) {
			$registered_size = $registered_sizes[ $size ] ?? [];
			if ( ! $registered_size ) {
				continue;
			}

			$width = $registered_size['width'];

			$height = $registered_size['height'];

			$sizes[ $size ] = [
				'width'       => $width,
				'height'      => $height,
				'orientation' => $height > $width ? 'portrait' : 'landscape',
				'url'         => $available_sizes->{$size}->source_url ?? $data->source_url,
			];
		}

		$width = $data->media_details->width;

		$height = $data->media_details->height;

		$sizes['full'] = [
			'width'       => $width,
			'height'      => $height,
			'orientation' => $height > $width ? 'portrait' : 'landscape',
			'url'         => $data->source_url,
		];

		return $sizes;
	}
}