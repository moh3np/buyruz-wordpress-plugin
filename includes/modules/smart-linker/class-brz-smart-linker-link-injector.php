<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Link injection using DOMDocument (no regex).
 */
class BRZ_Smart_Linker_Link_Injector {
    private $post_id;
    private $dom;
    private $body;
    private $original_html;
    private $source_type;
    private $settings;
    private $excluded_tags;

    /**
     * @param int    $post_id
     * @param string $html
     * @param string $source_type
     * @param array  $settings Optional settings array with open_new_tab, nofollow, exclude_html_tags
     */
    public function __construct( $post_id, $html, $source_type = 'post', $settings = array() ) {
        $this->post_id       = (int) $post_id;
        $this->original_html = $html;
        $this->source_type   = $source_type;
        $this->settings      = wp_parse_args( $settings, array(
            'open_new_tab'      => 1,
            'nofollow'          => 1,
            'exclude_html_tags' => 'h1,h2,h3',
        ) );
        
        // Parse excluded tags into array
        $this->excluded_tags = array_filter( array_map( 'trim', explode( ',', strtolower( $this->settings['exclude_html_tags'] ) ) ) );
        
        $this->dom = new DOMDocument();
        libxml_use_internal_errors( true );
        $this->dom->loadHTML( '<?xml encoding="utf-8" ?>' . $html, LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD );
        libxml_clear_errors();
        $this->body = $this->dom;
    }

    /**
     * @param array $links array of rows (id, keyword, target_url, fingerprint)
     * @return array {changed: bool, content: string}
     */
    public function inject( array $links ) {
        // Priority sorting based on rules
        $links = $this->sort_by_priority( $links );

        $inserted_any = false;

        foreach ( $links as $link ) {
            $keyword    = isset( $link['keyword'] ) ? $link['keyword'] : '';
            $target_url = isset( $link['target_url'] ) ? $link['target_url'] : '';

            if ( empty( $keyword ) || empty( $target_url ) ) {
                continue;
            }

            // Skip if anchor already exists.
            if ( $this->anchor_exists( $keyword, $target_url ) ) {
                continue;
            }

            $target_type = isset( $link['target_type'] ) ? sanitize_key( $link['target_type'] ) : '';
            $injected = $this->inject_single( $keyword, $target_url, $target_type );
            $inserted_any = $inserted_any || $injected;
        }

        if ( ! $inserted_any ) {
            return array(
                'changed' => false,
                'content' => $this->original_html,
            );
        }

        $html = $this->dom->saveHTML();
        // Remove xml header added earlier
        $html = preg_replace( '/^<\\?xml.+?\\?>/i', '', $html );

        return array(
            'changed' => true,
            'content' => $html,
        );
    }

    /**
     * Check existing anchors for same keyword+URL.
     */
    private function anchor_exists( $keyword, $target_url ) {
        $anchors = $this->dom->getElementsByTagName( 'a' );
        foreach ( $anchors as $a ) {
            $href = $a->getAttribute( 'href' ) ?? '';
            $text = trim( $a->textContent ?? '' );
            if ( empty( $href ) ) {
                continue;
            }
            if ( $this->urls_match( $href, $target_url ) && $this->texts_match( $text, $keyword ) ) {
                return true;
            }
        }
        return false;
    }

    private function urls_match( $a, $b ) {
        return trailingslashit( strtolower( trim( $a ) ) ) === trailingslashit( strtolower( trim( $b ) ) );
    }

    private function texts_match( $a, $b ) {
        $lower = function( $text ) {
            $text = trim( $text );
            if ( function_exists( 'mb_strtolower' ) ) {
                return mb_strtolower( $text, 'UTF-8' );
            }
            return strtolower( $text );
        };

        return $lower( $a ) === $lower( $b );
    }

    /**
     * Inject anchor into first text node containing the keyword.
     */
    private function inject_single( $keyword, $target_url, $target_type ) {
        $xpath = new DOMXPath( $this->dom );
        $text_nodes = $xpath->query( '//text()[normalize-space() != ""]' );
        if ( ! $text_nodes ) {
            return false;
        }

        $content_text = $this->dom->textContent;
        $total_len = strlen( $content_text );
        $min_offset = 0;
        $only_bottom = false;

        // Rule: product page linking to blog post -> only bottom 30%
        if ( 'product' === $this->source_type && 'post' === $target_type ) {
            $only_bottom = true;
            $min_offset  = (int) ( $total_len * 0.7 );
        }

        $offset_so_far = 0;
        foreach ( $text_nodes as $text_node ) {
            if ( $this->inside_anchor( $text_node ) ) {
                continue;
            }

            // Check if text node is inside an excluded tag (h1, h2, h3, etc.)
            if ( $this->inside_excluded_tag( $text_node ) ) {
                $offset_so_far += strlen( $text_node->nodeValue ?? '' );
                continue;
            }

            $pos = stripos( $text_node->nodeValue ?? '', $keyword );
            if ( false === $pos ) {
                $offset_so_far += strlen( $text_node->nodeValue ?? '' );
                continue;
            }

            $absolute_pos = $offset_so_far + $pos;
            if ( $only_bottom && $absolute_pos < $min_offset ) {
                $offset_so_far += strlen( $text_node->nodeValue ?? '' );
                continue;
            }

            // Split text node into [before][keyword][after]
            $full = $text_node->nodeValue ?? '';

            $before = substr( $full, 0, $pos );
            $match  = substr( $full, $pos, strlen( $keyword ) );
            $after  = substr( $full, $pos + strlen( $keyword ) );

            $parent = $text_node->parentNode;
            if ( $before !== '' ) {
                $parent->insertBefore( $this->dom->createTextNode( $before ), $text_node );
            }

            $a = $this->dom->createElement( 'a', htmlspecialchars( $match, ENT_QUOTES, 'UTF-8' ) );
            $a->setAttribute( 'href', esc_url( $target_url ) );
            $a->setAttribute( 'data-smart-link', '1' );
            
            // Apply link attributes from settings
            if ( ! empty( $this->settings['open_new_tab'] ) ) {
                $a->setAttribute( 'target', '_blank' );
            }
            
            // Build rel attribute
            $rel_parts = array();
            if ( ! empty( $this->settings['nofollow'] ) ) {
                $rel_parts[] = 'nofollow';
            }
            if ( ! empty( $this->settings['open_new_tab'] ) ) {
                $rel_parts[] = 'noopener';
                $rel_parts[] = 'noreferrer';
            }
            if ( ! empty( $rel_parts ) ) {
                $a->setAttribute( 'rel', implode( ' ', $rel_parts ) );
            }
            
            $parent->insertBefore( $a, $text_node );

            if ( $after !== '' ) {
                $parent->insertBefore( $this->dom->createTextNode( $after ), $text_node );
            }

            $parent->removeChild( $text_node );
            return true;
        }

        return false;
    }

    /**
     * Check if text node is inside an excluded HTML tag.
     *
     * @param DOMNode $node
     * @return bool
     */
    private function inside_excluded_tag( DOMNode $node ) {
        if ( empty( $this->excluded_tags ) ) {
            return false;
        }

        $current = $node->parentNode;
        while ( $current ) {
            if ( $current->nodeType === XML_ELEMENT_NODE ) {
                $tag_name = strtolower( $current->nodeName );
                if ( in_array( $tag_name, $this->excluded_tags, true ) ) {
                    return true;
                }
            }
            $current = $current->parentNode;
        }
        return false;
    }

    /**
     * Verify text node not already within <a>.
     *
     * @param DOMNode $node
     * @return bool
     */
    private function inside_anchor( DOMNode $node ) {
        while ( $node ) {
            if ( $node->nodeName === 'a' ) {
                return true;
            }
            $node = $node->parentNode;
        }
        return false;
    }

    /**
     * Sort links by priority per rules.
     */
    private function sort_by_priority( array $links ) {
        $is_product = ( 'product' === $this->source_type );
        usort( $links, function( $a, $b ) use ( $is_product ) {
            $ta = isset( $a['target_type'] ) ? $a['target_type'] : '';
            $tb = isset( $b['target_type'] ) ? $b['target_type'] : '';

            $pa = $this->priority_score( $ta, $is_product );
            $pb = $this->priority_score( $tb, $is_product );

            if ( $pa === $pb ) { return 0; }
            return ( $pa < $pb ) ? 1 : -1; // higher first
        });
        return $links;
    }

    private function priority_score( $target_type, $is_product_source ) {
        if ( $is_product_source ) {
            if ( 'product' === $target_type ) { return 3; }
            if ( 'post' === $target_type ) { return 2; } // caution
            return 1;
        } else {
            if ( 'product' === $target_type ) { return 3; } // high for blog->product
            if ( 'post' === $target_type ) { return 2; }
            return 1;
        }
    }
}
