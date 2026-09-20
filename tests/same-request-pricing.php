<?php
/** Integration regression. Requires an isolated bootstrap; refuses the shop DB. */
if (PHP_SAPI !== 'cli' || !getenv('GOLDMATE_TEST_BOOTSTRAP')) { exit(2); }
require getenv('GOLDMATE_TEST_BOOTSTRAP');
if (DB_NAME !== 'goldmate_audit_isolated_20260917') { throw new Exception('Isolated DB required'); }
$scenario = $argv[1] ?? 'empty-parent';
$ids = array(); $saves = 0; $failures = array();
function gm_check($ok, $label) { global $failures; if (!$ok) { $failures[] = $label; } }
$test_rate = 1000000;
function gm_expected($weight) { global $test_rate; return round($weight * $test_rate * (1 + .07 + 1.07 * .07 + (.07 + 1.07 * .07) * .09) / 1000) * 1000; }
// All fixtures/settings live in the isolated schema, no rate-history deletions.
foreach (array('goldmate_reprice_on_product_save'=>'yes','goldmate_reprice_on_item_save'=>'yes','goldmate_recalc_only_on_change'=>'no','goldmate_wc_tax_status'=>'none','goldmate_round_prices'=>'yes','goldmate_round_to'=>1000,'goldmate_round_mode'=>'round','goldmate_tax_method'=>'all','goldmate_tax_on_wage'=>'yes','goldmate_tax_on_profit'=>'yes') as $k=>$v) { update_option($k,$v); }
$rate_item = Goldmate_Rate_Items::get_by_slug('gold18');
if (!$rate_item || !Goldmate_Rate_Items::update($rate_item['id'], array('rate'=>1000000,'profit_pct'=>7,'tax_pct'=>9,'is_18k_reference'=>1))) { throw new Exception('Rate fixture failed'); }
$fixture_formula = Goldmate_Formulas::get_by_slug('default');
if (!$fixture_formula) { throw new Exception('Default formula missing'); }
$wpdb->update(Goldmate_Formulas::table(),array('rate_slug'=>'gold18','status'=>'active'),array('id'=>$fixture_formula['id']));
update_option(Goldmate_Components::OPTION,array());
if (!in_array($scenario,array('empty-parent','changed-weight','overwritten-price','repeated-save','changed-rate'),true)) { throw new Exception('Unknown scenario'); }
update_option('goldmate_discounts_enabled','no');
foreach (array('woocommerce_update_product','woocommerce_update_product_variation') as $hook) {
 add_action($hook,function() use (&$saves) { if (++$saves > 100) { throw new Exception('Unbounded save recursion'); } },1);
}
try {
 $p = new WC_Product_Variable(); $p->set_name('Isolated Goldmate regression');
 $p->update_meta_data('_goldmate_enabled','yes'); $p->update_meta_data('_goldmate_karat',18); $p->update_meta_data('_goldmate_wage_pct',7);
 $ids[]=$pid=$p->save();
 if ($scenario === 'empty-parent') { gm_check(false === Goldmate_Pricing::apply($pid), 'empty pass returns false'); }
 foreach (array(3.049,2.111) as $w) {
  $v = new WC_Product_Variation(); $v->set_parent_id($pid); $v->set_status('publish');
  $ids[]=$vid=$v->save(); update_post_meta($vid,'_goldmate_weight',$w); $vids[]=$vid;
 }
 Goldmate_Pricing::apply($pid);
 if ($scenario === 'changed-weight') {
  update_post_meta($vids[0],'_goldmate_weight',4.125);
  Goldmate_Pricing::apply($pid);
 }
 if ($scenario === 'overwritten-price') {
  update_post_meta($vids[0], '_regular_price', '1');
  update_post_meta($vids[0], '_price', '1');
  Goldmate_Pricing::apply($pid);
 }
 if ($scenario === 'changed-rate') {
  $test_rate = 1100000;
  Goldmate_Rate_Items::update($rate_item['id'],array('rate'=>$test_rate));
  Goldmate_Pricing::apply($pid);
 }
 if ($scenario === 'repeated-save') {
  for ($i=0; $i<5; $i++) { wc_get_product($vids[0])->save(); }
 }
 $weights = $scenario === 'changed-weight' ? array(4.125,2.111) : array(3.049,2.111);
 foreach ($vids as $i=>$id) {
  $v=wc_get_product($id); $expected=gm_expected($weights[$i]);
  gm_check((float)$v->get_regular_price('edit') === $expected, 'regular price '.$id.' expected '.$expected.' actual '.$v->get_regular_price('edit'));
  gm_check((float)$v->get_price('edit') === $expected, 'active price '.$id);
 }
 // Drain the actual WooCommerce deferred queue, not a mocked sync.
 do_action('shutdown');
 $p=wc_get_product($pid);
 $prices=$p->get_variation_prices(false)['price'];
 gm_check(!empty($prices) && (float)min($prices) === min(array_map('gm_expected',$weights)), 'parent minimum');
 gm_check(!empty($prices) && (float)max($prices) === max(array_map('gm_expected',$weights)), 'parent maximum');
 gm_check($saves < 40, 'bounded save hooks');
 echo json_encode(array('scenario'=>$scenario,'saves'=>$saves,'failures'=>$failures),JSON_UNESCAPED_SLASHES).PHP_EOL;
} finally {
 foreach (array_reverse($ids) as $id) { wp_delete_post($id,true); }
}
exit($failures ? 1 : 0);
