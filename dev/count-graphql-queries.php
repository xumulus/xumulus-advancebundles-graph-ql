<?php
// Repository-only developer tool, not shipped.
//
// Runs a products query asking for every xab field in-process, with the database profiler on, and counts the SQL
// statements the module's loaders issue. Each count should stay at 1 however many bundles are asked for (no N+1).
//
// Usage, from the Magento root:  php <path>/dev/count-graphql-queries.php 'XAB-BUNDLE-DYN,XAB-BUNDLE-FIX,24-WG080'
declare(strict_types=1);

use Magento\Framework\App\Bootstrap;

require getcwd() . '/app/bootstrap.php';
$bootstrap = Bootstrap::create(BP, $_SERVER);
$om = $bootstrap->getObjectManager();
$om->get(\Magento\Framework\App\State::class)->setAreaCode('graphql');
$om->configure($om->get(\Magento\Framework\ObjectManager\ConfigLoaderInterface::class)->load('graphql'));

$skus = json_encode(explode(',', $argv[1]));
$query = '{ products(filter:{sku:{in:' . $skus . '}}) { items { sku ... on BundleProduct { items { option_id'
    . ' xab_visible xab_cms_block xab_cms_block_html xab_gallery_single options { id xab_custom_option_required'
    . ' xab_custom_options { uid required max_characters } } } } } } }';

$conn = $om->get(\Magento\Framework\App\ResourceConnection::class)->getConnection();
$profiler = $conn->getProfiler();
$profiler->setEnabled(true);

$request = $om->get(\Magento\Framework\App\Request\Http::class);
$request->setMethod('POST');
$request->setPathInfo('/graphql');
$request->getHeaders()->addHeaderLine('Content-Type', 'application/json');
$request->setContent(json_encode(['query' => $query]));
$response = $om->get(\Magento\GraphQl\Controller\GraphQl::class)->dispatch($request);
$data = json_decode((string)$response->getContent(), true);

$options = 0;
$selections = 0;
foreach ($data['data']['products']['items'] ?? [] as $product) {
    foreach ($product['items'] ?? [] as $item) {
        $options++;
        $selections += count($item['options'] ?? []);
    }
}
$patterns = [
    'option settings (hide/custom_bundle_block)' => '/custom_bundle_block/',
    'selection overrides (custom_option_required)' => '/custom_option_required`? IS NOT NULL/',
    'child text custom options (catalog_product_option + title)' => '/FROM `catalog_product_option`.*option_title/s',
    'cms_block' => '/FROM `cms_block`/',
];
$counts = array_fill_keys(array_keys($patterns), 0);
foreach ($profiler->getQueryProfiles() ?: [] as $profile) {
    foreach ($patterns as $name => $pattern) {
        if (preg_match($pattern, $profile->getQuery())) {
            $counts[$name]++;
        }
    }
}
printf("products=%d bundle options=%d selections=%d errors=%s total SQL=%d\n", count($data['data']['products']['items'] ?? []),
    $options, $selections, json_encode($data['errors'] ?? null), $profiler->getTotalNumQueries());
foreach ($counts as $name => $count) {
    printf("  %-60s %d\n", $name, $count);
}
