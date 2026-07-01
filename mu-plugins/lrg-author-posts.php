<?php
/**
 * Plugin Name: LRG Author Posts Shortcode
 * Usage: [lrg_author_posts] on an author page (auto-detects author from page slug).
 */
if (!defined('ABSPATH')) exit;

function lrg_resolve_author_user($slug){
  if ($slug === 'levi-rodgers') return [1, 31]; // alias Levi's two user records
  $u = get_user_by('slug', $slug);
  return $u ? [$u->ID] : [];
}

function lrg_author_posts_shortcode($atts){
  $atts = shortcode_atts(['author'=>'', 'per_page'=>15], $atts, 'lrg_author_posts');
  $slug = $atts['author'];
  if (!$slug){ $p = get_queried_object(); if ($p instanceof WP_Post) $slug = $p->post_name; }
  if (!$slug) return '';
  $ids = lrg_resolve_author_user($slug);
  if (!$ids) return '';

  $paged = max(1, isset($_GET['apage']) ? (int)$_GET['apage'] : 1);
  $per = max(1, (int)$atts['per_page']);

  $q = new WP_Query([
    'post_type'=>'post','post_status'=>'publish','author__in'=>$ids,
    'posts_per_page'=>$per,'paged'=>$paged,'orderby'=>'date','order'=>'DESC','ignore_sticky_posts'=>true,
  ]);
  if (!$q->have_posts()) return '<p>No articles yet.</p>';

  ob_start();
  echo '<div class="lrg-author-archive"><div class="aGrid">';
  while ($q->have_posts()){ $q->the_post();
    $cat = get_the_category();
    $tag = $cat ? esc_html($cat[0]->name) : 'Guide';
    echo '<a class="aCard" href="'.esc_url(get_permalink()).'">';
    echo '<div class="tag">'.$tag.'</div>';
    echo '<h5>'.esc_html(get_the_title()).'</h5>';
    echo '<span class="more">Read guide &rarr;</span>';
    echo '</a>';
  }
  echo '</div>';

  $total = $q->max_num_pages;
  if ($total > 1){
    echo '<div class="aPager">';
    $base = remove_query_arg('apage');
    for ($i=1;$i<=$total;$i++){
      $url = esc_url(add_query_arg('apage',$i,$base));
      $cls = ($i==$paged) ? ' is-active' : '';
      echo '<a class="lrg-pagelink'.$cls.'" href="'.$url.'">'.$i.'</a>';
    }
    echo '</div>';
  }
  echo '</div>';
  wp_reset_postdata();

  // Self-contained styles — no ancestor dependency
  static $styles_printed = false;
  if (!$styles_printed) {
    $styles_printed = true;
    echo '<style>
.lrg-author-archive{max-width:1100px;margin:24px auto 0;}
.lrg-author-archive .aGrid{display:grid !important;grid-template-columns:repeat(3,1fr) !important;gap:20px !important;margin-top:0 !important;}
@media(max-width:768px){.lrg-author-archive .aGrid{grid-template-columns:1fr !important;}}
.lrg-author-archive .aPager{display:flex;flex-wrap:wrap;gap:8px;justify-content:center;margin:22px 0 0;}
.lrg-author-archive .lrg-pagelink{display:inline-block;min-width:34px;text-align:center;padding:6px 10px;border:1px solid #c7d2e0;border-radius:8px;text-decoration:none !important;color:#091A35 !important;font-size:.9rem;}
.lrg-author-archive .lrg-pagelink.is-active{background:#091A35;color:#fff !important;border-color:#091A35;}
</style>';
  }

  return ob_get_clean();
}
add_shortcode('lrg_author_posts','lrg_author_posts_shortcode');
