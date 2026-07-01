<?php
/**
 * Plugin Name: LRG Author Bio Card (Auto-Append)
 * Description: Appends a styled contributor bio card to every article at render time.
 *              Reads the post's author and pulls name, role, TREC license, bio, headshot,
 *              and social links. No per-post HTML needed.
 * Version: 2.0.0
 * Author: RSS Team
 */

if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Author metadata registry.
 * TREC licenses and social links keyed by WP user_id.
 * Bio text and role come from WP user meta (description + rss_mh_role).
 * Headshot follows the pattern: /wp-content/uploads/authors/{nicename}.png
 */
function _lrg_author_registry(): array {
    return [
        1  => ['trec' => '615524', 'area' => 'San Antonio',
               'socials' => []],
        23 => ['trec' => '616611', 'area' => 'San Antonio',
               'socials' => []],
        24 => ['trec' => '',       'area' => 'San Antonio',
               'socials' => []],
        25 => ['trec' => '718199', 'area' => 'San Antonio',
               'socials' => []],
        26 => ['trec' => '681023', 'area' => 'San Antonio',
               'socials' => []],
        27 => ['trec' => '617273', 'area' => 'San Antonio & Austin',
               'socials' => []],
        28 => ['trec' => '728156', 'area' => 'San Antonio & Austin',
               'socials' => []],
        29 => ['trec' => '629251', 'area' => 'San Antonio',
               'socials' => []],
        30 => ['trec' => '762458', 'area' => 'Boerne & Hill Country',
               'socials' => ['facebook'  => 'https://www.facebook.com/priscilla.hollenbeck',
                   'instagram' => 'https://www.instagram.com/priscilla_hollenbeck',
               ]],
    ];
}

/**
 * Build the bio card HTML for a given user ID.
 * Returns empty string if author has no usable data.
 */
function _lrg_build_bio_card( int $user_id ): string {
    $registry = _lrg_author_registry();
    $entry    = $registry[ $user_id ] ?? null;

    $display  = get_the_author_meta( 'display_name', $user_id );
    $bio      = trim( (string) get_the_author_meta( 'description', $user_id ) );
    $role     = trim( (string) get_user_meta( $user_id, 'rss_mh_role', true ) );
    $profile  = trim( (string) get_user_meta( $user_id, 'rss_mh_profile_url', true ) );
    $nicename = get_the_author_meta( 'user_nicename', $user_id );

    // Graceful exit: no display name or not in registry = skip
    if ( $display === '' || $entry === null ) {
        return '';
    }

    // Photo: convention-based path with file_exists check
    $photo_slug = $nicename;
    if ( $user_id === 1 ) $photo_slug = 'levi-rodgers';
    $photo_file = WP_CONTENT_DIR . "/uploads/authors/{$photo_slug}.png";
    $photo_url  = content_url( "uploads/authors/{$photo_slug}.png" );
    $photo_exists = file_exists( $photo_file );

    // Initials fallback: first letter of first + last word, uppercased
    $name_parts = explode( ' ', trim( $display ) );
    $initials   = strtoupper( substr( $name_parts[0], 0, 1 ) );
    if ( count( $name_parts ) > 1 ) {
        $initials .= strtoupper( substr( end( $name_parts ), 0, 1 ) );
    }

    // TREC and area from registry
    $trec = ! empty( $entry['trec'] ) ? $entry['trec'] : '';
    $area = ! empty( $entry['area'] ) ? $entry['area'] : '';

    // Social links as pill buttons
    $socials_html = '';
    if ( ! empty( $entry['socials'] ) ) {
        $links = [];
        $icons = ['facebook'  => 'Facebook',
            'instagram' => 'Instagram',
            'tiktok'    => 'TikTok',
        ];
        foreach ( $entry['socials'] as $platform => $url ) {
            $label = $icons[ $platform ] ?? ucfirst( $platform );
            $links[] = '<a href="' . esc_url( $url ) . '" target="_blank" rel="noopener">' . esc_html( $label ) . '</a>';
        }
        if ( $links ) {
            $socials_html = '<div class="rl-author__links">' . implode( "\n", $links ) . '</div>';
        }
    }

    // Bio paragraph
    $bio_html = '';
    if ( $bio !== '' ) {
        $bio_html = '<p class="rl-author__text">' . esc_html( $bio ) . '</p>';
    }

    // Name: link to profile if available
    $name_html = esc_html( $display );
    if ( $profile !== '' ) {
        $name_html = '<a href="' . esc_url( $profile ) . '">' . $name_html . '</a>';
    }

    // Photo or initials fallback
    if ( $photo_exists ) {
        $photo_html = '<img class="rl-author__photo" src="' . esc_url( $photo_url ) . '" alt="' . esc_attr( $display ) . ', ' . esc_attr( $role ) . ' at LRG Realty" width="208" height="208" loading="lazy">';
    } else {
        $photo_html = '<div class="rl-author__photo rl-author__photo--fallback" aria-label="' . esc_attr( $display ) . '">' . esc_html( $initials ) . '</div>';
    }

    // Meta line: credential + area + TREC
    $meta_parts = [
        40 => ['trec' => '', 'area' => 'San Antonio',
               'socials' => []],
    
        41 => ['trec' => '', 'area' => 'New Braunfels',
               'socials' => []],
    ];
    if ( $role !== '' ) {
        $meta_parts[] = '<span class="rl-author__credential">' . esc_html( $role ) . '</span>';
    }
    if ( $area !== '' ) {
        $meta_parts[] = '<span>' . esc_html( $area ) . '</span>';
    }
    if ( $trec !== '' ) {
        $meta_parts[] = '<span>TREC #' . esc_html( $trec ) . '</span>';
    }
    $meta_html = implode( "\n", $meta_parts );

    return '<section class="rl-section rl-author" aria-label="About the author">
<div class="rl-author__card">
' . $photo_html . '
<div class="rl-author__body">
<p class="rl-author__eyebrow">Written by</p>
<h3 class="rl-author__name">' . $name_html . '</h3>
<div class="rl-author__meta">
' . $meta_html . '
</div>
' . $bio_html . '
' . $socials_html . '
</div>
</div>
</section>';
}

/**
 * Append the bio card to article content at render time.
 * Fires on the_content filter at priority 90 (after most other filters,
 * before related-posts plugins at 99+).
 */
add_filter( 'the_content', function ( $content ) {
    if ( ! is_singular( 'post' ) ) return $content;
    if ( is_admin() ) return $content;

    $post = get_post();
    if ( ! $post ) return $content;

    $author_id = (int) $post->post_author;
    if ( $author_id < 1 ) return $content;

    // Don't double-append if content already has a manual bio card
    if ( strpos( $content, 'rl-contributor-bio' ) !== false ) {
        return $content;
    }
    if ( strpos( $content, 'rl-author__card' ) !== false ) {
        return $content;
    }

    $card = _lrg_build_bio_card( $author_id );
    if ( $card === '' ) return $content;

    // Insert before closing </div></div> of rl-page wrapper (if present),
    // otherwise just append
    $close_marker = '</div>' . "\n" . '</div>';
    $pos = strrpos( $content, $close_marker );
    if ( $pos !== false ) {
        $content = substr( $content, 0, $pos ) . "\n" . $card . "\n" . $close_marker;
    } else {
        $content .= "\n" . $card;
    }

    return $content;
}, 90 );

