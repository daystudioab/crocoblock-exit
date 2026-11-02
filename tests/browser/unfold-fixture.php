<?php
// Bygger testsidan för unfold-test.js med den riktiga koden.
require dirname( __DIR__ ) . '/wp-harness.php';
$items = '';
for ( $i = 0; $i < 10; $i++ ) {
	$items .= '<div style="height:120px;border:1px solid #ccc">Nyhet ' . $i . '</div>';
}
$html = CBX_Frontend::unfold_html( $items, array( 'height' => 20, 'height_mobile' => 40, 'unfold_text' => 'Visa fler nyheter', 'fold_text' => 'Visa färre nyheter', 'ud' => 300, 'fd' => 200 ), '734f017' );
echo '<!doctype html><meta charset="utf-8"><body><div class="elementor-element elementor-element-734f017">' . $html . '</div></body>';
