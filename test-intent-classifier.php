<?php
/**
 * Test Intent Classifier
 *
 * This is a standalone test file to verify the intent classifier functionality.
 * Access it by navigating to: /wp-content/plugins/whatsapp-commerce-hub/test-intent-classifier.php
 *
 * @package WhatsApp_Commerce_Hub
 */

// Load WordPress.
require_once '../../../wp-load.php';

// Check if user is admin.
if ( ! current_user_can( 'manage_options' ) ) {
	wp_die( 'You do not have permission to access this page.' );
}

/**
 * Run intent classifier tests.
 *
 * @return array Test results.
 */
function run_intent_classifier_tests() {
	$results = [];
	$classifier = new WCH_Intent_Classifier();

	// Test 1: Greeting intent
	$test_cases = [
		// Greetings
		[
			'text'            => 'Hello!',
			'expected_intent' => WCH_Intent::INTENT_GREETING,
			'min_confidence'  => 0.9,
			'description'     => 'Greeting - Hello',
		],
		[
			'text'            => 'Good morning',
			'expected_intent' => WCH_Intent::INTENT_GREETING,
			'min_confidence'  => 0.9,
			'description'     => 'Greeting - Good morning',
		],
		[
			'text'            => 'Hey there',
			'expected_intent' => WCH_Intent::INTENT_GREETING,
			'min_confidence'  => 0.9,
			'description'     => 'Greeting - Hey',
		],

		// Browse
		[
			'text'            => 'Show me your products',
			'expected_intent' => WCH_Intent::INTENT_BROWSE,
			'min_confidence'  => 0.85,
			'description'     => 'Browse - Show products',
		],
		[
			'text'            => 'I want to see the catalog',
			'expected_intent' => WCH_Intent::INTENT_BROWSE,
			'min_confidence'  => 0.85,
			'description'     => 'Browse - View catalog',
		],

		// Search
		[
			'text'            => 'I am looking for a blue shirt',
			'expected_intent' => WCH_Intent::INTENT_SEARCH,
			'min_confidence'  => 0.8,
			'description'     => 'Search - Looking for product',
			'expected_entity' => array( 'type' => 'PRODUCT_NAME', 'value' => 'a blue shirt' ),
		],
		[
			'text'            => 'Find me running shoes',
			'expected_intent' => WCH_Intent::INTENT_SEARCH,
			'min_confidence'  => 0.8,
			'description'     => 'Search - Find product',
			'expected_entity' => array( 'type' => 'PRODUCT_NAME', 'value' => 'running shoes' ),
		],

		// View Cart
		[
			'text'            => 'Show my cart',
			'expected_intent' => WCH_Intent::INTENT_VIEW_CART,
			'min_confidence'  => 0.85,
			'description'     => 'View Cart - My cart',
		],
		[
			'text'            => 'What is in my basket?',
			'expected_intent' => WCH_Intent::INTENT_VIEW_CART,
			'min_confidence'  => 0.85,
			'description'     => 'View Cart - My basket',
		],

		// Checkout
		[
			'text'            => 'I want to checkout',
			'expected_intent' => WCH_Intent::INTENT_CHECKOUT,
			'min_confidence'  => 0.85,
			'description'     => 'Checkout - I want to checkout',
		],
		[
			'text'            => 'Let me buy this',
			'expected_intent' => WCH_Intent::INTENT_CHECKOUT,
			'min_confidence'  => 0.85,
			'description'     => 'Checkout - Buy',
		],

		// Order Status
		[
			'text'            => 'Where is my order #12345?',
			'expected_intent' => WCH_Intent::INTENT_ORDER_STATUS,
			'min_confidence'  => 0.8,
			'description'     => 'Order Status - Where is order',
			'expected_entity' => array( 'type' => 'ORDER_NUMBER', 'value' => '12345' ),
		],
		[
			'text'            => 'Track my package',
			'expected_intent' => WCH_Intent::INTENT_ORDER_STATUS,
			'min_confidence'  => 0.8,
			'description'     => 'Order Status - Track package',
		],

		// Cancel
		[
			'text'            => 'Cancel my order',
			'expected_intent' => WCH_Intent::INTENT_CANCEL,
			'min_confidence'  => 0.75,
			'description'     => 'Cancel - Cancel order',
		],
		[
			'text'            => 'Remove this item',
			'expected_intent' => WCH_Intent::INTENT_CANCEL,
			'min_confidence'  => 0.75,
			'description'     => 'Cancel - Remove item',
		],

		// Help
		[
			'text'            => 'I need help',
			'expected_intent' => WCH_Intent::INTENT_HELP,
			'min_confidence'  => 0.85,
			'description'     => 'Help - Need help',
		],
		[
			'text'            => 'Connect me to a human agent',
			'expected_intent' => WCH_Intent::INTENT_HELP,
			'min_confidence'  => 0.85,
			'description'     => 'Help - Human agent',
		],
	];

	// Run all test cases
	foreach ( $test_cases as $test ) {
		$intent = $classifier->classify( $test['text'] );

		$passed = true;
		$error_msg = '';

		// Check intent
		if ( $intent->intent_name !== $test['expected_intent'] ) {
			$passed = false;
			$error_msg .= "Expected intent '{$test['expected_intent']}', got '{$intent->intent_name}'. ";
		}

		// Check confidence
		if ( $intent->confidence < $test['min_confidence'] ) {
			$passed = false;
			$error_msg .= "Confidence {$intent->confidence} is below threshold {$test['min_confidence']}. ";
		}

		// Check entity if expected
		if ( isset( $test['expected_entity'] ) ) {
			$entity = $intent->get_entity( $test['expected_entity']['type'] );
			if ( ! $entity ) {
				$passed = false;
				$error_msg .= "Expected entity '{$test['expected_entity']['type']}' not found. ";
			} elseif ( $entity['value'] !== $test['expected_entity']['value'] ) {
				$passed = false;
				$error_msg .= "Expected entity value '{$test['expected_entity']['value']}', got '{$entity['value']}'. ";
			}
		}

		$results[] = [
			'name'       => $test['description'],
			'passed'     => $passed,
			'error'      => $error_msg,
			'intent'     => $intent->intent_name,
			'confidence' => $intent->confidence,
			'entities'   => $intent->entities,
		];
	}

	// Test entity extraction
	$entity_tests = [
		[
			'text'            => 'I want 3 items',
			'expected_entity' => array( 'type' => 'QUANTITY', 'value' => 3 ),
			'description'     => 'Entity - Quantity extraction',
		],
		[
			'text'            => 'My phone is +1-234-567-8900',
			'expected_entity' => array( 'type' => 'PHONE', 'value' => '+12345678900' ),
			'description'     => 'Entity - Phone extraction',
		],
		[
			'text'            => 'Email me at test@example.com',
			'expected_entity' => array( 'type' => 'EMAIL', 'value' => 'test@example.com' ),
			'description'     => 'Entity - Email extraction',
		],
	];

	foreach ( $entity_tests as $test ) {
		$intent = $classifier->classify( $test['text'] );
		$entity = $intent->get_entity( $test['expected_entity']['type'] );

		$passed = true;
		$error_msg = '';

		if ( ! $entity ) {
			$passed = false;
			$error_msg = "Entity '{$test['expected_entity']['type']}' not found";
		} elseif ( $entity['value'] != $test['expected_entity']['value'] ) {
			$passed = false;
			$error_msg = "Expected '{$test['expected_entity']['value']}', got '{$entity['value']}'";
		}

		$results[] = [
			'name'       => $test['description'],
			'passed'     => $passed,
			'error'      => $error_msg,
			'intent'     => $intent->intent_name,
			'confidence' => $intent->confidence,
			'entities'   => $intent->entities,
		];
	}

	return $results;
}

/**
 * Display test results.
 *
 * @param array $results Test results.
 */
function display_test_results( $results ) {
	$total = count( $results );
	$passed = count( array_filter( $results, function( $r ) { return $r['passed']; } ) );
	$failed = $total - $passed;
	$pass_rate = $total > 0 ? round( ( $passed / $total ) * 100, 2 ) : 0;

	echo '<h2>Test Results</h2>';
	echo "<p><strong>Total Tests:</strong> {$total}</p>";
	echo "<p><strong>Passed:</strong> <span style='color: green;'>{$passed}</span></p>";
	echo "<p><strong>Failed:</strong> <span style='color: red;'>{$failed}</span></p>";
	echo "<p><strong>Pass Rate:</strong> {$pass_rate}%</p>";

	echo '<table style="width: 100%; border-collapse: collapse; margin-top: 20px;">';
	echo '<thead><tr style="background: #f0f0f0;">';
	echo '<th style="padding: 10px; text-align: left; border: 1px solid #ddd;">Test</th>';
	echo '<th style="padding: 10px; text-align: left; border: 1px solid #ddd;">Status</th>';
	echo '<th style="padding: 10px; text-align: left; border: 1px solid #ddd;">Intent</th>';
	echo '<th style="padding: 10px; text-align: left; border: 1px solid #ddd;">Confidence</th>';
	echo '<th style="padding: 10px; text-align: left; border: 1px solid #ddd;">Entities</th>';
	echo '<th style="padding: 10px; text-align: left; border: 1px solid #ddd;">Error</th>';
	echo '</tr></thead><tbody>';

	foreach ( $results as $result ) {
		$status_color = $result['passed'] ? 'green' : 'red';
		$status_text = $result['passed'] ? '✓ Pass' : '✗ Fail';
		$entities_text = ! empty( $result['entities'] ) ? wp_json_encode( $result['entities'] ) : 'None';

		echo '<tr>';
		echo '<td style="padding: 10px; border: 1px solid #ddd;">' . esc_html( $result['name'] ) . '</td>';
		echo '<td style="padding: 10px; border: 1px solid #ddd; color: ' . $status_color . ';"><strong>' . $status_text . '</strong></td>';
		echo '<td style="padding: 10px; border: 1px solid #ddd;">' . esc_html( $result['intent'] ) . '</td>';
		echo '<td style="padding: 10px; border: 1px solid #ddd;">' . round( $result['confidence'], 2 ) . '</td>';
		echo '<td style="padding: 10px; border: 1px solid #ddd; font-size: 11px;">' . esc_html( $entities_text ) . '</td>';
		echo '<td style="padding: 10px; border: 1px solid #ddd; color: red;">' . esc_html( $result['error'] ) . '</td>';
		echo '</tr>';
	}

	echo '</tbody></table>';
}

// Run tests.
$results = run_intent_classifier_tests();

?>
<!DOCTYPE html>
<html>
<head>
	<title>WCH Intent Classifier Test</title>
	<meta charset="utf-8">
	<style>
		body {
			font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Oxygen-Sans, Ubuntu, Cantarell, "Helvetica Neue", sans-serif;
			padding: 20px;
			max-width: 1400px;
			margin: 0 auto;
		}
		h1 {
			color: #333;
			border-bottom: 2px solid #0073aa;
			padding-bottom: 10px;
		}
		.stats {
			background: #f9f9f9;
			padding: 15px;
			border-radius: 5px;
			margin: 20px 0;
		}
	</style>
</head>
<body>
	<h1>WhatsApp Commerce Hub - Intent Classifier Tests</h1>

	<div class="stats">
		<?php display_test_results( $results ); ?>
	</div>

	<hr>

	<h3>Classifier Statistics</h3>
	<pre><?php
	$classifier = new WCH_Intent_Classifier();
	$stats = $classifier->get_statistics();
	print_r( $stats );
	?></pre>

	<hr>

	<h3>Interactive Test</h3>
	<form method="GET" style="margin: 20px 0;">
		<label for="test_text">Enter text to classify:</label><br>
		<input type="text" id="test_text" name="test_text" style="width: 500px; padding: 8px; margin: 10px 0;" value="<?php echo isset( $_GET['test_text'] ) ? esc_attr( $_GET['test_text'] ) : ''; ?>">
		<button type="submit" style="padding: 8px 20px;">Classify</button>
	</form>

	<?php if ( isset( $_GET['test_text'] ) && ! empty( $_GET['test_text'] ) ) : ?>
		<div style="background: #f0f0f0; padding: 15px; border-radius: 5px;">
			<h4>Classification Result:</h4>
			<?php
			$classifier = new WCH_Intent_Classifier();
			$intent = $classifier->classify( $_GET['test_text'] );
			?>
			<p><strong>Input:</strong> <?php echo esc_html( $_GET['test_text'] ); ?></p>
			<p><strong>Intent:</strong> <?php echo esc_html( $intent->intent_name ); ?></p>
			<p><strong>Confidence:</strong> <?php echo esc_html( round( $intent->confidence, 4 ) ); ?></p>
			<p><strong>Entities:</strong></p>
			<pre><?php print_r( $intent->entities ); ?></pre>
		</div>
	<?php endif; ?>

	<hr>

	<p><a href="<?php echo admin_url(); ?>">← Back to WordPress Admin</a></p>
</body>
</html>
